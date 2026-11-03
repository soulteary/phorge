<?php

/** Actual native stop followed by a separate audit transaction; never recovery. */
function governance_recovery_stop_cases($fixture, callable $check) {
  $fixture->assertOwned();
  $cookies = $_COOKIE; $method = $_SERVER['REQUEST_METHOD'] ?? null;
  $environment = null; $trigger = false;
  $suffix = substr($fixture->getNamespace(), -10);
  $conn = (new PhabricatorUser())->establishConnection('w');
  $table = $fixture->table('user', 'recovery_stop');
  $audit_table = $fixture->table('user', 'recovery_stop_audit');
  $trigger_name = '`'.$fixture->getNamespace().'_user`.`recovery_stop_fault`';
  try {
    $actor = (new PhabricatorUser())->setUsername('stopactor'.$suffix)
      ->setRealName('Synthetic Stop Actor')->setIsAdmin(1)->setIsApproved(1)->save();
    $subject = (new PhabricatorUser())->setUsername('stopsubject'.$suffix)
      ->setRealName('Synthetic Stop Subject')->setIsApproved(1)->save();
    $ordinary = (new PhabricatorUser())->setUsername('stopordinary'.$suffix)
      ->setRealName('Synthetic Stop Ordinary')->setIsApproved(1)->save();
    $sessions = new PhabricatorAuthSessionEngine();
    $auth = governance_native_request($actor, $sessions);
    $member = governance_native_request($ordinary, $sessions);
    $context = $auth['context']; $target = $subject->getPHID();
    $operation = 'native-stop-operation-'.$suffix;
    $reason = 'Synthetic scheduled maintenance';
    $record = static function($event, $op = null, $category = 'technical-pause')
      use ($context, $target, $operation, $reason) {
      return PhorgeRecoveryStopService::recordAlreadyStopped($context, $target,
        $event, $category, $reason, $op ?? $operation);
    };
    $refuse = static function($call, $message, $label) use ($check, $conn) {
      $refused = false;
      try { $call(); }
      catch (RuntimeException $error) {
        if (get_class($error) !== RuntimeException::class || $error->getMessage() !== $message) { throw $error; }
        $refused = true;
      }
      $check($refused && !$conn->isInsideTransaction(), $label);
    };
    $flag_off = PhabricatorEnv::beginScopedEnv()
      ->overrideEnvConfig('governance.recovery-stop-ledger.enabled', false);
    try {
      $refuse(static function() use ($record) { $record('1'); }, 'recovery_stop_profile_unavailable',
        'recovery-stop-disabled-candidate-refuses-before-transaction');
    } finally { unset($flag_off); }
    $environment = PhabricatorEnv::beginScopedEnv()
      ->overrideEnvConfig('governance.recovery-stop-ledger.enabled', true);
    $transition = static function($disabled) use ($subject, $actor, $target, $conn) {
      $fresh = (new PhabricatorUser())->load($subject->getID());
      (new PhabricatorUserTransactionEditor())->setActor($actor)
        ->setContentSource(PhabricatorContentSource::newForSource('console', array()))
        ->applyTransactions($fresh, array((new PhabricatorUserTransaction())
          ->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue($disabled)));
      $row = queryfx_one($conn, 'SELECT id,objectPHID,authorPHID,transactionType,oldValue,newValue FROM %R '.
        'WHERE BINARY objectPHID=BINARY %s AND transactionType=%s ORDER BY id DESC LIMIT 1',
        new PhabricatorUserTransaction(), $target, PhabricatorUserDisableTransaction::TRANSACTIONTYPE);
      if (!$row || $conn->isInsideTransaction()) { throw new RuntimeException('recovery_stop_fixture_native_commit_required'); }
      return $row;
    };
    $event = $transition(true); $id = (string)$event['id'];
    $check($event['objectPHID'] === $target && $event['oldValue'] === 'false' && $event['newValue'] === 'true' &&
      (new PhabricatorUser())->load($subject->getID())->getIsDisabled(),
      'recovery-stop-actual-native-disable-committed-before-classification');
    $result = $record($id);
    $stored = queryfx_one($conn, 'SELECT * FROM recovery_stop WHERE nativeTransactionID=%s', $id);
    $audit = queryfx_one($conn, 'SELECT * FROM recovery_stop_audit WHERE nativeTransactionID=%s', $id);
    $check($result === array('native_transaction_id' => $id, 'replayed' => false, 'recovery_authorized' => false) &&
      $stored['subjectPHID'] === $target && $stored['reason'] === $reason && $stored['category'] === 'technical-pause' &&
      $audit['requestDigest'] === $stored['requestDigest'] && $audit['eventDigest'] === $stored['eventDigest'] &&
      $audit['operationID'] === $stored['operationID'], 'recovery-stop-real-ledger-and-audit-commit-without-recovery-grant');
    $replay = $record($id);
    $check($replay === array('native_transaction_id' => $id, 'replayed' => true, 'recovery_authorized' => false) &&
      queryfx_one($conn, 'SELECT * FROM recovery_stop WHERE nativeTransactionID=%s', $id) === $stored &&
      queryfx_one($conn, 'SELECT * FROM recovery_stop_audit WHERE nativeTransactionID=%s', $id) === $audit,
      'recovery-stop-exact-operation-replay-does-not-duplicate-or-rewrite-audit');
    $refuse(static function() use ($record, $id) { $record($id, null, 'security'); },
      'recovery_stop_operation_conflict', 'recovery-stop-conflicting-operation-payload-refused');
    $refuse(static function() use ($record, $id, $operation) { $record($id, $operation.'-another'); },
      'recovery_stop_already_classified', 'recovery-stop-existing-classification-not-silently-replaced');
    $refuse(static function() use ($member, $target, $id, $reason, $operation) {
      PhorgeRecoveryStopService::recordAlreadyStopped($member['context'], $target, $id,
        'technical-pause', $reason, $operation);
    }, 'recovery_stop_admin_required', 'recovery-stop-current-ordinary-user-cannot-replay-admin-operation');
    $history = PhorgeRecoveryStopService::history($context, $target, '0', 1);
    $check(count($history) === 1 && (string)$history[0]['nativeTransactionID'] === $id &&
      $history[0]['category'] === 'technical-pause' &&
      PhorgeRecoveryStopService::history($context, $target, $id, 1) === array(),
      'recovery-stop-current-admin-history-has-bounded-native-id-pagination');
    $refuse(static function() use ($member, $target) {
      PhorgeRecoveryStopService::history($member['context'], $target);
    }, 'recovery_stop_admin_required', 'recovery-stop-history-not-visible-to-ordinary-user');
    $transition(false);
    $refuse(static function() use ($record, $id) { $record($id); },
      'recovery_stop_target_not_disabled', 'recovery-stop-active-account-not-classified-as-stopped');
    $new_event = $transition(true); $new_id = (string)$new_event['id'];
    $refuse(static function() use ($record, $id) { $record($id); },
      'recovery_stop_event_unknown', 'recovery-stop-old-event-rejected-after-real-disable-enable-disable');
    // A real SQL failure occurs after ledger insertion, before the audit can
    // persist. The earlier native disable is an independent committed fact.
    governance_native_setup_query($fixture, 'CREATE TRIGGER '.$trigger_name.' BEFORE INSERT ON '.$audit_table.
      " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic stop audit failure'");
    $trigger = true; $failed = false;
    try { $record($new_id, $operation.'-fault'); }
    catch (AphrontQueryException $error) {
      if ($error->getCode() !== 1644) { throw $error; } $failed = true;
    } finally {
      governance_native_setup_query($fixture, 'DROP TRIGGER '.$trigger_name); $trigger = false;
    }
    $check($failed && !$conn->isInsideTransaction() &&
      queryfx_one($conn, 'SELECT * FROM recovery_stop WHERE nativeTransactionID=%s', $new_id) === null &&
      queryfx_one($conn, 'SELECT * FROM recovery_stop_audit WHERE nativeTransactionID=%s', $new_id) === null &&
      (new PhabricatorUser())->load($subject->getID())->getIsDisabled() &&
      queryfx_one($conn, 'SELECT id,newValue FROM %R WHERE id=%s', new PhabricatorUserTransaction(), $new_id)['newValue'] === 'true',
      'recovery-stop-real-audit-fault-rolls-back-ledger-but-preserves-native-emergency-stop');
    $conn->openTransaction();
    try {
      queryfx($conn, 'UPDATE %R SET realName=%s WHERE id=%d', new PhabricatorUser(), 'Synthetic caller write', $subject->getID());
      $refused = false;
      try { $record($new_id, $operation.'-outer'); }
      catch (RuntimeException $error) {
        if ($error->getMessage() !== 'recovery_stop_writer_unavailable') { throw $error; } $refused = true;
      }
      $check($refused && $conn->isInsideTransaction() &&
        queryfx_one($conn, 'SELECT realName FROM %R WHERE id=%d', new PhabricatorUser(), $subject->getID())['realName'] === 'Synthetic caller write',
        'recovery-stop-refusal-preserves-unrelated-native-outer-transaction');
    } finally { $conn->killTransaction(); }
    // Revoke the actual credential rather than mutating the cached viewer.
    $sessions->logoutSession($actor, $auth['viewer']->getSession());
    $refuse(static function() use ($record, $new_id, $operation) { $record($new_id, $operation.'-logout'); },
      'governance_native_credential_ineligible', 'recovery-stop-actual-session-logout-refuses-classification');
    $refuse(static function() use ($context, $target) { PhorgeRecoveryStopService::history($context, $target); },
      'governance_native_credential_ineligible', 'recovery-stop-actual-session-logout-refuses-history');
    return array('actual_native_disable_then_separate_ledger' => true, 'actual_audit_failure_rollback' => true,
      'emergency_disable_depends_on_ledger' => false, 'recovery_authorized' => false,
      'concurrent_writer_proof' => false, 'production_entry_registered' => false);
  } finally {
    if ($trigger) { governance_native_setup_query($fixture, 'DROP TRIGGER '.$trigger_name); }
    unset($environment); $_COOKIE = $cookies;
    if ($method === null) { unset($_SERVER['REQUEST_METHOD']); } else { $_SERVER['REQUEST_METHOD'] = $method; }
  }
}
