<?php

/** Session-bound exchange backend. No route, cookie writer, or file authority. */
final class PhorgeGovernanceMediaExchangeService extends Phobject {

  const AUDIENCE = 'phorge-files';
  const PURPOSE = 'private-media';
  const TRANSACTION_TTL = 300;
  const CODE_TTL = 120;
  const CREDENTIAL_TTL = 3600;
  const BROWSER_TTL = 86400;

  public function __debugInfo() {
    return array('scope' => 'session-bound-media-exchange');
  }

  public static function validateSecret($value) {
    if (!is_string($value) || !preg_match('/\A[a-f0-9]{64}\z/D', $value)) {
      throw new InvalidArgumentException('media_exchange_secret_refused');
    }
    return $value;
  }

  public static function nextBrowserGeneration($value) {
    if ((!is_string($value) && !is_int($value)) ||
        !preg_match('/\A[1-9][0-9]*\z/D', (string)$value)) {
      throw new InvalidArgumentException('media_browser_generation_refused');
    }
    $text=(string)$value; $maximum=(string)PHP_INT_MAX;
    if (strlen($text)>strlen($maximum) ||
        (strlen($text)===strlen($maximum) && strcmp($text,$maximum)>=0)) {
      throw new InvalidArgumentException('media_browser_generation_refused');
    }
    return (int)$text+1;
  }

  public static function validateTarget($audience, $purpose, $path) {
    if ($audience !== self::AUDIENCE || $purpose !== self::PURPOSE ||
        !is_string($path) || strlen($path) > 1024 ||
        !preg_match('@\A/file/(?:data|xform)/[a-zA-Z0-9_./-]+\z@D', $path) ||
        strpos($path, '..') !== false || strpos($path, '//') !== false ||
        preg_match('@(?:^|/)\.(?:/|$)@', $path)) {
      throw new InvalidArgumentException('media_exchange_target_refused');
    }
    return $path;
  }

  private static function enabled() {
    if (PhabricatorEnv::getEnvConfigIfExists('governance.media-exchange.enabled', false) !== true) {
      throw new RuntimeException('media_exchange_disabled');
    }
  }

  private static function now(AphrontDatabaseConnection $connection) {
    return (int)queryfx_one($connection, 'SELECT UNIX_TIMESTAMP() AS n')['n'];
  }

  private static function connection() {
    self::enabled();
    return id(new PhabricatorUser())->establishConnection('w');
  }

