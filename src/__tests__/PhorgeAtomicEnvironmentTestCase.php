<?php

/** Verify actual storage dependencies before calling permission tests passed. */
final class PhorgeAtomicEnvironmentTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true);
  }

  public function testGorgeIsTheWritableFileEngine() {
    $this->assertEqual('1', getenv('ATOMIC_TEST_DISPOSABLE'));
    $engines = PhabricatorFileStorageEngine::loadWritableEngines();
    $this->assertEqual(array('gorge'),
      array_values(mpull($engines, 'getEngineIdentifier')));
    $bytes = 'atomic fixture '.bin2hex(Filesystem::readRandomBytes(16));
    $file = PhabricatorFile::newFromFileData($bytes,
      array('name' => 'atomic-roundtrip.txt'));
    try {
      $this->assertEqual('gorge', $file->getStorageEngine());
      $this->assertEqual($bytes, $file->loadFileData());
    } finally {
      $file->delete();
    }
  }

  public function testUserAvatarAndImageServiceAreUsable() {
    $user = $this->generateNewTestUser();
    $loaded = id(new PhabricatorPeopleQuery())->setViewer($user)
      ->withPHIDs(array($user->getPHID()))->needProfileImage(true)->executeOne();
    $this->assertTrue((bool)$loaded);
    $this->assertTrue(strlen((string)$loaded->getProfileImageURI()) > 0);
    $root = dirname(phutil_get_library_root('phabricator'));
    $image = Filesystem::readFile($root.'/resources/builtin/avatar.png');
    $info = id(new PhabricatorGorgeImageClient())->probe($image);
    $this->assertTrue($info['width'] > 0 && $info['height'] > 0);
    $files = id(new PhabricatorFileQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())->execute();
    $this->assertTrue((bool)$files, 'The avatar did not create a stored file.');
    foreach ($files as $file) {
      $this->assertEqual('gorge', $file->getStorageEngine());
    }
  }

  public function testStorageUpgradeCanBeRepeated() {
    $namespace = PhabricatorLiskDAO::getStorageNamespace();
    $this->assertTrue(strpos($namespace, self::NAMESPACE_PREFIX) === 0);
    $root = dirname(phutil_get_library_root('phabricator'));
    list($status) = exec_manual('php %s upgrade --force --namespace %s',
      $root.'/bin/storage', $namespace);
    $this->assertTrue($status === 0 || $status === 2);
    $apis = array();
    try {
      foreach (PhabricatorDatabaseRef::getMasterDatabaseRefs() as $ref) {
        $apis[] = id(new PhabricatorStorageManagementAPI())->setRef($ref)
          ->setUser($ref->getUser())->setHost($ref->getHost())
          ->setPort($ref->getPort())->setPassword($ref->getPass())
          ->setNamespace($namespace);
      }
      $query = id(new PhabricatorConfigSchemaQuery())->setAPIs($apis);
      $actual = $query->loadActualSchemata();
      $expected = $query->loadExpectedSchemata();
      foreach ($query->buildComparisonSchemata($expected, $actual) as $schema) {
        foreach ($schema->getAllIssues() as $issue) {
          // Retired quickstart tables remain deliberately surplus. No missing
          // column, bad type, migration error or other adjustment is accepted.
          $this->assertTrue(in_array($issue, array(
            PhabricatorConfigStorageSchema::ISSUE_SURPLUS,
            PhabricatorConfigStorageSchema::ISSUE_SURPLUSKEY,
            PhabricatorConfigStorageSchema::ISSUE_SUBWARN,
            PhabricatorConfigStorageSchema::ISSUE_SUBFAIL,
          ), true), 'Unexpected native schema adjustment: '.$issue);
        }
      }
      $this->assertTrue(true);
    } finally {
      foreach ($apis as $api) { PhabricatorLiskDAO::popStorageNamespace(); }
    }
  }
}
