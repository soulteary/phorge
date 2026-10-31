<?php

final class PhorgeMaintenanceRPCContractTestCase extends PhabricatorTestCase {
  private $directory;
  private $server;
  private $environment;

  protected function willRunOneTest($name) {
    $this->stopReceiver();
    if (!function_exists('curl_init')) {
      $this->assertSkipped('The maintenance client requires PHP cURL.');
    }
    if ($name === 'testNativeResponseDeadline' &&
        getenv('PHORGE_TEST_MAINTENANCE_TIMEOUT') !== '1') {
      $this->assertSkipped('Set PHORGE_TEST_MAINTENANCE_TIMEOUT=1 for the 90-second deadline.');
    }
    parent::willRunOneTest($name);
    $this->directory = Filesystem::createTemporaryDirectory();
    Filesystem::writeFile($this->directory.'/key', Filesystem::readRandomBytes(32));
    $this->setMode(array('kind' => 'ok'));
    $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    $this->assertTrue((bool)$socket);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $this->server = id(new ExecFuture('exec %s -S %s %s', PHP_BINARY,
      $address, dirname(__FILE__).'/fixtures/maintenance-rpc-router.php'))
      ->setEnv(array('PHORGE_MAINTENANCE_RPC_FIXTURE' => $this->directory));
    $this->server->start();
    $ready = false;
    for ($attempt = 0; $attempt < 250; $attempt++) {
      $connection = @stream_socket_client('tcp://'.$address, $error, $message, 0.02);
      if ($connection) {
        fclose($connection);
        $ready = true;
        break;
      }
      usleep(20000);
    }
    $this->assertTrue($ready, 'The disposable loopback RPC receiver started.');
    $env = PhabricatorEnv::beginScopedEnv();
    foreach (array(
      'entry-session.enabled' => true,
      'entry-session.trusted-proxies' => array('127.0.0.1'),
      'entry-session.cookie' => 'entry_session',
      'entry-session.principal-header' => 'X-Auth-User',
      'entry-session.provider' => 'traefik:traefik',
      'entry-session.provider-login-uri' => '',
      'entry-session.entry-login-uri' => '/entry/login/',
      'maintenance.enabled' => true,
      'maintenance.allow-http' => true,
      'maintenance.endpoint' => 'http://'.$address.'/rpc',
      'maintenance.key-file' => $this->directory.'/key',
    ) as $key => $value) {
      $env->overrideEnvConfig('integrations.'.$key, $value);
    }
    $this->environment = $env;
  }

  protected function didRunOneTest($name) {
    $this->stopReceiver();
    parent::didRunOneTest($name);
  }

  protected function didRunTests() {
    $this->stopReceiver();
    parent::didRunTests();
  }

  private function stopReceiver() {
    if ($this->server) {
      $this->server->resolveKill();
      $this->server = null;
    }
    $this->environment = null;
    if ($this->directory) {
      Filesystem::remove($this->directory);
      $this->directory = null;
    }
  }

  public function testEightActionsPreserveSignedBytesAndStableOperationIDs() {
    $password = "  测试 password 🔐 2026  ";
    $registration = '00000000-0000-4000-8000-000000000001';
    $actions = array(
      'overview' => array(),
      'list_registrations' => array('q' => '测试', 'offset' => 0, 'limit' => 50,
        'status' => 'pending'),
      'list_users' => array('q' => '', 'offset' => 0, 'limit' => 50),
      'list_audit' => array('q' => '', 'offset' => 0, 'limit' => 50),
      'approve' => array('registration_id' => $registration,
        'confirmed_phone' => '19900000001', 'note' => '测试审核', 'username' => 'example'),
      'reject' => array('registration_id' => $registration, 'reason' => 'test reason'),
      'set_user_status' => array('user_id' => 'example', 'status' => 'inactive',
        'reason' => 'test reason'),
      'set_password' => array('user_id' => 'example', 'password' => $password,
        'password_confirm' => $password, 'reason' => 'test password rotation'),
    );
    $original = array();
    foreach (array('http_proxy', 'all_proxy', 'no_proxy') as $name) {
      $original[$name] = getenv($name);
      putenv($name.'='.($name === 'no_proxy' ? '' : 'http://127.0.0.1:1'));
    }
    try {
      foreach ($actions as $action => $params) {
        $request_id = bin2hex(Filesystem::readRandomBytes(16));
        $first = $this->call($action, $params, $request_id);
        $second = $this->call($action, $params, $request_id);
        $this->assertTrue($first['signature_valid']);
        $this->assertEqual($action, $first['received']['action']);
        $this->assertEqual('PHID-USER-'.str_repeat('a', 20), $first['received']['actor_phid']);
        $this->assertEqual('immutable-test-principal', $first['received']['actor_principal']);
        $this->assertEqual($params, $first['received']['params']);
        $this->assertEqual($request_id, $first['received']['request_id']);
        $this->assertEqual($request_id, $second['received']['request_id']);
        $this->assertFalse($first['nonce'] === $second['nonce']);
      }
    } finally {
      foreach ($original as $name => $value) {
        putenv($value === false ? $name : $name.'='.$value);
      }
    }
    $this->assertEqual(16, count($this->trace()));
  }

