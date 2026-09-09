<?php

final class PhabricatorGorgeDiffClientTestCase extends PhabricatorTestCase {

  public function testBuildProseDiffFromServiceData() {
    $old = 'The quick brown fox';
    $new = 'The slow brown cat';

    $data = array(
      'parts' => array(
        array('type' => '=', 'text' => 'The '),
        array('type' => '-', 'text' => 'quick'),
        array('type' => '+', 'text' => 'slow'),
        array('type' => '=', 'text' => ' brown '),
        array('type' => '-', 'text' => 'fox'),
        array('type' => '+', 'text' => 'cat'),
      ),
    );

    $diff = PhabricatorGorgeDiffClient::newProseDiffFromData(
      $data,
      $old,
      $new);

    $this->assertEqual($data['parts'], $diff->getParts());
  }

  public function testRejectProseDiffWhichLosesInput() {
    $this->assertException(
      'Exception',
      array($this, 'buildLossyProseDiff'));
  }

  public function buildLossyProseDiff() {
    PhabricatorGorgeDiffClient::newProseDiffFromData(
      array(
        'parts' => array(
          array('type' => '=', 'text' => 'same'),
        ),
      ),
      'same old suffix',
      'same new suffix');
  }

  public function testConduitHTTPResponseStatusIsNotTransportFailure() {
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

    $result = $this->invokeConduitResponseParser(
      array($status, $body, array()));

    $this->assertEqual('pong', $result);
  }

  public function testConduitHTTPErrorDoesNotReturnResult() {
    $exception = null;
    try {
      $this->parseConduitHTTPError();
    } catch (Exception $ex) {
      $exception = $ex;
    }

    $this->assertEqual(true, $exception instanceof Exception);
    $this->assertEqual(
      true,
      strpos($exception->getMessage(), 'returned HTTP 500') !== false);
  }

  public function parseConduitHTTPError() {
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

    $this->invokeConduitResponseParser(
      array($status, $body, array()));
  }

  private function invokeConduitResponseParser(array $result) {
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
