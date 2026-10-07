<?php

final class PhabricatorIntegrationInboundConduitAPIMethod extends ConduitAPIMethod {
  public function getAPIMethodName() { return 'integration.inbound'; }
  public function getMethodDescription() { return pht('Apply a durably accepted inbound email.'); }
  public function shouldRequireAuthentication() { return false; }
  protected function defineParamTypes() {
    return array('eventID' => 'required string', 'message' => 'required wild',
      'phase' => 'optional string', 'evidence' => 'optional string');
  }
  protected function defineReturnType() { return 'map<string, wild>'; }
  public static function assertToken() {
    $expected = PhabricatorGorgeServiceRegistry::getService('conduit')->getConfiguredToken();
    $actual = AphrontRequest::getHTTPHeader('X-Service-Token');
    if (!phutil_nonempty_string($expected) || !phutil_nonempty_string($actual) ||
        !phutil_hashes_are_identical($expected, $actual)) {
      throw new Exception(pht('Invalid integration source token.'));
    }
  }
  protected function execute(ConduitAPIRequest $request) {
    self::assertToken();
    $id = $request->getValue('eventID');
    $message = $request->getValue('message');
    if (!preg_match('/^[a-f0-9]{64}$/D', $id) || !is_array($message) ||
        !PhabricatorGorgeIntegrationClient::enabled('inbound', idx($message, 'provider'))) {
      throw new Exception(pht('Inbound domain or identity is invalid.'));
    }
    $attachments = idx($message, 'attachments');
    if ($attachments === null) { $attachments = array(); }
    if (!is_array($attachments)) { throw new Exception(pht('Invalid inbound attachments.')); }
    $phase = $request->getValue('phase');
    if ($phase !== null && $phase !== '' && $phase !== 'reconcile') {
      throw new Exception(pht('Invalid inbound receipt phase.'));
    }
    $hash = hash('sha256', phutil_json_encode($message));
    $table = new PhabricatorGorgeInboundReceipt();
    $conn = $table->establishConnection('w');
    $table->openTransaction();
    try {
      queryfx($conn, 'INSERT INTO %T
        (eventID,digest,state,mailID,dateCreated,dateModified)
        VALUES (%s,%s,%s,NULL,%d,%d) ON DUPLICATE KEY UPDATE id=id', $table->getTableName(),
        $id, $hash, 'prepared', time(), time());
      $row = queryfx_one($conn, 'SELECT * FROM %T WHERE eventID=%s FOR UPDATE',
        $table->getTableName(), $id);
      if ($row['digest'] !== $hash) { throw new Exception(pht('Inbound identity conflict.')); }
      if ($row['state'] === 'done') {
        $table->saveTransaction();
        return array('state' => 'done', 'mailID' => (int)$row['mailID']);
      }
      if ($request->getValue('phase') === 'reconcile') {
        $evidence = $request->getValue('evidence');
        if ($row['state'] !== 'processing' ||
            (int)$row['dateModified'] > time() - 120 ||
            !is_string($evidence) || strlen($evidence) < 16 || strlen($evidence) > 2048) {
          throw new Exception(pht('Receipt is active or reconciliation evidence is missing.'));
        }
        queryfx($conn, 'UPDATE %T SET state=%s,dateModified=%d WHERE eventID=%s',
          $table->getTableName(), 'done', time(), $id);
        $table->saveTransaction();
        return array('state' => 'done', 'mailID' => (int)$row['mailID']);
      }
      if ($row['state'] === 'processing') {
        $table->saveTransaction();
        return array('state' => 'unknown', 'mailID' => (int)$row['mailID']);
      }
      $mail = id(new PhabricatorMetaMTAReceivedMail())
        ->setHeaders(idx($message, 'headers', array()))
        ->setBodies(array('text' => idx($message, 'text'), 'html' => idx($message, 'html')));
      $phids = array();
      foreach ($attachments as $attachment) {
        $bytes = base64_decode(idx($attachment, 'data'), true);
        if ($bytes === false) { throw new Exception(pht('Invalid attachment encoding.')); }
        $file = PhabricatorFile::newFromFileData($bytes, array(
          'name' => idx($attachment, 'name'), 'viewPolicy' => PhabricatorPolicies::POLICY_NOONE));
        $phids[] = $file->getPHID();
      }
      $mail->setAttachments($phids)->save();
      queryfx($conn, 'UPDATE %T SET state=%s,mailID=%d,dateModified=%d WHERE eventID=%s',
        $table->getTableName(), 'processing', $mail->getID(), time(), $id);
      $table->saveTransaction();
    } catch (Throwable $ex) {
      $table->killTransaction();
      throw $ex;
    }
    // Mark first, then execute business work. If interrupted, do not blindly replay
    // commands whose effects may already have committed in another database.
    $mail->processReceivedMail();
    queryfx($conn, 'UPDATE %T SET state=%s,dateModified=%d WHERE eventID=%s',
      $table->getTableName(), 'done', time(), $id);
    return array('state' => 'done', 'mailID' => $mail->getID());
  }
}
