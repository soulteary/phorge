<?php

/**
 * Fulltext storage engine backed by the Gorge search service.
 *
 * The service speaks Phorge's document model over HTTP and keeps the
 * Elasticsearch or Meilisearch clients on its own side, so this engine only
 * has to serialize documents and queries onto the wire. Compare
 * @{class:PhabricatorElasticFulltextStorageEngine}, which builds
 * Elasticsearch mappings and query DSL here in PHP: none of that lives in
 * this engine, and in exchange none of it can be tuned from this side either.
 *
 * Selected by a `cluster.search` entry of type "gorge":
 *
 *   [
 *     {
 *       "type": "gorge",
 *       "hosts": [
 *         {
 *           "host": "gorge-search",
 *           "port": 8120,
 *           "roles": {"read": true, "write": true}
 *         }
 *       ]
 *     }
 *   ]
 *
 * The shared service token is not part of that entry -- `cluster.search`
 * entries are validated against a fixed key table -- and comes from the
 * `gorge.search.token` option instead.
 */
final class PhabricatorGorgeFulltextStorageEngine
  extends PhabricatorFulltextStorageEngine {

  /**
   * Value of the `type` key of a `cluster.search` entry served by this
   * engine. Named as a constant so that callers which read the configuration
   * directly, rather than through @{class:PhabricatorSearchService}, do not
   * have to repeat the string.
   */
  const ENGINE_TYPE = 'gorge';

  /**
   * @return string Engine identifier string: "gorge"
   */
  public function getEngineIdentifier() {
    return self::ENGINE_TYPE;
  }

  public function getHostType() {
    return new PhabricatorGorgeSearchHost($this);
  }

  public function getHostForRead() {
    return $this->getService()->getAnyHostForRole('read');
  }

  public function getHostForWrite() {
    return $this->getService()->getAnyHostForRole('write');
  }


/* -(  Managing Documents  )------------------------------------------------- */


  public function reindexAbstractDocument(
    PhabricatorSearchAbstractDocument $doc) {

    $host = $this->getHostForWrite();

    $this->executeRequest(
      $host,
      'indexDocument',
      array($this->newDocumentSpec($doc)));
  }

  public function executeSearch(PhabricatorSavedQuery $query) {
    $spec = $this->newQuerySpec($query);

    // Try every readable host rather than one healthy host, as the
    // Elasticsearch engine does: a search which returns an error is visible
    // to the user immediately, so it is worth spending a second request on.
    // Normally there is one host and the service does its own failover.
    $exceptions = array();
    foreach ($this->getService()->getAllHostsForRole('read') as $host) {
      try {
        return $this->executeRequest($host, 'executeQuery', array($spec));
      } catch (Exception $ex) {
        $exceptions[] = $ex;
      }
    }

    throw new PhutilAggregateException(
      pht('All Fulltext Search hosts failed:'),
      $exceptions);
  }


/* -(  Managing the Index  )------------------------------------------------- */


  public function indexExists(?PhabricatorGorgeSearchHost $host = null) {
    if (!$host) {
      $host = $this->getHostForRead();
    }

    return $this->executeRequest($host, 'indexExists');
  }

  public function indexIsSane(?PhabricatorGorgeSearchHost $host = null) {
    if (!$host) {
      $host = $this->getHostForRead();
    }

    return $this->executeRequest(
      $host,
      'indexIsSane',
      array($this->getDocumentTypes()));
  }

  public function initIndex() {
    $host = $this->getHostForWrite();

    // Unlike the Elasticsearch engine, this does not check whether the index
    // exists first in order to delete it: the service drops and recreates it
    // as part of the same call, so asking twice would only add a round trip
    // and a window in which the answer could change.
    $this->executeRequest(
      $host,
      'initIndex',
      array($this->getDocumentTypes()));
  }

  public function getIndexStats(?PhabricatorGorgeSearchHost $host = null) {
    if (!$host) {
      $host = $this->getHostForRead();
    }

    $stats = $this->executeRequest($host, 'getIndexStats');

    // Which keys come back depends on which backend answered, so label the
    // ones we know and pass anything else through under its own name rather
    // than dropping it. A key which is missing is missing because that
    // backend does not report it, not because something went wrong.
    $labels = array(
      'queries' => pht('Queries'),
      'documents' => pht('Documents'),
      'deleted' => pht('Deleted'),
      'storage_bytes' => pht('Storage Used'),
      'indexing' => pht('Indexing'),
    );

    $result = array();
    foreach ($stats as $key => $value) {
      if ($key === 'storage_bytes') {
        $value = phutil_format_bytes((int)$value);
      } else if (is_bool($value)) {
        $value = $value ? pht('Yes') : pht('No');
      }

      $result[idx($labels, $key, $key)] = $value;
    }

    return $result;
  }


/* -(  Wire Format  )-------------------------------------------------------- */


  /**
   * Convert an abstract document into the wire form the service expects.
   *
   * Field names are camelCase and the field and relationship names are the
   * four-character constants from
   * @{class:PhabricatorSearchDocumentFieldType} and
   * @{class:PhabricatorSearchRelationship}. Both halves are a frozen contract
   * with the Go side: unknown keys are ignored when the request is decoded,
   * so renaming one here drops it silently.
   *
   * @param PhabricatorSearchAbstractDocument $doc Document to serialize.
   * @return map<string, wild> Wire representation of the document.
   */
  private function newDocumentSpec(PhabricatorSearchAbstractDocument $doc) {
    $spec = array(
      'phid' => $doc->getPHID(),
      'type' => $doc->getDocumentType(),
      'title' => (string)$doc->getDocumentTitle(),
      'dateCreated' => (int)$doc->getDocumentCreated(),
      'dateModified' => (int)$doc->getDocumentModified(),
    );

    // Fields arrive as a list of tuples rather than a map because a document
    // may carry several corpora for one field name -- every comment on a task
    // is another "cmnt" field -- so they are kept as a list on the wire too
    // and grouped by the service.
    $fields = array();
    foreach ($doc->getFieldData() as $field) {
      list($field_name, $corpus, $aux) = $field;

      $item = array(
        'name' => $field_name,
        'corpus' => (string)$corpus,
      );

      if ($aux !== null) {
        $item['aux'] = $aux;
      }

      $fields[] = $item;
    }

    if ($fields) {
      $spec['fields'] = $fields;
    }

    $relationships = array();
    foreach ($doc->getRelationshipData() as $relationship) {
      list($field_name, $related_phid, $rtype, $time) = $relationship;

      $item = array(
        'name' => $field_name,
        'relatedPHID' => $related_phid,
        'rtype' => $rtype,
      );

      if ($time) {
        $item['timestamp'] = (int)$time;
      }

      $relationships[] = $item;
    }

    if ($relationships) {
      $spec['relationships'] = $relationships;
    }

    return $spec;
  }


  /**
   * Convert a saved query into the wire form the service expects.
   *
   * @param PhabricatorSavedQuery $query Query to serialize.
   * @return map<string, wild> Wire representation of the query.
   */
  private function newQuerySpec(PhabricatorSavedQuery $query) {
    $types = $query->getParameter('types');
    if (!$types) {
      $types = $this->getDocumentTypes();
    }

    $spec = array(
      'query' => (string)$query->getParameter('query', ''),
      'types' => array_values($types),
    );

    // Every one of these is a list of PHIDs except "statuses", which is a
    // list of the relationship constants "open" and "clos". Empty lists are
    // left out rather than sent as empty, since the service treats a present
    // key as a filter to apply.
    $list_parameters = array(
      'authorPHIDs',
      'ownerPHIDs',
      'subscriberPHIDs',
      'projectPHIDs',
      'repositoryPHIDs',
      'statuses',
    );

    foreach ($list_parameters as $parameter) {
      $value = $query->getParameter($parameter, array());
      if (is_array($value) && $value) {
        $spec[$parameter] = array_values($value);
      }
    }

    if ($query->getParameter('withAnyOwner')) {
      $spec['withAnyOwner'] = true;
    }

    if ($query->getParameter('withUnowned')) {
      $spec['withUnowned'] = true;
    }

    $exclude = $query->getParameter('exclude');
    if (phutil_nonempty_string($exclude)) {
      $spec['exclude'] = $exclude;
    }

    // The default limit matches the Elasticsearch engine, which asks for one
    // more result than a page of 100 so the caller can tell that there is
    // another page. Deep pagination is clamped by the service rather than
    // rejected here, so a very large offset returns the last reachable page
    // instead of an error.
    $spec['offset'] = (int)$query->getParameter('offset', 0);
    $spec['limit'] = (int)$query->getParameter('limit', 101);

    return $spec;
  }

  private function getDocumentTypes() {
    return array_keys(
      PhabricatorSearchApplicationSearchEngine::getIndexableDocumentTypes());
  }


/* -(  Internals  )---------------------------------------------------------- */


  private function newClient(PhabricatorGorgeSearchHost $host) {
    return id(new PhabricatorGorgeSearchClient())
      ->setURI($host->getURI())
      ->setToken(PhabricatorEnv::getEnvConfigIfExists('gorge.search.token'));
  }


  /**
   * Call one client method against one host, recording the outcome.
   *
   * The health record is what `getAnyHostForRole()` consults to route around
   * a host which is not answering, so every failure is recorded, including
   * the ones which are our fault rather than the host's -- a rejected
   * document fails the same way on every host, and the record is sampled
   * rather than a running tally, so one bad request does not evict a working
   * service.
   *
   * @param PhabricatorGorgeSearchHost $host Host to call.
   * @param string $method Method of @{class:PhabricatorGorgeSearchClient}.
   * @param list<wild> $arguments Arguments to that method.
   * @return wild Whatever the method returns.
   */
  private function executeRequest(
    PhabricatorGorgeSearchHost $host,
    $method,
    array $arguments = array()) {

    $client = $this->newClient($host);

    try {
      $result = call_user_func_array(
        array($client, $method),
        $arguments);
    } catch (Exception $ex) {
      $host->didHealthCheck(false);
      throw $ex;
    }

    $host->didHealthCheck(true);

    return $result;
  }

}
