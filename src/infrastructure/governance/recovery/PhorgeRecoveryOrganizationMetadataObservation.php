<?php

/**
 * Live metadata observations for the isolated organization profile.
 *
 * This collector issues only SELECTs. Its supplied control connection is NOT a
 * read-only credential: the required global TRIGGER grant also permits DDL.
 * No observation is a producer/DDL lease or a restoration permit.
 */
final class PhorgeRecoveryOrganizationMetadataObservation extends Phobject {
  public static function tables() {
    return array('organization_guard','organization_unit','organization_member',
      'organization_appointment','organization_primarydepartment',
      'organization_operation','organization_audit','organization_approval',
      'organization_transfer','organization_handover',
      'organization_projectdisposition','organization_outbox');
  }

  public static function capture(
    AphrontDatabaseConnection $writer,
    AphrontDatabaseConnection $control) {
    PhorgeRecoveryRestoreProfile::assertOwnedWriter($writer);
    if(get_class($control)!==AphrontMySQLiDatabaseConnection::class||
      $control===$writer||!$control->isInsideTransaction()||
      $control->isReadLocking()||$control->isWriteLocking()){
      throw new RuntimeException('recovery_metadata_control_profile_unknown');}
    $actual=queryfx_one($writer,'SELECT DATABASE() db,@@server_uuid uuid,CONNECTION_ID() tid');
    $metadata=queryfx_one($control,'SELECT @@server_uuid uuid,CURRENT_ROLE() role,@@proxy_user proxy');
    if(!$actual||!$metadata||$actual['uuid']!==$metadata['uuid']||
      !is_string($actual['uuid'])||!preg_match('/\A[a-f0-9-]{36}\z/D',$actual['uuid'])||
      $metadata['role']!=='NONE'||$metadata['proxy']!==null){
      throw new RuntimeException('recovery_metadata_control_profile_unknown');}

    // Acquire every relevant table MDL before observing engines, grants or
    // absence. This is necessary, but not sufficient, for an incoming-FK fence.
    foreach(self::tables()as$table){
      queryfx_all($writer,'SELECT 1 FROM %T WHERE 1=0 LOCK IN SHARE MODE',$table);
    }
    foreach(self::tables()as$table){
      $engine=queryfx_one($control,'SELECT ENGINE FROM information_schema.TABLES '.
        'WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$actual['db'],$table);
      if(!$engine||$engine['ENGINE']!=='InnoDB'){
        throw new RuntimeException('recovery_metadata_engine_unknown');}
    }
    queryfx_all($control,'SELECT Host FROM mysql.user WHERE 1=0 LOCK IN SHARE MODE');
    $engine=queryfx_one($control,'SELECT ENGINE FROM information_schema.TABLES '.
      'WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s','mysql','user');
    if(!$engine||$engine['ENGINE']!=='InnoDB'){
      throw new RuntimeException('recovery_metadata_grant_store_unknown');}
    // Observe the actual authenticated grant row, never a caller account name.
    // MySQL 8.4 exempts grant-table reads from FOR SHARE row locking.
    // This SQL modifier must NOT be interpreted as a REVOKE fence.
    // No authentication_string or User_attributes payload is retrieved.
    $privileges=queryfx_all($control,'SELECT Select_priv,Trigger_priv,Process_priv,'.
      'IF(JSON_CONTAINS_PATH(COALESCE(User_attributes,JSON_OBJECT()),'.
      '%s,%s),1,0) restricted FROM mysql.user FORCE INDEX (PRIMARY) '.
      'WHERE Host=SUBSTRING_INDEX(CURRENT_USER(),%s,-1) AND '.
      'User=SUBSTRING(CURRENT_USER(),1,LENGTH(CURRENT_USER())-'.
      'LENGTH(SUBSTRING_INDEX(CURRENT_USER(),%s,-1))-1) LOCK IN SHARE MODE',
      'one','$.Restrictions','@','@');
    if(count($privileges)!==1||array_keys($privileges[0])!==array(
      'Select_priv','Trigger_priv','Process_priv','restricted')||
      $privileges[0]['Select_priv']!=='Y'||$privileges[0]['Trigger_priv']!=='Y'||
      $privileges[0]['Process_priv']!=='Y'||
      !in_array($privileges[0]['restricted'],array(0,'0'),true)){
      throw new RuntimeException('recovery_metadata_privilege_unknown');}
    $grants=queryfx_all($control,'SHOW GRANTS FOR CURRENT_USER()');$statements=array();
    foreach($grants as$row){
      if(count($row)!==1){throw new RuntimeException('recovery_metadata_grant_profile_unknown');}
      $statements[]=head($row);
    }
    $description=PhorgeRecoveryMetadataGrantDescription::describe($statements,
      $metadata['role'],$metadata['proxy']);
    if($description['required_global_privileges_claimed']!==true){
      throw new RuntimeException('recovery_metadata_privilege_unknown');}
    $triggers=0;$foreign_keys=0;
    foreach(self::tables()as$table){
      $row=queryfx_one($control,'SELECT COUNT(*) n FROM information_schema.TRIGGERS '.
        'WHERE EVENT_OBJECT_SCHEMA=%s AND EVENT_OBJECT_TABLE=%s',$actual['db'],$table);
      $triggers+=(int)$row['n'];
      // Includes incoming constraints declared in an unregistered child schema.
      $row=queryfx_one($control,'SELECT COUNT(*) n FROM information_schema.REFERENTIAL_CONSTRAINTS '.
        'WHERE (CONSTRAINT_SCHEMA=%s AND TABLE_NAME=%s) OR '.
        '(UNIQUE_CONSTRAINT_SCHEMA=%s AND REFERENCED_TABLE_NAME=%s)',
        $actual['db'],$table,$actual['db'],$table);
      $foreign_keys+=(int)$row['n'];
    }
    if($triggers!==0||$foreign_keys!==0){
      throw new RuntimeException('recovery_metadata_native_producers_unknown');}
    return array('schema_version'=>2,'family'=>'organization',
      'server_digest'=>hash('sha256',$actual['uuid']),
      'grant_description_digest'=>$description['source_digest'],
      'all_twelve_table_mdl_acquired'=>true,
      'global_visibility_grant_row_observed'=>true,
      'global_visibility_grant_row_locked'=>false,
      'grant_revoke_absence_fence_proved'=>false,
      'observed_trigger_and_incoming_outgoing_fk_absence'=>true,
      // SELECT MDL has not yet been proved to prevent concurrent incoming-FK
      // creation. Never promote a momentary zero count to an absence lease.
      'concurrent_ddl_absence_fence_proved'=>false,
      'all_producers_and_absence_fences_proved'=>false,
      'restoration_authorized'=>false);
  }
}
