<?php

/** Native current locking reads on the organization's physical user writer. */
final class PhorgeGovernanceNativeIdentitySource extends Phobject {
  private $connection;
  private $data;
  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('governance_identity_serialization_refused'); }
  public function __unserialize(array $data) { throw new RuntimeException('governance_identity_serialization_refused'); }
  public function __debugInfo() { return array('scope' => 'native-current-source'); }

  private static function assertConnection(AphrontDatabaseConnection $conn) {
    $project_scope = class_exists('PhorgeProjectNativeExecutionScope', false) &&
      PhorgeProjectNativeExecutionScope::isSourceConnection($conn);
    $task_grant_scope=class_exists('PhorgeTaskContentGrantWriteScope',false) &&
      PhorgeTaskContentGrantWriteScope::isSourceConnection($conn);
    if ($project_scope && $task_grant_scope) { throw new RuntimeException('governance_mixed_native_writer_scope_refused'); }
    if (get_class($conn) !== AphrontMySQLiDatabaseConnection::class ||
        !$conn->isInsideTransaction() ||
        (!$project_scope && !$task_grant_scope && ($conn !== (new PhabricatorUser())->establishConnection('w') ||
          $conn !== (new PhabricatorAuthSession())->establishConnection('w') ||
          $conn !== (new PhabricatorUserTransaction())->establishConnection('w')))) {
      throw new RuntimeException('governance_same_native_writer_required');
    }
    $tables = array(new PhabricatorUser(), new PhabricatorAuthSession(), new PhabricatorUserTransaction());
    $epoch_table = new PhorgeGovernanceIdentityEpoch();
    if (!$project_scope && !$task_grant_scope && $conn !== $epoch_table->establishConnection('w')) {
      throw new RuntimeException('governance_same_native_writer_required');
    }
    $epoch_schema = $project_scope
      ? PhorgeProjectNativeExecutionScope::sourceDatabase($conn, $epoch_table)
      : ($task_grant_scope ? PhorgeTaskContentGrantWriteScope::sourceDatabase($conn, $epoch_table) : queryfx_one($conn, 'SELECT DATABASE() n')['n']);
    queryfx_one($conn, 'SELECT userPHID FROM %R WHERE 1=0 LOCK IN SHARE MODE', $epoch_table);
    $epoch_engine = queryfx_one($conn, 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', $epoch_schema, $epoch_table->getTableName());
    if (!$epoch_engine || $epoch_engine['ENGINE'] !== 'InnoDB') {
      throw new RuntimeException('governance_native_transactional_source_required');
    }
    foreach ($tables as $table) {
      $schema = $project_scope
        ? PhorgeProjectNativeExecutionScope::sourceDatabase($conn, $table)
        : ($task_grant_scope ? PhorgeTaskContentGrantWriteScope::sourceDatabase($conn, $table) : queryfx_one($conn, 'SELECT DATABASE() n')['n']);
      queryfx_one($conn, 'SELECT id FROM %R WHERE 1=0 LOCK IN SHARE MODE', $table);
      $engine = queryfx_one($conn,
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
        $schema, $table->getTableName());
      if (!$engine || $engine['ENGINE'] !== 'InnoDB') {
        throw new RuntimeException('governance_native_transactional_source_required');
      }
    }
  }

  public static function lockIdentityPHID($phid, AphrontDatabaseConnection $conn) {
    self::assertConnection($conn);
    if (!is_string($phid) || !preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/', $phid)) {
      throw new RuntimeException('governance_native_identity_required');
    }
    $row = queryfx_one($conn, 'SELECT phid,isAdmin,isApproved,isDisabled,isSystemAgent,isMailingList,accountSecret,conduitCertificate FROM %R WHERE BINARY phid=BINARY %s LOCK IN SHARE MODE',
      new PhabricatorUser(), $phid);
    if (!$row || $row['phid'] !== $phid) {
      throw new RuntimeException('governance_native_identity_required');
    }
    foreach (array('isAdmin','isApproved','isDisabled','isSystemAgent','isMailingList') as $field) {
      if (!in_array($row[$field], array(0,1,'0','1'), true)) {
        throw new RuntimeException('governance_native_identity_shape');
      }
    }
    $generation = queryfx_one($conn,
      'SELECT id FROM %R WHERE BINARY objectPHID=BINARY %s ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE',
      new PhabricatorUserTransaction(), $phid);
    $epoch = $generation ? (string)$generation['id'] : '0';
    if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/', $epoch)) {
      throw new RuntimeException('governance_native_generation_unknown');
    }
    $legacy = queryfx_one($conn,
      'SELECT userPHID,epoch FROM %R WHERE BINARY userPHID=BINARY %s LOCK IN SHARE MODE',
      new PhorgeGovernanceIdentityEpoch(), $phid);
    if ($legacy !== null && ($legacy['userPHID'] !== $phid ||
        !preg_match('/\A[1-9][0-9]*\z/', (string)$legacy['epoch']))) {
      throw new RuntimeException('governance_native_generation_unknown');
    }
    $identity_digest = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    $binding_epoch = $legacy === null ? null : hash('sha256', json_encode(array('nativeTransactionID'=>$epoch,
      'legacyRoleEpoch'=>(string)$legacy['epoch'], 'identityDigest'=>$identity_digest), JSON_THROW_ON_ERROR));
    return array('userPHID' => $phid,
      'eligible' => (string)$row['isApproved']==='1' && (string)$row['isDisabled']==='0' &&
        (string)$row['isSystemAgent']==='0' && (string)$row['isMailingList']==='0',
      'isAdmin' => (string)$row['isAdmin']==='1', 'accountEpoch' => $binding_epoch,
      'digest' => $identity_digest);
  }

  private static function initializeIssuerEpoch($user, AphrontDatabaseConnection $conn) {
    // Task grant governance is read-only for People: never initialize a fourth table.
    if (class_exists('PhorgeTaskContentGrantWriteScope',false) &&
        PhorgeTaskContentGrantWriteScope::isSourceConnection($conn)) {
      throw new RuntimeException('task_grant_existing_issuer_epoch_required');
    }
    // Called only after the actual credential row and native account are locked
    // and validated. This is explicit metadata initialization, never a read-only
    // observation or a missing-table fallback.
    queryfx($conn, 'INSERT IGNORE INTO %R (userPHID,epoch) VALUES (%s,1)',
      new PhorgeGovernanceIdentityEpoch(), $user);
    $identity = self::lockIdentityPHID($user, $conn);
    if ($identity['accountEpoch'] === null) {
      throw new RuntimeException('governance_native_generation_unknown');
    }
    return $identity;
  }

  public static function lockCurrent(PhorgeGovernanceNativeActorContext $context,
    AphrontDatabaseConnection $conn) {
    if ($context->getCredentialType() === 'api-token') {
      return self::lockAPIToken($context, $conn);
    }
    return self::lockMediaBinding($context->getUserPHID(), $context->getSessionPHID(),
      $context->getCredentialDigest(), null, $conn);
  }

  private static function assertTokenConnection(AphrontDatabaseConnection $conn) {
    $table = new PhabricatorConduitToken();
    $token_connection = $table->establishConnection('w');
    if (get_class($token_connection) !== AphrontMySQLiDatabaseConnection::class) {
      throw new RuntimeException('governance_same_native_primary_required');
    }
    $primary = queryfx_one($conn, 'SELECT @@server_uuid AS uuid');
    $token_primary = queryfx_one($token_connection, 'SELECT @@server_uuid AS uuid,DATABASE() AS db');
    if (!$primary || !$token_primary || !is_string($primary['uuid']) ||
        $primary['uuid'] !== $token_primary['uuid']) {
      throw new RuntimeException('governance_same_native_primary_required');
    }
    queryfx_one($conn, 'SELECT id FROM %R WHERE 1=0 LOCK IN SHARE MODE', $table);
    $engine = queryfx_one($conn,
      'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
      $token_primary['db'], $table->getTableName());
    if (!$engine || $engine['ENGINE'] !== 'InnoDB') {
      throw new RuntimeException('governance_native_transactional_source_required');
    }
  }

  private static function lockAPIToken(PhorgeGovernanceNativeActorContext $context,
    AphrontDatabaseConnection $conn) {
    $identity = self::lockIdentityPHID($context->getUserPHID(), $conn);
    if (!$identity['eligible']) { throw new RuntimeException('governance_native_credential_ineligible'); }
    self::assertTokenConnection($conn);
    $table = new PhabricatorConduitToken();
    $secret = $context->getCredentialEnvelope()->openEnvelope();
    $row = queryfx_one($conn,
      'SELECT id,objectPHID,tokenType,token,expires FROM %R WHERE BINARY token=BINARY %s LOCK IN SHARE MODE',
      $table, $secret);
    $now = queryfx_one($conn, 'SELECT UNIX_TIMESTAMP() AS n');
    if (!$row || !hash_equals($row['token'], $secret) ||
        $row['objectPHID'] !== $identity['userPHID'] ||
        !in_array($row['tokenType'], array(PhabricatorConduitToken::TYPE_STANDARD,
          PhabricatorConduitToken::TYPE_COMMANDLINE), true) ||
        ($row['expires'] !== null && (int)$row['expires'] <= (int)$now['n'])) {
      throw new RuntimeException('governance_native_credential_ineligible');
    }
    if ($identity['accountEpoch'] === null) {
      $identity = self::initializeIssuerEpoch($identity['userPHID'], $conn);
    }
    $fresh = queryfx_one($conn, 'SELECT UNIX_TIMESTAMP() AS n');
    if ($row['expires'] !== null && (int)$row['expires'] <= (int)$fresh['n']) {
      throw new RuntimeException('governance_native_credential_ineligible');
    }
    $source = new self(); $source->connection = $conn;
    $source->data = array('userPHID'=>$identity['userPHID'], 'sessionPHID'=>'api-token:'.(string)$row['id'],
      'credentialDigest'=>hash('sha256', $secret), 'accountEpoch'=>$identity['accountEpoch'],
      'identityDigest'=>$identity['digest'], 'isAdmin'=>$identity['isAdmin'],
      'expiresAt'=>$row['expires'] === null ? null : (int)$row['expires'],
      'credentialType'=>'api-token');
    return PhorgeGovernanceNativeIdentitySnapshot::newFromSource($source);
  }

  public static function lockMediaBinding($user, $session, $digest, $epoch,
    AphrontDatabaseConnection $conn) {
    $identity = self::lockIdentityPHID($user, $conn);
    if (!$identity['eligible'] || !is_string($session) ||
        !preg_match('/\APHID-SSSN-[a-zA-Z0-9]{20}\z/', $session) ||
        !is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/', $digest) ||
        ($epoch !== null && $epoch !== $identity['accountEpoch'])) {
      throw new RuntimeException('governance_native_credential_ineligible');
    }
    // The PHID lookup is non-authoritative planning, not a locking scan.
    // Current-lock only that native PRIMARY row: revalidation must not touch
    // unrelated uncommitted sessions created by another native producer.
    $session_table = new PhabricatorAuthSession();
    $plan = queryfx_one($conn, 'SELECT id FROM %R WHERE phid=%s LIMIT 2',
      $session_table, $session);
    if (!$plan || !preg_match('/\A[1-9][0-9]{0,9}\z/D', (string)$plan['id']) ||
        (int)$plan['id'] > 4294967295) {
      throw new RuntimeException('governance_native_credential_ineligible');
    }
    $row = queryfx_one($conn,
      'SELECT id,phid,userPHID,type,sessionKey,sessionStart,sessionExpires,isPartial FROM %R FORCE INDEX (PRIMARY) WHERE id=%d LOCK IN SHARE MODE',
      $session_table, (int)$plan['id']);
    $now = queryfx_one($conn, 'SELECT UNIX_TIMESTAMP() AS n');
    if (!$row || (string)$row['id'] !== (string)$plan['id'] ||
        $row['phid'] !== $session || $row['userPHID'] !== $user ||
        $row['type'] !== PhabricatorAuthSession::TYPE_WEB || (string)$row['isPartial'] !== '0' ||
        !hash_equals($digest, hash('sha256', $row['sessionKey'])) ||
        (int)$row['sessionStart'] > (int)$now['n'] || (int)$row['sessionExpires'] <= (int)$now['n']) {
      throw new RuntimeException('governance_native_credential_ineligible');
    }
    if ($identity['accountEpoch'] === null) {
      if ($epoch !== null) { throw new RuntimeException('governance_native_generation_unknown'); }
      $identity = self::initializeIssuerEpoch($user, $conn);
    }
    $fresh = queryfx_one($conn, 'SELECT UNIX_TIMESTAMP() AS n');
    if ((int)$row['sessionExpires'] <= (int)$fresh['n']) {
      throw new RuntimeException('governance_native_credential_ineligible');
    }
    $source = new self(); $source->connection = $conn;
    $source->data = array('userPHID'=>$user, 'sessionPHID'=>$session,
      'credentialDigest'=>$digest, 'accountEpoch'=>$identity['accountEpoch'],
      'identityDigest'=>$identity['digest'], 'isAdmin'=>$identity['isAdmin'],
      'expiresAt'=>(int)$row['sessionExpires'], 'credentialType'=>'web');
    return PhorgeGovernanceNativeIdentitySnapshot::newFromSource($source);
  }

  /** Recheck a durable public binding; this never creates an ActorContext grant. */
  public static function lockCredentialBinding(array $binding,
    AphrontDatabaseConnection $conn) {
    $keys = array_keys($binding); sort($keys);
    if ($keys !== array('accountEpoch','credentialDigest','credentialType','expiresAt',
      'identityDigest','isAdmin','sessionPHID','userPHID')) {
      throw new RuntimeException('governance_native_binding_shape');
    }
    if ($binding['credentialType'] === 'web') {
      $snapshot = self::lockMediaBinding($binding['userPHID'], $binding['sessionPHID'],
        $binding['credentialDigest'], $binding['accountEpoch'], $conn);
    } else if ($binding['credentialType'] === 'api-token') {
      $identity = self::lockIdentityPHID($binding['userPHID'], $conn);
      self::assertTokenConnection($conn);
      if (!$identity['eligible'] || $identity['accountEpoch'] === null ||
          $identity['accountEpoch'] !== $binding['accountEpoch'] ||
          !is_string($binding['sessionPHID']) ||
          !preg_match('/\Aapi-token:[1-9][0-9]{0,9}\z/', $binding['sessionPHID']) ||
          !is_string($binding['credentialDigest']) ||
          !preg_match('/\A[a-f0-9]{64}\z/', $binding['credentialDigest'])) {
        throw new RuntimeException('governance_native_binding_shape');
      }
      // This current read is through the same native user writer. The token
      // adapter below independently verifies configured-primary UUID and engine.
      $row = queryfx_one($conn, 'SELECT id,objectPHID,tokenType,token,expires FROM %R WHERE id=%s LOCK IN SHARE MODE',
        new PhabricatorConduitToken(), substr($binding['sessionPHID'],10));
      if (!$row || ('api-token:'.(string)$row['id']) !== $binding['sessionPHID'] ||
          $row['objectPHID'] !== $binding['userPHID'] ||
          !hash_equals($binding['credentialDigest'], hash('sha256',$row['token']))) {
        throw new RuntimeException('governance_native_credential_ineligible');
      }
      $now = queryfx_one($conn, 'SELECT UNIX_TIMESTAMP() AS n');
      if (!in_array($row['tokenType'], array(PhabricatorConduitToken::TYPE_STANDARD,
          PhabricatorConduitToken::TYPE_COMMANDLINE), true) ||
          ($row['expires'] !== null && (int)$row['expires'] <= (int)$now['n'])) {
        throw new RuntimeException('governance_native_credential_ineligible');
      }
      $source = new self(); $source->connection = $conn;
      $source->data = array('userPHID'=>$identity['userPHID'],
        'sessionPHID'=>'api-token:'.(string)$row['id'],
        'credentialDigest'=>hash('sha256',$row['token']),
        'accountEpoch'=>$identity['accountEpoch'], 'identityDigest'=>$identity['digest'],
        'isAdmin'=>$identity['isAdmin'],
        'expiresAt'=>$row['expires']===null ? null : (int)$row['expires'],
        'credentialType'=>'api-token');
      $snapshot = PhorgeGovernanceNativeIdentitySnapshot::newFromSource($source);
    } else {
      throw new RuntimeException('governance_native_credential_mode_unsupported');
    }
    if (!hash_equals(hash('sha256',json_encode($binding,JSON_THROW_ON_ERROR)),
        $snapshot->getBindingDigest())) {
      // Stored JSON key ordering is not an authority change.
      $current = $snapshot->getSource()->getSnapshotData();
      ksort($current); ksort($binding);
      if ($current !== $binding) { throw new RuntimeException('governance_native_source_changed'); }
    }
    return $snapshot;
  }

  public function getSnapshotData() { return $this->data; }

  public static function revalidate(PhorgeGovernanceNativeIdentitySnapshot $snapshot,
    AphrontDatabaseConnection $conn) {
    $source = $snapshot->getSource();
    if ($source->connection !== $conn) {
      throw new RuntimeException('governance_native_source_connection_changed');
    }
    if ($source->data['credentialType'] === 'api-token') {
      $current = self::lockCredentialBinding($source->data, $conn);
    } else {
      $current = self::lockMediaBinding($snapshot->getUserPHID(), $snapshot->getSessionPHID(),
        $snapshot->getCredentialDigest(), $snapshot->getAccountEpoch(), $conn);
    }
    if (!hash_equals($snapshot->getBindingDigest(), $current->getBindingDigest())) {
      throw new RuntimeException('governance_native_source_changed');
    }
    return $current;
  }
}
