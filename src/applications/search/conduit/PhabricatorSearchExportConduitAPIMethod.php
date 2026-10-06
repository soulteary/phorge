<?php

/** Read-only projection export. A missing object is NOT a deletion receipt. */
final class PhabricatorSearchExportConduitAPIMethod extends ConduitAPIMethod {

  const MAX_DOCUMENTS = 50;
  const MAX_BYTES = 2097152;

  public function getAPIMethodName() { return 'search.export'; }
  public function getMethodDescription() {
    return pht('Build a batch of fulltext projections without publishing them.');
  }
  public function shouldRequireAuthentication() { return false; }
  protected function defineParamTypes() {
    return array('phids' => 'required list<phid>');
  }
  protected function defineReturnType() { return 'map<string, wild>'; }
  protected function defineErrorTypes() {
    return array(
      'ERR-SEARCH-EXPORT-AUTH' => pht('A configured service token is required.'),
      'ERR-SEARCH-EXPORT-BATCH' => pht('Invalid projection export batch.'));
  }

  public static function assertServiceToken($presented) {
    $expected = PhabricatorGorgeServiceRegistry::getService('conduit')
      ->getConfiguredToken();
    if (!phutil_nonempty_string($expected) ||
        !phutil_nonempty_string($presented) ||
        !hash_equals($expected, $presented)) {
      throw new ConduitException('ERR-SEARCH-EXPORT-AUTH');
    }
  }

  public static function validatePHIDs(array $phids) {
    if (!$phids || count($phids) > self::MAX_DOCUMENTS) {
      throw new ConduitException('ERR-SEARCH-EXPORT-BATCH');
    }
    $seen = array();
    foreach ($phids as $phid) {
      if (!is_string($phid) || strlen($phid) > 64 ||
          !preg_match('/\APHID-[A-Z0-9]{4}-[a-zA-Z0-9]+\z/', $phid) ||
          isset($seen[$phid])) {
        throw new ConduitException('ERR-SEARCH-EXPORT-BATCH');
      }
      $seen[$phid] = true;
    }
  }

  protected function execute(ConduitAPIRequest $request) {
    self::assertServiceToken(AphrontRequest::getHTTPHeader('X-Service-Token'));
    $phids = $request->getValue('phids');
    self::validatePHIDs($phids);
    $objects = id(new PhabricatorObjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs($phids)->execute();
    $objects = mpull($objects, null, 'getPHID');
    $results = array();
    $bytes = 0;
    foreach ($phids as $phid) {
      $item = array('phid' => $phid);
      $object = idx($objects, $phid);
      if (!$object) {
        $item['status'] = 'missing';
      } else if (!($object instanceof PhabricatorFulltextInterface)) {
        $item['status'] = 'unsupported';
      } else {
        try {
          $engine = $object->newFulltextEngine();
          if (!$engine) {
            $item['status'] = 'unsupported';
          } else {
            $document = $engine->setObject($object)->buildFulltextDocument();
            $spec = PhabricatorSearchDocumentSerializer::newDocumentSpec($document);
            $size = strlen(phutil_json_encode($spec));
            if ($size > self::MAX_BYTES || $bytes + $size > self::MAX_BYTES) {
              $item['status'] = 'too-large';
            } else {
              $bytes += $size;
              $item['status'] = 'exported';
              $item['document'] = $spec;
            }
          }
        } catch (Throwable $ex) {
          // Never convert an export failure to a delete or expose object content.
          phlog($ex);
          $item['status'] = 'retry';
        }
      }
      $results[] = $item;
    }
    return array(
      'exportVersion' => 1,
      'serializerVersion' => PhabricatorSearchDocumentSerializer::VERSION,
      'materialized' => false,
      'results' => $results);
  }
}
