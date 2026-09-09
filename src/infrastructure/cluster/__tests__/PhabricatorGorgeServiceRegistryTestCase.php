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

  public function testServicePolicyResolutionAndFallbackCounts() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.service-policy', 'required');
    $env->overrideEnvConfig(
      'gorge.service-policies',
      array(
        'search' => 'fallback',
        'render' => 'off',
      ));

    $search = PhabricatorGorgeServiceRegistry::getService('search');
    $render = PhabricatorGorgeServiceRegistry::getService('render');
    $file = PhabricatorGorgeServiceRegistry::getService('file');

    $this->assertTrue($search->isFallbackAllowed());
    $this->assertTrue($render->isDisabled());
    $this->assertTrue($file->isRequired());

    PhabricatorGorgeServiceSpec::resetFallbackCounts();
    $search->recordFallback('query');
    $search->recordFallback('query');
    $this->assertEqual(
      array('search.query' => 2),
      PhabricatorGorgeServiceSpec::getFallbackCounts());

    PhabricatorGorgeServiceSpec::resetFallbackCounts();
    unset($env);
  }

  public function testOffDoesNotOverrideExclusiveOwnership() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.service-policy', 'off');
    $env->overrideEnvConfig('gorge.taskqueue.owner', 'gorge');

    $service = PhabricatorGorgeServiceRegistry::getService('taskqueue');
    $this->assertTrue($service->isDisabled());
    $this->assertTrue($service->isOwnedBy('gorge'));
    $this->assertTrue(PhabricatorGorgeTaskQueueClient::isConfigured());

    unset($env);
  }

  public function testSearchOffSynthesizesNativeService() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.service-policy', 'off');
    $env->overrideEnvConfig(
      'cluster.search',
      array(
        array(
          'type' => 'gorge',
          'hosts' => array(),
        ),
      ));

    $services = PhabricatorSearchService::newRefs();
    $this->assertEqual(1, count($services));
    $config = $services[0]->getConfig();
    $this->assertEqual('mysql', $config['type']);

    unset($env);
  }

  public function testDatabaseOperationFallbackPolicy() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.db.uri', 'http://gorge-db:8170');
    $env->overrideEnvConfig('storage.default-namespace', 'phabricator');

    $cache = PhabricatorCaches::getRequestCache();
    $cache_key = PhabricatorGorgeDBClient::KEY_SHOULD_USE.
      '(http://gorge-db:8170, phabricator)';
    $cache->setKey(
      $cache_key,
      array(
        'checked' => true,
        'usable' => true,
      ));

    $env->overrideEnvConfig('gorge.service-policy', 'fallback');
    PhabricatorGorgeServiceSpec::resetFallbackCounts();

    $result = PhabricatorGorgeDBClient::executeWithFallback(
      'test',
      function() {
        throw new Exception('service failure');
      },
      function() {
        return 'native';
      });

    $this->assertEqual('native', $result);
    $this->assertEqual(
      array('db.test' => 1),
      PhabricatorGorgeServiceSpec::getFallbackCounts());

    $env->overrideEnvConfig('gorge.service-policy', 'required');
    $caught = null;
    try {
      PhabricatorGorgeDBClient::executeWithFallback(
        'test',
        function() {
          throw new Exception('required failure');
        },
        function() {
          return 'unreachable';
        });
    } catch (Exception $ex) {
      $caught = $ex;
    }
    $this->assertTrue($caught instanceof Exception);

    $cache->deleteKey($cache_key);
    PhabricatorGorgeServiceSpec::resetFallbackCounts();
    unset($env);
  }

}
