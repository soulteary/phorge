<?php

/** Attest to a committed native stop. No account transition or recovery grant. */
final class PhorgeRecoveryStopService extends Phobject {
  private static function profile() {
    if (PhabricatorEnv::getEnvConfig('governance.recovery-stop-ledger.enabled') !== true ||
        PhabricatorEnv::getEnvConfig('cluster.databases') !== array() ||
        !in_array(PhabricatorEnv::getEnvConfig('gorge.db.uri'), array('', null), true) ||
        PhabricatorEnv::isReadOnly() !== false ||
        LiskDAO::shouldIsolateAllLiskEffectsToCurrentProcess() ||
        LiskDAO::shouldIsolateAllLiskEffectsToTransactions() ||
        (new ReflectionProperty(PhabricatorLiskDAO::class, 'namespaceStack'))->getValue() !== array()) {
      throw new RuntimeException('recovery_stop_profile_unavailable');
    }
  }

  private static function transaction(PhorgeGovernanceNativeActorContext $context,
    array $subjects, callable $work) {
    self::profile();
    $user = new PhabricatorUser();
    $conn = $user->establishConnection('w');
    if (get_class($conn) !== AphrontMySQLiDatabaseConnection::class ||
        $conn !== (new PhorgeRecoveryStop())->establishConnection('w') ||
        $conn->isInsideTransaction() || $conn->isReadLocking() || $conn->isWriteLocking() ||
        (new ReflectionProperty(LiskDAO::class, 'forcedConnection'))->getValue($user) !== null) {
      throw new RuntimeException('recovery_stop_writer_unavailable');
    }
    $default = queryfx_one($conn, 'SELECT @@SESSION.transaction_isolation level');
    $restore = array('READ-UNCOMMITTED' => 'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
      'READ-COMMITTED' => 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
      'REPEATABLE-READ' => 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
      'SERIALIZABLE' => 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    if (!$default || !isset($restore[$default['level']])) {
      throw new RuntimeException('recovery_stop_isolation_unknown');
    }
    $owned = false; $queued = false;
    try {
      // Reject an untracked raw START TRANSACTION before taking ownership.
      queryfx($conn, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); $queued = true;
      $conn->openTransaction(); $owned = true; $queued = false;
      $database = queryfx_one($conn, 'SELECT DATABASE() db,@@server_uuid uuid');
      if (!$database || $database['db'] !== $user->getAphrontRefDatabaseName() ||
          !is_string($database['uuid']) || $database['uuid'] === '') {
        throw new RuntimeException('recovery_stop_writer_unavailable');
      }
      $subjects[] = $context->getUserPHID();
      $subjects = array_unique($subjects); sort($subjects, SORT_STRING);
      // Serialize writers by actual account rows in one canonical order, before
      // credentials, stop events or idempotency rows. Never upgrade shared locks.
      foreach ($subjects as $subject) {
        PhorgeRecoveryStopRequest::user($subject);
        $row = queryfx_one($conn, 'SELECT phid FROM %R WHERE BINARY phid=BINARY %s FOR UPDATE',
          $user, $subject);
        if (!$row || $row['phid'] !== $subject) {
          throw new RuntimeException('recovery_stop_subject_unavailable');
        }
      }
      $snapshot = PhorgeGovernanceNativeIdentitySource::lockCurrent($context, $conn);
      if (!$snapshot->isSystemAdmin()) { throw new RuntimeException('recovery_stop_admin_required'); }
      foreach (array('recovery_stop', 'recovery_stop_audit') as $table) {
        queryfx_one($conn, 'SELECT nativeTransactionID FROM %T WHERE 1=0 LOCK IN SHARE MODE', $table);
        $engine = queryfx_one($conn, 'SELECT ENGINE FROM information_schema.TABLES '.
          'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table);
        if (!$engine || $engine['ENGINE'] !== 'InnoDB') {
          throw new RuntimeException('recovery_stop_schema_unavailable');
        }
      }
      $result = $work($conn, $snapshot);
      self::profile();
      $current = PhorgeGovernanceNativeIdentitySource::revalidate($snapshot, $conn);
      if (!$current->isSystemAdmin()) { throw new RuntimeException('recovery_stop_admin_required'); }
      $conn->saveTransaction(); $owned = false;
      return $result;
    } catch (Throwable $error) {
      if ($owned && $conn->isInsideTransaction()) { $conn->killTransaction(); }
      throw $error;
    } finally {
      if ($queued) { queryfx($conn, $restore[$default['level']]); }
    }
  }

  private static function event(AphrontDatabaseConnection $conn, array $request) {
    $subject = $request['subject'];
    $identity = PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($subject, $conn);
    $row = queryfx_one($conn, 'SELECT phid,isDisabled FROM %R WHERE BINARY phid=BINARY %s LOCK IN SHARE MODE',
      new PhabricatorUser(), $subject);
    if (!$row || $row['phid'] !== $subject || !in_array($row['isDisabled'], array(1, '1'), true)) {
      throw new RuntimeException('recovery_stop_target_not_disabled');
    }
    $event = queryfx_one($conn, 'SELECT id,objectPHID,authorPHID,transactionType,oldValue,newValue FROM %R '.
      'WHERE BINARY objectPHID=BINARY %s AND transactionType=%s ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE',
      new PhabricatorUserTransaction(), $subject, PhabricatorUserDisableTransaction::TRANSACTIONTYPE);
    if (!$event) { throw new RuntimeException('recovery_stop_native_event_missing'); }
    return array('event' => PhorgeRecoveryStopRequest::event($event, $subject,
      $request['native_disable_transaction']), 'identity' => $identity['digest']);
  }

  public static function recordAlreadyStopped(PhorgeGovernanceNativeActorContext $context,
    $subject, $transaction, $category, $reason, $operation) {
    $request = PhorgeRecoveryStopRequest::validate($subject, $transaction, $category, $reason, $operation);
    return self::transaction($context, array($subject), static function($conn, $actor) use ($request) {
      $source = self::event($conn, $request);
      $digest = hash('sha256', json_encode(array($request, $actor->getUserPHID(), $source['event']), JSON_THROW_ON_ERROR));
      $previous = queryfx_one($conn, 'SELECT nativeTransactionID,requestDigest FROM recovery_stop '.
        'WHERE BINARY operationID=BINARY %s FOR UPDATE', $request['operation_id']);
      $replayed = false;
      if ($previous) {
        if ((string)$previous['nativeTransactionID'] !== $request['native_disable_transaction'] ||
            !hash_equals($digest, $previous['requestDigest'])) {
          throw new RuntimeException('recovery_stop_operation_conflict');
        }
        $replayed = true;
      } else {
        $existing = queryfx_one($conn, 'SELECT nativeTransactionID FROM recovery_stop '.
          'WHERE nativeTransactionID=%s FOR UPDATE', $request['native_disable_transaction']);
        if ($existing) { throw new RuntimeException('recovery_stop_already_classified'); }
        $now = queryfx_one($conn, 'SELECT UNIX_TIMESTAMP() n')['n'];
        queryfx($conn, 'INSERT INTO recovery_stop (nativeTransactionID,subjectPHID,actorPHID,category,reason,'.
          'operationID,eventDigest,requestDigest,createdAt) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)',
          $request['native_disable_transaction'], $request['subject'], $actor->getUserPHID(), $request['category'],
          $request['reason'], $request['operation_id'], $source['event'], $digest, $now);
        queryfx($conn, 'INSERT INTO recovery_stop_audit (nativeTransactionID,operationID,actorPHID,'.
          'requestDigest,eventDigest,createdAt) VALUES (%s,%s,%s,%s,%s,%s)',
          $request['native_disable_transaction'], $request['operation_id'], $actor->getUserPHID(),
          $digest, $source['event'], $now);
        PhorgeGovernanceNoticeOutbox::recoveryStoppedAudit($conn,$actor,$request['native_disable_transaction']);
      }
      // Audit insertion can wait or fail. Recheck both physical rows, the native
      // stop source and the actor credential before the owned commit.
      $record = queryfx_one($conn, 'SELECT * FROM recovery_stop WHERE nativeTransactionID=%s FOR UPDATE',
        $request['native_disable_transaction']);
      $audit = queryfx_one($conn, 'SELECT * FROM recovery_stop_audit WHERE nativeTransactionID=%s FOR UPDATE',
        $request['native_disable_transaction']);
      if (!$record || !$audit || (string)$record['createdAt'] !== (string)$audit['createdAt'] ||
          $record['subjectPHID'] !== $request['subject'] ||
          $record['category'] !== $request['category'] || $record['reason'] !== $request['reason']) {
        throw new RuntimeException('recovery_stop_audit_inconsistent');
      }
      foreach (array($record, $audit) as $row) {
        if ((string)$row['nativeTransactionID'] !== $request['native_disable_transaction'] ||
            $row['operationID'] !== $request['operation_id'] || $row['actorPHID'] !== $actor->getUserPHID() ||
            !hash_equals($digest, $row['requestDigest']) || !hash_equals($source['event'], $row['eventDigest'])) {
          throw new RuntimeException('recovery_stop_audit_inconsistent');
        }
      }
      if (self::event($conn, $request) !== $source) { throw new RuntimeException('recovery_stop_source_changed'); }
      return array('native_transaction_id' => $request['native_disable_transaction'],
        'replayed' => $replayed, 'recovery_authorized' => false);
    });
  }

  public static function history(PhorgeGovernanceNativeActorContext $context, $subject, $after = '0', $limit = 25) {
    PhorgeRecoveryStopRequest::user($subject);
    if ($after !== '0') { $after = PhorgeRecoveryStopRequest::transactionID($after); }
    if (!is_int($limit) || $limit < 1 || $limit > 100) {
      throw new InvalidArgumentException('recovery_stop_page_invalid');
    }
    return self::transaction($context, array(), static function($conn, $actor) use ($subject, $after, $limit) {
      return queryfx_all($conn, 'SELECT nativeTransactionID,subjectPHID,actorPHID,category,reason,createdAt '.
        'FROM recovery_stop WHERE BINARY subjectPHID=BINARY %s AND nativeTransactionID>%s '.
        'ORDER BY nativeTransactionID LIMIT %d LOCK IN SHARE MODE', $subject, $after, $limit);
    });
  }
}
