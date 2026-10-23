<?php

final class ConduitCallTestCase extends PhabricatorTestCase {

  public function testConduitPing() {
    $call = new ConduitCall('conduit.ping', array());
    $result = $call->execute();

    $this->assertFalse(empty($result));
  }

  public function testConduitAuth() {
    $call = new ConduitCall('user.whoami', array(), true);

    $caught = null;
    try {
      $result = $call->execute();
    } catch (ConduitException $ex) {
      $caught = $ex;
    }

    $this->assertTrue(
      ($caught instanceof ConduitException),
      pht(
        '%s should require authentication.',
        'user.whoami'));
    $this->assertEqual('ERR-INVALID-AUTH', $caught->getMessage());
    $this->assertEqual(
      pht('This method requires authentication.'),
      $caught->getErrorDescription());
  }

  public function testConduitThrowableClosesProfiler() {
    $instance = new ReflectionProperty('PhutilServiceProfiler', 'instance');
    $previous_profiler = PhutilServiceProfiler::getInstance();

    try {
      foreach (array(
        new Exception('Method exception.'),
        new Error('Method error.'),
        new TypeError('Method type error.'),
      ) as $failure) {
        $profiler = id(new ReflectionClass('PhutilServiceProfiler'))
          ->newInstanceWithoutConstructor();
        $instance->setValue(null, $profiler);

        $call = new ConduitCall('conduit.ping', array());
        $handler = new class($failure) extends ConduitAPIMethod {
          private $failure;

          public function __construct(Throwable $failure) {
            $this->failure = $failure;
          }

          public function getAPIMethodName() { return 'conduit.ping'; }
          public function getMethodDescription() { return 'Fail a call.'; }
          public function shouldRequireAuthentication() { return false; }
          protected function defineParamTypes() { return array(); }
          protected function defineReturnType() { return 'void'; }

          protected function execute(ConduitAPIRequest $request) {
            throw $this->failure;
          }
        };
        id(new ReflectionProperty('ConduitCall', 'handler'))
          ->setValue($call, $handler);

        $caught = null;
        try {
          $call->execute();
        } catch (Throwable $ex) {
          $caught = $ex;
        }

        $this->assertEqual($failure, $caught);
        $events = array_values($profiler->getServiceCallLog());
        $this->assertEqual(1, count($events));
        $this->assertEqual('conduit', $events[0]['type']);
        $this->assertEqual('conduit.ping', $events[0]['method']);
        $this->assertTrue(isset($events[0]['end']));
        $this->assertTrue(isset($events[0]['duration']));
      }
    } finally {
      $instance->setValue(null, $previous_profiler);
    }
  }

  public function testConduitControllerThrowableResponse() {
    $response = $this->newConduitResponse(
      'conduit.ping',
      array('params' => array('value' => array('nested' => 'value'))));

    $this->assertEqual(null, $response['result']);
    $this->assertEqual('ERR-CONDUIT-CORE', $response['error_code']);
    $this->assertTrue(strlen($response['error_info']) > 0);
  }

  public function testConduitControllerAuthentication() {
    $response = $this->newConduitResponse('user.whoami', array());
    $this->assertEqual(null, $response['result']);
    $this->assertEqual('ERR-INVALID-SESSION', $response['error_code']);

    $response = $this->newConduitResponse('conduit.ping', array());
    $this->assertEqual(null, $response['error_code']);
    $this->assertFalse(empty($response['result']));
  }

  private function newConduitResponse($method, array $data) {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('cluster.read-only', true);

    $request = id(new AphrontRequest('example.com', '/api/'.$method))
      ->setURIMap(array('method' => $method))
      ->setUser(new PhabricatorUser())
      ->setRequestData($data);
    $controller = id(new PhabricatorConduitAPIController())
      ->setRequest($request);
    $response = $controller->handleRequest($request);

    $this->assertTrue($response instanceof AphrontJSONResponse);
    return phutil_json_decode($response->buildResponseString());
  }
}
