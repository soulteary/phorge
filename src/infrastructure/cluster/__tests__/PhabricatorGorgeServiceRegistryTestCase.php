<?php

final class PhabricatorGorgeServiceRegistryTestCase
  extends PhabricatorTestCase {

  public function testRegistryContainsEveryDeploymentDomain() {
    $this->assertEqual(
      array(
        'render',
        'conduit',
        'notification',
        'mailer',
        'search',
        'file',
        'webhook',
        'taskqueue',
        'db',
      ),
      array_keys(PhabricatorGorgeServiceRegistry::getServices()));

    $this->assertEqual(
      array('gorge.search.token', 'cluster.search'),
      PhabricatorGorgeServiceRegistry::getService('search')
        ->getConfigurationKeys());
  }

  public function testExplicitOwnershipDoesNotDependOnEndpoint() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.webhook.uri', null);
    $env->overrideEnvConfig('gorge.webhook.owner', 'gorge');

    $service = PhabricatorGorgeServiceRegistry::getService('webhook');
    $this->assertEqual('gorge', $service->getOwner());
    $this->assertTrue($service->isOwnedBy('gorge'));

    unset($env);
  }

  public function testAutomaticOwnershipPreservesLegacyBehavior() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig(
      'gorge.taskqueue.uri',
      'http://gorge-taskqueue:8090/');
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'auto');

    $service = PhabricatorGorgeServiceRegistry::getService('taskqueue');
    $this->assertEqual(
      'http://gorge-taskqueue:8090',
      $service->getConfiguredURI());
    $this->assertEqual('gorge', $service->getOwner());

    unset($env);
  }

}
