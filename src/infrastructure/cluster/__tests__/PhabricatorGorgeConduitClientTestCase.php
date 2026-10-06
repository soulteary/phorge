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

  public function testAnySuccessStatusReturnsTheResult() {
    // Conduit itself always answers 200, but the gateway in front of it may
    // answer any 2xx of its own accord. A response whose envelope parses
    // should not be discarded over the status line.
    foreach (array(200, 201, 202, 204, 299) as $status_code) {
      $result = $this->parseStatus($status_code);

      $this->assertEqual(
        'pong',
        $result,
        pht('Result for HTTP %d.', $status_code));
    }
  }

  public function testNonSuccessStatusDoesNotReturnTheResult() {
    // The boundaries either side of the 2xx range, plus a server error, all
    // with a well-formed success envelope: the status is what rejects them.
    foreach (array(199, 300, 404, 500) as $status_code) {
      $caught = null;
      try {
        $this->parseStatus($status_code);
      } catch (Exception $ex) {
        $caught = $ex;
      }

      $this->assertTrue(
        ($caught !== null),
        pht('HTTP %d must not produce a result.', $status_code));

      $this->assertTrue(
        (strpos($caught->getMessage(), (string)$status_code) !== false),
        pht('Message for HTTP %d names the status.', $status_code));
    }
  }

  private function parseStatus($status_code) {
    $body = phutil_json_encode(
      array(
        'result' => 'pong',
        'error_code' => null,
        'error_info' => null,
      ));

    $status = new HTTPFutureHTTPResponseStatus(
      $status_code,
      $body,
      array());

    return $this->invokeResponseParser(
      array($status, $body, array()));
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
