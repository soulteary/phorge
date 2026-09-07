<?php

/**
 * One host of a `cluster.search` entry which is served by Gorge.
 *
 * A host here is an instance of the Gorge search service, not of the search
 * engine behind it: the service is what this server talks to, and which
 * Elasticsearch or Meilisearch hosts sit behind it is configured on its own
 * side. That is the whole reason to prefer a single entry with a single host
 * -- fan-out and failover across search engines already happen once, in the
 * service, and describing them a second time here means maintaining two
 * halves of one topology.
 */
final class PhabricatorGorgeSearchHost
  extends PhabricatorSearchHost {

  /**
   * Port the service listens on unless an entry says otherwise. This is the
   * port the `gorge-search` container publishes.
   */
  const DEFAULT_PORT = 8120;

  private $protocol = 'http';
  private $path = '';

  public function setConfig($config) {
    // Default to read and write, as @{class:PhabricatorMySQLSearchHost} does
    // and unlike @{class:PhabricatorElasticsearchHost}, which defaults to no
    // roles at all. A host with no roles is skipped by every caller without
    // complaining, so an entry which names a service and forgets to list
    // roles would be a search configuration that quietly does nothing.
    $this->setRoles(
      idx(
        $config,
        'roles',
        array(
          'read' => true,
          'write' => true,
        )));

    $this
      ->setHost(idx($config, 'host', $this->host))
      ->setPort(idx($config, 'port', self::DEFAULT_PORT))
      ->setProtocol(idx($config, 'protocol', $this->protocol))
      ->setPath(idx($config, 'path', $this->path));

    return $this;
  }

  /**
   * @return string Display name of the search host: "Gorge Search"
   */
  public function getDisplayName() {
    return pht('Gorge Search');
  }

  /**
   * @return string[] Get a list of fields to show in the status overview UI
   */
  public function getStatusViewColumns() {
    return array(
      pht('Protocol') => $this->getProtocol(),
      pht('Host') => $this->getHost(),
      pht('Port') => $this->getPort(),
      pht('URI Prefix') => $this->getPath(),
      pht('Roles') => implode(', ', array_keys($this->getRoles())),
    );
  }

  public function setProtocol($protocol) {
    $this->protocol = $protocol;
    return $this;
  }

  /**
   * @return string Search host protocol, by default "http"
   */
  public function getProtocol() {
    return $this->protocol;
  }

  public function setPath($path) {
    $this->path = $path;
    return $this;
  }

  /**
   * Path prefix the service is mounted under, if it is behind a proxy which
   * rewrites paths.
   *
   * Note that this is not the index name, which is the meaning `path` has for
   * @{class:PhabricatorElasticsearchHost}: the index lives on the service
   * side, where the search engines are configured. Normally this is empty and
   * the routes are reached at the root, as they are when the service runs as
   * a container in the same network.
   *
   * @return string Path prefix, or the empty string.
   */
  public function getPath() {
    return $this->path;
  }

  /**
   * Base URI of the service on this host, with no trailing slash.
   *
   * This returns a string rather than a @{class@arcanist:PhutilURI}, which is
   * what @{class:PhabricatorElasticsearchHost} returns: the client appends
   * fixed route paths to it, so there is nothing to build up piece by piece.
   *
   * @return string Base URI of the service.
   */
  public function getURI() {
    $uri = $this->getProtocol().'://'.$this->getHost().':'.$this->getPort();

    $path = $this->getPath();
    if (phutil_nonempty_string($path)) {
      $uri = $uri.'/'.trim($path, '/');
    }

    return $uri;
  }

  public function getConnectionStatus() {
    // "Sane" rather than merely reachable, matching Elasticsearch: an index
    // whose mapping no longer matches what the service would build can be
    // reached and searched, and will quietly return worse results, so the
    // status column should not call it okay.
    $status = $this->getEngine()->indexIsSane($this);
    return $status ? parent::STATUS_OKAY : parent::STATUS_FAIL;
  }

}