  private static function begin(AphrontDatabaseConnection $connection) {
    if (get_class($connection) !== AphrontMySQLiDatabaseConnection::class ||
        $connection->isInsideTransaction()) {
      throw new RuntimeException('media_exchange_transaction_context_refused');
    }
    $connection->openTransaction();
    try {
      foreach (array('governance_mediabrowser' => 'browserDigest',
        'governance_mediaexchange' => 'transactionDigest',
        'governance_mediacredential' => 'secretDigest') as $table => $key) {
        // Retain native metadata locks until commit/rollback before any mutation.
        queryfx_all($connection, 'SELECT %C FROM %T WHERE 1=0 LOCK IN SHARE MODE', $key, $table);
        $engine = queryfx_one($connection,
          'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table);
        if (!$engine || $engine['ENGINE'] !== 'InnoDB') {
          throw new RuntimeException('media_exchange_transactional_schema_required');
        }
      }
    } catch (Throwable $error) {
      $connection->killTransaction();
      throw new RuntimeException('media_exchange_transactional_schema_required');
    }
  }

  private static function browser(AphrontDatabaseConnection $connection, $digest, PhorgeGovernanceMediaIssuer $issuer) {
    $row = queryfx_one($connection,
      'SELECT * FROM %T WHERE browserDigest=%s FOR UPDATE',
      'governance_mediabrowser', $digest);
    if (!$row || !$issuer->matchesRow($row) ||
        (int)$row['expiresAt'] <= self::now($connection) ||
        !hash_equals($row['browserDigest'], $digest)) {
      throw new RuntimeException('media_exchange_browser_refused');
    }
    self::nextBrowserGeneration($row['generation']);
    return $row;
  }

  /** Anonymous transaction preparation grants no file or account access. */
  public function beginBrowserTransaction($browser_secret = null) {
    $connection = self::connection();
    $issuer = PhorgeGovernanceMediaIssuer::newFromConfiguration();
    $new_browser = ($browser_secret === null);
    $secret = $new_browser ? bin2hex(random_bytes(32)) : self::validateSecret($browser_secret);
    $digest = $issuer->digest('browser', $secret);
    $transaction = bin2hex(random_bytes(32));
    self::begin($connection);
    try {
      $now = self::now($connection);
      if ($new_browser) {
        queryfx($connection, 'INSERT INTO %T (browserDigest,generation,expiresAt,issuerIncarnation) VALUES (%s,1,%d,%s)',
          'governance_mediabrowser', $digest, $now + self::BROWSER_TTL, $issuer->getIncarnation());
      }
      $browser = self::browser($connection, $digest, $issuer);
      $expires = min($now + self::TRANSACTION_TTL, (int)$browser['expiresAt']);
      queryfx($connection, 'INSERT INTO %T (transactionDigest,browserDigest,generation,expiresAt,issuerIncarnation) VALUES (%s,%s,%d,%d,%s)',
        'governance_mediaexchange', $issuer->digest('transaction', $transaction), $digest,
        $browser['generation'], $expires, $issuer->getIncarnation());
      $issuer->assertCurrent();
      $connection->saveTransaction();
      return array('transaction_id' => $transaction,
        'browser_secret' => new PhutilOpaqueEnvelope($secret),
        'expires_at' => $expires);
    } catch (Throwable $error) {
      $connection->killTransaction();
      throw new RuntimeException('media_exchange_preparation_refused');
    }
  }

  /** Must be called from the actual application's CSRF-protected web request. */
  public function issue(AphrontRequest $request, $transaction, $audience, $purpose, $path) {
    self::enabled();
    self::validateSecret($transaction);
    self::validateTarget($audience, $purpose, $path);
    if (!$request->isHTTPPost()) { throw new RuntimeException('media_exchange_request_refused'); }
    $request->validateCSRF();
    $context = PhorgeGovernanceNativeActorContext::newFromWebRequest($request);
    $connection = self::connection();
    $issuer = PhorgeGovernanceMediaIssuer::newFromConfiguration();
    self::begin($connection);
    try {
      $exchange = $this->lockExchange($connection, $transaction, $issuer);
      $snapshot = PhorgeGovernanceNativeIdentitySource::lockCurrent($context, $connection);
      $now = self::now($connection);
      $expires = min($now + self::CODE_TTL, $snapshot->getExpiresAt(),
        (int)$exchange['expiresAt'], (int)$exchange['_browserExpiresAt']);
      if ($expires <= $now || $exchange['codeDigest'] !== null) {
        throw new RuntimeException('media_exchange_already_issued');
      }
      $generation = self::nextBrowserGeneration($exchange['generation']);
      queryfx($connection, 'UPDATE %T SET generation=%d WHERE browserDigest=%s',
        'governance_mediabrowser', $generation, $exchange['browserDigest']);
      queryfx($connection, 'UPDATE %T SET revokedAt=%d WHERE browserDigest=%s AND revokedAt IS NULL',
        'governance_mediacredential', $now, $exchange['browserDigest']);
      $code = bin2hex(random_bytes(32));
      queryfx($connection, 'UPDATE %T SET generation=%d,codeDigest=%s,userPHID=%s,sessionPHID=%s,credentialDigest=%s,accountEpoch=%s,sessionExpiresAt=%d,audience=%s,purpose=%s,returnPath=%s,expiresAt=%d WHERE transactionDigest=%s',
        'governance_mediaexchange', $generation, $issuer->digest('code', $code), $snapshot->getUserPHID(),
        $snapshot->getSessionPHID(), $snapshot->getCredentialDigest(), $snapshot->getAccountEpoch(),
        $snapshot->getExpiresAt(), $audience, $purpose, $path, $expires,
        $issuer->digest('transaction', $transaction));
      PhorgeGovernanceNativeIdentitySource::revalidate($snapshot, $connection);
      if (self::now($connection) >= $expires) { throw new RuntimeException('media_exchange_expired'); }
      $issuer->assertCurrent();
      $connection->saveTransaction();
      return array('exchange_code' => new PhutilOpaqueEnvelope($code), 'expires_at' => $expires);
    } catch (Throwable $error) {
      $connection->killTransaction();
      throw new RuntimeException('media_exchange_issue_refused');
    }
  }

  private function lockExchange(AphrontDatabaseConnection $connection, $transaction, PhorgeGovernanceMediaIssuer $issuer) {
    $digest = $issuer->digest('transaction', $transaction);
    // Discovery contains no authority. The browser lock always precedes row locks.
    $first = queryfx_one($connection, 'SELECT browserDigest FROM %T WHERE transactionDigest=%s',
      'governance_mediaexchange', $digest);
    if (!$first) { throw new RuntimeException('media_exchange_unknown'); }
    $browser = self::browser($connection, $first['browserDigest'], $issuer);
    $row = queryfx_one($connection, 'SELECT * FROM %T WHERE transactionDigest=%s FOR UPDATE',
      'governance_mediaexchange', $digest);
    if (!$row || !$issuer->matchesRow($row) || !hash_equals($row['browserDigest'], $browser['browserDigest']) ||
        (string)$row['generation'] !== (string)$browser['generation'] ||
        $row['usedAt'] !== null || (int)$row['expiresAt'] <= self::now($connection)) {
      throw new RuntimeException('media_exchange_stale');
    }
    $row['_browserExpiresAt'] = (int)$browser['expiresAt'];
    return $row;
  }

  public function redeem($transaction, $code, $browser_secret, $audience, $purpose, $path) {
    self::validateSecret($transaction); self::validateSecret($code);
    self::validateSecret($browser_secret); self::validateTarget($audience, $purpose, $path);
    $connection = self::connection();
    $issuer = PhorgeGovernanceMediaIssuer::newFromConfiguration();
    self::begin($connection);
    try {
      $row = $this->lockExchange($connection, $transaction, $issuer);
      if (!hash_equals($row['browserDigest'], $issuer->digest('browser', $browser_secret)) ||
          !is_string($row['codeDigest']) || !hash_equals($row['codeDigest'], $issuer->digest('code', $code)) ||
          $row['audience'] !== $audience || $row['purpose'] !== $purpose || $row['returnPath'] !== $path) {
        throw new RuntimeException('media_exchange_binding_refused');
      }
      $snapshot = PhorgeGovernanceNativeIdentitySource::lockMediaBinding(
        $row['userPHID'], $row['sessionPHID'], $row['credentialDigest'], (string)$row['accountEpoch'], $connection);
      $now = self::now($connection);
      $expires = min($now + self::CREDENTIAL_TTL, $snapshot->getExpiresAt(),
        (int)$row['_browserExpiresAt']);
      if ($expires <= $now) { throw new RuntimeException('media_exchange_expired'); }
      $secret = bin2hex(random_bytes(32));
      $generation = (int)$row['generation'];
      queryfx($connection, 'UPDATE %T SET revokedAt=%d WHERE browserDigest=%s AND revokedAt IS NULL',
        'governance_mediacredential', $now, $row['browserDigest']);
      queryfx($connection, 'UPDATE %T SET usedAt=%d WHERE transactionDigest=%s',
        'governance_mediaexchange', $now, $issuer->digest('transaction', $transaction));
      queryfx($connection, 'INSERT INTO %T (secretDigest,browserDigest,generation,userPHID,sessionPHID,credentialDigest,accountEpoch,expiresAt,audience,purpose,revokedAt,issuerIncarnation) VALUES (%s,%s,%d,%s,%s,%s,%s,%d,%s,%s,NULL,%s)',
        'governance_mediacredential', $issuer->digest('credential', $secret), $row['browserDigest'], $generation,
        $snapshot->getUserPHID(), $snapshot->getSessionPHID(), $snapshot->getCredentialDigest(),
        $snapshot->getAccountEpoch(), $expires, $audience, $purpose, $issuer->getIncarnation());
      PhorgeGovernanceNativeIdentitySource::revalidate($snapshot, $connection);
      if (self::now($connection) >= min($expires, (int)$row['expiresAt'])) {
        throw new RuntimeException('media_exchange_expired');
      }
      $issuer->assertCurrent();
      $connection->saveTransaction();
      return array('credential' => new PhutilOpaqueEnvelope($secret), 'expires_at' => $expires,
        'return_path' => $path);
    } catch (Throwable $error) {
      $connection->killTransaction();
      throw new RuntimeException('media_exchange_redemption_refused');
    }
  }

  private static function identityTransactionBookkeeping(
    AphrontDatabaseConnection $connection, $action) {
    // Native SQL classifies START/COMMIT/ROLLBACK as writes. Only these fixed
    // identity-read transaction operations bypass the request CSRF write guard.
    $unguarded=AphrontWriteGuard::beginScopedUnguardedWrites();
    try {
      switch ($action) {
        case 'begin': self::begin($connection); break;
        case 'commit': $connection->saveTransaction(); break;
        case 'rollback':
          if ($connection->isInsideTransaction()) { $connection->killTransaction(); }
          break;
        default: throw new LogicException('media_identity_bookkeeping_refused');
      }
    } finally { unset($unguarded); }
  }

  /** Resolves identity only; callers must still authorize the requested file. */
  public function authenticate($secret) {
    self::validateSecret($secret);
    $connection = self::connection();
    $issuer = PhorgeGovernanceMediaIssuer::newFromConfiguration();
    self::identityTransactionBookkeeping($connection,'begin');
    try {
      $digest = $issuer->digest('credential', $secret);
      $first = queryfx_one($connection, 'SELECT browserDigest FROM %T WHERE secretDigest=%s',
        'governance_mediacredential', $digest);
      if (!$first) { throw new RuntimeException('media_credential_unknown'); }
      $browser = self::browser($connection, $first['browserDigest'], $issuer);
      $row = queryfx_one($connection, 'SELECT * FROM %T WHERE secretDigest=%s FOR UPDATE',
        'governance_mediacredential', $digest);
      if (!$row || !$issuer->matchesRow($row) || !hash_equals($row['secretDigest'], $digest) ||
          !hash_equals($row['browserDigest'], $browser['browserDigest']) ||
          $row['audience'] !== self::AUDIENCE || $row['purpose'] !== self::PURPOSE ||
          $row['revokedAt'] !== null || (string)$row['generation'] !== (string)$browser['generation'] ||
          (int)$row['expiresAt'] <= self::now($connection)) {
        throw new RuntimeException('media_credential_stale');
      }
      $snapshot = PhorgeGovernanceNativeIdentitySource::lockMediaBinding(
        $row['userPHID'], $row['sessionPHID'], $row['credentialDigest'], (string)$row['accountEpoch'], $connection);
      PhorgeGovernanceNativeIdentitySource::revalidate($snapshot, $connection);
      if (self::now($connection) >= min((int)$row['expiresAt'], (int)$browser['expiresAt'])) {
        throw new RuntimeException('media_credential_stale');
      }
      $issuer->assertCurrent();
      self::identityTransactionBookkeeping($connection,'commit');
      return $snapshot;
    } catch (Throwable $error) {
      self::identityTransactionBookkeeping($connection,'rollback');
      throw new RuntimeException('media_credential_refused');
    }
  }
}
