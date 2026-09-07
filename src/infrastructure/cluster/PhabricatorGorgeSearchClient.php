<?php

/**
 * HTTP client for the Gorge search service.
 *
 * Gorge is a Go service which fronts Elasticsearch and Meilisearch with one
 * HTTP API, keeping its own per-host health table and failing over between
 * backends. It speaks Phorge's document model directly: the four-character
 * field and relationship constants, the camelCase wire names, and the
 * document types Phorge indexes.
 *
 * Request building and envelope parsing live in
 * @{class:PhabricatorGorgeServiceClient}, which all of the Gorge clients
 * share. This class adds the seven search routes and unwraps the small
 * per-route payloads.
 *
 * Like @{class:PhabricatorGorgeMailerClient} and unlike
 * @{class:PhabricatorGorgeRenderClient}, the endpoint is injected rather than
 * read from configuration here: it comes from the `hosts` of the
 * `cluster.search` entry which selected the engine, so that a service with
 * several hosts is described the same way every other search service is. The
 * shared token is the one thing which does come from a global option, since
 * `cluster.search` entries have a fixed key table with nowhere to put it.
 */
final class PhabricatorGorgeSearchClient
  extends PhabricatorGorgeServiceClient {

  const PATH_INDEX = '/api/search/index';
  const PATH_QUERY = '/api/search/query';
  const PATH_INIT = '/api/search/init';
  const PATH_EXISTS = '/api/search/exists';
  const PATH_STATS = '/api/search/stats';
  const PATH_SANE = '/api/search/sane';
  const PATH_BACKENDS = '/api/search/backends';

  protected static function getServiceName() {
    return pht('Gorge search service');
  }

  protected function getDefaultTimeout() {
    // Longer than the render client's 15 seconds because one client serves
    // both kinds of traffic: an interactive search, and index management
    // which deletes and recreates every mapping.
    //
    // There is no per-entry override to offer. A `cluster.search` entry is
    // validated against the fixed key table in
    // @{class:PhabricatorClusterSearchConfigType}, which has no "timeout"
    // key and rejects unknown ones, so a value written there would be a
    // configuration error rather than a longer timeout.
    return 30;
  }


/* -(  Documents  )---------------------------------------------------------- */


  /**
   * Add or replace one document in the index.
   *
   * @param map<string, wild> $document Document in wire form, as serialized
   *   by @{class:PhabricatorGorgeFulltextStorageEngine}.
   * @return wild The "data" section of the response envelope.
   */
  public function indexDocument(array $document) {
    return $this->executeJSONRequest(self::PATH_INDEX, $document);
  }


  /**
   * Execute a fulltext query.
   *
   * @param map<string, wild> $query Query in wire form.
   * @return list<string> Matching PHIDs, in relevance order.
   */
  public function executeQuery(array $query) {
    $uri = $this->getURI().self::PATH_QUERY;

    $data = $this->executeJSONRequest(self::PATH_QUERY, $query);

    $phids = idx($data, 'phids');

    // An empty result arrives as JSON null rather than as an empty list: the
    // service builds the list lazily and encodes a nil slice.
    if ($phids === null) {
      $phids = array();
    }

    if (!is_array($phids)) {
      throw new Exception(
        pht(
          'The %s returned a response for "%s" with no usable "%s" field.',
          static::getServiceName(),
          $uri,
          'phids'));
    }

    return array_values($phids);
  }


/* -(  Index Management  )--------------------------------------------------- */


  /**
   * Create the index, dropping and rebuilding it if it already exists.
   *
   * @param list<string> $doc_types Document types to build mappings for.
   * @return wild The "data" section of the response envelope.
   */
  public function initIndex(array $doc_types) {
    $spec = array(
      'docTypes' => array_values($doc_types),
    );

    return $this->executeJSONRequest(self::PATH_INIT, $spec);
  }


  /**
   * Does the index exist?
   *
   * @return bool True if the index exists.
   */
  public function indexExists() {
    $uri = $this->getURI().self::PATH_EXISTS;

    $data = $this->executeGETRequest(self::PATH_EXISTS);

    return $this->requireBoolean($data, 'exists', $uri);
  }


  /**
   * Does the index match the mapping the service would create today?
   *
   * @param list<string> $doc_types Document types to compare.
   * @return bool True if the index is usable as-is.
   */
  public function indexIsSane(array $doc_types) {
    $uri = $this->getURI().self::PATH_SANE;

    $spec = array(
      'docTypes' => array_values($doc_types),
    );

    $data = $this->executeJSONRequest(self::PATH_SANE, $spec);

    return $this->requireBoolean($data, 'sane', $uri);
  }


  /**
   * Read index statistics.
   *
   * The keys depend on which backend answered -- Elasticsearch reports
   * "queries", "documents", "deleted" and "storage_bytes", Meilisearch
   * reports "documents" and "indexing" -- so this returns them as they
   * arrive and leaves labelling to the caller.
   *
   * @return map<string, wild> Statistics reported by the service.
   */
  public function getIndexStats() {
    $data = $this->executeGETRequest(self::PATH_STATS);

    if (!is_array($data)) {
      return array();
    }

    return $data;
  }


  /**
   * List the backends the service has configured.
   *
   * This is a one-shot call used by diagnostics.
   *
   * @return wild Backend list reported by the service.
   */
  public function getBackends() {
    return $this->executeGETRequest(self::PATH_BACKENDS);
  }


/* -(  Internals  )---------------------------------------------------------- */


  private function executeGETRequest($path) {
    $uri = $this->getURI().$path;

    $result = $this->newRequestFuture($uri)->resolve();

    return self::parseResponseEnvelope($uri, $result);
  }

  private function executeJSONRequest($path, array $body) {
    $uri = $this->getURI().$path;

    $result = $this->newJSONRequestFuture($uri, $body)->resolve();

    return self::parseResponseEnvelope($uri, $result);
  }


  /**
   * Read a boolean out of a response, refusing to guess if it is missing.
   *
   * The answers to "does the index exist" and "is the index sane" both drive
   * setup issues which tell an operator to rebuild the index, and a missing
   * key coerced to false would produce that advice out of a malformed
   * response rather than out of a real answer.
   *
   * @param wild $data The "data" section of the envelope.
   * @param string $key Key to read.
   * @param string $uri URI which was requested, for diagnostics.
   * @return bool Value of the key.
   */
  private function requireBoolean($data, $key, $uri) {
    $value = null;
    if (is_array($data)) {
      $value = idx($data, $key);
    }

    if (!is_bool($value)) {
      throw new Exception(
        pht(
          'The %s returned a response for "%s" with no usable "%s" field.',
          static::getServiceName(),
          $uri,
          $key));
    }

    return $value;
  }

}
