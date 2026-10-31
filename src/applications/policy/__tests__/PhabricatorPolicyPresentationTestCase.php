<?php

final class PhabricatorPolicyPresentationTestCase extends PhabricatorTestCase {

  public function testDetailsTranslateActionsAndSelectValues() {
    $locale = PhabricatorEnv::beginScopedLocale('zh_CN');
    $rules = array();
    foreach (array('full', 'new', 'waxing', 'waning') as $index => $value) {
      $rules[] = array('action' => $index % 2 ? 'deny' : 'allow',
        'rule' => 'PhabricatorLunarPhasePolicyRule', 'value' => $value);
    }
    $policy = id(new PhabricatorPolicy())->setRules($rules)
      ->setDefaultAction('deny');
    $html = $this->renderPolicy($policy);
    foreach (array('满月', '新月', '月盈', '月亏', '允许', '拒绝') as $label) {
      $this->assertTrue(strpos($html, $label) !== false, $label);
    }
    foreach (array('当月亮', 'Allow', 'Deny', 'waxing', 'waning') as $raw) {
      $this->assertFalse(strpos($html, $raw) !== false, $raw);
    }
    $this->assertEqual($rules, $policy->getRules());
  }

  public function testUnknownSelectValueIsEscapedAndPreserved() {
    $policy = id(new PhabricatorPolicy())->setRules(array(array(
      'action' => 'allow', 'rule' => 'PhabricatorLunarPhasePolicyRule',
      'value' => '<unknown-phase>')))->setDefaultAction('deny');
    $html = $this->renderPolicy($policy);
    $this->assertTrue(strpos($html, '&lt;unknown-phase&gt;') !== false);
    $this->assertFalse(strpos($html, '<unknown-phase>') !== false);
    $this->assertEqual('<unknown-phase>', $policy->getRules()[0]['value']);
  }

  public function testHiddenRulesDoNotChangeStoredPolicyEvaluation() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phorge.product-profile', 'collaboration');
    $env->overrideEnvConfig('policy.editor.hidden-rules', null);
    $policy = id(new PhabricatorPolicy())->setRules(array(array(
      'action' => 'allow', 'rule' => 'PhabricatorLunarPhasePolicyRule',
      'value' => 'new')))->setDefaultAction('deny');
    $method = new ReflectionMethod(
      'PhabricatorPolicyEditController', 'hasRetiredRules');
    $method->setAccessible(true);
    $this->assertTrue($method->invoke(null, $policy));
    $rule = new PhabricatorLunarPhasePolicyRule();
    $time = PhabricatorTime::pushTime(934354800, 'UTC');
    $this->assertTrue($rule->applyRule(
      new PhabricatorUser(), 'new', new PhabricatorProject()));
    $this->assertFalse($rule->applyRule(
      new PhabricatorUser(), 'full', new PhabricatorProject()));
    $env->overrideEnvConfig('policy.editor.hidden-rules', array());
    $this->assertFalse($method->invoke(null, $policy));
    $this->assertEqual('new', $policy->getRules()[0]['value']);
  }

  public function testPrivateAuthorPoliciesRejectInactiveAndUnapprovedPeople() {
    $author = id(new PhabricatorUser())->setPHID('PHID-USER-author')
      ->setIsApproved(1);
    $other = id(new PhabricatorUser())->setPHID('PHID-USER-other')
      ->setIsApproved(1);
    $admin = clone $other;
    $admin->setIsAdmin(1);
    $disabled = clone $author;
    $disabled->setIsDisabled(1);
    $unapproved = clone $author;
    $unapproved->setIsApproved(0);
    foreach (array(
      array(new TeamPrivateProjectPolicyRule(), new PhabricatorProject()),
      array(new TeamPrivateTaskPolicyRule(), new ManiphestTask()),
    ) as $case) {
      list($rule, $object) = $case;
      $object->setAuthorPHID($author->getPHID());
      foreach (array(array($author, true), array($admin, true),
        array($other, false), array($disabled, false),
        array($unapproved, false), array(new PhabricatorUser(), false)) as $row) {
        $this->assertEqual($row[1], $rule->applyRule($row[0], null, $object));
      }
    }
  }

  private function renderPolicy(PhabricatorPolicy $policy) {
    return (string)hsprintf('%s', id(new PhabricatorPolicyRulesView())
      ->setViewer(new PhabricatorUser())->setPolicy($policy));
  }
}
