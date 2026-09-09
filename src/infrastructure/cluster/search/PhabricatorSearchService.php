<?php

class PhabricatorSearchService
  extends Phobject {

  const KEY_REFS = 'cluster.search.refs';

  protected $config;
  protected $disabled;
  protected $engine;
  protected $hosts = array();
  protected $hostsConfig;
  protected $hostType;
  protected $roles = array();

  const STATUS_OKAY = 'okay';
  const STATUS_FAIL = 'fail';

  const ROLE_WRITE = 'write';
  const ROLE_READ = 'read';

  public function __construct(PhabricatorFulltextStorageEngine $engine) {
    $this->engine = $engine;
    $this->hostType = $engine->getHostType();
  }

  /**
   * @throws Exception
   * @return PhabricatorSearchHost An instance of a subclass of
   *   PhabricatorSearchHost
   */
  public function newHost($config) {
    $host = clone($this->hostType);
    $host_config = $this->config + $config;
    $host->setConfig($host_config);
    $this->hosts[] = $host;
    return $host;
  }

  /**
   * @return PhabricatorFulltextStorageEngine A subclass of
   *   PhabricatorFulltextStorageEngine
   */
  public function getEngine() {
    return $this->engine;
  }

  /**
   * @return string Display name of the search host, e.g. "MySQL"
   */
  public function getDisplayName() {
    return $this->hostType->getDisplayName();
  }

  /**
   * @return string[] Get a list of fields to show in the status overview UI
   */
  public function getStatusViewColumns() {
    return $this->hostType->getStatusViewColumns();
  }

  public function setConfig($config) {
    $this->config = $config;

    if (!isset($config['hosts'])) {
      $config['hosts'] = array(
        array(
          'host' => idx($config, 'host'),
          'port' => idx($config, 'port'),
          'protocol' => idx($config, 'protocol'),
          'roles' => idx($config, 'roles'),
        ),
      );
    }
    foreach ($config['hosts'] as $host) {
      $this->newHost($host);
    }

  }

  /**
   * @return string[]
   */
  public function getConfig() {
    return $this->config;
  }

  public static function getConnectionStatusMap() {
    return array(
      self::STATUS_OKAY => array(
        'icon' => 'fa-exchange',
        'color' => 'green',
        'label' => pht('Okay'),
      ),
      self::STATUS_FAIL => array(
        'icon' => 'fa-times',
        'color' => 'red',
        'label' => pht('Failed'),
      ),
    );
  }

  public function isWritable() {
    return (bool)$this->getAllHostsForRole(self::ROLE_WRITE);
  }

  public function isReadable() {
    return (bool)$this->getAllHostsForRole(self::ROLE_READ);
  }

  public function getPort() {
    return idx($this->config, 'port');
  }

  public function getProtocol() {
    return idx($this->config, 'protocol');
  }


  public function getVersion() {
    return idx($this->config, 'version');
  }

  public function getHosts() {
    return $this->hosts;
  }


  /**
   * Get a random host reference with the specified role, skipping hosts which
   * failed recent health checks.
   * @throws PhabricatorClusterNoHostForRoleException if no healthy hosts match.
   * @return PhabricatorSearchHost
   */
  public function getAnyHostForRole($role) {
    $hosts = $this->getAllHostsForRole($role);
    shuffle($hosts);
    foreach ($hosts as $host) {
      $health = $host->getHealthRecord();
      if ($health->getIsHealthy()) {
        return $host;
      }
    }
    throw new PhabricatorClusterNoHostForRoleException($role);
  }


  /**
   * Get all configured hosts for this service which have the specified role.
   * @return PhabricatorSearchHost[]
   */
  public function getAllHostsForRole($role) {
    // if the role is explicitly set to false at the top level, then all hosts
    // have the role disabled.
    if (idx($this->config, $role) === false) {
      return array();
    }

    $hosts = array();
    foreach ($this->hosts as $host) {
      if ($host->hasRole($role)) {
        $hosts[] = $host;
      }
    }
    return $hosts;
  }

  /**
   * Get a reference to all configured fulltext search cluster services
   * @return PhabricatorSearchService[]
   */
  public static function getAllServices() {
    $cache = PhabricatorCaches::getRequestCache();

    $refs = $cache->getKey(self::KEY_REFS);
    if (!$refs) {
      $refs = self::newRefs();
      $cache->setKey(self::KEY_REFS, $refs);
    }

    return $refs;
  }

  /**
   * Load all valid PhabricatorFulltextStorageEngine subclasses
   */
  public static function loadAllFulltextStorageEngines() {
    return id(new PhutilClassMapQuery())
    ->setAncestorClass(PhabricatorFulltextStorageEngine::class)
    ->setUniqueMethod('getEngineIdentifier')
    ->execute();
  }

  /**
   * Create instances of PhabricatorSearchService based on configuration
   * @return PhabricatorSearchService[]
   */
  public static function newRefs() {
    $services = PhabricatorEnv::getEnvConfig('cluster.search');
    $engines = self::loadAllFulltextStorageEngines();
    $refs = array();

    $gorge = PhabricatorGorgeServiceRegistry::getService('search');
    if ($gorge->isDisabled()) {
      $services = array_values(
        array_filter(
          $services,
          function($config) {
            return !(is_array($config) &&
              idx($config, 'type') ===
                PhabricatorGorgeFulltextStorageEngine::ENGINE_TYPE);
          }));

      // A deployment generated before this policy existed may contain only
      // the Gorge entry. "off" means select the native implementation, not
      // disable search, so provide the normal MySQL service if filtering the
      // configured Gorge service leaves no destination.
      if (!$services) {
        $services[] = array(
          'type' => 'mysql',
          'roles' => array('read' => true, 'write' => true),
        );
      }
    }

    foreach ($services as $config) {
      // Normally, we've validated configuration before we get this far, but
      // make sure we don't fatal if we end up here with a bogus configuration.
      if (!isset($engines[$config['type']])) {
        throw new Exception(
          pht(
            'Configured search engine type "%s" is unknown. Valid engines '.
            'are: %s.',
            $config['type'],
            implode(', ', array_keys($engines))));
      }

      $engine = clone($engines[$config['type']]);
      $cluster = new self($engine);
      $cluster->setConfig($config);
      $engine->setService($cluster);
      $refs[] = $cluster;
    }

    if ($gorge->isFallbackAllowed()) {
      $has_native_read = false;
      $has_native_write = false;
      foreach ($refs as $ref) {
        $config = $ref->getConfig();
        if (idx($config, 'type') ===
            PhabricatorGorgeFulltextStorageEngine::ENGINE_TYPE) {
          continue;
        }

        $has_native_read = $has_native_read || $ref->isReadable();
        $has_native_write = $has_native_write || $ref->isWritable();
      }

      // A native engine which can only read does not make indexing fallback
      // viable, and a write-only engine can not answer queries. Fill only the
      // missing roles so both operations have a real fallback destination.
      if (!$has_native_read || !$has_native_write) {
        $config = array(
          'type' => 'mysql',
          'roles' => array(
            'read' => !$has_native_read,
            'write' => !$has_native_write,
          ),
        );

        $engine = clone($engines['mysql']);
        $cluster = new self($engine);
        $cluster->setConfig($config);
        $engine->setService($cluster);
        $refs[] = $cluster;
      }
    }

    return $refs;
  }


  /**
   * (re)index the document: attempt to pass the document to all writable
   * fulltext search hosts
   */
  public static function reindexAbstractDocument(
    PhabricatorSearchAbstractDocument $document) {

    $exceptions = array();
    $gorge_fallback_exceptions = array();
    $native_write_succeeded = false;
    foreach (self::getAllServices() as $service) {
      if (!$service->isWritable()) {
        continue;
      }

      $engine = $service->getEngine();
      $is_gorge =
        (idx($service->getConfig(), 'type') ===
          PhabricatorGorgeFulltextStorageEngine::ENGINE_TYPE);
      try {
        $engine->reindexAbstractDocument($document);
        if (!$is_gorge) {
          $native_write_succeeded = true;
        }
      } catch (Exception $ex) {
        if ($is_gorge) {
          $gorge = PhabricatorGorgeServiceRegistry::getService('search');
          if ($gorge->isFallbackAllowed()) {
            $gorge_fallback_exceptions[] = $ex;
            continue;
          }
        }

        $exceptions[] = $ex;
      }
    }

    if ($gorge_fallback_exceptions) {
      if ($native_write_succeeded) {
        PhabricatorGorgeServiceRegistry::getService('search')
          ->recordFallback('index');
      } else {
        foreach ($gorge_fallback_exceptions as $exception) {
          $exceptions[] = $exception;
        }
      }
    }

    if ($exceptions) {
      throw new PhutilAggregateException(
        pht(
          'Writes to search services failed while reindexing document "%s".',
          $document->getPHID()),
        $exceptions);
    }
  }

  /**
   * Execute a full-text query and return a list of PHIDs of matching objects.
   * @return string[]
   * @throws PhutilAggregateException
   */
  public static function executeSearch(PhabricatorSavedQuery $query) {
    $result_set = self::newResultSet($query);
    return $result_set->getPHIDs();
  }

  /**
   * @param  PhabricatorSavedQuery $query
   * @return PhabricatorFulltextResultSet
   */
  public static function newResultSet(PhabricatorSavedQuery $query) {
    $exceptions = array();
    $fallback_from_gorge = false;
    // try all services until one succeeds
    foreach (self::getAllServices() as $service) {
      if (!$service->isReadable()) {
        continue;
      }

      $is_gorge = (idx($service->getConfig(), 'type') === 'gorge');
      if (!$is_gorge && $fallback_from_gorge) {
        PhabricatorGorgeServiceRegistry::getService('search')
          ->recordFallback('query');
        $fallback_from_gorge = false;
      }

      try {
        $engine = $service->getEngine();
        $phids = $engine->executeSearch($query);

        return id(new PhabricatorFulltextResultSet())
          ->setPHIDs($phids)
          ->setFulltextTokens($engine->getFulltextTokens());
      } catch (PhutilSearchQueryCompilerSyntaxException $ex) {
        // If there's a query compilation error, return it directly to the
        // user: they issued a query with bad syntax.
        throw $ex;
      } catch (Exception $ex) {
        if ($is_gorge) {
          $gorge = PhabricatorGorgeServiceRegistry::getService('search');
          if (!$gorge->isFallbackAllowed()) {
            throw $ex;
          }
          $fallback_from_gorge = true;
        }
        $exceptions[] = $ex;
      }
    }
    $msg = pht('All of the configured Fulltext Search services failed.');
    throw new PhutilAggregateException($msg, $exceptions);
  }

}
