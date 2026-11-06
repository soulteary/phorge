<?php
/** Fixed fixture diagnostics only. No request source rebinding or permit. */
function governance_recovery_partial_attempt($name=null) {
  $allowed=array('partial-config-mutation','partial-restored-config-preview',
    'partial-project-mutation','partial-space-mutation','partial-policy-addition',
    'partial-policy-budget','partial-policy-delete','partial-restored-policy-preview');
  if($name!==null&&(!is_string($name)||!in_array($name,$allowed,true))) {
    throw new RuntimeException('recovery_partial_attempt_unknown');
  }
  $GLOBALS['governance_recovery_partial_attempt']=$name;
}

/** Compare only fixed source components; no raw key/value/hash ever returned. */
function governance_recovery_partial_source_components(array $before,array $after) {
  $changed=array();
  foreach(array('application-config','policy','project-relationships','spaces')as$family) {
    $changed[$family]=($before['partial_facts'][$family]??null)!==
      ($after['partial_facts'][$family]??null);
  }
  foreach(array('identity','credentials','organization','media','restore_lineage',
    'runtime_inventory_digest','families')as$key) {
    $changed[$key]=($before[$key]??null)!==($after[$key]??null);
  }
  $left=$before;$right=$after;
  foreach(array('partial_facts','identity','credentials','organization','media',
    'restore_lineage','runtime_inventory_digest','families')as$key) {
    unset($left[$key],$right[$key]);
  }
  $changed['other-bound-fields']=$left!==$right;
  $old_partial=$before['partial_facts']??array();$new_partial=$after['partial_facts']??array();
  foreach(array('application-config','policy','project-relationships','spaces')as$key) {
    unset($old_partial[$key],$new_partial[$key]);
  }
  $changed['other-partial-fields']=$old_partial!==$new_partial;
  return $changed;
}

/** Actual service-owned read transaction and source adapters, fixture only. */
function governance_recovery_partial_restored_source_diagnostic(
  $fixture,$context,$id,array $deleted) {
  if(get_class($fixture)!==PhorgeGovernanceCompletionFixture::class) {
    throw new RuntimeException('recovery_partial_diagnostic_fixture_unknown');
  }
  $fixture->assertOwned();$namespace=$fixture->getNamespace();
  if(!is_string($namespace)||!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$namespace)) {
    throw new RuntimeException('recovery_partial_diagnostic_route_unknown');
  }
  $id=PhorgeRecoveryRestoreRequest::identifier($id);
  $policy_id=PhorgeRecoveryStopRequest::transactionID($deleted['id']);
  $phid=$deleted['phid'];
  if(!is_string($phid)||strlen($phid)>64||!$phid) {
    throw new RuntimeException('recovery_partial_diagnostic_identity_unknown');
  }
  $planning=new ReflectionMethod(PhorgeRecoveryRestoreService::class,'planning');
  $stored=new ReflectionMethod(PhorgeRecoveryRestoreService::class,'stored');
  $transaction=new ReflectionMethod(PhorgeRecoveryRestoreService::class,'withTransaction');
  $planned=$planning->invoke(null,$id);
  $unguarded=AphrontWriteGuard::beginScopedUnguardedWrites();
  $rollback=new RuntimeException('recovery_partial_diagnostic_rollback');
  $observed=null;$reader_connection=null;
  try {
    try {$transaction->invoke(null,$context,$planned,
      static function($conn,$actor,$subjects)use($stored,$context,$id,$namespace,$policy_id,$phid,$rollback,&$observed,&$reader_connection) {
        $reader_connection=$conn;
        $database=queryfx_one($conn,'SELECT DATABASE() db');
        if(!$database||$database['db']!==$namespace.'_user') {
          throw new RuntimeException('recovery_partial_diagnostic_route_unknown');
        }
        // Same validation as preview. No decoding or acceptance of an unchecked
        // caller binding, no UPDATE to the ticket or approval/operation ledger.
        list($row,$binding)=$stored->invoke(null,$conn,$id,$subjects);
        $remaining=queryfx_all($conn,
          'SELECT id FROM %T.policy WHERE id=%s OR phid=%s LIMIT 2 LOCK IN SHARE MODE',
          $namespace.'_policy',$policy_id,$phid);
        $current=PhorgeRecoveryRestoreAssessment::capture($context,$conn,
          $row['subjectPHID'],$row['nativeTransactionID'])->getBinding();
        $observed=array('schema'=>'native-partial-source-difference-v1',
          'reader_succeeded'=>true,'fixture_policy_row_absent'=>!$remaining,
          'changed'=>governance_recovery_partial_source_components($binding['sources'],$current));
        // Never return a plan: force the actual service catch/ROLLBACK path,
        // including a possible lazy issuer-epoch INSERT on this User writer.
        throw $rollback;
      });}catch(Throwable $error) {
        if($error!==$rollback){throw $error;}
        if($reader_connection===null||$reader_connection->isInsideTransaction()||!is_array($observed)) {
          throw new RuntimeException('recovery_partial_diagnostic_rollback_unproved');
        }
        return $observed;
      }
    throw new RuntimeException('recovery_partial_diagnostic_return_unproved');
  }finally{unset($unguarded);}
}

/** Restrict public stderr to exactly the authored boolean schema. */
function governance_recovery_partial_source_projection($value) {
  $fallback=array('schema'=>'native-partial-source-difference-v1','reader_succeeded'=>false);
  if(!is_array($value)||($value['schema']??null)!==$fallback['schema']||
      ($value['reader_succeeded']??null)!==true||
      ($value['approval_fingerprint_unchanged']??null)!==true||
      !is_bool($value['fixture_policy_row_absent']??null)||!is_array($value['changed']??null)) {
    return $fallback;
  }
  $keys=array('application-config','policy','project-relationships','spaces','identity',
    'credentials','organization','media','restore_lineage','runtime_inventory_digest',
    'families','other-bound-fields','other-partial-fields');
  $actual=array_keys($value['changed']);sort($actual);$expected=$keys;sort($expected);
  if($actual!==$expected){return $fallback;}
  $out=array();foreach($keys as$key){
    if(!is_bool($value['changed'][$key])){return $fallback;}
    $out[$key]=$value['changed'][$key];
  }
  return array('schema'=>$fallback['schema'],'reader_succeeded'=>true,
    'fixture_policy_row_absent'=>$value['fixture_policy_row_absent'],
    'approval_fingerprint_unchanged'=>true,'changed'=>$out);
}
