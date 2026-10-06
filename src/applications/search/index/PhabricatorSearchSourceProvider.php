<?php

/** Domain-owned primary scans; this provider does not own job cursors. */
class PhabricatorSearchSourceProvider extends Phobject {
  const MAX_ROWS = 32;
  const MAX_PAGE_BYTES = 3145728;

  protected function newSourceObjects() {
    return id(new PhutilClassMapQuery())
      ->setAncestorClass('PhabricatorFulltextInterface')->execute();
  }

  private function sources() {
    $sources = array();
    $unsupported = array();
    foreach ($this->newSourceObjects() as $object) {
      $class = get_class($object);
      if (!($object instanceof LiskDAO) ||
          !($object instanceof PhabricatorFulltextInterface) ||
          $object->getIDKey() !== 'id' ||
          !$object->getConfigOption(LiskDAO::CONFIG_AUX_PHID) ||
          $object->getConfigOption(LiskDAO::CONFIG_NO_TABLE)) {
        $unsupported[] = $class;
        continue;
      }
      $sources[$class] = $object;
    }
    ksort($sources);
    sort($unsupported);
    return array($sources, $unsupported);
  }

  public static function parseID($value) {
    if (PHP_INT_SIZE < 8 || !is_string($value) ||
        !preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) ||
        strlen($value) > strlen((string)PHP_INT_MAX) ||
        (strlen($value) === strlen((string)PHP_INT_MAX) &&
          strcmp($value, (string)PHP_INT_MAX) > 0)) {
      throw new InvalidArgumentException(pht('Invalid source cursor.'));
    }
    return (int)$value;
  }

  public function catalog() {
    list($sources, $unsupported) = $this->sources();
    $descriptors = array();
    foreach ($sources as $class => $source) {
      $conn = $source->establishConnection('w');
      if ($conn->isInsideTransaction()) {
        throw new Exception(pht('Source catalog requires committed connections.'));
      }
      $row = queryfx_one($conn, 'SELECT COALESCE(MAX(id),0) AS upperID FROM %R', $source);
      $upper = (string)$row['upperID'];
      self::parseID($upper);
      $descriptors[] = array('className' => $class, 'upperID' => $upper);
    }
    return array(
      'sourceScanVersion' => 1,
      'namespace' => PhabricatorEnv::getEnvConfig('storage.default-namespace'),
      'serializerVersion' => PhabricatorSearchDocumentSerializer::VERSION,
      'sources' => $descriptors, 'unsupportedClasses' => $unsupported,
      'scope' => 'registered-lisk-fulltext',
      'sourceCoverageVerified' => false);
  }

  public function scan($class, $after, $upper) {
    $after_id = self::parseID($after);
    $upper_id = self::parseID($upper);
    if ($after_id > $upper_id) {
      throw new InvalidArgumentException(pht('Source cursor exceeds captured upper bound.'));
    }
    list($sources) = $this->sources();
    if (!is_string($class) || !isset($sources[$class])) {
      throw new InvalidArgumentException(pht('Source class is not registered for scanning.'));
    }
    $source = $sources[$class];
    $conn = $source->establishConnection('w');
    $search = new PhabricatorSearchGorgeProjection();
    if ($conn->isInsideTransaction() ||
        $search->establishConnection('w')->isInsideTransaction()) {
      throw new Exception(pht('Source scan requires committed connections.'));
    }
    $rows = queryfx_all($conn,
      'SELECT id,phid FROM %R WHERE id>%d AND id<=%d ORDER BY id LIMIT %d',
      $source, $after_id, $upper_id, self::MAX_ROWS);
    $items = array();
    $bytes = 0;
    $cursor = $after;
    $deadline = microtime(true) + 10;
    foreach ($rows as $row) {
      if ($items && microtime(true) >= $deadline) { break; }
      $phid = $row['phid'];
      if (!is_string($phid) ||
          !preg_match('/\APHID-[A-Z0-9]{4}-[a-zA-Z0-9]+\z/', $phid)) {
        throw new Exception(pht('Source row has no valid PHID.'));
      }
      $lock = PhabricatorGlobalLock::newLock('index', array('objectPHID' => $phid));
      $lock->lock(1);
      try {
        // Re-read on the writer while holding the same lock as index/deletion.
        $live = queryfx_one($conn, 'SELECT * FROM %R WHERE id=%d', $source, $row['id']);
        $item = array('sourceID' => (string)$row['id'], 'phid' => $phid);
        if (!$live) {
          // Never invent a tombstone: authoritative intents handle destruction.
          $item['status'] = 'missing';
        } else {
          if ($live['phid'] !== $phid) {
            throw new Exception(pht('Source identity changed during scan.'));
          }
          $object = clone $source;
          $object->loadFromArray($live);
          $engine = $object->newFulltextEngine();
          if (!$engine) {
            $item['status'] = 'no-engine';
          } else {
            $doc = $engine->setObject($object)->buildFulltextDocument();
            if ($doc->getPHID() !== $phid) {
              throw new Exception(pht('Source document identity mismatch.'));
            }
            $size = strlen(phutil_json_encode(
              PhabricatorSearchDocumentSerializer::newDocumentSpec($doc)));
            // Reserve envelope overhead, and return a prefix without advancing
            // past an object which did not fit. A single oversized doc fails.
            if ($size > PhabricatorSearchExportConduitAPIMethod::MAX_BYTES) {
              throw new Exception(pht('Source document exceeds the projection limit.'));
            }
            if ($bytes + $size + 2048 > self::MAX_PAGE_BYTES) { break; }
            $item['status'] = 'materialized';
            $item['event'] = PhabricatorSearchProjectionPublisher::publishDocument(
              PhabricatorEnv::getEnvConfig('storage.default-namespace'), $doc);
            $bytes += $size + 2048;
          }
        }
        $items[] = $item;
        $cursor = (string)$row['id'];
      } finally {
        $lock->unlock();
      }
    }
    $complete = count($rows) < self::MAX_ROWS && count($items) === count($rows);
    if ($complete) { $cursor = $upper; }
    return array(
      'sourceScanVersion' => 1, 'namespace' => PhabricatorEnv::getEnvConfig('storage.default-namespace'),
      'serializerVersion' => PhabricatorSearchDocumentSerializer::VERSION,
      'className' => $class, 'afterID' => $after, 'upperID' => $upper,
      'nextID' => $cursor, 'complete' => $complete, 'items' => $items,
      'materialized' => true, 'sourceCoverageVerified' => false);
  }
}
