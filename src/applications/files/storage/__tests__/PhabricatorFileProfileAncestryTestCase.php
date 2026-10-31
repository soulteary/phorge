<?php

final class PhabricatorFileProfileAncestryTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testPrivateProfileSourceIsRequiredForEveryViewer() {
    $author = $this->generateNewTestUser();
    $other = $this->generateNewTestUser();
    $source = $this->newFile($author);
    $profile = $this->newFile($other, true);
    $this->link($source->getPHID(), $profile);

    foreach (array($author, $other, new PhabricatorUser()) as $viewer) {
      $expected = ($viewer->getPHID() === $author->getPHID());
      $this->assertEqual($expected, $this->canQuery($viewer, $source));
      // Owning a transform must not bypass the original's visibility.
      $this->assertEqual($expected, $this->canQuery($viewer, $profile));
    }
  }

  public function testMultiSourceVisibilityDoesNotDependOnRowOrder() {
    $a = $this->generateNewTestUser();
    $b = $this->generateNewTestUser();
    $other = $this->generateNewTestUser();
    $first = $this->newFile($a);
    $second = $this->newFile($b);
    foreach (array(false, true) as $profile) {
      foreach (array(array($first, $second), array($second, $first)) as $sources) {
        $derived = $this->newFile($other, $profile);
        foreach ($sources as $source) {
          $this->link($source->getPHID(), $derived);
        }
        $this->assertTrue($this->canQuery($a, $derived));
        $this->assertTrue($this->canQuery($b, $derived));
        $this->assertFalse($this->canQuery($other, $derived));
        $this->assertFalse($this->canQuery(new PhabricatorUser(), $derived));
      }
    }
  }

  public function testVoidDoesNotMaskPrivateRealSources() {
    $author = $this->generateNewTestUser();
    $other = $this->generateNewTestUser();
    $source = $this->newFile($author);
    foreach (array(false, true) as $void_first) {
      $derived = $this->newFile($other, true);
      $sources = array($source->getPHID(), PhabricatorPHIDConstants::PHID_VOID);
      if ($void_first) {
        $sources = array_reverse($sources);
      }
      foreach ($sources as $phid) {
        $this->link($phid, $derived);
      }
      $this->assertTrue($this->canQuery($author, $derived));
      $this->assertFalse($this->canQuery($other, $derived));
      $this->assertFalse($this->canQuery(new PhabricatorUser(), $derived));
    }
  }

  public function testStandaloneProfilesAndBuiltinSentinelRemainPublic() {
    $scope = PhabricatorEnv::beginScopedEnv();
    $scope->overrideEnvConfig('policy.allow-public', true);
    $author = $this->generateNewTestUser();
    $profile = $this->newFile($author, true);
    $anonymous = new PhabricatorUser();
    $this->assertTrue($this->canQuery($anonymous, $profile));
    $this->link(PhabricatorPHIDConstants::PHID_VOID, $profile);
    $this->assertTrue($this->canQuery($anonymous, $profile));

    $builtin = PhabricatorFile::newFromFileData('builtin fixture', array(
      'name' => 'builtin-profile',
      'builtin' => 'builtin-profile',
      'authorPHID' => $author->getPHID(),
      'viewPolicy' => PhabricatorPolicies::POLICY_NOONE,
      'storageEngines' => array(new PhabricatorTestStorageEngine()),
    ));
    $this->assertTrue($this->canQuery($anonymous, $builtin));
  }

  public function testBuiltinLookingNameDoesNotBypassRealSource() {
    $author = $this->generateNewTestUser();
    $source = $this->newFile($author);
    $derived = $this->newFile($author, true);
    $derived->setName('builtin/profile.png')->save();
    $this->link($source->getPHID(), $derived);
    $this->assertFalse($derived->isBuiltin());
    $this->assertFalse($this->canQuery(new PhabricatorUser(), $derived));
  }

  public function testPublicRealSourceIsAVisibleWitnessUntilRevoked() {
    $scope = PhabricatorEnv::beginScopedEnv();
    $scope->overrideEnvConfig('policy.allow-public', true);
    $author = $this->generateNewTestUser();
    $source = $this->newFile($author);
    $source->setViewPolicy(PhabricatorPolicies::POLICY_PUBLIC)->save();
    $derived = $this->newFile($author, true);
    $this->link(PhabricatorPHID::generateNewPHID('FILE'), $derived);
    $this->link(PhabricatorPHIDConstants::PHID_VOID, $derived);
    $this->link($source->getPHID(), $derived);
    $this->assertTrue($this->canQuery(new PhabricatorUser(), $derived));
    $source->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
    $this->assertFalse($this->canQuery(new PhabricatorUser(), $derived));
  }

  public function testDeletedSourceRevokesPreviouslyVisibleProfile() {
    $author = $this->generateNewTestUser();
    $source = $this->newFile($author);
    $derived = $this->newFile($author, true);
    $this->link($source->getPHID(), $derived);
    $this->assertTrue($this->canQuery($author, $derived));
    $source->delete();
    $this->assertFalse($this->canQuery($author, $derived));
  }

  public function testDeepProfileChainUsesNativeQueryAncestry() {
    $author = $this->generateNewTestUser();
    $other = $this->generateNewTestUser();
    $source = $this->newFile($author);
    for ($depth = 0; $depth < 20; $depth++) {
      $derived = $this->newFile($author, true);
      $this->link($source->getPHID(), $derived);
      $source = $derived;
    }
    $this->assertTrue($this->canQuery($author, $source));
    $this->assertFalse($this->canQuery($other, $source));
  }

  public function testMissingAndCyclicSourcesAreRejected() {
    $author = $this->generateNewTestUser();
    $missing = $this->newFile($author, true);
    $this->link(PhabricatorPHID::generateNewPHID('FILE'), $missing);
    $this->assertFalse($this->canQuery($author, $missing));

    $a = $this->newFile($author, true);
    $b = $this->newFile($author, true);
    $this->link($a->getPHID(), $b);
    $this->link($b->getPHID(), $a);
    foreach (array($author, new PhabricatorUser()) as $viewer) {
      $this->assertFalse($this->canQuery($viewer, $a));
      $this->assertFalse($this->canQuery($viewer, $b));
      foreach (array(PhabricatorFileQuery::class, PhabricatorObjectQuery::class)
        as $query_class) {
        $results = id(new $query_class())->setViewer($viewer)
          ->withPHIDs(array($a->getPHID(), $b->getPHID()))
          ->setRaisePolicyExceptions(false)->execute();
        $this->assertEqual(array(), $results);
      }
    }
  }

  public function testInvalidSourceTypeDoesNotBecomeAFileGrant() {
    $author = $this->generateNewTestUser();
    $profile = $this->newFile($author, true);
    $this->link($author->getPHID(), $profile);
    $this->assertFalse($this->canQuery($author, $profile));
  }

  public function testBatchQueryKeepsSourceDecisionsSeparate() {
    $a = $this->generateNewTestUser();
    $b = $this->generateNewTestUser();
    $source_a = $this->newFile($a);
    $source_b = $this->newFile($b);
    $profile_a = $this->newFile($b, true);
    $profile_b = $this->newFile($a, true);
    $this->link($source_a->getPHID(), $profile_a);
    $this->link($source_b->getPHID(), $profile_b);
    $results = id(new PhabricatorFileQuery())
      ->setViewer($a)
      ->withPHIDs(array($profile_a->getPHID(), $profile_b->getPHID()))
      ->setRaisePolicyExceptions(false)
      ->execute();
    $this->assertEqual(array($profile_a->getPHID()),
      array_values(mpull($results, 'getPHID')));
  }

  public function testBatchChainMatchesIndividualVisibility() {
    $author = $this->generateNewTestUser();
    $other = $this->generateNewTestUser();
    $source = $this->newFile($author);
    $chain = array($source->getPHID());
    for ($depth = 0; $depth < 20; $depth++) {
      $derived = $this->newFile($author, true);
      $this->link($source->getPHID(), $derived);
      $chain[] = $derived->getPHID();
      $source = $derived;
    }
    $this->assertTrue($this->canQuery($author, $source));
    // Ordinary files remain visible in the same batch as derived descendants.
    for ($index = 0; $index < 3; $index++) {
      $chain[] = $this->newFile($author)->getPHID();
    }
    foreach (array(PhabricatorFileQuery::class, PhabricatorObjectQuery::class)
      as $query_class) {
      foreach (array($chain, array_reverse($chain)) as $phids) {
        foreach (array($author, $other, new PhabricatorUser()) as $viewer) {
          $results = id(new $query_class())
            ->setViewer($viewer)->withPHIDs($phids)
            ->setRaisePolicyExceptions(false)->execute();
          $actual = array_values(mpull($results, 'getPHID'));
          sort($actual);
          $expected = ($viewer === $author) ? $chain : array();
          sort($expected);
          $this->assertEqual($expected, $actual,
            $query_class.' must agree with individual ancestry checks.');
        }
      }
    }
  }

  private function newFile(PhabricatorUser $author, $profile = false) {
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

  private function link($source_phid, PhabricatorFile $derived) {
    id(new PhabricatorTransformedFile())
      ->setOriginalPHID($source_phid)
      ->setTransformedPHID($derived->getPHID())
      ->setTransform('ancestry-'.Filesystem::readRandomCharacters(16))
      ->save();
  }

  private function canQuery(PhabricatorUser $viewer, PhabricatorFile $file) {
    return (bool)id(new PhabricatorFileQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($file->getPHID()))
      ->setRaisePolicyExceptions(false)
      ->execute();
  }
}
