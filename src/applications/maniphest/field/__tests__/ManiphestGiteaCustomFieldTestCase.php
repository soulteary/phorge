<?php

final class ManiphestGiteaCustomFieldTestCase extends PhabricatorTestCase {

  public function testCollaborationProfileBuildsStableFields() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phorge.product-profile', 'collaboration');
    $env->overrideEnvConfig('maniphest.custom-field-definitions', array());

    $fields = id(new ManiphestGiteaCustomField())->createFields(null);
    $this->assertEqual(
      array(
        'std:maniphest:gitea.repository',
        'std:maniphest:gitea.issue',
        'std:maniphest:gitea.pull-request',
        'std:maniphest:gitea.commit',
      ),
      mpull($fields, 'getFieldKey'));
    $this->assertEqual(
      array(
        'custom.gitea.repository',
        'custom.gitea.issue',
        'custom.gitea.pull-request',
        'custom.gitea.commit',
      ),
      mpull($fields, 'getModernFieldKey'));

    unset($env);
  }

  public function testAdministratorDefinitionOverridesDefault() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phorge.product-profile', 'collaboration');
    $env->overrideEnvConfig(
      'maniphest.custom-field-definitions',
      array(
        'gitea.issue' => array(
          'name' => 'Administrator Issue Field',
          'type' => 'text',
          'caption' => 'Administrator contract.',
        ),
      ));

    $defaults = id(new ManiphestGiteaCustomField())->createFields(null);
    $this->assertEqual(
      array(
        'std:maniphest:gitea.repository',
        'std:maniphest:gitea.pull-request',
        'std:maniphest:gitea.commit',
      ),
      mpull($defaults, 'getFieldKey'));

    $configured =
      id(new ManiphestConfiguredCustomField())->createFields(null);
    $this->assertEqual(1, count($configured));
    $this->assertEqual(
      'std:maniphest:gitea.issue',
      head($configured)->getFieldKey());
    $this->assertEqual(
      'custom.gitea.issue',
      head($configured)->getModernFieldKey());
    $this->assertEqual(
      'Administrator Issue Field',
      head($configured)->getFieldName());

    unset($env);
  }

  public function testFullProfileDoesNotBuildGiteaFields() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phorge.product-profile', 'full');

    $this->assertEqual(
      array(),
      id(new ManiphestGiteaCustomField())->createFields(null));

    unset($env);
  }

}
