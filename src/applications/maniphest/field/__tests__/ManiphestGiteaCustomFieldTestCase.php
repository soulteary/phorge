<?php

final class ManiphestGiteaCustomFieldTestCase extends PhabricatorTestCase {

  public function testCollaborationProfileBuildsStableFields() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phorge.product-profile', 'collaboration');

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

  public function testFullProfileDoesNotBuildGiteaFields() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phorge.product-profile', 'full');

    $this->assertEqual(
      array(),
      id(new ManiphestGiteaCustomField())->createFields(null));

    unset($env);
  }

}