  public function testMalformedFailureAndOversizeResponsesFailClosed() {
    foreach (array(
      array(array('kind' => 'error', 'status' => 403, 'code' => 'forbidden'), 'forbidden'),
      array(array('kind' => 'error', 'status' => 409, 'code' => 'operation_in_progress'), 'operation_in_progress'),
      array(array('kind' => 'error', 'status' => 500, 'code' => 'forbidden'), 'service_unavailable'),
      array(array('kind' => 'error', 'status' => 403, 'code' => '<unsafe-code>'), 'service_unavailable'),
      array(array('kind' => 'json', 'status' => 200, 'body' => 'not json'), 'service_unavailable'),
      array(array('kind' => 'json', 'status' => 200, 'body' => '{"ok":true,"data":null}'), 'service_unavailable'),
      array(array('kind' => 'json', 'status' => 201, 'body' => '{"ok":true,"data":{}}'), 'service_unavailable'),
      array(array('kind' => 'oversize'), 'service_unavailable'),
      array(array('kind' => 'truncated'), 'service_unavailable'),
    ) as $case) {
      $this->setMode($case[0]);
      $this->assertServiceError($case[1], function() { $this->call(); });
    }
    $this->assertEqual(9, count($this->trace()));
  }

  public function testRedirectsAreNotFollowed() {
    $this->setMode(array('kind' => 'redirect'));
    $this->assertServiceError('service_unavailable', function() { $this->call(); });
    $trace = $this->trace();
    $this->assertEqual(1, count($trace));
    $this->assertEqual('/rpc', $trace[0]['path']);
  }

  public function testDisabledOrInvalidRequestsNeverReachTheReceiver() {
    $this->environment->overrideEnvConfig('integrations.maintenance.enabled', false);
    $this->assertServiceError('service_unavailable', function() { $this->call(); });
    $this->environment->overrideEnvConfig('integrations.maintenance.enabled', true);
    $this->assertServiceError('invalid_input', function() {
      $this->call('overview', array(), 'invalid-operation-id');
    });
    $this->assertServiceError('invalid_input', function() {
      $this->call('list_users', array('q' => str_repeat('x', 16385)));
    });
    $this->assertServiceError('invalid_input', function() {
      $this->call('list_users', array('q' => "\xff"));
    });
    $this->assertServiceError('forbidden', function() {
      id(new TeamMaintenanceClient())->call(new PhabricatorUser(), '', 'overview', array());
    });
    $this->assertEqual(array(), $this->trace());
  }

  public function testRealRequestGuardHonorsOptOutAnonymousAndConfiguredIdentityGates() {
    foreach (array(
      array(array('enabled' => false, 'trusted-proxies' => array()), 'allowed'),
      array(array('enabled' => true, 'anonymous' => true), 'allowed'),
      array(array('enabled' => true), 'entry_identity_required'),
      array(array('enabled' => true, 'principal' => true), 'entry_identity_required'),
      array(array('enabled' => true, 'cookie' => true), 'entry_identity_required'),
      array(array('enabled' => true, 'principal' => true, 'cookie' => true,
        'trusted-proxies' => array('192.0.2.10')), 'entry_identity_required'),
      array(array('enabled' => true, 'principal' => true, 'cookie' => true,
        'disabled' => true), 'entry_identity_required'),
    ) as $case) {
      $this->setMode(array('kind' => 'entry-guard') + $case[0]);
      $result = $this->call();
      $this->assertEqual($case[1], $result['outcome']);
    }
  }

  public function testNativeResponseDeadline() {
    $this->setMode(array('kind' => 'stall'));
    $start = microtime(true);
    $this->assertServiceError('service_unavailable', function() { $this->call(); });
    $elapsed = microtime(true) - $start;
    $this->assertTrue($elapsed >= 85 && $elapsed < 94,
      'The native client applies its 90-second response deadline.');
  }

  private function call($action = 'overview', array $params = array(), $request_id = null) {
    $actor = id(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('a', 20));
    return id(new TeamMaintenanceClient())->call($actor,
      'immutable-test-principal', $action, $params, $request_id);
  }

  private function assertServiceError($code, $call) {
    $caught = null;
    try { $call(); }
    catch (TeamMaintenanceServiceException $ex) { $caught = $ex; }
    $this->assertTrue($caught instanceof TeamMaintenanceServiceException);
    $this->assertEqual($code, $caught->getServiceCode());
    $this->assertFalse(strpos($caught->getMessage(), 'private backend detail') !== false);
  }

  private function setMode(array $mode) {
    Filesystem::writeFile($this->directory.'/mode.json', json_encode($mode));
  }

  private function trace() {
    $path = $this->directory.'/trace.jsonl';
    if (!file_exists($path)) { return array(); }
    return array_map('phutil_json_decode', array_filter(explode("\n", Filesystem::readFile($path))));
  }
}
