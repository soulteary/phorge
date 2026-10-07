<?php

final class PhabricatorMailUnknownOutcomeTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true);
  }

  public function testInvalidReceiptPersistsUnknownAndPreventsResend() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('metamta.gorge-delivery-mode', 'legacy');
    $user = $this->generateNewTestUser();
    $mail = id(new PhabricatorMetaMTAMail())
      ->addTos(array($user->getPHID()));
    // Persist without scheduling a real send. Every write is in the unit
    // runner's disposable storage fixture transaction.
    $save = new ReflectionMethod('PhabricatorLiskDAO', 'save');
    $save->invoke($mail);
    $gorge = new class extends PhabricatorMailAdapter {
      const ADAPTERTYPE = 'gorge';
      public $requests = 0;
      public function getSupportedMessageTypes() { return array('email'); }
      protected function validateOptions(array $options) {}
      public function newDefaultOptions() { return array(); }
      public function sendMessage(PhabricatorMailExternalMessage $message) {
        $this->requests++;
        $body = '{"data":{}}';
        $parser = new ReflectionMethod('PhabricatorGorgeMailerClient', 'parseSendResponse');
        $parser->invoke(null, 'http://fixture/api/mailer/send', array(
          new HTTPFutureHTTPResponseStatus(200, $body, array()), $body));
      }
    };
    $fallback = new PhabricatorMailTestAdapter();
    $caught = null;
    try { $mail->sendWithMailers(array($gorge, $fallback)); }
    catch (PhabricatorMetaMTAUnknownOutcomeException $ex) { $caught = $ex; }
    $this->assertTrue($caught instanceof PhabricatorMetaMTAUnknownOutcomeException);
    $this->assertEqual(1, $gorge->requests);
    $this->assertEqual(array(), $fallback->getGuts());
    $this->assertEqual(PhabricatorMailOutboundStatus::STATUS_UNKNOWN,
      $mail->getStatus());
    $reloaded = id(new PhabricatorMetaMTAMail())->load($mail->getID());
    $this->assertEqual(PhabricatorMailOutboundStatus::STATUS_UNKNOWN,
      $reloaded->getStatus());
    $caught = null;
    try { $reloaded->sendWithMailers(array($gorge, $fallback)); }
    catch (PhabricatorMetaMTAUnknownOutcomeException $ex) { $caught = $ex; }
    $this->assertTrue($caught instanceof PhabricatorMetaMTAUnknownOutcomeException);
    id(new PhabricatorMetaMTAWorker($mail->getID()))->executeTask();
    $this->assertEqual(1, $gorge->requests,
      'Reload, direct send and worker retry must not resubmit unknown mail.');
    $this->assertEqual(array(), $fallback->getGuts());
  }

  public function testSafeRejectionReleasesFenceForRetry() {
    $user = $this->generateNewTestUser();
    $mail = id(new PhabricatorMetaMTAMail())
      ->addTos(array($user->getPHID()));
    $save = new ReflectionMethod('PhabricatorLiskDAO', 'save');
    $save->invoke($mail);
    $gorge = new class extends PhabricatorMailAdapter {
      const ADAPTERTYPE = 'gorge';
      public $requests = 0;
      public $body = '{"error":{"code":"ERR_SEND_FAILED","message":"not accepted"}}';
      public $code = 502;
      public function getSupportedMessageTypes() { return array('email'); }
      protected function validateOptions(array $options) {}
      public function newDefaultOptions() { return array(); }
      public function sendMessage(PhabricatorMailExternalMessage $message) {
        $this->requests++;
        $parser = new ReflectionMethod('PhabricatorGorgeMailerClient', 'parseSendResponse');
        $parser->invoke(null, 'http://fixture/api/mailer/send', array(
          new HTTPFutureHTTPResponseStatus($this->code, $this->body, array()),
          $this->body));
      }
    };
    $fallback = new PhabricatorMailTestAdapter();
    $caught = null;
    try { $mail->sendWithMailers(array($gorge, $fallback)); }
    catch (Exception $ex) { $caught = $ex; }
    $this->assertTrue($caught instanceof Exception);
    $this->assertFalse($caught instanceof PhabricatorMetaMTAUnknownOutcomeException);
    $this->assertEqual(PhabricatorMailOutboundStatus::STATUS_QUEUE,
      id(new PhabricatorMetaMTAMail())->load($mail->getID())->getStatus());
    $this->assertEqual(array(), $fallback->getGuts());
    $gorge->code = 200;
    $gorge->body = '{"data":{"mailerKey":"fixture"}}';
    $mail->sendWithMailers(array($gorge, $fallback));
    $this->assertEqual(2, $gorge->requests);
    $this->assertEqual(PhabricatorMailOutboundStatus::STATUS_SENT,
      id(new PhabricatorMetaMTAMail())->load($mail->getID())->getStatus());
  }
}
