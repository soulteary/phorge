<?php

/** Locked native directory facts; not a certificate for Project or other families. */
final class PhorgeRecoveryRestoreOrganizationSource extends Phobject {
  const MAX_ROWS=64;

  public static function capture(AphrontDatabaseConnection $conn,$subject) {
    PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);
    PhorgeRecoveryStopRequest::user($subject);
    $profiles=array(
      'organization_guard'=>array('id','version'),
      'organization_member'=>array('id','unitID','userPHID','state','validFrom','validUntil','version'),
      'organization_appointment'=>array('id','unitID','userPHID','role','state','validFrom','validUntil','version'),
      'organization_primarydepartment'=>array('id','userPHID','unitID','version'),
      'organization_unit'=>array('id','unitID','kind','state','parentID','version',
        'visibilityModel','visibilityVersion','associatedDepartmentID','contactUserPHID'));
    foreach ($profiles as $table=>$columns) {
      queryfx_one($conn,'SELECT id FROM %T WHERE 1=0 LOCK IN SHARE MODE',$table);
      $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES '.
        'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table);
      if (!$engine || $engine['ENGINE']!=='InnoDB') {
        throw new RuntimeException('recovery_restore_organization_schema_unknown');
      }
    }
    // All current native directory writers take this mutex after account locks.
    // It fences an empty selected membership set against those producers, but
    // does not certify unknown/raw writers or transfer/outbox lineage.
    $guard=queryfx_one($conn,'SELECT id,version FROM organization_guard WHERE id=1 FOR UPDATE');
    if (!$guard || (string)$guard['id']!=='1') {
      throw new RuntimeException('recovery_restore_organization_guard_unknown');
    }
    PhorgeRecoveryStopRequest::transactionID($guard['version']);
    $digests=array();$units=array();$total=0;
    foreach (array('organization_member','organization_appointment','organization_primarydepartment') as $table) {
      $parts=array();foreach ($profiles[$table] as $column) {$parts[]='`'.$column.'`';}
      $projection=qsprintf($conn,implode(',',$parts));
      $rows=queryfx_all($conn,'SELECT %Q FROM %T WHERE userPHID=%s '.
        'ORDER BY id LIMIT %d LOCK IN SHARE MODE',$projection,$table,$subject,self::MAX_ROWS+1);
      $total+=count($rows);
      if ($total>self::MAX_ROWS) {throw new RuntimeException('recovery_restore_organization_budget');}
      $digests[$table]=array();
      foreach ($rows as $row) {
        if ($row['userPHID']!==$subject) {throw new RuntimeException('recovery_restore_organization_identity_unknown');}
        PhorgeRecoveryStopRequest::transactionID($row['id']);
        PhorgeRecoveryStopRequest::transactionID($row['version']);
        if ($row['unitID']!==null) {$units[]=$row['unitID'];}
        $digests[$table][]=PhorgeRecoveryRestoreRequest::digest($row);
      }
    }
    $units=array_values(array_unique($units));sort($units,SORT_STRING);
    $digests['organization_unit']=array();
    foreach ($units as $unit) {
      if (!is_string($unit) || strlen($unit)>64 || strlen($unit)<1) {
        throw new RuntimeException('recovery_restore_organization_identity_unknown');
      }
      $parts=array();foreach ($profiles['organization_unit'] as $column) {$parts[]='`'.$column.'`';}
      $row=queryfx_one($conn,'SELECT %Q FROM organization_unit WHERE unitID=%s LOCK IN SHARE MODE',
        qsprintf($conn,implode(',',$parts)),$unit);
      if (!$row || $row['unitID']!==$unit || $row['visibilityModel']!=='restricted-admin-v1') {
        throw new RuntimeException('recovery_restore_organization_identity_unknown');
      }
      PhorgeRecoveryStopRequest::transactionID($row['version']);
      PhorgeRecoveryStopRequest::transactionID($row['visibilityVersion']);
      $digests['organization_unit'][]=PhorgeRecoveryRestoreRequest::digest($row);
    }
    return array('provider_schema'=>1,'family'=>'organization','status'=>'partial',
      'subject_digest'=>hash('sha256',$subject),'guard_version'=>(string)$guard['version'],
      'selected_row_digests'=>$digests,'known_native_directory_producer_mutex_held'=>true,
      'all_producers_and_absence_fences_proved'=>false,'restore_lineage_complete'=>false,
      'complete'=>false,'restoration_authorized'=>false);
  }
}
