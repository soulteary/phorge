<?php

final class PhabricatorPolicyEditorTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testHistoricalPostCannotSilentlyDropUnavailableRules() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phorge.product-profile', 'collaboration');
    $env->overrideEnvConfig('policy.editor.hidden-rules', null);
    $actor = $this->generateNewTestUser();
    $policy = $this->newHistoricalPolicy($actor);
    $before = $this->countPolicies();
    $rules = $policy->getRules();

    foreach (array('en_US', 'zh_CN') as $code) {
      $locale = PhabricatorEnv::beginScopedLocale($code);
      try {
        $response = $this->requestEditor($actor, $policy->getPHID(), array(
          'rules' => json_encode(array($rules[0])),
          'default' => 'allow',
        ));
        $this->assertTrue($response instanceof AphrontDialogResponse);
        $this->assertEqual(
          pht('Historical Policy (Read Only)'),
          $response->getDialog()->getTitle());
        $html = (string)hsprintf('%s', $response->getDialog());
        $this->assertTrue(strpos($html, '__start_policy_replacement__') !== false);
        $this->assertTrue(strpos($html, 'PhabricatorLunarPhasePolicyRule') === false);
        $this->assertEqual($before, $this->countPolicies());
        $this->assertEqual($rules, $policy->reload()->getRules());
        $this->assertEqual('deny', $policy->getDefaultAction());
      } finally {
        unset($locale);
      }
    }
    unset($env);
  }

  public function testHistoricalPolicyRemainsReadableWhenEveryChoiceIsHidden() {
    $actor = $this->generateNewTestUser();
    $policy = $this->newHistoricalPolicy($actor);
    $all = id(new PhutilClassMapQuery())
      ->setAncestorClass(PhabricatorPolicyRule::class)
      ->execute();
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('policy.editor.hidden-rules', array_keys($all));
    $before = $this->countPolicies();
    $response = $this->requestEditor($actor, $policy->getPHID());
    $this->assertEqual(
      pht('Historical Policy (Read Only)'), $response->getDialog()->getTitle());
    $this->assertEqual($before, $this->countPolicies());
    unset($env);
  }

  public function testInternalRulesAreReadOnlyEvenWithEmptyOverride() {
    $actor = $this->generateNewTestUser();
    $policy = id(new PhabricatorPolicy())
      ->setRules(array(array(
        'action' => 'allow',
        'rule' => PhorgeGlobalPolicyRule::class,
        'value' => PhabricatorPolicies::POLICY_USER,
      )))
      ->setDefaultAction('deny')
      ->save();
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('policy.editor.hidden-rules', array());
    $response = $this->requestEditor($actor, $policy->getPHID());
    $this->assertEqual(
      pht('Historical Policy (Read Only)'), $response->getDialog()->getTitle());
    unset($env);
  }

  public function testStartingOrCancellingReplacementCreatesNothing() {
    $actor = $this->generateNewTestUser();
    $before = $this->countPolicies();
    $response = $this->requestEditor(
      $actor,
      AphrontFormPolicyControl::getSelectCustomKey(),
      array('__start_policy_replacement__' => '1', 'capability' => 'view'));
    $this->assertTrue($response instanceof AphrontDialogResponse);
    $this->assertEqual(
      pht('Custom "%s" Policy', pht('Can View')),
      $response->getDialog()->getTitle());
    $html = (string)hsprintf('%s', $response->getDialog());
    $this->assertTrue(strpos($html, 'name="capability"') !== false);
    $this->assertEqual($before, $this->countPolicies());
  }

  public function testReplacementMarkerCannotBeUsedToSaveRules() {
    $actor = $this->generateNewTestUser();
    $before = $this->countPolicies();
    foreach (array(
      array('__start_policy_replacement__' => '1', 'rules' => '[]'),
      array('__start_policy_replacement__' => 'unexpected'),
    ) as $data) {
      $response = $this->requestEditor(
        $actor, AphrontFormPolicyControl::getSelectCustomKey(), $data);
      $this->assertEqual(
        pht('Unavailable Policy Rule'), $response->getDialog()->getTitle());
      $this->assertEqual($before, $this->countPolicies());
    }
  }

  public function testSavingReplacementDoesNotApplyItToOriginalObject() {
    $actor = $this->generateNewTestUser();
    $policy = $this->newHistoricalPolicy($actor);
    $project = PhabricatorProject::initializeNewProject($actor)
      ->setName('Policy replacement '.$this->getNextObjectSeed())
      ->setViewPolicy($policy->getPHID())
      ->setEditPolicy($actor->getPHID())
      ->save();
    $before = mpull(id(new PhabricatorPolicy())->loadAllWhere('1 = 1'),
      'getPHID', 'getPHID');
    $response = $this->requestEditor(
      $actor,
      AphrontFormPolicyControl::getSelectCustomKey(),
      array(
        'rules' => json_encode(array(array(
          'action' => 'allow',
          'rule' => PhabricatorUsersPolicyRule::class,
          'value' => array($actor->getPHID()),
        ))),
        'default' => 'deny',
      ));
    $this->assertTrue($response instanceof AphrontAjaxResponse);
    $after = mpull(id(new PhabricatorPolicy())->loadAllWhere('1 = 1'),
      'getPHID', 'getPHID');
    $added = array_values(array_diff($after, $before));
    $this->assertEqual(1, count($added));
    $this->assertEqual($policy->getPHID(), $project->reload()->getViewPolicy());

    id(new ConduitCall('project.edit', array(
      'objectIdentifier' => $project->getPHID(),
      'transactions' => array(array('type' => 'view', 'value' => head($added))),
    )))
      ->setUser($actor)
      ->execute();
    $this->assertEqual(head($added), $project->reload()->getViewPolicy());
    $this->assertEqual('deny', $policy->reload()->getDefaultAction());
  }

  public function testMalformedOrUnavailablePostedRulesCreateNoPolicy() {
    $actor = $this->generateNewTestUser();
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phorge.product-profile', 'collaboration');
    $env->overrideEnvConfig('policy.editor.hidden-rules', null);
    $before = $this->countPolicies();
    $valid = array(array(
      'action' => 'allow',
      'rule' => PhabricatorUsersPolicyRule::class,
      'value' => array($actor->getPHID()),
    ));
    $bad_action = $valid;
    $bad_action[0]['action'] = '允许';
    $hidden = array(array(
      'action' => 'allow',
      'rule' => PhabricatorLunarPhasePolicyRule::class,
      'value' => 'full',
    ));
    foreach (array(
      array('rules' => '{'),
      array('rules' => 'null'),
      array('rules' => '["not-a-rule"]'),
      array('rules' => json_encode($bad_action)),
      array('rules' => json_encode($hidden)),
      array('rules' => json_encode($valid), 'default' => '允许'),
    ) as $post) {
      $response = $this->requestEditor(
        $actor,
        AphrontFormPolicyControl::getSelectCustomKey(),
        $post + array('default' => 'deny'));
      $this->assertEqual(
        pht('Unavailable Policy Rule'), $response->getDialog()->getTitle());
      $this->assertEqual($before, $this->countPolicies());
    }
    unset($env);
  }

  private function newHistoricalPolicy(PhabricatorUser $actor) {
    return id(new PhabricatorPolicy())
      ->setRules(array(
        array('action' => 'allow', 'rule' => PhabricatorUsersPolicyRule::class,
          'value' => array($actor->getPHID())),
        array('action' => 'deny', 'rule' => PhabricatorLunarPhasePolicyRule::class,
          'value' => 'full'),
      ))
      ->setDefaultAction('deny')
      ->save();
  }

  private function countPolicies() {
    return count(id(new PhabricatorPolicy())->loadAllWhere('1 = 1'));
  }

  private function requestEditor(
    PhabricatorUser $actor,
    $phid,
    ?array $post = null) {

    $old_method = idx($_SERVER, 'REQUEST_METHOD');
    $_SERVER['REQUEST_METHOD'] = $post === null ? 'GET' : 'POST';
    try {
      $data = $post === null ? array() : $post + array(
        '__form__' => '1',
        '__csrf__' => $actor->getCSRFToken(),
      );
      $request = id(new AphrontRequest('phabricator.example.com',
        '/policy/edit/type/PROJ/'.$phid.'/'))
        ->setUser($actor)
        ->setURIMap(array('objectType' => 'PROJ', 'phid' => $phid))
        ->setRequestData($data);
      return id(new PhabricatorPolicyEditController())
        ->setRequest($request)
        ->handleRequest($request);
    } finally {
      if ($old_method === null) {
        unset($_SERVER['REQUEST_METHOD']);
      } else {
        $_SERVER['REQUEST_METHOD'] = $old_method;
      }
    }
  }

}
