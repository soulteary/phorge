<?php

final class LiskDAOTestCase extends PhabricatorTestCase {

  public function testCheckProperty() {
    $scratch = new HarbormasterScratchTable();
    $scratch->getData();

    $this->assertException('Exception', array($this, 'getData'));
  }

  public function getData() {
    $isolation = new LiskIsolationTestDAO();
    $isolation->getData();
  }

  public function testGorgeStorageSchema() {
    $spec = new PhabricatorStorageSchemaSpec();
    $method = new ReflectionMethod(
      PhabricatorConfigSchemaSpec::class, 'getDetailsForDataType');
    $objects = array(
      new PhabricatorFeedGorgeOutbox(),
      new PhabricatorMetaMTAGorgeOutbox(),
      new PhabricatorMetaMTAGorgeAttempt(),
      new PhabricatorMetaMTAGorgeDelivery(),
      new PhabricatorWorkerGorgeInbox(),
    );
    foreach ($objects as $object) {
      $columns = $object->getSchemaColumns();
      $identifier = isset($columns['eventID']) ? 'eventID' : 'deliveryID';
      $details = $method->invoke($spec, $columns[$identifier]);
      $this->assertEqual('varbinary(128)', $details['type']);
      $this->assertEqual(false, $details['nullable']);
      if (isset($columns['id'])) {
        $details = $method->invoke($spec, $columns['id']);
        $this->assertEqual('bigint(20) unsigned', $details['type']);
        $this->assertEqual(true, $details['auto']);
      }
    }
    $delivery = new PhabricatorMetaMTAGorgeDelivery();
    $columns = $delivery->getSchemaColumns();
    $details = $method->invoke($spec, $columns['projectionPending']);
    $this->assertEqual('tinyint(1)', $details['type']);
    $this->assertEqual(
      true,
      (new PhabricatorGlobalLockDAO())->getConfigOption(LiskDAO::CONFIG_NO_TABLE));
  }

}
