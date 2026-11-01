<?php
if(!isset($fixture,$owner,$namespace)){throw new Exception('Owned recovery fixture required.');}
$operation=$mode;$mode='recovery-bootstrap';require __DIR__.'/queue_fixture.php';$mode=$operation;
$path=$dir.'/recovery-state.json';
function recovery_park($id){$t=new PhabricatorWorkerActiveTask();$c=$t->establishConnection('w');queryfx($c,'UPDATE %T SET leaseOwner=%s,leaseExpires=2147483647 WHERE id!=%d',$t->getTableName(),'owned-fixture-parked',$id);queryfx($c,'UPDATE %T SET leaseOwner=NULL,leaseExpires=0 WHERE id=%d',$t->getTableName(),$id);}
function recovery_scope($owner){
 if($owner==='native'){return null;}
 $uri=getenv('PERMISSION_RECOVERY_QUEUE_URI');$parsed=parse_url($uri);
 if($owner!=='gorge'||($parsed['host']??null)!=='127.0.0.1'||($parsed['scheme']??null)!=='http'){throw new Exception('Owned loopback queue required.');}
 $scope=PhabricatorEnv::beginScopedEnv();$scope->overrideEnvConfig('gorge.taskqueue.owner','gorge');$scope->overrideEnvConfig('gorge.taskqueue.uri',$uri);$scope->overrideEnvConfig('gorge.taskqueue.token','recovery-queue-only');$scope->overrideEnvConfig('gorge.service-policy','required');if(!PhabricatorGorgeTaskQueueClient::isConfigured()){throw new Exception('Gorge did not own the isolated queue.');}return $scope;
}
if($mode==='recovery-xlsx'){
 $checks=array('optionalEnabled'=>(new PhabricatorExcelExportFormat())->isExportFormatEnabled());$tasks=array();
 foreach(array('public','private') as $kind){$t=ManiphestTask::initializeNewTask($creator)->setTitle(($kind==='public'?'=1+2 public canary':'Private XLSX canary').' ,"quoted"')->setViewPolicy($kind==='public'?PhabricatorPolicies::POLICY_USER:$creator->getPHID())->setEditPolicy($creator->getPHID())->save();$tasks[$kind]=$t;}
 foreach(array('owner','outsider') as $role){$viewer=queue_actor($role);$engine=id(new ManiphestTaskSearchEngine())->setViewer($viewer);$saved=$engine->newSavedQuery()->setParameter('ids',mpull($tasks,'getID'));$engine->saveQuery($saved);
 $file=id(new PhabricatorExportEngine())->setViewer($viewer)->setSearchEngine($engine)->setSavedQuery($saved)->setExportFormat(new PhabricatorExcelExportFormat())->setTitle('Isolated XLSX')->setFilename('recovery')->exportFile();
 file_put_contents($dir.'/'.$role.'.xlsx',$file->loadFileData());file_put_contents($dir.'/'.$role.'-columns.json',json_encode(array_values(mpull($engine->newExportFieldList(),'getKey'))));$checks[$role.'PrivateFile']=$file->getViewPolicy()===PhabricatorPolicies::POLICY_NOONE&&$file->getAuthorPHID()===$viewer->getPHID();
 if($role==='owner'){$hidden=$file;}}
 $denied=false;try{$p=id(new PhabricatorFileQuery())->setViewer($actor)->withPHIDs(array($hidden->getPHID()))->executeOne();$denied=!$p;}catch(PhabricatorPolicyException $ex){$denied=true;}$checks['outsiderCannotQueryOwnerFile']=$denied;echo json_encode($checks);return;
}
if($mode==='recovery-legacy-running'){
 $p=PhabricatorProject::initializeNewProject($creator)->setName('Legacy untouched')->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy(PhabricatorPolicies::POLICY_USER)->save();
 $job=PhabricatorWorkerBulkJob::initializeNewJob($actor,new PhabricatorEditEngineBulkJobType(),array('objectPHIDs'=>array($p->getPHID()),'xactions'=>array(array('type'=>PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'new'=>'Forbidden legacy replay'))))->setIsSilent(true)->setStatus(PhabricatorWorkerBulkJob::STATUS_RUNNING)->save();
 $item=PhabricatorWorkerBulkTask::initializeNewTask($job,$p->getPHID())->setStatus(PhabricatorWorkerBulkTask::STATUS_RUNNING)->save();$before=queue_project_state($p->getPHID());$worker=PhabricatorWorker::scheduleTask(PhabricatorWorkerBulkJobTaskWorker::class,array('jobID'=>$job->getID(),'taskID'=>$item->getID()));
 $result=queue_run($worker->getID());$item=id(new PhabricatorWorkerBulkTask())->load($item->getID());echo json_encode(array('explicitReconciliationFailure'=>$result instanceof PhabricatorWorkerArchiveTask&&(int)$result->getResult()===1,'unversionedStateUntouched'=>$item->getStatus()===PhabricatorWorkerBulkTask::STATUS_RUNNING&&queue_job($job->getID())->getStatus()===PhabricatorWorkerBulkJob::STATUS_RUNNING,'zeroLegacyBusinessWrite'=>$before===queue_project_state($p->getPHID())));return;
}
if($mode==='recovery-concurrency-seed'){
 $rows=array();foreach(array('member','space') as $kind){
 $p=PhabricatorProject::initializeNewProject($creator)->setName('Recovery permission '.$kind)->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy($kind==='member'?'obj.project.members':PhabricatorPolicies::POLICY_USER)->setJoinPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
 if($kind==='member'){route_edge($p,$creator,true);route_edge($p,$actor,true);$space=null;}else{$space=PhabricatorSpacesNamespace::initializeNewNamespace($creator)->setNamespaceName('Concurrent Space')->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy($creator->getPHID())->save();PhabricatorSpacesNamespaceQuery::destroySpacesCache();$p->setSpacePHID($space->getPHID())->save();}
 $rows[$kind]=array('project'=>$p->getPHID(),'space'=>$space?$space->getPHID():null);}
 file_put_contents($dir.'/recovery-permissions.json',json_encode($rows));echo json_encode(array('seeded'=>2));return;
}
if(str_starts_with($mode,'recovery-permission-')){
 $kind=$argv[2]??'';$s=json_decode(file_get_contents($dir.'/recovery-permissions.json'),true)[$kind];
 if($mode==='recovery-permission-revoke'){
 if($kind==='member'){route_edge(route_project($s['project']),$actor,false);}else{$space=id(new PhabricatorSpacesNamespaceQuery())->setViewer($omni)->withPHIDs(array($s['space']))->executeOne();id(new PhabricatorSpacesNamespaceEditor())->setActor($creator)->setContentSource(PhabricatorContentSource::newForSource('console',array()))->setIsSilent(true)->setContinueOnMissingFields(true)->applyTransactions($space,array(id(new PhabricatorSpacesNamespaceTransaction())->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)->setNewValue($creator->getPHID())));}
 echo json_encode(array('revoked'=>true));return;}
 if($mode==='recovery-permission-hold'){
 $p=route_project($s['project']);$editor=queue_editor($actor);
 $hook=new class extends PhabricatorEditorExtension {const EXTENSIONKEY='recovery-barrier';public function getExtensionName(){return 'Recovery barrier';}public function setObject($object){return $this;}public function validateTransactions($object,array $xactions){$d=getenv('PERMISSION_ROUTES_PRIVATE');file_put_contents($d.'/permission-ready','ready');$end=microtime(true)+40;while(!is_file($d.'/permission-release')){if(microtime(true)>$end){throw new Exception('Permission barrier timed out.');}usleep(10000);}return array();}};
 (new ReflectionProperty(PhabricatorApplicationTransactionEditor::class,'extensions'))->setValue($editor,array($hook->setViewer($actor)->setEditor($editor)));$committed=false;
 try{$editor->applyTransactions($p,array(queue_x(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'Recovery in flight '.$kind)));$committed=true;}catch(PhabricatorPolicyException $ex){}
 echo json_encode(array('committed'=>$committed));return;}
 if($mode==='recovery-permission-verify'){
 $before=queue_project_state($s['project']);$denied=false;try{queue_editor($actor)->applyTransactions(route_project($s['project']),array(queue_x(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'Forbidden recovered edit')));}catch(PhabricatorPolicyException $ex){$denied=true;}
 // Space admission is a viewer-scoped query boundary, separate from CAN_EDIT.
 $query_denied=false;try{$q=id(new PhabricatorProjectQuery())->setViewer($actor)->withPHIDs(array($s['project']))->executeOne();$query_denied=!$q;}catch(PhabricatorPolicyException $ex){$query_denied=true;}
 echo json_encode(array('freshEditDenied'=>$denied,'denialNoWrite'=>$before===queue_project_state($s['project']),'nativeQueryAdmission'=>$kind==='space'?$query_denied:!$query_denied));return;}
}
if($mode==='recovery-crash-seed'){
 $ownership=$argv[2];$phase=$argv[3];$scope=recovery_scope($ownership);
 $p=PhabricatorProject::initializeNewProject($creator)->setName('Recovery original')->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy(PhabricatorPolicies::POLICY_USER)->setJoinPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
 $job=PhabricatorWorkerBulkJob::initializeNewJob($actor,new PhabricatorEditEngineBulkJobType(),array('objectPHIDs'=>array($p->getPHID()),'xactions'=>array(array('type'=>PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'new'=>'Recovery committed '.$ownership.' '.$phase))))->setIsSilent(true)->setStatus(PhabricatorWorkerBulkJob::STATUS_WAITING)->save();
 $worker=PhabricatorWorker::scheduleTask(PhabricatorWorkerBulkJobCreateWorker::class,array('jobID'=>$job->getID()));
 if(str_starts_with($phase,'task-')||$phase==='retry'){recovery_park($worker->getID());queue_run($worker->getID());$worker=queue_find(PhabricatorWorkerBulkJobTaskWorker::class,$job->getID());}
 $state=array('owner'=>$ownership,'phase'=>$phase,'project'=>$p->getPHID(),'jobID'=>$job->getID(),'workerID'=>$worker->getID(),'expectedName'=>'Recovery committed '.$ownership.' '.$phase);file_put_contents($path,json_encode($state));
 $w=new PhabricatorWorkerBulkJob();$c=$w->establishConnection('w');
 $body='DO SLEEP(60);';
 if($phase==='creator-before'){$table=$w->getTableName();$condition='NEW.id='.(int)$job->getID()." AND NEW.status='running'";$event='UPDATE';}
 else if($phase==='creator-after'){$table=id(new PhabricatorWorkerArchiveTask())->getTableName();$condition='NEW.id='.(int)$worker->getID();$event='INSERT';}
 else if($phase==='task-after'){$t=head(id(new PhabricatorWorkerBulkTask())->loadAllWhere('bulkJobPHID=%s',$job->getPHID()));$table=$t->getTableName();$condition='NEW.id='.(int)$t->getID()." AND NEW.status='done'";$event='UPDATE';}
 else if($phase==='retry'){$t=head(id(new PhabricatorWorkerBulkTask())->loadAllWhere('bulkJobPHID=%s',$job->getPHID()));$table=$t->getTableName();$condition='NEW.id='.(int)$t->getID()." AND NEW.status='running'";$event='UPDATE';$body="SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated Gorge retry';";}
 else if($phase==='task-before'){$x=new PhabricatorProjectTransaction();$c=$x->establishConnection('w');$table=$x->getTableName();$condition=qsprintf($c,'NEW.objectPHID=%s',$p->getPHID());$event='INSERT';}
 else{throw new Exception('Unknown crash boundary.');}
 queryfx($c,'CREATE TRIGGER recovery_crash_barrier BEFORE %Q ON %T FOR EACH ROW BEGIN IF %Q THEN %Q END IF; END',$event,$table,$condition,$body);
 echo json_encode(array('seeded'=>true));return;
}
if($mode==='recovery-baseline'){echo json_encode(array('nativeOwnerRestored'=>!PhabricatorGorgeTaskQueueClient::isConfigured(),'xlsxNotGloballyEnabled'=>!(new PhabricatorExcelExportFormat())->isExportFormatEnabled(),'optionalProjectFlagsOff'=>PhabricatorEnv::getEnvConfig('project.workspace-mode')==='native'&&PhabricatorEnv::getEnvConfig('project.history-state')==='off'));return;}
$s=json_decode(file_get_contents($path),true);
if($mode==='recovery-gorge-retry'){
 $scope=recovery_scope('gorge');$before=queue_project_state($s['project']);recovery_park($s['workerID']);
 try{$failed=queue_run($s['workerID']);}finally{$w=new PhabricatorWorkerBulkJob();queryfx($w->establishConnection('w'),'DROP TRIGGER recovery_crash_barrier');}
 $stored=id(new PhabricatorWorkerActiveTask())->load($s['workerID']);$checks=array('transientRetained'=>$failed instanceof PhabricatorWorkerActiveTask,'oneFailure'=>(int)$stored->getFailureCount()===1,'backoffStored'=>(int)$stored->getLeaseExpires()>time(),'zeroBusinessWrite'=>$before===queue_project_state($s['project']));
 recovery_park($s['workerID']);$done=queue_run($s['workerID']);$after=queue_project_state($s['project']);$job=queue_job($s['jobID']);$items=id(new PhabricatorWorkerBulkTask())->loadAllWhere('bulkJobPHID=%s',$job->getPHID());
 $checks['retryArchived']=$done instanceof PhabricatorWorkerArchiveTask&&(int)$done->getResult()===0;$checks['oneBusinessCommit']=$after['count']===1&&$after['name']===$s['expectedName'];$checks['jobComplete']=$job->getStatus()===PhabricatorWorkerBulkJob::STATUS_COMPLETE;$checks['oneItem']=count($items)===1;echo json_encode($checks);return;
}
if($mode==='recovery-crash-observe'){
 $w=new PhabricatorWorkerBulkJob();$threads=queryfx_all($w->establishConnection('r'),'SHOW PROCESSLIST');$found=array();
 foreach($threads as $thread){if(in_array($thread['db'],array($namespace.'_worker',$namespace.'_project'),true)&&$thread['State']==='User sleep'){$found[]=$thread;}}
 if(count($found)>1){throw new Exception('Ambiguous owned crash connection.');}
 if($found){file_put_contents($dir.'/recovery-sleep.json',json_encode(array('id'=>(int)head($found)['Id'])));}
 echo json_encode(array('atBarrier'=>count($found)===1));return;
}
if($mode==='recovery-crash-run'){$scope=recovery_scope($s['owner']);recovery_park($s['workerID']);queue_run($s['workerID']);echo json_encode(array('finished'=>true));return;}
if($mode==='recovery-crash-clean'){
 $w=new PhabricatorWorkerBulkJob();$c=$w->establishConnection('w');$row=array('connectionID'=>json_decode(file_get_contents($dir.'/recovery-sleep.json'),true)['id']);
 $threads=queryfx_all($c,'SHOW PROCESSLIST');foreach($threads as $thread){if((int)$thread['Id']===(int)$row['connectionID']){if(!in_array($thread['db'],array($namespace.'_worker',$namespace.'_project'),true)){throw new Exception('Foreign connection refused.');}queryfx($c,'KILL CONNECTION %d',$row['connectionID']);}}
 $trigger_conn=$s['phase']==='task-before'?(new PhabricatorProjectTransaction())->establishConnection('w'):$c;queryfx($trigger_conn,'DROP TRIGGER recovery_crash_barrier');
 $job=queue_job($s['jobID']);$items=id(new PhabricatorWorkerBulkTask())->loadAllWhere('bulkJobPHID=%s',$job->getPHID());$business=queue_project_state($s['project']);
 $active=id(new PhabricatorWorkerActiveTask())->load($s['workerID']);
 if($s['phase']==='creator-before'){$durable=$job->getStatus()===PhabricatorWorkerBulkJob::STATUS_WAITING&&count($items)===0&&!$job->getParameter('nativeQueueBatchVersion');}
 else{$durable=$job->getStatus()===PhabricatorWorkerBulkJob::STATUS_RUNNING&&count($items)===1&&$job->getParameter('nativeQueueBatchVersion')===1&&head($items)->getStatus()===($s['phase']==='creator-after'?PhabricatorWorkerBulkTask::STATUS_WAITING:PhabricatorWorkerBulkTask::STATUS_RUNNING);}
 echo json_encode(array('queueTaskStillActive'=>(bool)$active,'businessBoundary'=>$business['count']===($s['phase']==='task-after'?1:0),'durableItemAndJobState'=>$durable));return;
}
if($mode==='recovery-crash-resume'){
 $scope=recovery_scope($s['owner']);$before=queue_project_state($s['project']);recovery_park($s['workerID']);$result=queue_run($s['workerID']);$job=queue_job($s['jobID']);$items=id(new PhabricatorWorkerBulkTask())->loadAllWhere('bulkJobPHID=%s',$job->getPHID());$checks=array('oneBusinessItem'=>count($items)===1);
 if(str_starts_with($s['phase'],'creator-')){$checks['creatorAcknowledged']=$result instanceof PhabricatorWorkerArchiveTask&&(int)$result->getResult()===PhabricatorWorkerArchiveTask::RESULT_SUCCESS;$child=queue_find(PhabricatorWorkerBulkJobTaskWorker::class,$job->getID());recovery_park($child->getID());$finished=queue_run($child->getID());$checks['childArchived']=$finished instanceof PhabricatorWorkerArchiveTask&&(int)$finished->getResult()===PhabricatorWorkerArchiveTask::RESULT_SUCCESS;$after=queue_project_state($s['project']);$checks['oneBusinessCommit']=$after['count']===1&&$after['name']===$s['expectedName'];}
 else{$checks['interruptedWorkerFailedClosed']=$result instanceof PhabricatorWorkerArchiveTask&&(int)$result->getResult()===PhabricatorWorkerArchiveTask::RESULT_FAILURE;$checks['indeterminateItemFailed']=head($items)->getStatus()===PhabricatorWorkerBulkTask::STATUS_FAIL;$checks['noBusinessReplay']=$before===queue_project_state($s['project']);}
 $checks['jobComplete']=queue_job($job->getID())->getStatus()===PhabricatorWorkerBulkJob::STATUS_COMPLETE;echo json_encode($checks);return;
}
throw new Exception('Unknown recovery operation.');
