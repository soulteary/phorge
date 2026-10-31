<?php

final class PhabricatorPolicyPresentationTestCase extends PhabricatorTestCase {

  public function testActionsAndSelectValuesAreLocalizedWithoutMutation() {
    $rules = array();
    foreach (array('full', 'new', 'waxing', 'waning') as $index => $value) {
      $rules[] = array(
        'action' => $index % 2 ? 'deny' : 'allow',
        'rule' => PhabricatorLunarPhasePolicyRule::class,
        'value' => $value,
      );
    }
    $policy = id(new PhabricatorPolicy())
      ->setRules($rules)
      ->setDefaultAction('deny');

    foreach (array(
      'en_US' => array('Allow', 'Deny', 'is full', 'is new', 'is waxing',
        'is waning'),
      'zh_CN' => array('允许', '拒绝', '满月', '新月', '月盈', '月亏'),
    ) as $code => $labels) {
      $locale = PhabricatorEnv::beginScopedLocale($code);
      try {
        $html = $this->renderPolicy($policy);
        foreach ($labels as $label) {
          $this->assertTrue(strpos($html, $label) !== false, $label);
        }
        if ($code === 'zh_CN') {
          foreach (array('Allow', 'Deny', 'waxing', 'waning') as $raw) {
            $this->assertFalse(strpos($html, $raw) !== false, $raw);
          }
        }
        $this->assertEqual($rules, $policy->getRules());
        $this->assertEqual('deny', $policy->getDefaultAction());
      } finally {
        unset($locale);
      }
    }
  }

  public function testUnknownSelectValueAndActionAreEscapedAndPreserved() {
    $value = '<unknown-phase>&"';
    $action = '<unknown-action>';
    $policy = id(new PhabricatorPolicy())
      ->setRules(array(array(
        'action' => $action,
        'rule' => PhabricatorLunarPhasePolicyRule::class,
        'value' => $value,
      )))
      ->setDefaultAction($action);
    $html = $this->renderPolicy($policy);
    $this->assertTrue(strpos($html, '&lt;unknown-phase&gt;') !== false);
    $this->assertTrue(strpos($html, '&lt;unknown-action&gt;') !== false);
    $this->assertFalse(strpos($html, '<unknown-phase>') !== false);
    $this->assertFalse(strpos($html, '<unknown-action>') !== false);
    $this->assertEqual($value, $policy->getRules()[0]['value']);
    $this->assertEqual($action, $policy->getDefaultAction());
  }

  public function testEditorProfilesAndExplicitOverride() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('policy.editor.hidden-rules', null);
    $env->overrideEnvConfig('phorge.product-profile', 'collaboration');
    $this->assertEqual(
      array(PhabricatorLunarPhasePolicyRule::class,
        PhabricatorLegalpadSignaturePolicyRule::class),
      PhabricatorPolicyEditorRuleSet::getHiddenRuleClasses());
    $env->overrideEnvConfig('phorge.product-profile', 'full');
    $this->assertEqual(
      array(), PhabricatorPolicyEditorRuleSet::getHiddenRuleClasses());
    $env->overrideEnvConfig('phorge.product-profile', 'collaboration');
    $env->overrideEnvConfig('policy.editor.hidden-rules', array());
    $this->assertEqual(
      array(), PhabricatorPolicyEditorRuleSet::getHiddenRuleClasses());
    $env->overrideEnvConfig('policy.editor.hidden-rules', array(
      PhabricatorLunarPhasePolicyRule::class,
      PhabricatorLunarPhasePolicyRule::class,
    ));
    $this->assertEqual(
      array(PhabricatorLunarPhasePolicyRule::class),
      PhabricatorPolicyEditorRuleSet::getHiddenRuleClasses());
    unset($env);
  }

  public function testInvalidEditorRuleConfigurationIsRejected() {
    $env = PhabricatorEnv::beginScopedEnv();
    foreach (array('not-a-list', array('Class\nInjected'), array(7)) as $bad) {
      $env->overrideEnvConfig('policy.editor.hidden-rules', $bad);
      $caught = false;
      try {
        PhabricatorPolicyEditorRuleSet::getHiddenRuleClasses();
      } catch (Exception $ex) {
        $caught = true;
      }
      $this->assertTrue($caught);
    }
    unset($env);
  }

  public function testHidingRuleDoesNotChangeItsEvaluationOrStoredValue() {
    $env = PhabricatorEnv::beginScopedEnv();
    $time = PhabricatorTime::pushTime(934354800, 'UTC');
    $rule = new PhabricatorLunarPhasePolicyRule();
    $viewer = new PhabricatorUser();
    $object = new PhabricatorProject();
    try {
      foreach (array(null, array()) as $hidden) {
        $env->overrideEnvConfig('phorge.product-profile', 'collaboration');
        $env->overrideEnvConfig('policy.editor.hidden-rules', $hidden);
        $this->assertTrue($rule->applyRule($viewer, 'new', $object));
        $this->assertFalse($rule->applyRule($viewer, 'full', $object));
        $this->assertFalse($rule->applyRule($viewer, 'unknown', $object));
      }
    } finally {
      unset($time);
      unset($env);
    }
  }

  public function testNewPolicyMessagesHaveChineseAndEnglishForms() {
    $messages = array(
      'Historical Policy (Read Only)' => '历史策略（只读）',
      'Create Replacement Policy' => '新建替代策略',
      'Unavailable Policy Rule' => '不可选的策略规则',
      'Historical policies can not be edited directly. '.
        'This submission was not saved.' =>
        '历史策略不可直接修改，本次提交未保存。',
    );
    foreach (array('en_US', 'zh_CN') as $code) {
      $locale = PhabricatorEnv::beginScopedLocale($code);
      try {
        foreach ($messages as $english => $chinese) {
          $this->assertEqual(
            $code === 'zh_CN' ? $chinese : $english,
            pht($english));
        }
      } finally {
        unset($locale);
      }
    }
  }

  private function renderPolicy(PhabricatorPolicy $policy) {
    return (string)hsprintf('%s', id(new PhabricatorPolicyRulesView())
      ->setViewer(new PhabricatorUser())
      ->setPolicy($policy));
  }

}
