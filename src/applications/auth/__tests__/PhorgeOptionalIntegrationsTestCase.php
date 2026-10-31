<?php

final class PhorgeOptionalIntegrationsTestCase extends PhabricatorTestCase {

  public function testOptionalIntegrationsAreDisabledWithoutConfiguration() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('integrations.entry-session.enabled', false);
    $env->overrideEnvConfig('integrations.entry-session.enforce-file-policy', false);
    $env->overrideEnvConfig('integrations.maintenance.enabled', false);
    $app = new TeamMaintenanceApplication();
    $this->assertFalse($app->isLaunchable());
    $this->assertTrue($app->isUnlisted());
    $this->assertEqual(array(), $app->getRoutes());
    // An opt-out installation must not query the optional session table.
    id(new TeamEntrySessionGuard())->didLogout(new PhabricatorUser(), array());
  }

  public function testProfileAndExplicitPolicyChoices() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('policy.editor.hidden-rules', null);
    $env->overrideEnvConfig('phorge.product-profile', 'full');
    $this->assertEqual(array(), PhabricatorPolicyEditorRuleSet::getHiddenRuleClasses());
    $env->overrideEnvConfig('phorge.product-profile', 'collaboration');
    $this->assertEqual(array('PhabricatorLunarPhasePolicyRule',
      'PhabricatorLegalpadSignaturePolicyRule'),
      PhabricatorPolicyEditorRuleSet::getHiddenRuleClasses());
    $env->overrideEnvConfig('policy.editor.hidden-rules', array());
    $this->assertEqual(array(), PhabricatorPolicyEditorRuleSet::getHiddenRuleClasses());
  }

  public function testGatewayConfigurationRejectsHeaderAndCookieInjection() {
    $env = $this->entryEnvironment();
    $config = PhorgeOptionalIntegrations::getEntryConfiguration();
    $this->assertEqual('/auth/login/traefik:traefik/', $config['provider-login-uri']);
    foreach (array(
      'trusted-proxies' => array('proxy.invalid'),
      'cookie' => "entry; injected=value",
      'principal-header' => "X-Auth-User\r\nInjected",
      'provider' => 'https://outside.invalid/',
      'provider-login-uri' => '//outside.invalid/login',
      'entry-login-uri' => 'https://outside.invalid/login',
    ) as $key => $value) {
      $scope = PhabricatorEnv::beginScopedEnv();
      $scope->overrideEnvConfig('integrations.entry-session.'.$key, $value);
      $rejected = false;
      try { PhorgeOptionalIntegrations::getEntryConfiguration(); }
      catch (Exception $ex) { $rejected = true; }
      $this->assertTrue($rejected, $key);
      unset($scope);
    }
  }

  public function testLocalContinuationAndServiceURLsAreRestricted() {
    foreach (array('/auth/', '/entry/login?fresh=1') as $uri) {
      $this->assertTrue(PhorgeOptionalIntegrations::isLocalPath($uri));
    }
    foreach (array('//outside.invalid/', '/\\outside.invalid/',
      "\n/auth/", 'https://outside.invalid/', '/auth/#fragment') as $uri) {
      $this->assertFalse(PhorgeOptionalIntegrations::isLocalPath($uri));
    }
    $env = $this->entryEnvironment();
    $env->overrideEnvConfig('integrations.entry-session.enabled', true);
    $env->overrideEnvConfig('integrations.maintenance.enabled', true);
    $file = new TempFile();
    Filesystem::writeFile($file, Filesystem::readRandomBytes(32));
    $env->overrideEnvConfig('integrations.maintenance.key-file', (string)$file);
    $env->overrideEnvConfig('integrations.maintenance.endpoint', 'https://maintenance.invalid/rpc');
    $this->assertTrue(PhorgeOptionalIntegrations::isMaintenanceEnabled());
    foreach (array('http://maintenance.invalid/rpc',
      'https://user:password@maintenance.invalid/rpc',
      'https://maintenance.invalid/other',
      'https://maintenance.invalid/rpc?forward=1') as $uri) {
      $env->overrideEnvConfig('integrations.maintenance.endpoint', $uri);
      $this->assertFalse(PhorgeOptionalIntegrations::isMaintenanceEnabled());
    }
    $env->overrideEnvConfig('integrations.maintenance.endpoint', 'http://maintenance.invalid/rpc');
    $env->overrideEnvConfig('integrations.maintenance.allow-http', true);
    $this->assertTrue(PhorgeOptionalIntegrations::isMaintenanceEnabled());
  }

  public function testMissingBackendConfigurationNeverCreatesAnEntry() {
    $env = $this->entryEnvironment();
    $env->overrideEnvConfig('integrations.maintenance.enabled', true);
    $env->overrideEnvConfig('integrations.entry-session.enabled', false);
    $this->assertFalse(PhorgeOptionalIntegrations::isMaintenanceEnabled());
    $env->overrideEnvConfig('integrations.entry-session.enabled', true);
    $env->overrideEnvConfig('integrations.maintenance.endpoint', '');
    $env->overrideEnvConfig('integrations.maintenance.key-file', '');
    $this->assertFalse(PhorgeOptionalIntegrations::isMaintenanceEnabled());
  }

  public function testConfiguredAppVisibilityAndPaginationMatchTheServiceContract() {
    $env = $this->entryEnvironment();
    $env->overrideEnvConfig('integrations.entry-session.enabled', true);
    $env->overrideEnvConfig('integrations.maintenance.enabled', true);
    $env->overrideEnvConfig('integrations.maintenance.endpoint', 'https://maintenance.invalid/rpc');
    $file = new TempFile();
    Filesystem::writeFile($file, Filesystem::readRandomBytes(32));
    $env->overrideEnvConfig('integrations.maintenance.key-file', (string)$file);
    $app = new TeamMaintenanceApplication();
    $this->assertTrue($app->isLaunchable());
    $this->assertFalse($app->isUnlisted());
    $this->assertEqual(6, count($app->getRoutes()['/maintenance/']));
    $this->assertTrue($app->isPinnedByDefault(id(new PhabricatorUser())->setIsAdmin(1)));
    $this->assertFalse($app->isPinnedByDefault(new PhabricatorUser()));

    $controller = new TeamMaintenanceOverviewController();
    $params = new ReflectionMethod($controller, 'listParams');
    $params->setAccessible(true);
    $request = id(new AphrontRequest('phorge.example.invalid', '/maintenance/'))
      ->setRequestData(array('offset' => '1000001'));
    $this->assertEqual(100000, $params->invoke($controller, $request)['offset']);
    $request->setRequestData(array('offset' => '-1'));
    $this->assertEqual(0, $params->invoke($controller, $request)['offset']);
    $pager = new ReflectionMethod($controller, 'pager');
    $pager->setAccessible(true);
    $html = (string)hsprintf('%s', $pager->invoke($controller, '/maintenance/users/',
      array('offset' => 100000, 'limit' => 50, 'q' => ''), 200000));
    $this->assertFalse(strpos($html, 'offset=100050') !== false);

    $env->overrideEnvConfig('integrations.maintenance.key-file', '');
    $this->assertFalse($app->isLaunchable());
    $this->assertTrue($app->isUnlisted());
    $this->assertEqual(array(), $app->getRoutes());
  }

  public function testFileGuardUsesNativeDataDownloadAndTransformRoutes() {
    $phid = 'PHID-FILE-example';
    foreach (array(
      '/file/data/key/'.$phid.'/file.txt',
      '/file/download/key/'.$phid.'/file.txt',
      '/file/data/@example/key/'.$phid.'/file.txt',
      '/file/download/@example/key/'.$phid.'/file.txt',
      '/file/xform/thumb-60x45/'.$phid.'/key/',
      '/file/xform/@example/thumb-60x45/'.$phid.'/key/',
    ) as $path) {
      $this->assertEqual($phid, TeamEntrySessionGuard::getProtectedFilePHID($path));
    }
    foreach (array('/auth/login/', '/res/example', '/file/upload/', '/F21') as $path) {
      $this->assertEqual(null, TeamEntrySessionGuard::getProtectedFilePHID($path));
    }
    $response = id(new TeamEntrySessionMismatchHandler())->handleRequestThrowable(
      new AphrontRequest('phorge.example.invalid', '/'), new TeamEntryFilePolicyException());
    $this->assertEqual(404, $response->getHTTPResponseCode());
    $this->assertEqual("Not Found\n", $response->buildResponseString());
  }

  public function testRecoveryOnlyAllowsSafeDocumentNavigation() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('phabricator.base-uri', 'https://phorge.example.invalid/');
    $env->overrideEnvConfig('security.require-https', true);
    $original = $_SERVER;
    try {
      $_SERVER['HTTPS'] = 'on';
      $_SERVER['REQUEST_METHOD'] = 'GET';
      $_SERVER['HTTP_ACCEPT'] = 'text/html';
      $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
      $request = id(new AphrontRequest('phorge.example.invalid', '/project/'))
        ->setRequestData(array());
      $handler = new TeamEntrySessionMismatchHandler();
      $method = new ReflectionMethod($handler, 'canReauthenticateNavigation');
      $method->setAccessible(true);
      $changed = new TeamEntrySessionMismatchException('entry_session_changed');
      $this->assertTrue($method->invoke($handler, $request, $changed));
      $this->assertFalse($method->invoke($handler, $request,
        new TeamEntrySessionMismatchException('entry_identity_required')));
      $_SERVER['REQUEST_METHOD'] = 'POST';
      $this->assertFalse($method->invoke($handler, $request, $changed));
      $_SERVER['REQUEST_METHOD'] = 'GET';
      foreach (array(AphrontRequest::TYPE_AJAX, AphrontRequest::TYPE_WORKFLOW,
        AphrontRequest::TYPE_QUICKSAND, AphrontRequest::TYPE_CONDUIT,
        AphrontRequest::TYPE_FORM) as $flag) {
        $request->setRequestData(array($flag => true));
        $this->assertFalse($method->invoke($handler, $request, $changed));
      }
      $request->setRequestData(array());
      foreach (array('/api/user.whoami', '/file/data/key/phid/name', '/res/example') as $path) {
        $request->setPath($path);
        $this->assertFalse($method->invoke($handler, $request, $changed));
      }
      $request->setPath('/project/');
      $_SERVER['HTTP_SEC_FETCH_DEST'] = 'image';
      $this->assertFalse($method->invoke($handler, $request, $changed));
    } finally {
      $_SERVER = $original;
    }
  }

  public function testSessionBindingMigrationUsesNativePatchRegistry() {
    $patches = PhabricatorSQLPatchList::buildAllPatches();
    $patch = $patches['optionalentrysession:20261031.entry-session.sql'];
    $this->assertEqual(array('phabricator:db.user'), $patch->getAfter());
    $sql = Filesystem::readFile($patch->getName());
    $this->assertTrue(strpos($sql, 'user_entrysessionbinding') !== false);
    $this->assertTrue(strpos($sql, 'CREATE TABLE IF NOT EXISTS') !== false);
  }

  private function entryEnvironment() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('integrations.entry-session.trusted-proxies', array('192.0.2.10'));
    $env->overrideEnvConfig('integrations.entry-session.cookie', 'entry_session');
    $env->overrideEnvConfig('integrations.entry-session.principal-header', 'X-Auth-User');
    $env->overrideEnvConfig('integrations.entry-session.provider', 'traefik:traefik');
    $env->overrideEnvConfig('integrations.entry-session.provider-login-uri', '');
    $env->overrideEnvConfig('integrations.entry-session.entry-login-uri', '/entry/login');
    return $env;
  }
}
