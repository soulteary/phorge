<?php

final class PhabricatorHandleQueryTestCase extends PhabricatorTestCase {

  public function testAnonymousPHIDsAreIgnored() {
    $handles = id(new PhabricatorHandleQuery())
      ->setViewer(new PhabricatorUser())
      ->withPHIDs(array(null, '', null))
      ->execute();
    $this->assertEqual(array(), $handles);
  }

}
