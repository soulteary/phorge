<?php

final class PhabricatorSearchProjectionTestCase extends PhabricatorTestCase {

  public function testBuildDoesNotRunLocalIndexes() {
    $events = new ArrayObject();
    $object = new class extends Phobject {
      public function getPHID() { return 'PHID-TASK-projection-test'; }
    };
    $extension = new class($events) extends PhabricatorFulltextEngineExtension {
      private $events;
      public function __construct($events) { $this->events = $events; }
      public function getExtensionName() { return 'Projection test'; }
      public function shouldEnrichFulltextObject($object) { return true; }
      public function shouldIndexFulltextObject($object) { return true; }
      public function enrichFulltextObject($object, $document) {
        $this->events[] = 'enrich';
        $document->addField('body', 'enriched');
      }
      public function indexFulltextObject($object, $document) {
        $this->events[] = 'local';
      }
    };
    $engine = new class($extension) extends PhabricatorFulltextEngine {
      private $extension;
      public function __construct($extension) { $this->extension = $extension; }
      protected function newFulltextExtensions() {
        return array($this->extension);
      }
      protected function buildAbstractDocument($document, $object) {
        $document->setDocumentTitle('Projection');
      }
    };
    $engine->setObject($object);
    $document = $engine->buildFulltextDocument();
    $this->assertEqual(array('enrich'), $events->getArrayCopy());
    $this->assertEqual('Projection', $document->getDocumentTitle());
    $engine->indexLocalFulltextDocument($document);
    $this->assertEqual(array('enrich', 'local'), $events->getArrayCopy());
    $document->setPHID('PHID-TASK-other');
    $caught = false;
    try { $engine->indexLocalFulltextDocument($document); }
    catch (InvalidArgumentException $ex) { $caught = true; }
    $this->assertTrue($caught);
    $this->assertEqual(array('enrich', 'local'), $events->getArrayCopy());
  }

