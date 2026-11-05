<?php

/** Failure-only bounded snapshots. Creator IDs are not transaction ownership. */
function governance_recovery_api_sample_project(array $ps,array $trx,array $registry,$namespace,array $tables) {
  if(count($ps)>16||count($trx)>12){throw new RuntimeException('recovery_api_sample_budget');}
  governance_recovery_api_registry_role_names($registry);
  $opaque=static function($value,$nullable=false){
    return($nullable&&$value===null)||(is_string($value)&&strlen($value)>0&&strlen($value)<=256&&
      preg_match('/\A[\x21-\x7e]+\z/D',$value));};
  $states=array('RUNNING'=>'running','LOCK WAIT'=>'lock-wait','ROLLING BACK'=>'rolling-back','COMMITTING'=>'committing');
  $out=array('ps_waits'=>array(),'innodb_transactions'=>array(),'ownership_proved'=>false);
  $rows=array();
  foreach($trx as$row){
    if(array_keys($row)!==array('process_id','trx_id','trx_state','requested_id')||
      !$opaque($row['trx_id'])||!$opaque($row['requested_id'],true)||!is_string($row['trx_state'])){
      throw new RuntimeException('recovery_api_sample_shape');}
    $id=governance_recovery_api_nullable_thread($row['process_id']);
    if($id===null||isset($rows[$id])){throw new RuntimeException('recovery_api_sample_transaction_identity');}
    $roles=governance_recovery_api_registry_matches($registry,$id);
    if(!$roles){throw new RuntimeException('recovery_api_sample_transaction_identity');}
    $rows[$id]=$row;
    $out['innodb_transactions'][]=array('roles'=>$roles,'state'=>$states[$row['trx_state']]??'other',
      'requested_lock_present'=>$row['requested_id']!==null);
  }
  foreach($ps as$row){
    if(array_keys($row)!==array('request_creator','block_creator','request_lock_id','blocking_trx_id','schema_name','table_name','index_name','mode_name','type_name')||
      !$opaque($row['request_lock_id'])||!$opaque($row['blocking_trx_id'])){
      throw new RuntimeException('recovery_api_sample_shape');}
    $requester=governance_recovery_api_nullable_thread($row['request_creator']);
    $creator=governance_recovery_api_nullable_thread($row['block_creator']);
    $creator_roles=$requester===null?array():governance_recovery_api_registry_matches($registry,$requester);
    $request_row=$requester===null?null:($rows[$requester]??null);
    $producer=$registry['parent-producer']??null;$producer_row=$producer===null?null:($rows[$producer]??null);
    if(!is_string($row['mode_name'])||($row['index_name']!==null&&!is_string($row['index_name']))||!is_string($row['type_name'])){
      throw new RuntimeException('recovery_api_sample_shape');}
    $out['ps_waits'][]=array('request_event_creator_roles'=>$creator_roles,
      'blocking_event_creator_roles'=>$creator===null?array():governance_recovery_api_registry_matches($registry,$creator),
      'object_class'=>governance_recovery_api_object_class($row,$namespace,$tables),
      'index_class'=>$row['index_name']==='PRIMARY'?'primary':($row['index_name']===null?'none':'other'),
      'lock_mode'=>str_starts_with($row['mode_name'],'S')?'shared':(str_starts_with($row['mode_name'],'X')?'exclusive':'other'),
      'lock_type'=>$row['type_name']==='RECORD'?'record':($row['type_name']==='TABLE'?'table':'other'),
      'creator_thread_I_S_row_present'=>$request_row!==null,
      'creator_I_S_state'=>$request_row===null?'absent':($states[$request_row['trx_state']]??'other'),
      'creator_I_S_requested_id_equals_P_S'=>$request_row!==null&&$request_row['requested_id']===$row['request_lock_id'],
      'producer_I_S_row_present'=>$producer_row!==null,
      'producer_I_S_trx_id_equals_P_S_blocking_id'=>$producer_row!==null&&$producer_row['trx_id']===$row['blocking_trx_id'],
      'event_creator_is_transaction_owner_proved'=>false);
  }
  return$out;
}

