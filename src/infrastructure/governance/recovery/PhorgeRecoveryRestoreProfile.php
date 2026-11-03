<?php

/** Reuse the already exercised R02 isolated route contract; no production DSN. */
final class PhorgeRecoveryRestoreProfile extends Phobject {
  public static function assertCurrent() {
    if (PhabricatorEnv::getEnvConfigIfExists('governance.account-recovery.enabled',false)!==true) {
      throw new RuntimeException('recovery_restore_feature_unavailable');
    }
    if (PhabricatorEnv::isReadOnly()!==false ||
        (new ReflectionProperty(LiskDAO::class,'forcedConnection'))->getValue(new PhabricatorUser())!==null) {
      throw new RuntimeException('recovery_restore_native_writer_required');
    }
    // Fixed native implementation, not a caller-provided validator or boolean.
    $routing=new ReflectionMethod(PhorgeRecoveryNativeCaptureScope::class,'routing');
    $route=$routing->invoke(null);
    PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
    return array('namespace'=>$route['storage.default-namespace'],
      'routing_digest'=>hash('sha256',serialize($route)));
  }

  public static function assertOwnedWriter(AphrontDatabaseConnection $conn) {
    $profile=self::assertCurrent();
    if (get_class($conn)!==AphrontMySQLiDatabaseConnection::class ||
        $conn!==(new PhabricatorUser())->establishConnection('w') || !$conn->isInsideTransaction()) {
      throw new RuntimeException('recovery_restore_native_writer_required');
    }
    $database=queryfx_one($conn,'SELECT DATABASE() db');
    if (!$database || $database['db']!==$profile['namespace'].'_user') {
      throw new RuntimeException('recovery_restore_native_writer_required');
    }
    $marker=queryfx_one($conn,'SELECT marker,quickstart_sha256 FROM %T.fixture_guard '.
      'WHERE id=1 LOCK IN SHARE MODE',$profile['namespace'].'_governance');
    if (!$marker || $marker['marker']!=='N07.NATIVE-SOURCE.v1:synthetic-only' ||
        $marker['quickstart_sha256']!==hash_file('sha256',
          dirname(phutil_get_library_root('phabricator')).'/resources/sql/quickstart.sql')) {
      throw new RuntimeException('recovery_restore_fixture_lineage_unknown');
    }
  }
}
