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
    $lifecycle=self::captureLifecycle($conn,$subject);
    return array('provider_schema'=>2,'family'=>'organization','status'=>'partial',
      'subject_digest'=>hash('sha256',$subject),'guard_version'=>(string)$guard['version'],
      'selected_row_digests'=>$digests,'lifecycle_row_digests'=>$lifecycle,
      'lifecycle_coverage'=>'indexed-participant-and-linked-handover-disposition-outbox-v1','known_native_directory_producer_mutex_held'=>true,
      'all_producers_and_absence_fences_proved'=>false,'restore_lineage_complete'=>false,
      'complete'=>false,'restoration_authorized'=>false);
  }
  /** Fixed indexed current reads. Caller still owes the producer/DDL lease. */
  private static function captureLifecycle(AphrontDatabaseConnection $conn,$subject) {
    $tables=array('organization_approval','organization_transfer','organization_handover',
      'organization_projectdisposition','organization_outbox');
    foreach ($tables as $table) {
      queryfx_all($conn,'SELECT 1 FROM %T WHERE 1=0 LOCK IN SHARE MODE',$table);
      $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES '.
        'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table);
      if (!$engine || $engine['ENGINE']!=='InnoDB') {
        throw new RuntimeException('recovery_restore_organization_schema_unknown');
      }
    }
    // A legacy binding cannot be inferred absent from nullable participant
    // columns. Never silently skip it or backfill it while capturing authority.
    $legacy=queryfx_one($conn,'SELECT approvalID FROM organization_approval '.
      'FORCE INDEX (key_source_version) WHERE sourceParticipantVersion<>1 LIMIT 1 LOCK IN SHARE MODE');
    if ($legacy) { throw new RuntimeException('organization_approval_participants_unindexed'); }
    $rows=array_fill_keys($tables,array());$bytes=0;$total=0;
    $plans=array(
      'organization_approval'=>array('sourceSubjectPHID','sourceRequesterPHID','sourceExecutorPHID','approverPHID'),
      'organization_transfer'=>array('subjectPHID','responsiblePHID','requesterPHID','executorPHID','approverPHID'),
      'organization_handover'=>array('subjectPHID','responsiblePHID'),
      'organization_projectdisposition'=>array('manualActorPHID'));
    foreach ($plans as $table=>$columns) {
      foreach ($columns as $column) {
        self::readLifecycleRows($conn,$table,$column,$subject,$rows,$bytes,$total);
      }
    }
    foreach ($rows['organization_transfer'] as $row) {
      self::readLifecycleRows($conn,'organization_handover','transferID',$row['transferID'],$rows,$bytes,$total);
      self::readLifecycleRows($conn,'organization_outbox','transferID',$row['transferID'],$rows,$bytes,$total);
    }
    foreach ($rows['organization_handover'] as $row) {
      self::readLifecycleRows($conn,'organization_projectdisposition','handoverID',$row['handoverID'],$rows,$bytes,$total);
      self::readLifecycleRows($conn,'organization_outbox','handoverID',$row['handoverID'],$rows,$bytes,$total);
    }
    $digests=array();
    foreach ($rows as $table=>$selected) {
      ksort($selected,SORT_STRING);$digests[$table]=array();
      foreach ($selected as $row) {
        if ($table==='organization_approval') {
          $data=json_decode($row['bindingJSON'],true,16,JSON_THROW_ON_ERROR);
          if (!is_array($data)) { throw new RuntimeException('organization_approval_binding_refused'); }
          PhorgeGovernanceNativeApprovalAdapter::assertParticipantMetadata($row,$data,true);
          if (!hash_equals($row['requestDigest'],hash('sha256',PhorgeGovernanceOrganizationApprovalAdapter::canonicalJSON($data)))) {
            throw new RuntimeException('organization_approval_record_refused');
          }
        }
        $digests[$table][]=PhorgeRecoveryRestoreRequest::digest($row);
      }
    }
    return $digests;
  }

  private static function readLifecycleRows(AphrontDatabaseConnection $conn,$table,$column,$value,
    array &$rows,&$bytes,&$total) {
    $profiles=array(
      'organization_approval'=>array('key'=>'approvalID','columns'=>array('sourceSubjectPHID','sourceRequesterPHID','sourceExecutorPHID','approverPHID'),
        'large'=>array('bindingJSON','approverBindingJSON')),
      'organization_transfer'=>array('key'=>'id','columns'=>array('subjectPHID','responsiblePHID','requesterPHID','executorPHID','approverPHID'),
        'large'=>array('bindingJSON','approverBindingJSON')),
      'organization_handover'=>array('key'=>'id','columns'=>array('subjectPHID','responsiblePHID','transferID'),'large'=>array()),
      'organization_projectdisposition'=>array('key'=>'id','columns'=>array('handoverID','manualActorPHID'),'large'=>array('note')),
      'organization_outbox'=>array('key'=>'id','columns'=>array('transferID','handoverID'),'large'=>array()));
    if (!isset($profiles[$table]) || !in_array($column,$profiles[$table]['columns'],true) ||
        !is_string($value) || strlen($value)>128) {
      throw new RuntimeException('recovery_restore_organization_plan_unknown');
    }
    $indexes=array(
      'organization_approval'=>array('sourceSubjectPHID'=>'key_source_subject','sourceRequesterPHID'=>'key_source_requester',
        'sourceExecutorPHID'=>'key_source_executor','approverPHID'=>'key_source_approver'),
      'organization_transfer'=>array('subjectPHID'=>'key_subject','responsiblePHID'=>'key_responsible',
        'requesterPHID'=>'key_requester','executorPHID'=>'key_executor','approverPHID'=>'key_approver'),
      'organization_handover'=>array('subjectPHID'=>'key_subject','responsiblePHID'=>'key_responsible','transferID'=>'key_transfer'),
      'organization_projectdisposition'=>array('handoverID'=>'key_identity','manualActorPHID'=>'key_manual_actor'),
      'organization_outbox'=>array('transferID'=>'key_transfer','handoverID'=>'key_handover'));
    $index=$indexes[$table][$column];
    $profile=$profiles[$table];$key=$profile['key'];$lengths=array();
    foreach ($profile['large'] as $field) { $lengths[]=qsprintf($conn,'OCTET_LENGTH(%C)',$field); }
    $projection=qsprintf($conn,'%C',$key);
    foreach ($lengths as $i=>$length) { $projection=qsprintf($conn,'%Q,%Q AS %C',$projection,$length,'bytes'.$i); }
    // Lock selected indexed rows before fetching any potentially large payload.
    $selected=queryfx_all($conn,'SELECT %Q FROM %T FORCE INDEX (%C) WHERE %C=%s ORDER BY %C LIMIT %d LOCK IN SHARE MODE',
      $projection,$table,$index,$column,$value,$key,self::MAX_ROWS+1);
    if (count($selected)>self::MAX_ROWS) { throw new RuntimeException('recovery_restore_organization_budget'); }
    foreach ($selected as $entry) {
      $identity=(string)$entry[$key];
      if (isset($rows[$table][$identity])) { continue; }
      if (++$total>self::MAX_ROWS) { throw new RuntimeException('recovery_restore_organization_budget'); }
      foreach ($lengths as $i=>$unused) {
        $size=$entry['bytes'.$i];
        if ($size!==null && ((!is_int($size)&&!is_string($size)) || !preg_match('/\A[0-9]+\z/D',(string)$size) ||
          strlen((string)$size)>5 || (int)$size>32768)) {
          throw new RuntimeException('recovery_restore_organization_budget');
        }
        $bytes+=(int)$size;
      }
      if ($bytes>131072) { throw new RuntimeException('recovery_restore_organization_budget'); }
      $row=queryfx_one($conn,'SELECT * FROM %T FORCE INDEX (PRIMARY) WHERE %C=%s LOCK IN SHARE MODE',$table,$key,$identity);
      if (!$row || (string)$row[$key]!==$identity || $row[$column]!==$value) {
        throw new RuntimeException('recovery_restore_organization_identity_unknown');
      }
      $rows[$table][$identity]=$row;
    }
  }
}
