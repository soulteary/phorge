<?php

final class PhorgeGovernanceNativeRuleHierarchyTestCase extends PhutilTestCase {

  public function testExactNativeRuleValues() {
    $user = 'PHID-USER-'.str_repeat('a', 20);
    $project = 'PHID-PROJ-'.str_repeat('b', 20);
    foreach (array(PhabricatorProjectsPolicyRule::class,
      PhabricatorProjectsAllPolicyRule::class) as $class) {
      $this->assertTrue(
        PhorgeGovernanceNativeRuleHierarchyVerifier::validateRule($class, array()));
      $this->assertTrue(
        PhorgeGovernanceNativeRuleHierarchyVerifier::validateRule($class, array($project)));
      $this->assertFalse(
        PhorgeGovernanceNativeRuleHierarchyVerifier::validateRule($class, array($user)));
    }
    $this->assertTrue(PhorgeGovernanceNativeRuleHierarchyVerifier::validateRule(
      PhabricatorUsersPolicyRule::class, array($user)));
    $this->assertTrue(PhorgeGovernanceNativeRuleHierarchyVerifier::validateRule(
      PhabricatorProjectMembersPolicyRule::class, array()));
    $this->assertFalse(PhorgeGovernanceNativeRuleHierarchyVerifier::validateRule(
      PhabricatorProjectMembersPolicyRule::class, array($project)));
  }

  public function testUnknownMalformedAndUnboundedRuleValuesBlock() {
    $class = PhabricatorUsersPolicyRule::class;
    $phid = 'PHID-USER-'.str_repeat('a', 20);
    foreach (array(array($phid, $phid), array('x' => $phid), array(null),
      array(new stdClass()), array('PHID-USER-invalid')) as $value) {
      $this->assertFalse(
        PhorgeGovernanceNativeRuleHierarchyVerifier::validateRule($class, $value));
    }
    $large = array();
    for ($i = 0; $i < 17; $i++) {
      $large[] = 'PHID-USER-'.str_pad((string)$i, 20, 'a');
    }
    $this->assertFalse(
      PhorgeGovernanceNativeRuleHierarchyVerifier::validateRule($class, $large));
    foreach (array('stdClass', null, 1, PhabricatorPolicyRule::class) as $unknown) {
      $this->assertFalse(
        PhorgeGovernanceNativeRuleHierarchyVerifier::validateRule($unknown, array()));
    }
  }

  public function testUnknownRuleCannotProducePermissionOrTouchContext() {
    $result = PhorgeGovernanceNativeRuleHierarchyVerifier::observe(
      array(), new PhabricatorUser(), new PhabricatorProject(),
      'stdClass', array());
    $this->assertEqual('unsupported_native_rule_or_value', $result['reason']);
    $this->assertEqual('unknown', $result['native_rule_result']);
    foreach (array('can_execute', 'native_closure_complete', 'risk_complete',
      'execution_atomicity_proved', 'source_and_native_observation_atomic') as $key) {
      $this->assertFalse($result[$key]);
    }
  }
  public function testNativeParentAttachmentStructuralInvariants() {
    $root = (new PhabricatorProject())->setPHID('PHID-PROJ-'.str_repeat('a', 20))
      ->setParentProjectPHID(null)->setProjectPathKey('aaaa')
      ->setProjectPath('aaaa')->setProjectDepth(0)->attachParentProject(null);
    $child = (new PhabricatorProject())->setPHID('PHID-PROJ-'.str_repeat('b', 20))
      ->setParentProjectPHID($root->getPHID())->setProjectPathKey('bbbb')
      ->setProjectPath('aaaabbbb')->setProjectDepth(1)->attachParentProject($root);
    $check = new ReflectionMethod(
      PhorgeGovernanceNativeRuleHierarchyVerifier::class,
      'hasConsistentNativeParent');
    $this->assertTrue($check->invoke(null, $root));
    $this->assertTrue($check->invoke(null, $child));
    $native_hydrated = clone $child;
    $native_hydrated->setProjectDepth('1');
    $this->assertTrue($check->invoke(null, $native_hydrated));
    foreach (array('01', '1.0', true, null, 'garbage') as $bad_depth) {
      $bad = clone $child;
      $bad->setProjectDepth($bad_depth);
      $this->assertFalse($check->invoke(null, $bad));
    }
    $missing = clone $child;
    $missing->attachParentProject(null);
    $this->assertFalse($check->invoke(null, $missing));
    foreach (array('path', 'depth', 'key', 'parent') as $axis) {
      $bad = clone $child;
      switch ($axis) {
        case 'path': $bad->setProjectPath('xxxxbbbb'); break;
        case 'depth': $bad->setProjectDepth(0); break;
        case 'key': $bad->setProjectPathKey('xxxx'); break;
        case 'parent': $bad->setParentProjectPHID($bad->getPHID()); break;
      }
      $this->assertFalse($check->invoke(null, $bad));
    }
  }

}
