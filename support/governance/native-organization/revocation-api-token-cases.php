<?php

/** Actual owned token DAO deletion and native authentication, never HTTP. */
function governance_native_revocation_api_token_cases($fixture,callable $check,array $state){
  $fixture->assertOwned();$revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/',$revision)){
    throw new RuntimeException('measurement_source_revision_required');}
  $boot=trim(file_get_contents('/proc/sys/kernel/random/boot_id'));
  if(!preg_match('/\A[a-f0-9-]{36}\z/',$boot)||PHP_INT_SIZE!==8){throw new RuntimeException('measurement_linux_clock_required');}
  $user=(new PhabricatorUser())->setUsername('n12api'.substr($fixture->getNamespace(),-12))
    ->setRealName('Synthetic Native API Observation')->setIsApproved(1)->setIsEmailVerified(1)->save();
  (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress($user->getUsername().'@example.invalid')
    ->setIsPrimary(1)->setIsVerified(1)->save();
  $token=PhabricatorConduitToken::initializeNewToken($user->getPHID(),PhabricatorConduitToken::TYPE_STANDARD)->save();
  $id=(int)$token->getID();$owner=hash('sha256',$user->getPHID());$boot_hash=hash('sha256',$boot);
  $conn=$token->establishConnection('w');$owned_transaction=false;
  try{
    $probe=static function()use($fixture,$token,$id,$owner,$boot_hash){
      $start=hrtime(true);
      $worker=governance_native_start_worker($fixture,array('action'=>'measurement.api-token-auth',
        'token'=>$token->getToken(),'token_id'=>$id,'owner_binding'=>$owner));
      $result=governance_native_finish_worker($worker);$end=hrtime(true);
      if($result['status']!=='committed'||$result['boot_id_sha256']!==$boot_hash||$result['owner_binding']!==$owner){
        throw new RuntimeException('measurement_probe_binding_refused');}
      return array('start_ns'=>$start,'finish_ns'=>$end,'outcome'=>$result['outcome'],
        'authentication_code'=>$result['authentication_code'],'token_present'=>$result['token_present'],
        'source_binding'=>$result['source_binding']);
    };
    $before=$probe();
    $check($before['outcome']==='allowed'&&$before['authentication_code']==='native-success'&&$before['token_present']===true,
      'n12-native-api-independent-real-token-authentication-pretransition-allow');
    $row=queryfx_one($conn,'SELECT id,objectPHID,tokenType,token,expires FROM %R WHERE id=%d',new PhabricatorConduitToken(),$id);
    $source=hash('sha256',phutil_json_encode(array('id'=>$id,'row'=>$row)));
    $other=queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE id<>%d',new PhabricatorConduitToken(),$id);
    $check($row&&$row['objectPHID']===$user->getPHID()&&$row['tokenType']===PhabricatorConduitToken::TYPE_STANDARD&&
      $before['source_binding']===$source&&!$conn->isInsideTransaction()&&
      !(new PhabricatorUser())->establishConnection('w')->isInsideTransaction(),
      'n12-native-api-token-source-and-no-outer-transaction-proved');
    $operation=bin2hex(random_bytes(32));$start=hrtime(true);
    $conn->openTransaction();$owned_transaction=true;
    $token->delete();$affected=$conn->getAffectedRows();
    if($affected!==1){throw new RuntimeException('measurement_single_token_delete_required');}
    $conn->saveTransaction();$owned_transaction=false;$ack=hrtime(true);
    $absent=queryfx_one($conn,'SELECT id FROM %R WHERE id=%d',new PhabricatorConduitToken(),$id);
    $check($affected===1&&$absent===null&&!$conn->isInsideTransaction()&&$start<=$ack&&
      $other===queryfx_one($conn,'SELECT COUNT(*) n FROM %R WHERE id<>%d',new PhabricatorConduitToken(),$id),
      'n12-native-api-actual-owned-token-dao-delete-commits-exactly-one-row');
    $after=$probe();$repeat=$probe();$absent_binding=hash('sha256',phutil_json_encode(array('id'=>$id,'row'=>null)));
    $check($after['outcome']==='denied'&&$repeat['outcome']==='denied'&&
      $after['authentication_code']==='ERR-INVALID-AUTH'&&$repeat['authentication_code']==='ERR-INVALID-AUTH'&&
      $after['token_present']===false&&$repeat['token_present']===false&&
      $after['source_binding']===$absent_binding&&$repeat['source_binding']===$absent_binding,
      'n12-native-api-independent-absence-and-native-authentication-denial-after-revoke');
    $binding=hash('sha256',phutil_json_encode(array($operation,$owner,$id,$source,$absent_binding)));
    $attempts=array();foreach(array($before,$after,$repeat)as$i=>$sample){$attempts[]=array(
      'id'=>$i+1,'transition_id'=>$binding,'boot_id_sha256'=>$boot_hash,
      'start_ns'=>$sample['start_ns'],'finish_ns'=>$sample['finish_ns'],'outcome'=>$sample['outcome']);}
    $check($before['finish_ns']<=$start&&$ack<=$after['start_ns']&&$after['finish_ns']<=$repeat['start_ns'],
      'n12-native-api-serial-parent-clock-binds-actual-delete-and-fresh-authentication');
    return array('profile'=>'native-conduit-token-revoke-authentication-only','actual_source_checked'=>true,
      'actual_independent_authentication_processes'=>3,'token_rows_deleted'=>1,'native_transaction_record_available'=>false,
      'HTTP_proved'=>false,'gateway_proved'=>false,'SLA_proved'=>false,'convergence_proved'=>false,
      'event'=>array('schema'=>2,'source_revision'=>$revision,'producer_sha256'=>hash_file('sha256',__FILE__),
        'family'=>'api-token','clock'=>array('kind'=>'monotonic_ns','boot_id_sha256'=>$boot_hash),
        'transition'=>array('id'=>$binding,'action'=>'revoke','operation_binding_sha256'=>$binding,
          'boundary'=>'native-token-dao-delete-own-transaction','operation_start_ns'=>$start,'commit_confirmed_return_ns'=>$ack),
        'attempts'=>$attempts),'before_source_binding_sha256'=>$source,'after_source_binding_sha256'=>$absent_binding);
  }finally{
    if($owned_transaction){$conn->killTransaction();}
    // Never restore a deleted token. Only remove this fixture-created token if
    // an earlier failed observation left it present.
    $remaining=(new PhabricatorConduitToken())->load($id);
    if($remaining){
      if($remaining->getObjectPHID()!==$user->getPHID()||!hash_equals($remaining->getToken(),$token->getToken())){
        throw new RuntimeException('measurement_token_cleanup_binding_changed');}
      $remaining->delete();
    }
  }
}
