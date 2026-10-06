<?php

final class PhabricatorMailDeliveryConduitAPIMethod extends ConduitAPIMethod {
  public function getAPIMethodName() { return 'mail.delivery'; }
  public function getMethodDescription() {
    return pht('Prepare or apply an immutable Gorge email delivery.');
  }
  public function shouldRequireAuthentication() { return false; }
  public function shouldAllowUnguardedWrites() { return true; }
  protected function defineParamTypes() {
    return array('mailID' => 'required int', 'phase' => 'required string',
      'result' => 'optional string');
  }
  protected function defineReturnType() { return 'map<string, wild>'; }
  protected function defineErrorTypes() {
    return array('ERR-MAIL-AUTH' => pht('Service authentication required.'));
  }
  protected function execute(ConduitAPIRequest $request) {
    $expected = PhabricatorGorgeServiceRegistry::getService('conduit')
      ->getConfiguredToken();
    $actual = AphrontRequest::getHTTPHeader('X-Service-Token');
    if (!phutil_nonempty_string($expected) ||
        !phutil_nonempty_string($actual) || !hash_equals($expected, $actual)) {
      throw new ConduitException('ERR-MAIL-AUTH');
    }
    $id = $request->getValue('mailID');
    $lock = PhabricatorGlobalLock::newLock('gorge-mail', array('id' => $id));
    $lock->lock(5);
    try {
      $mail = id(new PhabricatorMetaMTAMail())->load($id);
      if (!$mail) { throw new Exception(pht('Mail no longer exists.')); }
      switch ($request->getValue('phase')) {
        case 'prepare': return $mail->prepareGorgeDelivery();
        case 'authorize': return $mail->authorizeGorgeDelivery();
        case 'apply':
          $mail->applyGorgeDelivery(phutil_json_decode($request->getValue('result')));
          return array('applied' => true);
        default: throw new Exception(pht('Unknown mail delivery phase.'));
      }
    } finally { $lock->unlock(); }
  }
}
