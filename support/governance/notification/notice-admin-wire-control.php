<?php
/** Fixed owned CLI controls; no HTTP mutation route or caller-selected target. */
$level=ob_get_level();ob_start();
set_error_handler(static function($severity,$message,$file,$line){if(!(error_reporting()&$severity)){return false;}
  throw new ErrorException('notice_wire_control_runtime_error',0,$severity,$file,$line);});
$root=dirname(__DIR__,3);$proof=null;$guard=null;$installed=false;$result=null;$failed=false;
try{
  require_once$root.'/support/runtime/php-version.php';phorge_assert_supported_php_version(PHP_VERSION);
  if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||count($argv)!==2||
    !in_array($argv[1],array('status','mutate','relay-retry'),true)){
    throw new RuntimeException('notice_wire_control_action_refused');}
  foreach(array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE')as$key){$v=getenv($key);
    if($v!==false&&$v!==''){throw new RuntimeException('notice_wire_deployment_refused');}}
  foreach(array('local.json','deployment.json','ENVIRONMENT')as$name){if(file_exists($root.'/conf/local/'.$name)){
    throw new RuntimeException('notice_wire_deployment_refused');}}
  require_once __DIR__.'/notice-wire-config.php';$config=governance_notice_wire_config(getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR'));
  if(!in_array($config['notice']['case'],array('allow','admin-revoked'),true)){throw new RuntimeException('notice_admin_wire_fixed_case_required');}
  $data=$config['base'];$control=governance_notice_wire_json($config['directory'],'notice-control.json',8192);
  $keys=array_keys($control);sort($keys);
  if($keys!==array('actor_phid','email_phid','event_id','native_transaction_phid','operator_phid','operator_session','owner','source_kind')||
    ($control['owner']??null)!==$data['owner']||$control['source_kind']!=='native-admin-grant'||
    !is_string($control['native_transaction_phid'])||!preg_match('/\APHID-XACT-USER-[a-zA-Z0-9]{15}\z/D',$control['native_transaction_phid'])||
    !is_string($control['event_id'])||!preg_match('/\A[a-f0-9]{64}\z/D',$control['event_id'])||
    !is_string($control['operator_session'])||strlen($control['operator_session'])>256){throw new RuntimeException('notice_wire_control_binding_refused');}
  foreach(array('actor_phid','operator_phid','email_phid')as$key){$type=$key==='email_phid'?'EADR':'USER';
    if(!is_string($control[$key])||!preg_match('/\APHID-'.$type.'-[a-zA-Z0-9]{20}\z/D',$control[$key])){
      throw new RuntimeException('notice_wire_control_binding_refused');}}
  require_once$root.'/support/runtime/bootstrap.php';phutil_load_library($root.'/src/');
  if(!hash_equals($data['source_sha256'],hash_file('sha256',$root.'/resources/sql/quickstart.sql'))){throw new RuntimeException('notice_wire_source_refused');}
  $console=PhutilConsole::newLocalConsole();$console->disableOut();PhutilConsole::setConsole($console);
  $proof=governance_private_mail_proof($data);governance_notice_wire_install_env($config);$installed=true;
  $guard=new AphrontWriteGuard(static function()use($proof,$data){
    $row=$proof->query('SELECT owner_nonce FROM `'.$data['writer']['namespace'].'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();
    if(!$row||!hash_equals($data['fixture_nonce'],$row['owner_nonce'])){throw new RuntimeException('notice_wire_owner_changed');}});
  $meta=(new PhabricatorMetaMTAGorgeOutbox())->establishConnection('w');
  $event=queryfx_one($meta,'SELECT eventID,state,sourceKind,sourceID FROM governance_notice_event WHERE eventID=%s',$control['event_id']);
  if(!$event||$event['eventID']!==$control['event_id']||!in_array($event['state'],array('pending','prepared'),true)){
    throw new RuntimeException('notice_wire_event_refused');}
  if($event['sourceKind']!=='native-admin-grant'){throw new RuntimeException('notice_admin_wire_source_kind_refused');}
  $actual_xaction=(new PhabricatorUserTransaction())->load($event['sourceID']);
  if(!$actual_xaction||$actual_xaction->getPHID()!==$control['native_transaction_phid']||$actual_xaction->getObjectPHID()!==$control['actor_phid']){throw new RuntimeException('notice_admin_wire_source_binding_refused');}
  if($argv[1]==='status'){
    $rows=queryfx_all($meta,'SELECT mailPHID FROM governance_notice_delivery WHERE eventID=%s ORDER BY recipientPHID',$event['eventID']);
    if(count($rows)>128){throw new RuntimeException('notice_wire_mapping_budget_refused');}
    $mails=array();
    foreach($rows as$row){$mail=(new PhabricatorMetaMTAMail())->loadOneWhere('BINARY phid=BINARY %s',$row['mailPHID']);
      if(!$mail||$mail->getPHID()!==$row['mailPHID']){throw new RuntimeException('notice_wire_mapped_mail_missing');}
      if(!in_array($mail->getStatus(),array(PhabricatorMailOutboundStatus::STATUS_QUEUE,PhabricatorMailOutboundStatus::STATUS_SENT,
        PhabricatorMailOutboundStatus::STATUS_FAIL,PhabricatorMailOutboundStatus::STATUS_VOID,PhabricatorMailOutboundStatus::STATUS_UNKNOWN,PhabricatorMailOutboundStatus::STATUS_EXPIRED),true)){
        throw new RuntimeException('notice_wire_mail_state_refused');}
      $snapshot=$mail->getParameters()['gorge.delivery-snapshot']??null;
      $mails[]=array('status'=>$mail->getStatus(),'quarantined'=>$mail->getPrivateMailPolicyValue('governance.delivery-quarantined',false)===true,
        'snapshot_present'=>$snapshot!==null,'snapshot_sha256'=>$snapshot===null?null:hash('sha256',phutil_json_encode($snapshot)));
    }
    $relay=queryfx_one($meta,'SELECT deliveredEpoch,queueTaskID FROM metamta_gorgeoutbox WHERE eventID=%s','governance.notice/'.$event['eventID'].'/1');
    $result=array('passed'=>true,'action'=>'status','event_state'=>$event['state'],'mail_count'=>count($rows),'mails'=>$mails,
      'relay_delivered'=>$relay!==null&&$relay['deliveredEpoch']!==null,
      'relay_task_present'=>$relay!==null&&$relay['queueTaskID']!==null);
  }elseif($argv[1]==='relay-retry'){
    queryfx($meta,'UPDATE metamta_gorgeoutbox SET deliveredEpoch=NULL,nextAttempt=0 WHERE eventID=%s AND deliveredEpoch IS NOT NULL',
      'governance.notice/'.$event['eventID'].'/1');
    if($meta->getAffectedRows()!==1){throw new RuntimeException('notice_wire_relay_replay_precondition');}
    $result=array('passed'=>true,'action'=>'relay-retry','native_event_unchanged'=>true);
  }else{
    $case=$config['notice']['case'];
    if($case!=='admin-revoked'){throw new RuntimeException('notice_admin_wire_mutation_not_allowed');}
    $sessions=new PhabricatorAuthSessionEngine();
    $operator=$sessions->loadUserForSession(PhabricatorAuthSession::TYPE_WEB,$control['operator_session']);
    if(!$operator||$operator->getPHID()!==$control['operator_phid']||!$operator->getIsAdmin()||$operator->getIsDisabled()){
      throw new RuntimeException('notice_admin_wire_operator_refused');}
    $_SERVER['REQUEST_METHOD']='POST';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$control['operator_session'];
    $request=(new AphrontRequest('fixture.invalid','/people/fixture/'))->setUser($operator)
      ->setRequestData(array(AphrontRequest::getCSRFTokenName()=>$operator->getCSRFToken()));$request->validateCSRF();
    $user=(new PhabricatorUser())->loadOneWhere('BINARY phid=BINARY %s',$control['actor_phid']);
    if(!$user||$user->getPHID()!==$control['actor_phid']||!$user->getIsAdmin()){
      throw new RuntimeException('notice_admin_wire_target_precondition_refused');}
    $writer=$user->establishConnection('w');$before=(int)queryfx_one($writer,'SELECT COALESCE(MAX(id),0) n FROM %R WHERE objectPHID=%s',
      new PhabricatorUserTransaction(),$user->getPHID())['n'];
    (new PhabricatorUserTransactionEditor())->setActor($operator)->setContentSourceFromRequest($request)->setCancelURI('/people/')
      ->applyTransactions($user,array((new PhabricatorUserTransaction())
        ->setTransactionType(PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE)->setNewValue(false)));
    $row=queryfx_one($writer,'SELECT phid,authorPHID,oldValue,newValue FROM %R WHERE objectPHID=%s AND transactionType=%s AND id>%d',
      new PhabricatorUserTransaction(),$user->getPHID(),PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE,$before);
    if(!$row||$row['authorPHID']!==$operator->getPHID()||$row['oldValue']!=='true'||$row['newValue']!=='false'||$writer->isInsideTransaction()){
      throw new RuntimeException('notice_admin_wire_native_revoke_not_committed');}
    $primary=queryfx_one($writer,'SELECT CONNECTION_ID() thread,@@server_uuid uuid');
    $proof->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $identity=$proof->query('SELECT CONNECTION_ID() thread,@@server_uuid uuid,@@autocommit ac')->fetch_assoc();
    if((string)$identity['thread']===(string)$primary['thread']||$identity['uuid']!==$primary['uuid']||!in_array($identity['ac'],array(1,'1'),true)){
      throw new RuntimeException('notice_admin_wire_revoke_observer_refused');}
    $observe=$proof->prepare('SELECT isAdmin FROM `'.$data['writer']['namespace'].'_user`.`user` WHERE id=? AND phid=?');
    $id=$user->getID();$phid=$user->getPHID();$observe->bind_param('is',$id,$phid);
    try{$observe->execute();$committed=$observe->get_result()->fetch_all(MYSQLI_ASSOC);}finally{$observe->close();}
    if(count($committed)!==1||!in_array($committed[0]['isAdmin'],array(0,'0'),true)){
      throw new RuntimeException('notice_admin_wire_revoke_observed_commit_refused');}
    $result=array('passed'=>true,'action'=>$argv[1],'case'=>$case,'native_mail_not_prepared_by_control'=>true);
  }
}catch(Throwable$error){$failed=true;$result=array('passed'=>false,'error_type'=>get_class($error),
  'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());}
finally{
  try{if($guard!==null){$guard->dispose();}if($installed){LiskDAO::closeAllConnections();}if($proof!==null){$proof->close();}}
  catch(Throwable$error){$failed=true;$result=array('passed'=>false,'error_type'=>'RuntimeException','cleanup_failed'=>true);}
}
$output='';while(ob_get_level()>$level){$output=ob_get_clean().$output;}restore_error_handler();
if($output!==''){$failed=true;$result=array('passed'=>false,'unexpected_native_output_bytes'=>strlen($output),'unexpected_native_output_sha256'=>hash('sha256',$output));}
echo json_encode($result,JSON_THROW_ON_ERROR)."\n";exit($failed?1:0);