function governance_recovery_api_wait_samples($fixture,mysqli $observer,array $registry,$namespace,array $tables) {
  $fixture->assertOwned();
  if(getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)){
    throw new RuntimeException('recovery_api_sample_scope');}
  governance_recovery_api_registry_role_names($registry);
  $ids=implode(',',array_map('intval',array_unique(array_values($registry))));
  $ps_sql='SELECT CAST(rt.PROCESSLIST_ID AS CHAR) request_creator,CAST(bt.PROCESSLIST_ID AS CHAR) block_creator,'.
    'w.REQUESTING_ENGINE_LOCK_ID request_lock_id,CAST(w.BLOCKING_ENGINE_TRANSACTION_ID AS CHAR) blocking_trx_id,'.
    'l.OBJECT_SCHEMA schema_name,l.OBJECT_NAME table_name,l.INDEX_NAME index_name,l.LOCK_MODE mode_name,l.LOCK_TYPE type_name '.
    'FROM performance_schema.data_lock_waits w '.
    'JOIN performance_schema.data_locks l ON l.ENGINE=w.ENGINE AND l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID '.
    'LEFT JOIN performance_schema.threads rt ON rt.THREAD_ID=w.REQUESTING_THREAD_ID '.
    'LEFT JOIN performance_schema.threads bt ON bt.THREAD_ID=w.BLOCKING_THREAD_ID '.
    "WHERE w.ENGINE='INNODB' AND l.OBJECT_SCHEMA IN ('".$observer->real_escape_string($namespace.'_user')."','".
    $observer->real_escape_string($namespace.'_conduit')."','".$observer->real_escape_string($namespace.'_oauth_server')."') LIMIT 17";
  $is_sql='SELECT CAST(TRX_MYSQL_THREAD_ID AS CHAR) process_id,CAST(TRX_ID AS CHAR) trx_id,TRX_STATE trx_state,TRX_REQUESTED_LOCK_ID requested_id '.
    'FROM information_schema.INNODB_TRX WHERE TRX_MYSQL_THREAD_ID IN ('.$ids.') LIMIT 13';
  $query=static function($sql)use($observer){$result=$observer->query($sql);
    if(!$result){throw new RuntimeException('recovery_api_sample_query');}
    try{return$result->fetch_all(MYSQLI_ASSOC);}finally{$result->free();}};
  $samples=array();$raw=array();$gap=null;
  for($index=0;$index<2;$index++){
    if($index===1){$start=hrtime(true);usleep(250000);$gap=hrtime(true)-$start;}
    // No I_S join: retain live P_S rows even when the I_S cache cannot match.
    $ps=$query($ps_sql);$trx=$query($is_sql);
    $samples[]=governance_recovery_api_sample_project($ps,$trx,$registry,$namespace,$tables);
    $identities=array();foreach($ps as$row){$identities[]=array($row['request_lock_id'],$row['blocking_trx_id'],$row['schema_name'],$row['table_name']);}
    sort($identities);$raw[]=$identities;
  }
  $writer=$registry['child-writer_thread']??null;$reader=$registry['child-reader_thread']??null;
  return array('profile'=>'failure-only-P_S-vs-I_S-two-sample-v1','samples'=>$samples,
    'child_writer_reader_alias'=>$writer!==null&&$reader!==null&&$writer===$reader,
    'P_S_wait_present_in_both_samples'=>count($raw[0])>0&&count($raw[1])>0,
    'same_P_S_wait_identity_across_samples'=>count($raw[0])>0&&count($raw[1])>0&&$raw[0]===$raw[1],
    'local_no_I_S_read_gap_at_least_250ms'=>is_int($gap)&&$gap>=250000000,
    'other_sessions_I_S_reads_controlled'=>false,'cache_causality_proved'=>false,
    'ownership_proved'=>false,'success_matcher_changed'=>false);
}
