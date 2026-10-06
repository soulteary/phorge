<?php

final class PhabricatorObjectListQueryTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testObjectListQuery() {
    $user = $this->generateNewTestUser();
    $name = $user->getUsername();
    $phid = $user->getPHID();

    $result = $this->parseObjectList("@{$name}");
    $this->assertEqual(array($phid), $result);

    $result = $this->parseObjectList("{$name}");
    $this->assertEqual(array($phid), $result);

    $result = $this->parseObjectList("{$name}, {$name}");
    $this->assertEqual(array($phid), $result);

    $result = $this->parseObjectList("@{$name}, {$name}");
    $this->assertEqual(array($phid), $result);

    $result = $this->parseObjectList('');
    $this->assertEqual(array(), $result);

    $result = $this->parseObjectList("{$name}!", array(), false, array('!'));
    $this->assertEqual(
      array(
        array(
          'phid' => $phid,
          'suffixes' => array('!' => '!'),
        ),
      ),
      $result);

    // Any monogram-addressable object exercises the "ignore text after the
    // monogram" parsing rule; this used to use an Owners package.
    $object = PhabricatorCountdown::initializeNewCountdown($user)
      ->setTitle(pht('Query Test Countdown'))
      ->setDescription('')
      ->setEpoch(PhabricatorTime::getNow())
      ->setMailKey(Filesystem::readRandomCharacters(20))
      ->save();

    $object_phid = $object->getPHID();
    $object_mono = $object->getMonogram();

    $result = $this->parseObjectList("{$object_mono} Any Ignored Text");
    $this->assertEqual(array($object_phid), $result);

    $result = $this->parseObjectList("{$object_mono} Any Text, {$name}");
    $this->assertEqual(array($object_phid, $phid), $result);

    $result = $this->parseObjectList(
      "{$object_mono} Any Text!, {$name}",
      array(),
      false,
      array('!'));
    $this->assertEqual(
      array(
        array(
          'phid' => $object_phid,
          'suffixes' => array('!' => '!'),
        ),
        array(
          'phid' => $phid,
          'suffixes' => array(),
        ),
      ),
      $result);

    // Expect failure when loading a user if objects must be of type "DUCK".
    $caught = null;
    try {
      $result = $this->parseObjectList("{$name}", array('DUCK'));
    } catch (Exception $ex) {
      $caught = $ex;
    }
    $this->assertTrue($caught instanceof Exception);


    // Expect failure when loading an invalid object.
    $caught = null;
    try {
      $result = $this->parseObjectList('invalid');
    } catch (Exception $ex) {
      $caught = $ex;
    }
    $this->assertTrue($caught instanceof Exception);


    // Expect failure when loading ANY invalid object, by default.
    $caught = null;
    try {
      $result = $this->parseObjectList("{$name}, invalid");
    } catch (Exception $ex) {
      $caught = $ex;
    }
    $this->assertTrue($caught instanceof Exception);


    // With partial results, this should load the valid user.
    $result = $this->parseObjectList("{$name}, invalid", array(), true);
    $this->assertEqual(array($phid), $result);
  }

  private function parseObjectList(
    $list,
    array $types = array(),
    $allow_partial = false,
    $suffixes = array()) {

    $query = id(new PhabricatorObjectListQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->setObjectList($list);

    if ($types) {
      $query->setAllowedTypes($types);
    }

    if ($allow_partial) {
      $query->setAllowPartialResults(true);
    }

    if ($suffixes) {
      $query->setSuffixes($suffixes);
    }

    return $query->execute();
  }

}
