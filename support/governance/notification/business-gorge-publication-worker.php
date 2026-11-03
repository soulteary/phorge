<?php
/** Independent fixed native publication probe; never an HTTP route or daemon. */
$root=dirname(__DIR__,3);$proof=null;$guard=null;$owned=false;$buffer=false;$failure=null;$result=null;
$mode=(new mysqli_driver())->report_mode;
try{
  require_once$root.'/support/runtime/php-version.php';phorge_assert_supported_php_version(PHP_VERSION);
  if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||count($argv)!==2){throw new RuntimeException('business_publication_scope_refused');}
  foreach(array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE')as$key){$value=getenv($key);if($value!==false&&$value!==''){throw new RuntimeException('business_publication_deployment_refused');}}
  foreach(array('local.json','deployment.json','ENVIRONMENT')as$name){if(file_exists($root.'/conf/local/'.$name)){throw new RuntimeException('business_publication_deployment_refused');}}
  require_once __DIR__.'/race-owned-config.php';$directory=$argv[1];$data=governance_private_mail_config($directory);
  $path=$directory.'/probe.json';if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>2048){throw new RuntimeException('business_publication_payload_refused');}
  $probe=json_decode(file_get_contents($path),true,4,JSON_THROW_ON_ERROR);
  if(!is_array($probe)||array_keys($probe)!==array('owner','task_id','object_phid','phase','lease_owner','lease_expires')||$probe['owner']!==$data['owner']||
    !in_array($probe['phase'],array('prepare','execute'),true)||!is_string($probe['lease_owner'])||!preg_match('/\Abusiness-gorge-[a-f0-9]{32}\z/D',$probe['lease_owner'])||!is_int($probe['lease_expires'])||$probe['lease_expires']<=time()||
    !is_int($probe['task_id'])||$probe['task_id']<1||!is_string($probe['object_phid'])||!preg_match('/\APHID-(?:TASK|PROJ)-[a-zA-Z0-9]{20}\z/D',$probe['object_phid'])){throw new RuntimeException('business_publication_payload_refused');}
  require_once$root.'/support/runtime/bootstrap.php';ob_start();$buffer=true;phutil_load_library($root.'/src/');
  $proof=governance_private_mail_proof($data);governance_private_mail_install_env($data);$owned=true;
  require_once __DIR__.'/business-queue-config.php';
  $queue_scope=governance_private_business_queue_scope($directory,$data['owner']);
  $guard=new AphrontWriteGuard(static function()use($proof,$data){$row=$proof->query('SELECT owner_nonce FROM `'.$data['writer']['namespace'].'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();if(!$row||!hash_equals($data['fixture_nonce'],$row['owner_nonce'])){throw new RuntimeException('business_publication_owner_changed');}});
  $task=(new PhabricatorWorkerActiveTask())->load($probe['task_id']);
  if(!$task||$task->getTaskClass()!=='PhabricatorApplicationTransactionPublishWorker'||$task->getObjectPHID()!==$probe['object_phid']||$task->getLeaseOwner()!==$probe['lease_owner']||(int)$task->getLeaseExpires()!==$probe['lease_expires']){throw new RuntimeException('business_publication_task_refused');}
  $stored=(new PhabricatorWorkerTaskData())->load($task->getDataID());$payload=$stored?$stored->getData():null;
  if(!is_array($payload)||($payload['objectPHID']??null)!==$probe['object_phid']||!is_array($payload['xactionPHIDs']??null)||!$payload['xactionPHIDs']||count($payload['xactionPHIDs'])>16){throw new RuntimeException('business_publication_data_refused');}
  $visible=(new PhabricatorObjectQuery())->setViewer(PhabricatorUser::getOmnipotentUser())->withPHIDs(array($probe['object_phid']))->executeOne();
  $project_description=false;
  if($visible instanceof PhabricatorProject){
    $fields=PhabricatorCustomField::getObjectFields($visible,PhabricatorCustomField::ROLE_VIEW);
    $fields->readFieldsFromStorage($visible);
    $field=PhabricatorCustomField::getObjectField($visible,PhabricatorCustomField::ROLE_VIEW,'std:project:internal:description');
    $project_description=$field&&$field->getProxy()->getFieldValue()==='SYNTHETIC_PRIVATE_BUSINESS_COMMENT';
  }
  $mail=new PhabricatorMetaMTAMail();$start=(int)queryfx_one($mail->establishConnection('r'),'SELECT COALESCE(MAX(id),0) n FROM %R',$mail)['n'];
  $_SERVER['HTTP_X_SERVICE_TOKEN']=$data['service_token'];
  $handler=(new ConduitCall('worker.execute',array('taskID'=>$probe['task_id'],'taskClass'=>$task->getTaskClass(),
    'phase'=>$probe['phase'],'executionVersion'=>1,'failureCount'=>(int)$task->getFailureCount(),
    'priority'=>(int)$task->getPriority(),'leaseOwner'=>$probe['lease_owner'],'leaseExpires'=>$probe['lease_expires'],
    'data'=>phutil_json_encode($payload))))->execute();
  $classification=$handler['result']??null;
  if(!in_array($classification,array('prepared','success','permanent-failure'),true)){
    throw new RuntimeException('business_gorge_handler_unexpected_result');
  }
  $missing=$classification==='permanent-failure';
  if($missing&&($visible!==null||($handler['failureReason']??null)!=='Unable to load object with PHID "'.$probe['object_phid'].'"!')){
    throw new RuntimeException('business_gorge_unexpected_permanent_failure');
  }
  if($probe['phase']==='execute'){
    require_once __DIR__.'/business-gorge-queue.php';
    $queue=json_decode(file_get_contents($directory.'/queue.json'),true,4,JSON_THROW_ON_ERROR);
    $lease=array('taskID'=>$probe['task_id'],'leaseOwner'=>$probe['lease_owner'],'leaseExpires'=>$probe['lease_expires']);
    if($classification==='success'){
      $followups=$handler['followups']??null;
      if(!is_array($followups)||count($followups)>64){throw new RuntimeException('business_gorge_followup_budget');}
      governance_business_gorge_http($queue,'finalize',$lease+array('duration'=>0,'followups'=>$followups),$probe['lease_owner']);
    }else{governance_business_gorge_http($queue,'resolve',$lease+array('outcome'=>'failure'),$probe['lease_owner']);}
  }
  $mails=$mail->loadAllWhere('id>%d AND relatedPHID=%s ORDER BY id',$start,$probe['object_phid']);$envelopes=array();
  foreach($mails as$item){$recipients=array_values(array_unique(array_merge($item->getToPHIDs(),$item->getCcPHIDs())));
    $allowed=PhorgePrivateMailPolicy::authorize($item);$prepared=$item->prepareGorgeDelivery();$physical=false;
    if(isset($prepared['authorization'])){$physical=($item->authorizeGorgePhysicalDelivery($prepared['deliveryID'],$prepared['authorization']['messageHash'],phutil_json_encode($prepared['message']))['allowed']??null)===true;}
    $envelopes[]=array('mail_id'=>(int)$item->getID(),'recipient_count'=>count($recipients),'recipients'=>$recipients,
      'policy_authorized'=>$allowed,'prepared'=>isset($prepared['authorization']),
      'no_private_producer_payload'=>!str_contains(phutil_json_encode($prepared),'SYNTHETIC_PRIVATE_BUSINESS_COMMENT')&&!str_contains(phutil_json_encode($prepared),'Synthetic business '),
      'physical_gate_allowed'=>$physical,'quarantined'=>$item->getPrivateMailPolicyValue('governance.delivery-quarantined',false));}
  $result=array('passed'=>true,'actual_owned_taskqueue_http_ready'=>true,'source_visible'=>$visible!==null,'native_publication_missing_source_permanent'=>$missing,
    'publication_completed'=>$classification==='success','native_project_description_present'=>$project_description,'handler_result'=>$classification,'envelopes'=>$envelopes,'physical_delivery_attempted'=>false);
}catch(Throwable$error){$failure=array('type'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine());}
finally{try{if($guard!==null){$guard->dispose();}if($owned){LiskDAO::closeAllConnections();}if($proof!==null){$proof->close();}}catch(Throwable$error){$failure=array('type'=>'cleanup_failed');}mysqli_report($mode);if($buffer){ob_end_clean();}}
echo json_encode($failure?array('passed'=>false,'failure'=>$failure):$result,JSON_THROW_ON_ERROR)."\n";exit($failure?1:0);
