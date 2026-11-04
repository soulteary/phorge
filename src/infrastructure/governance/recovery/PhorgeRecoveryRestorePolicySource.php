<?php

/** Fixed native policy/Space row facts, never an interpreter or restore permit. */
final class PhorgeRecoveryRestorePolicySource extends Phobject {
  const MAX_ROWS=64;
  const MAX_ROW_BYTES=16384;
  const MAX_TOTAL_BYTES=65536;

  public static function capture(AphrontDatabaseConnection $conn) {
    PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);
    $database=queryfx_one($conn,'SELECT DATABASE() db')['db'];
    if (!is_string($database) || !preg_match('/\Agovernance_native_[a-f0-9]{16}_user\z/D',$database)) {
      throw new RuntimeException('recovery_policy_route_unknown');
    }
    $prefix=substr($database,0,-5);
    $profiles=array(
      array($prefix.'_policy','policy',array('id','phid','rules','defaultAction','dateCreated','dateModified')),
      array($prefix.'_policy','policy_namedpolicy',array('id','phid','effectivePolicy','name','description',
        'viewPolicy','editPolicy','targetObjectType','dateCreated','dateModified')),
      array($prefix.'_spaces','spaces_namespace',array('id','phid','namespaceName','viewPolicy','editPolicy',
        'isDefaultNamespace','description','isArchived','dateCreated','dateModified')));
    $facts=array();$total_rows=0;$total_bytes=0;
    foreach ($profiles as $profile) {
      list($schema,$table,$columns)=$profile;
      // Acquire MDL with a current read before inspecting schema. All data reads
      // use this same physical People writer, never an independently routed DAO.
      queryfx_all($conn,'SELECT id FROM %T.%T WHERE 1=0 LOCK IN SHARE MODE',$schema,$table);
      $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES '.
        'WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$schema,$table);
      if (!$engine || $engine['ENGINE']!=='InnoDB') {
        throw new RuntimeException('recovery_policy_schema_unknown');
      }
      $keys=queryfx_all($conn,'SELECT COLUMN_NAME,SEQ_IN_INDEX FROM information_schema.STATISTICS '.
        'WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s ORDER BY SEQ_IN_INDEX',
        $schema,$table,'PRIMARY');
      if (count($keys)!==1 || $keys[0]['COLUMN_NAME']!=='id' || (string)$keys[0]['SEQ_IN_INDEX']!=='1') {
        throw new RuntimeException('recovery_policy_primary_unknown');
      }
      $actual=queryfx_all($conn,'SELECT COLUMN_NAME FROM information_schema.COLUMNS '.
        'WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$schema,$table);
      $names=ipull($actual,'COLUMN_NAME');sort($names,SORT_STRING);$expected=$columns;sort($expected,SORT_STRING);
      if ($names!==$expected) {throw new RuntimeException('recovery_policy_columns_unknown');}
      $lengths=array();$digests=array();
      foreach ($columns as $column) {
        // Fixed source literals only; NULL and byte length are independently
        // bound so NULL/empty and concatenation ambiguity cannot alias.
        $lengths[]='COALESCE(OCTET_LENGTH(`'.$column.'`),0)';
        $digests[]='SHA2(CONCAT(IF(`'.$column.'` IS NULL,\'N\',\'V\'),'.
          'COALESCE(CAST(OCTET_LENGTH(`'.$column.'`) AS CHAR),\'0\'),\':\','.
          'COALESCE(CAST(`'.$column.'` AS BINARY),\'\')),256) AS `'.$column.'`';
      }
      $sizes=queryfx_all($conn,'SELECT id,(%Q) bytes FROM %T.%T FORCE INDEX (PRIMARY) '.
        'ORDER BY id LIMIT %d LOCK IN SHARE MODE',qsprintf($conn,implode('+',$lengths)),
        $schema,$table,self::MAX_ROWS+1);
      $total_rows+=count($sizes);
      if ($total_rows>self::MAX_ROWS) {throw new RuntimeException('recovery_policy_row_budget');}
      $rows=array();
      foreach ($sizes as $size) {
        PhorgeRecoveryStopRequest::transactionID($size['id']);
        $bytes=self::boundedBytes($size['bytes']);$total_bytes+=$bytes;
        if ($total_bytes>self::MAX_TOTAL_BYTES) {throw new RuntimeException('recovery_policy_byte_budget');}
        $row=queryfx_one($conn,'SELECT %Q FROM %T.%T WHERE id=%s LOCK IN SHARE MODE',
          qsprintf($conn,implode(',',$digests)),$schema,$table,$size['id']);
        if (!$row || array_keys($row)!==$columns) {throw new RuntimeException('recovery_policy_row_changed');}
        foreach ($row as $digest) {
          if (!is_string($digest)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest)) {
            throw new RuntimeException('recovery_policy_digest_unknown');
          }
        }
        $rows[]=array('identity_digest'=>hash('sha256',(string)$size['id']),
          'bytes'=>$bytes,'field_digests'=>$row);
      }
      $facts[$table]=$rows;
    }
    return array('provider_schema'=>1,'selected_source_rows'=>$facts,
      'current_rows_locked'=>true,'empty_range_absence_proved'=>false,
      'policy_interpreter_complete'=>false,'automatic_object_grants_complete'=>false,
      'producer_lineage_complete'=>false,'complete'=>false,'restoration_authorized'=>false);
  }

  public static function boundedBytes($value) {
    if ((!is_string($value)&&!is_int($value)) ||
        !preg_match('/\A(?:0|[1-9][0-9]{0,5})\z/D',(string)$value) ||
        (int)$value>self::MAX_ROW_BYTES) {
      throw new RuntimeException('recovery_policy_byte_budget');
    }
    return (int)$value;
  }
}
