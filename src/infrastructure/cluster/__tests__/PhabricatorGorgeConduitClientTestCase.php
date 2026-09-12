<?php

final class PhabricatorGorgeConduitClientTestCase
  extends PhabricatorTestCase {

  public function testHTTPResponseStatusIsNotTransportFailure() {
    $body = phutil_json_encode(
      array(
        'result' => 'pong',
        'error_code' => null,
        'error_info' => null,
      ));

    $status = new HTTPFutureHTTPResponseStatus(
      200,
      $body,
      array());

    $result = $this->invokeResponseParser(
      array($status, $body, array()));

    $this->assertEqual('pong', $result);
  }

  public function testHTTPErrorDoesNotReturnResult() {
    $exception = null;
    try {
      $this->parseHTTPError();
    } catch (Exception $ex) {
      $exception = $ex;
    }

    $this->assertEqual(true, $exception instanceof Exception);
    $this->assertEqual(
      true,
      strpos($exception->getMessage(), 'returned HTTP 500') !== false);
  }

  private function parseHTTPError() {
    $body = phutil_json_encode(
      array(
        'result' => 'unexpected',
        'error_code' => null,
        'error_info' => null,
      ));

    $status = new HTTPFutureHTTPResponseStatus(
      500,
      $body,
      array());

    $this->invokeResponseParser(
      array($status, $body, array()));
  }

  private function invokeResponseParser(array $result) {
    $method = new ReflectionMethod(
      'PhabricatorGorgeConduitClient',
      'parseConduitResponse');
    $method->setAccessible(true);

    return $method->invoke(
      null,
      'http://gorge-conduit/api/conduit.ping',
      $result);
  }

}
