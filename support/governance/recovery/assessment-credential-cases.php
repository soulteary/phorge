<?php
/** Real durable Store + real native credential rows. Isolated proof only. */
require_once __DIR__.'/restore-review-cases.php';
require_once __DIR__.'/borrowed-session-observer.php';
function governance_recovery_assessment_credential_obligations(){return array(
  'assessment-credential-current-owner-three-native-MDL-and-readsets',
  'assessment-credential-durable-submit-binds-three-actual-source-digests',
  'assessment-credential-repeat-preview-stable-without-domain-write',
  'assessment-credential-independent-review-persists-blocked-not-permit',
  'assessment-credential-session-change-refuses-old-review-without-write',
  'assessment-credential-conduit-change-refuses-old-review-without-write',
  'assessment-credential-oauth-access-change-refuses-old-review-without-write',
  'assessment-credential-execute-still-requires-all-thirteen-families');}
function governance_recovery_assessment_credential_fingerprint($conn){
  $rows=array();foreach(array('recovery_restore_request','recovery_restore_operation','recovery_restore_audit',
    'organization_member','organization_appointment','organization_primarydepartment')as$table){
    $rows[$table]=queryfx_all($conn,'SELECT * FROM %T ORDER BY 1',$table);}
  $rows['users']=queryfx_all($conn,'SELECT phid,isDisabled,isApproved,isAdmin FROM %R ORDER BY id',new PhabricatorUser());
  return hash('sha256',phutil_json_encode($rows));
}
function governance_recovery_assessment_credential_refuse($conn,callable$call,$expected){
  $before=governance_recovery_assessment_credential_fingerprint($conn);$refused=false;
  try{$call();}catch(RuntimeException$error){if(get_class($error)!==RuntimeException::class||$error->getMessage()!==$expected){throw$error;}$refused=true;}
  return $refused&&!$conn->isInsideTransaction()&&$before===governance_recovery_assessment_credential_fingerprint($conn);
}
function governance_recovery_assessment_credential_cases($fixture,mysqli$control,array$state,callable$check){
  $fixture->assertOwned();$requester=$state['issuer_auth'];$approver=$state['owner_auth'];$subject=$state['subjects']['commit'];
  if($requester['context']->getUserPHID()===$approver['context']->getUserPHID()||
    in_array($subject->getPHID(),array($requester['context']->getUserPHID(),$approver['context']->getUserPHID()),true)){
    throw new RuntimeException('assessment_credential_independent_roles_required');}
  $conn=governance_recovery_borrowed_default_writer();$sessions=new PhabricatorAuthSessionEngine();
  // Native constructors/INSERTs run and commit before the native stop. No raw
  // token UPDATE, fake avatar/session, forced writer or privilege grant is used.
  $native_session=governance_native_request($subject,$sessions)['viewer']->getSession();
  $conduit=PhabricatorConduitToken::initializeNewToken($subject->getPHID(),PhabricatorConduitToken::TYPE_STANDARD)->save();
  $client=PhabricatorOAuthServerClient::initializeNewClient($requester['viewer'])
    ->setName('Synthetic durable credential assessment')->setRedirectURI('https://fixture.invalid/oauth/')->save();
  $oauth=(new PhabricatorOAuthServer())->setUser($subject)->setClient($client)->generateAccessToken();
  (new PhabricatorUserTransactionEditor())->setActor($requester['viewer'])
    ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
    ->applyTransactions($subject,array((new PhabricatorUserTransaction())
      ->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue(true)));
  if($conn->isInsideTransaction()){throw new RuntimeException('assessment_credential_setup_commit_required');}
  $event=queryfx_one($conn,'SELECT id FROM %R WHERE BINARY objectPHID=BINARY %s AND transactionType=%s ORDER BY id DESC LIMIT 1',
    new PhabricatorUserTransaction(),$subject->getPHID(),PhabricatorUserDisableTransaction::TRANSACTIONTYPE);
  if(!$event){throw new RuntimeException('assessment_credential_native_stop_missing');}
  foreach(array($native_session,$conduit,$oauth)as$row){$present=queryfx_one($conn,'SELECT id FROM %R WHERE id=%d',$row,$row->getID());
    if(!$present||(string)$present['id']!==(string)$row->getID()){throw new RuntimeException('assessment_credential_native_seed_missing_after_stop');}}
  $suffix=substr($fixture->getNamespace(),-10);$stop=(string)$event['id'];
  PhorgeRecoveryStopService::recordAlreadyStopped($requester['context'],$subject->getPHID(),$stop,'technical-pause','Synthetic credential assessment stop','assessment-stop-'.$suffix);
  governance_recovery_restore_prime_space_cache($fixture,$conn,array($requester['viewer'],$approver['viewer'],$subject));
  $done=array();$record=static function($ok,$label)use($check,&$done){
    if($ok!==true||!in_array($label,governance_recovery_assessment_credential_obligations(),true)||isset($done[$label])){throw new RuntimeException('assessment_credential_case_refused');}
    $check(true,$label);$done[$label]=true;};
  governance_recovery_borrowed_with_observer($fixture,$control,static function($observer)use($fixture,$conn,$requester,$approver,$subject,$stop,$suffix,$native_session,$conduit,$oauth,$record){
    $store=new ReflectionMethod(PhorgeRecoveryRestoreService::class,'withTransaction');
    $sample=static function()use($store,$requester,$subject,$stop,$observer,$fixture){
      return $store->invoke(null,$requester['context'],array($subject->getPHID()),static function($writer)use($requester,$subject,$stop,$observer,$fixture){
        $assessment=PhorgeRecoveryRestoreAssessment::capture($requester['context'],$writer,$subject->getPHID(),$stop);
        $binding=$assessment->getBinding();PhorgeRecoveryRestoreRequest::sourceVersion($binding);
        $thread=queryfx_one($writer,'SELECT CAST(CONNECTION_ID() AS CHAR) id')['id'];$held=true;
        foreach(array(new PhabricatorAuthSession(),new PhabricatorConduitToken(),new PhabricatorOAuthServerAccessToken(),new PhabricatorOAuthServerAuthorizationCode())as$table){
          $rows=queryfx_all($observer,'SELECT m.LOCK_DURATION,m.LOCK_STATUS FROM performance_schema.metadata_locks m JOIN performance_schema.threads t ON t.THREAD_ID=m.OWNER_THREAD_ID WHERE t.PROCESSLIST_ID=%s AND m.OBJECT_TYPE=%s AND m.OBJECT_SCHEMA=%s AND m.OBJECT_NAME=%s',$thread,'TABLE',$table->getAphrontRefDatabaseName(),$table->getTableName());
          $found=false;foreach($rows as$row){if($row===array('LOCK_DURATION'=>'TRANSACTION','LOCK_STATUS'=>'GRANTED')){$found=true;}}
          $engine=queryfx_one($writer,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$table->getAphrontRefDatabaseName(),$table->getTableName());
          $held=$held&&$found&&$engine===array('ENGINE'=>'InnoDB');}
        return array('result'=>array('binding'=>$binding,'held'=>$held),'bindings'=>array(),'deadline'=>null);
      });};
    $initial=$sample();$readset=$initial['binding']['credential_readset'];
    $record($initial['held']&&$readset['availability']==='observed-partial'&&$readset['production_observer_factory_available']===false&&
      count($initial['binding']['families'])===13&&$readset['complete']===false&&
      $readset['provider_schema']===2&&count($readset['sources'])===4&&isset($readset['sources']['oauth-code']),
      'assessment-credential-current-owner-three-native-MDL-and-readsets');
      foreach(array('session'=>$native_session,'conduit'=>$conduit,'oauth-access'=>$oauth)as$name=>$row){
      $receipt=PhorgeRecoveryRestoreService::submit($requester['context'],array('native_stop_transaction'=>$stop,
        'operation_id'=>'assessment-submit-'.$name.'-'.$suffix,'reason'=>'Synthetic native credential readset','subject'=>$subject->getPHID(),'ttl_seconds'=>600));
      $id=$receipt['request_id'];$stored=queryfx_one($conn,'SELECT bindingJSON FROM recovery_restore_request WHERE requestID=%s',$id);
      $binding=json_decode($stored['bindingJSON'],true,32,JSON_THROW_ON_ERROR);$before=$sample()['binding'];
      if($binding['sources']['credential_readset']!==$before['credential_readset']||$binding['sources']['schema_version']!==5||
        $receipt['review_state']!=='submitted-blocked'||$receipt['restoration_authorized']!==false){throw new RuntimeException('assessment_credential_durable_readset_missing');}
      $fingerprint=governance_recovery_assessment_credential_fingerprint($conn);
      $preview=PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');$repeat=PhorgeRecoveryRestoreService::preview($approver['context'],$id,'1');
      if($preview!==$repeat||$preview['source_schema_version']!==5||count($preview['incomplete_families'])!==13||
        $preview['preview_is_authorization']!==false||$fingerprint!==governance_recovery_assessment_credential_fingerprint($conn)){
        throw new RuntimeException('assessment_credential_preview_not_stable');}
      $review=PhorgeRecoveryRestoreService::review($approver['context'],$id,'1','assessment-review-'.$name.'-'.$suffix);
      $reviewed=queryfx_one($conn,'SELECT reviewState,permitState,version,approverPHID FROM recovery_restore_request WHERE requestID=%s',$id);
      if($review['review_state']!=='reviewed-blocked'||$review['restoration_authorized']!==false||!$reviewed||
        array_keys($reviewed)!==array('reviewState','permitState','version','approverPHID')||
        $reviewed['reviewState']!=='reviewed-blocked'||$reviewed['permitState']!=='blocked'||
        !in_array($reviewed['version'],array(2,'2'),true)||$reviewed['approverPHID']!==$approver['context']->getUserPHID()){
        throw new RuntimeException('assessment_credential_durable_review_not_blocked');}
      if($name==='session'){
        $record(true,'assessment-credential-durable-submit-binds-three-actual-source-digests');
        $record(true,'assessment-credential-repeat-preview-stable-without-domain-write');
        $record(true,'assessment-credential-independent-review-persists-blocked-not-permit');}
      // Each native DAO revocation commits outside the Store owner. It is not a
      // claim that every native DELETE producer has a recovery INSERT fence.
      $row->delete();$after=$sample()['binding'];$changed=array();
      foreach($before['credential_readset']['sources']as$kind=>$entry){if($entry!==$after['credential_readset']['sources'][$kind]){$changed[]=$kind;}}
      $refused=governance_recovery_assessment_credential_refuse($conn,static function()use($approver,$id,$name,$suffix){
        PhorgeRecoveryRestoreService::review($approver['context'],$id,'2','assessment-stale-'.$name.'-'.$suffix);},'recovery_restore_sources_stale');
      $record($changed===array($name)&&$refused,'assessment-credential-'.$name.'-change-refuses-old-review-without-write');
    }
    $fresh=PhorgeRecoveryRestoreService::submit($requester['context'],array('native_stop_transaction'=>$stop,
      'operation_id'=>'assessment-final-submit-'.$suffix,'reason'=>'Synthetic incomplete native source set','subject'=>$subject->getPHID(),'ttl_seconds'=>600));
    PhorgeRecoveryRestoreService::review($approver['context'],$fresh['request_id'],'1','assessment-final-review-'.$suffix);
    $record(governance_recovery_assessment_credential_refuse($conn,static function()use($requester,$fresh){
      PhorgeRecoveryRestoreService::execute($requester['context'],$fresh['request_id'],'2');},'recovery_restore_sources_incomplete'),
      'assessment-credential-execute-still-requires-all-thirteen-families');
  });
  $labels=array_keys($done);sort($labels,SORT_STRING);$expected=governance_recovery_assessment_credential_obligations();sort($expected,SORT_STRING);
  if($labels!==$expected){throw new RuntimeException('assessment_credential_obligations_missing');}
  return array('profile'=>'isolated-durable-assessment-native-credential-readset-v1','executed_case_obligations'=>$labels,
    'actual_durable_submit_preview_review'=>true,'three_native_credential_sources_consumed'=>true,'native_DAO_revocations_exercised'=>true,
    'participant_owns_transaction'=>false,'production_observer_factory_available'=>false,'all_13_sources_complete'=>false,
    'restoration_authorized'=>false,'permit'=>false,'execution_blocked'=>true,'cross_database_atomicity_proved'=>false);
}
