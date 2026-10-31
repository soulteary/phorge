<?php

final class PhabricatorFileProfileAncestryTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testProfileTransformRetainsPrivateSourceVisibility() {
    $author = $this->generateNewTestUser();
    $other = $this->generateNewTestUser();
    $source = $this->newFile($author, false);
    $derived = $this->newFile($author, true);
    id(new PhabricatorTransformedFile())
      ->setOriginalPHID($source->getPHID())
      ->setTransformedPHID($derived->getPHID())
      ->setTransform('private-profile-test')
      ->save();

    foreach (array($author, $other, new PhabricatorUser()) as $viewer) {
      $expected = ($viewer->getPHID() === $author->getPHID());
      $this->assertEqual($expected, $this->canQuery($viewer, $source));
      $this->assertEqual($expected, $this->canQuery($viewer, $derived));
    }
  }

  public function testStandaloneProfileAndVoidBuiltinSourceRemainPublic() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('policy.allow-public', true);
    $author = $this->generateNewTestUser();
    $profile = $this->newFile($author, true);
    $this->assertTrue($this->canQuery(new PhabricatorUser(), $profile));
    id(new PhabricatorTransformedFile())
      ->setOriginalPHID(PhabricatorPHIDConstants::PHID_VOID)
      ->setTransformedPHID($profile->getPHID())
      ->setTransform('builtin-profile-test')
      ->save();
    $this->assertTrue($this->canQuery(new PhabricatorUser(), $profile));
  }

  private function newFile(PhabricatorUser $author, $profile) {
    return PhabricatorFile::newFromFileData(
      Filesystem::readRandomCharacters(64), array(
        'name' => 'profile-ancestry-test',
        'authorPHID' => $author->getPHID(),
        'viewPolicy' => PhabricatorPolicies::POLICY_NOONE,
        'profile' => $profile,
        'canCDN' => false,
        'storageEngines' => array(new PhabricatorTestStorageEngine()),
      ));
  }

  private function canQuery(PhabricatorUser $viewer, PhabricatorFile $file) {
    return (bool)id(new PhabricatorFileQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($file->getPHID()))
      ->setRaisePolicyExceptions(false)
      ->execute();
  }
}
