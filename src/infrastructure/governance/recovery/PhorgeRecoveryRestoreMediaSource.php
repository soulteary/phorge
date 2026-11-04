<?php

/** Actual selected media facts. Neither this capture nor its digest is a permit. */
final class PhorgeRecoveryRestoreMediaSource extends Phobject {
  const MAX_ROWS = 64;
  const MAX_BYTES = 131072;

  private static function profiles() {
    return array(
      'governance_mediabrowser'=>array('browserDigest', array(
        'issuerIncarnation'=>'varbinary','browserDigest'=>'binary',
        'generation'=>'bigint','expiresAt'=>'bigint')),
      'governance_mediaexchange'=>array('transactionDigest', array(
        'issuerIncarnation'=>'varbinary','transactionDigest'=>'binary','browserDigest'=>'binary',
        'generation'=>'bigint','expiresAt'=>'bigint','codeDigest'=>'binary',
        'userPHID'=>'varbinary','sessionPHID'=>'varbinary','credentialDigest'=>'binary',
        'accountEpoch'=>'binary','sessionExpiresAt'=>'bigint','audience'=>'varbinary',
        'purpose'=>'varbinary','returnPath'=>'longblob','targetJSON'=>'longblob',
        'targetDigest'=>'binary','clientNonceDigest'=>'binary','usedAt'=>'bigint')),
      'governance_mediacredential'=>array('secretDigest', array(
        'issuerIncarnation'=>'varbinary','secretDigest'=>'binary','browserDigest'=>'binary',
        'generation'=>'bigint','userPHID'=>'varbinary','sessionPHID'=>'varbinary',
        'credentialDigest'=>'binary','accountEpoch'=>'binary','expiresAt'=>'bigint',
        'audience'=>'varbinary','purpose'=>'varbinary','revokedAt'=>'bigint')));
  }

  private static function schema(AphrontDatabaseConnection $conn, $table, array $profile) {
    queryfx_one($conn, 'SELECT %T FROM %T WHERE 1=0 LOCK IN SHARE MODE', $profile[0], $table);
    $engine=queryfx_one($conn, 'SELECT ENGINE FROM information_schema.TABLES '.
      'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table);
    if (!$engine || $engine['ENGINE']!=='InnoDB') {
      throw new RuntimeException('recovery_restore_media_schema_unknown');
    }
    $columns=queryfx_all($conn, 'SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,CHARACTER_MAXIMUM_LENGTH,IS_NULLABLE FROM information_schema.COLUMNS '.
      'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION', $table);
    $actual=array();
    $nullable=$table==='governance_mediaexchange'
      ? array('issuerIncarnation','codeDigest','userPHID','sessionPHID','credentialDigest',
        'accountEpoch','sessionExpiresAt','audience','purpose','returnPath','targetJSON',
        'targetDigest','clientNonceDigest','usedAt')
      : ($table==='governance_mediacredential'
        ? array('issuerIncarnation','revokedAt') : array('issuerIncarnation'));
    foreach ($columns as $column) {
      $name=$column['COLUMN_NAME']; $kind=$column['DATA_TYPE']; $actual[$name]=$kind;
      if ($column['IS_NULLABLE']!==(in_array($name,$nullable,true)?'YES':'NO') ||
          ($kind==='bigint' && !preg_match('/\Abigint(?:\([0-9]+\))? unsigned\z/D',$column['COLUMN_TYPE']))) {
        throw new RuntimeException('recovery_restore_media_schema_unknown');
      }
      if (in_array($kind,array('binary','varbinary'),true)) {
        $width=in_array($name,array('issuerIncarnation','audience','purpose'),true)?32:64;
        if ((string)$column['CHARACTER_MAXIMUM_LENGTH']!==(string)$width) {
          throw new RuntimeException('recovery_restore_media_schema_unknown');
        }
      }
    }
    $expected=$profile[1]; ksort($actual); ksort($expected);
    if ($actual!==$expected) { throw new RuntimeException('recovery_restore_media_schema_unknown'); }
    $primary=queryfx_all($conn, 'SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS '.
      'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME=%s ORDER BY SEQ_IN_INDEX',
      $table, 'PRIMARY');
    if (count($primary)!==1 || $primary[0]['COLUMN_NAME']!==$profile[0] ||
        !in_array($primary[0]['NON_UNIQUE'],array(0,'0'),true)) {
      throw new RuntimeException('recovery_restore_media_schema_unknown');
    }
  }

  private static function rows(AphrontDatabaseConnection $conn, $table, array $profile,
    array $keys, &$bytes) {
    if (!$keys) { return array(); }
    if (count($keys)>self::MAX_ROWS) { throw new RuntimeException('recovery_restore_media_budget'); }
    $columns=array_keys($profile[1]); sort($columns, SORT_STRING);
    // Only this fixed, private profile supplies SQL identifiers.
    $lengths=array(); $projection=array();
    foreach ($columns as $column) {
      if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D', $column)) {
        throw new RuntimeException('recovery_restore_media_profile_unknown');
      }
      $lengths[]='COALESCE(OCTET_LENGTH(`'.$column.'`),0)';
      $projection[]='`'.$column.'`';
    }
    $sum=qsprintf($conn, implode('+', $lengths));
    // Current row reads precede payload access. An aggregate consistent read
    // would be stale after switching the owned recovery transaction to RR.
    $sizes=queryfx_all($conn,'SELECT %T identity_key,%Q bytes FROM %T '.
      'WHERE %T IN (%Ls) ORDER BY %T LOCK IN SHARE MODE',
      $profile[0],$sum,$table,$profile[0],$keys,$profile[0]);
    if(count($sizes)!==count($keys)){throw new RuntimeException('recovery_restore_media_changed');}
    $observed=array();
    foreach($sizes as$size){
      if(!preg_match('/\A[0-9]+\z/D',(string)$size['bytes'])||
        strlen((string)$size['bytes'])>6||(int)$size['bytes']>self::MAX_BYTES-$bytes){
        throw new RuntimeException('recovery_restore_media_budget');}
      $bytes+=(int)$size['bytes'];$observed[]=$size['identity_key'];
    }
    $wanted=$keys;sort($wanted,SORT_STRING);sort($observed,SORT_STRING);
    if($wanted!==$observed){throw new RuntimeException('recovery_restore_media_changed');}
    $select=qsprintf($conn, implode(',', $projection));
    $rows=queryfx_all($conn, 'SELECT %Q FROM %T WHERE %T IN (%Ls) ORDER BY %T LOCK IN SHARE MODE',
      $select, $table, $profile[0], $keys, $profile[0]);
    if (count($rows)!==count($keys)) { throw new RuntimeException('recovery_restore_media_changed'); }
    return $rows;
  }