  public function testForcedIndexPolicyReachesBuiltDocument() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('cluster.search', array());
    $fulltext = new class extends PhabricatorFulltextEngine {
      public $document;
      protected function newFulltextExtensions() { return array(); }
      protected function buildAbstractDocument($document, $object) {
        $document->setDocumentTitle('same content');
        $this->document = $document;
      }
    };
    $object = new class($fulltext) extends Phobject {
      private $engine;
      public function __construct($engine) { $this->engine = $engine; }
      public function getPHID() { return 'PHID-TASK-force'; }
      public function newFulltextEngine() { return $this->engine; }
    };
    $extension = new PhabricatorFulltextIndexEngineExtension();
    $extension->setParameters(array('force' => true));
    $extension->indexObject(new PhabricatorIndexEngine(), $object);
    $forced = $fulltext->document;
    $this->assertTrue($forced->getForceProjection());
    $extension->setParameters(array('force' => false));
    $extension->indexObject(new PhabricatorIndexEngine(), $object);
    $normal = $fulltext->document;
    $this->assertFalse($normal->getForceProjection());
    $this->assertEqual(
      PhabricatorSearchDocumentSerializer::newDocumentSpec($normal),
      PhabricatorSearchDocumentSerializer::newDocumentSpec($forced));
    $this->assertEqual(
      PhabricatorSearchDocumentSerializer::getDocumentHash($normal),
      PhabricatorSearchDocumentSerializer::getDocumentHash($forced));
  }

  public function testWireProjectionPreservesTuples() {
    $doc = id(new PhabricatorSearchAbstractDocument())
      ->setPHID('PHID-TASK-test')->setDocumentType('TASK')
      ->setDocumentTitle('中文标题')->setDocumentCreated(11)
      ->setDocumentModified(12)->addField('cmnt', 'first')
      ->addField('cmnt', 'second', 'PHID-XACT-test')
      ->addRelationship('auth', 'PHID-USER-test', 'USER', 0)
      ->addRelationship('clos', 'PHID-TASK-test', 'TASK', 13);
    $spec = PhabricatorSearchDocumentSerializer::newDocumentSpec($doc);
    $this->assertEqual(array(
      'phid' => 'PHID-TASK-test', 'type' => 'TASK', 'title' => '中文标题',
      'dateCreated' => 11, 'dateModified' => 12,
      'fields' => array(
        array('name' => 'titl', 'corpus' => '中文标题'),
        array('name' => 'cmnt', 'corpus' => 'first'),
        array('name' => 'cmnt', 'corpus' => 'second', 'aux' => 'PHID-XACT-test')),
      'relationships' => array(
        array('name' => 'auth', 'relatedPHID' => 'PHID-USER-test', 'rtype' => 'USER'),
        array('name' => 'clos', 'relatedPHID' => 'PHID-TASK-test',
          'rtype' => 'TASK', 'timestamp' => 13))), $spec);
    $this->assertEqual($spec,
      PhabricatorSearchDocumentSerializer::newDocumentSpec($doc));
  }
  public function testExportBatchAndAuthentication() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.conduit.token', 'export-test-only');
    PhabricatorSearchExportConduitAPIMethod::assertServiceToken('export-test-only');
    PhabricatorSearchExportConduitAPIMethod::validatePHIDs(array('PHID-TASK-valid'));
    foreach (array('', null, 'wrong') as $token) {
      $caught = false;
      try { PhabricatorSearchExportConduitAPIMethod::assertServiceToken($token); }
      catch (ConduitException $ex) { $caught = true; }
      $this->assertTrue($caught);
    }
    $env->overrideEnvConfig('gorge.conduit.token', null);
    $caught = false;
    try { PhabricatorSearchExportConduitAPIMethod::assertServiceToken('export-test-only'); }
    catch (ConduitException $ex) { $caught = true; }
    $this->assertTrue($caught);
    foreach (array(array(), array('invalid'), array('PHID-TASK-a', 'PHID-TASK-a'),
      array('PHID-TASK-../unsafe'), range(1, 51)) as $batch) {
      $caught = false;
      try { PhabricatorSearchExportConduitAPIMethod::validatePHIDs($batch); }
      catch (ConduitException $ex) { $caught = true; }
      $this->assertTrue($caught);
    }
  }

  public function testCanonicalDocumentHash() {
    $doc = id(new PhabricatorSearchAbstractDocument())
      ->setPHID('PHID-TASK-golden')->setDocumentType('TASK')
      ->setDocumentTitle('中文 <>&/')->setDocumentCreated(11)
      ->setDocumentModified(12)->addField('cmnt', 'first')
      ->addField('cmnt', 'second', 'PHID-XACT-golden')
      ->addRelationship('auth', 'PHID-USER-golden', 'USER', 0);
    $this->assertEqual('34b08b99566fb782a8542e593cf02b28d748b640ca7254988cde7b7aaf6ac552',
      PhabricatorSearchDocumentSerializer::getDocumentHash($doc));
    $without_aux = clone $doc;
    $doc->addField('body', 'empty aux', '');
    $without_aux->addField('body', 'empty aux');
    $this->assertEqual(
      PhabricatorSearchDocumentSerializer::getDocumentHash($without_aux),
      PhabricatorSearchDocumentSerializer::getDocumentHash($doc));
  }

  public function testDatabaseTimestampStringsNormalizeToIntegers() {
    $doc = id(new PhabricatorSearchAbstractDocument())
      ->setPHID('PHID-TASK-time')->setDocumentType('TASK')
      ->setDocumentTitle('timestamp')->setDocumentCreated('11')
      ->setDocumentModified('12')
      ->addRelationship('auth', 'PHID-USER-time', 'USER', '13');
    $spec = PhabricatorSearchDocumentSerializer::newDocumentSpec($doc);
    $this->assertEqual(13, $spec['relationships'][0]['timestamp']);
    $this->assertTrue(is_int($spec['relationships'][0]['timestamp']));
  }

}