  public static function capture(PhorgeGovernanceNativeActorContext $context,
    AphrontDatabaseConnection $conn, $subject) {
    PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);
    PhorgeRecoveryStopRequest::user($subject);
    $issuer=PhorgeGovernanceMediaIssuer::newFromConfiguration();
    $actor=PhorgeGovernanceNativeIdentitySource::lockCurrent($context, $conn);
    if (!$actor->isSystemAdmin()) { throw new RuntimeException('recovery_restore_admin_required'); }
    $database=queryfx_one($conn, 'SELECT DATABASE() db');
    if (!$database || $database['db']!==(new PhabricatorUser())->getAphrontRefDatabaseName()) {
      throw new RuntimeException('recovery_restore_media_writer_unknown');
    }
    $profiles=self::profiles();
    foreach ($profiles as $table=>$profile) { self::schema($conn, $table, $profile); }
    $groups=array(); $browsers=array(); $bytes=0;
    foreach (array('governance_mediaexchange','governance_mediacredential') as $table) {
      $profile=$profiles[$table];
      $ids=queryfx_all($conn, 'SELECT %T,browserDigest FROM %T WHERE BINARY userPHID=BINARY %s '.
        'ORDER BY %T LIMIT 65 LOCK IN SHARE MODE', $profile[0], $table, $subject, $profile[0]);
      if (count($ids)>self::MAX_ROWS) { throw new RuntimeException('recovery_restore_media_budget'); }
      $keys=array();
      foreach ($ids as $id) {
        foreach (array($id[$profile[0]],$id['browserDigest']) as $digest) {
          if (!is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/D', $digest)) {
            throw new RuntimeException('recovery_restore_media_row_unknown');
          }
        }
        $keys[]=$id[$profile[0]]; $browsers[$id['browserDigest']]=true;
      }
      $groups[$table]=self::rows($conn, $table, $profile, $keys, $bytes);
    }
    $browser_keys=array_keys($browsers); sort($browser_keys, SORT_STRING);
    $groups['governance_mediabrowser']=self::rows($conn, 'governance_mediabrowser',
      $profiles['governance_mediabrowser'], $browser_keys, $bytes);
    $facts=array();
    foreach ($groups as $table=>$rows) {
      $facts[$table]=array();
      foreach ($rows as $row) {
        // LOBs may contain private paths. Hash actual bytes without emitting them.
        ksort($row, SORT_STRING); $encoded=array();
        foreach ($row as $key=>$value) { $encoded[$key]=$value===null?null:base64_encode((string)$value); }
        $facts[$table][]=hash('sha256', json_encode($encoded, JSON_THROW_ON_ERROR));
      }
    }
    $issuer->assertCurrent();
    $current=PhorgeGovernanceNativeIdentitySource::revalidate($actor, $conn);
    if (!$current->isSystemAdmin()) { throw new RuntimeException('recovery_restore_admin_required'); }
    return array('provider_schema'=>1,'family'=>'media-credentials','status'=>'partial',
      'subject_digest'=>hash('sha256',$subject),'row_digests'=>$facts,
      'issuer_binding_digest'=>$issuer->digest('configuration','current'),
      'all_producers_and_absence_fences_proved'=>false,'restore_lineage_complete'=>false,
      'complete'=>false,'restoration_authorized'=>false);
  }
}
