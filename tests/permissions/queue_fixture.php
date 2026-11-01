<?php

// Never bootstrap independently: the parent has verified namespace ownership.
if (!isset($fixture,$owner,$namespace) || PHP_SAPI!=='cli') { throw new Exception('Owned disposable fixture required.'); }
$dir=getenv('PERMISSION_ROUTES_PRIVATE');
function queue_actor($role) { global $fixture,$omni;return id(new PhabricatorPeopleQuery())->setViewer($omni)->withPHIDs(array($fixture['users'][$role]['phid']))->needUserSettings(true)->executeOne(); }
function queue_editor($actor) { return id(new PhabricatorProjectTransactionEditor())->setActor($actor)->setContentSource(PhabricatorContentSource::newForSource('console',array()))->setIsSilent(true)->setContinueOnMissingFields(true)->setContinueOnNoEffect(true); }
function queue_x($type,$value) {return id(new PhabricatorProjectTransaction())->setTransactionType($type)->setNewValue($value);}
function queue_run($id) {
  $tasks=id(new PhabricatorWorkerLeaseQuery())->withIDs(array($id))->setLimit(1)->execute();
  if(count($tasks)!==1){throw new Exception('Owned queue task not leased.');}
  return head($tasks)->executeTask();
}
function queue_find($class,$job_id) {
  $rows=id(new PhabricatorWorkerActiveTaskQuery())->withClassNames(array($class))->execute();$found=array();
  foreach($rows as $row){$data=id(new PhabricatorWorkerTaskData())->load($row->getDataID())->getData();if((int)idx($data,'jobID')===(int)$job_id){$found[]=$row;}}
  if(count($found)!==1){throw new Exception('Expected exactly one owned worker task.');}return head($found);
}
function queue_job($id) {return id(new PhabricatorWorkerBulkJobQuery())->setViewer(PhabricatorUser::getOmnipotentUser())->withIDs(array($id))->executeOne();}
function queue_token_rows($project) {return queryfx_all($project->establishConnection('r'),'SELECT * FROM %T WHERE projectID=%d ORDER BY id',PhabricatorProject::TABLE_DATASOURCE_TOKEN,$project->getID());}
function queue_project_state($phid) {
  $p=route_project($phid);$x=new PhabricatorProjectTransaction();
  $rows=queryfx_all($x->establishConnection('r'),'SELECT * FROM %T WHERE objectPHID=%s ORDER BY id',$x->getTableName(),$phid);
  return array('name'=>$p->getName(),'count'=>count($rows),'hash'=>hash('sha256',json_encode($rows)));
}
$actor=queue_actor('outsider');$creator=queue_actor('owner');
if($mode==='recovery-bootstrap'){return;}
if($mode==='queue-formats') {
  $rows=array();
  foreach(array('public','private') as $kind){
    $t=ManiphestTask::initializeNewTask($creator)->setTitle(($kind==='public'?'=FormatCanary':'SecretFormat').$fixture['token'].' ,"quoted"'."\t".$kind)->setViewPolicy($kind==='private'?$creator->getPHID():PhabricatorPolicies::POLICY_USER)->setEditPolicy($creator->getPHID())->save();
    $rows[$kind]=array('id'=>$t->getID(),'phid'=>$t->getPHID(),'title'=>$t->getTitle());
  }
  $checks=array();
  foreach(array('owner','outsider') as $role){
    $viewer=queue_actor($role);$engine=id(new ManiphestTaskSearchEngine())->setViewer($viewer);
    $saved=$engine->newSavedQuery()->setParameter('ids',array_column($rows,'id'));$engine->saveQuery($saved);
    foreach(array('csv'=>new PhabricatorCSVExportFormat(),'text'=>new PhabricatorTextExportFormat()) as $key=>$format){
      $file=id(new PhabricatorExportEngine())->setViewer($viewer)->setSearchEngine($engine)->setSavedQuery($saved)->setExportFormat($format)->setFilename('owned-format')->setTitle('Owned format')->exportFile();
      $bytes=$file->loadFileData();$lines=explode("\n",rtrim($bytes,"\n"));$parsed=array();
      foreach($lines as $line){$parsed[]=$key==='csv'?str_getcsv($line,',','"',''):explode("\t",$line);}
      $columns=count(head($parsed));$aligned=true;foreach($parsed as $line){$aligned=$aligned&&count($line)===$columns;}
      $prefix=$role.' '.$key.' ';
      $checks[$prefix.'rowCount']=count($parsed)===($role==='owner'?3:2);
      $checks[$prefix.'emptyColumnsRetained']=$aligned && $columns>10;
      $checks[$prefix.'visiblePositive']=str_contains($bytes,'FormatCanary'.$fixture['token']);
      $checks[$prefix.'hiddenMetadata']=$role==='owner'?str_contains($bytes,'SecretFormat'.$fixture['token']):(!str_contains($bytes,'SecretFormat'.$fixture['token'])&&!str_contains($bytes,$rows['private']['phid']));
      $checks[$prefix.'privateFile']=$file->getViewPolicy()===PhabricatorPolicies::POLICY_NOONE && $file->getAuthorPHID()===$viewer->getPHID();
      $checks[$prefix.'escaping']=$key==='csv'?str_contains($bytes,'(!) =FormatCanary'):str_contains($bytes,'\\tpublic');
    }
  }
  $checks['excelUnavailableByNativeDependency']=!(new PhabricatorExcelExportFormat())->isExportFormatEnabled();
  echo json_encode($checks,JSON_THROW_ON_ERROR);return;
}
if($mode==='queue-retry') {
  $checks=array();
  foreach(array('allowed','revoked') as $kind){
    $p=PhabricatorProject::initializeNewProject($creator)->setName('QueueBoundary'.$fixture['token'].' '.$kind)->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy(PhabricatorPolicies::POLICY_USER)->setJoinPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
    $type=new PhabricatorEditEngineBulkJobType();
    $job=PhabricatorWorkerBulkJob::initializeNewJob($actor,$type,array('objectPHIDs'=>array($p->getPHID()),'xactions'=>array(array('type'=>PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'new'=>'Queue committed '.$kind))))->setIsSilent(true)->setStatus(PhabricatorWorkerBulkJob::STATUS_WAITING)->save();
    $create=PhabricatorWorker::scheduleTask(PhabricatorWorkerBulkJobCreateWorker::class,array('jobID'=>$job->getID()));
    $created=queue_run($create->getID());
    $checks[$kind.'CreatorArchived']=$created instanceof PhabricatorWorkerArchiveTask && $created->getResult()===PhabricatorWorkerArchiveTask::RESULT_SUCCESS;
    $worker=queue_find(PhabricatorWorkerBulkJobTaskWorker::class,$job->getID());
    $bulk=head(id(new PhabricatorWorkerBulkTask())->loadAllWhere('bulkJobPHID=%s',$job->getPHID()));
    $conn=$bulk->establishConnection('w');$before=queue_project_state($p->getPHID());
    // Fail before the business worker's try/catch to exercise native lease retry.
    queryfx($conn,"CREATE TRIGGER permission_queue_fault BEFORE UPDATE ON %T FOR EACH ROW BEGIN IF NEW.id=%d AND NEW.status=%s THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated queue retry'; END IF; END",$bulk->getTableName(),$bulk->getID(),PhabricatorWorkerBulkTask::STATUS_RUNNING);
    try{$failed=queue_run($worker->getID());}finally{queryfx($conn,'DROP TRIGGER permission_queue_fault');}
    $checks[$kind.'TransientFailureRetained']=$failed instanceof PhabricatorWorkerActiveTask && (int)$failed->getFailureCount()===1 && $failed->getExecutionException()!==null;
    $checks[$kind.'RetryDelayed']=(int)$failed->getLeaseExpires()>time();
    $checks[$kind.'FailureNoBusinessWrite']=$before===queue_project_state($p->getPHID());
    if($kind==='revoked'){queue_editor($creator)->applyTransactions(route_project($p->getPHID()),array(queue_x(PhabricatorTransactions::TYPE_EDIT_POLICY,$creator->getPHID())));}
    $before_retry=queue_project_state($p->getPHID());
    // Native testing API expires this exact owned lease; never sleep five minutes.
    $failed->setLeaseDuration(-1);
    $result=queue_run($worker->getID());$after=queue_project_state($p->getPHID());
    $bulk=id(new PhabricatorWorkerBulkTask())->load($bulk->getID());$job=queue_job($job->getID());
    $checks[$kind.'QueueArchived']=$result instanceof PhabricatorWorkerArchiveTask && $result->getResult()===PhabricatorWorkerArchiveTask::RESULT_SUCCESS;
    $checks[$kind.'BusinessStatus']=$bulk->getStatus()===($kind==='allowed'?PhabricatorWorkerBulkTask::STATUS_DONE:PhabricatorWorkerBulkTask::STATUS_FAIL);
    $checks[$kind.'JobComplete']=$job->getStatus()===PhabricatorWorkerBulkJob::STATUS_COMPLETE;
    $checks[$kind.'NativeBusinessResult']=$kind==='allowed'?($after['name']==='Queue committed allowed' && $after['count']===$before_retry['count']+1):$before_retry===$after;
    // The native administrative retry primitive must not duplicate a completed item.
    $again=$result->unarchiveTask();$duplicate=queue_run($again->getID());
    $checks[$kind.'DuplicatePermanentFailure']=$duplicate instanceof PhabricatorWorkerArchiveTask && $duplicate->getResult()===PhabricatorWorkerArchiveTask::RESULT_FAILURE;
    $checks[$kind.'DuplicateNoBusinessWrite']=$after===queue_project_state($p->getPHID());
    $creator_repeat=$created;
    foreach(array(1,2) as $attempt){
      $creator_repeat=queue_run($creator_repeat->unarchiveTask()->getID());
      $items=id(new PhabricatorWorkerBulkTask())->loadAllWhere('bulkJobPHID=%s',$job->getPHID());
      $checks[$kind.'CreatorDuplicate'.$attempt]=$creator_repeat instanceof PhabricatorWorkerArchiveTask && $creator_repeat->getResult()===PhabricatorWorkerArchiveTask::RESULT_SUCCESS && count($items)===1;
    }

  }
  echo json_encode($checks,JSON_THROW_ON_ERROR);return;
}
if($mode==='queue-large-seed'){
  $rows=array();
  for($i=0;$i<1006;$i++){
    $task=ManiphestTask::initializeNewTask($creator)->setTitle('LargeBoundary'.$fixture['token'].' item'.$i.' end')->setViewPolicy($i===1005?$creator->getPHID():PhabricatorPolicies::POLICY_USER)->setEditPolicy($creator->getPHID())->save();
    $rows[]=array('id'=>(int)$task->getID(),'phid'=>$task->getPHID(),'title'=>$task->getTitle());
  }
  $engine=id(new ManiphestTaskSearchEngine())->setViewer($actor);$saved=$engine->newSavedQuery()->setParameter('ids',array_column($rows,'id'));$engine->saveQuery($saved);
  file_put_contents($dir.'/queue-large.json',json_encode(array('rows'=>$rows,'queryKey'=>$saved->getQueryKey())));
  echo json_encode(array('queryKey'=>$saved->getQueryKey(),'visibleAtSubmission'=>1005));return;
}
if($mode==='queue-large-run'){
  $large=json_decode(file_get_contents($dir.'/queue-large.json'),true);$table=new PhabricatorWorkerBulkJob();
  $jobs=$table->loadAllWhere('jobTypeKey=%s','export');$job=null;
  foreach($jobs as $candidate){if($candidate->getParameter('queryKey')===$large['queryKey'] && $candidate->getAuthorPHID()===$actor->getPHID()){$job=$candidate;}}
  if(!$job){throw new Exception('HTTP submission did not create owned export job.');}
  $checks=array('httpQueuedExport'=>$job->getStatus()===PhabricatorWorkerBulkJob::STATUS_WAITING);
  foreach(array_slice($large['rows'],0,2) as $row){id(new ConduitCall('maniphest.edit',array('objectIdentifier'=>$row['phid'],'transactions'=>array(array('type'=>'view','value'=>$creator->getPHID())))))->setUser($creator)->execute();}
  $create=queue_find(PhabricatorWorkerBulkJobCreateWorker::class,$job->getID());$c=queue_run($create->getID());
  $checks['creatorArchived']=$c instanceof PhabricatorWorkerArchiveTask && $c->getResult()===PhabricatorWorkerArchiveTask::RESULT_SUCCESS;
  $work=queue_find(PhabricatorWorkerBulkJobTaskWorker::class,$job->getID());$done=queue_run($work->getID());$job=queue_job($job->getID());
  $checks['workerArchived']=$done instanceof PhabricatorWorkerArchiveTask && $done->getResult()===PhabricatorWorkerArchiveTask::RESULT_SUCCESS;
  $checks['jobComplete']=$job->getStatus()===PhabricatorWorkerBulkJob::STATUS_COMPLETE;
  $file=id(new PhabricatorFileQuery())->setViewer($actor)->withPHIDs(array($job->getParameter('filePHID')))->executeOne();
  if(!$file){throw new Exception('Missing private asynchronous export.');}
  $bytes=$file->loadFileData();$data=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
  $expected=array_map(static function($row){return 'T'.$row['id'];},array_slice($large['rows'],2,1003));$actual=array_column($data,'monogram');sort($expected);sort($actual);
  $checks['twoPageExactVisibleSet']=$expected===$actual && count($data)===1003 && count(array_unique($actual))===1003;
  foreach(array(0,1,1005) as $i){$row=$large['rows'][$i];$checks['hiddenOrRevokedOmitted'.$i]=!str_contains($bytes,$row['title'])&&!str_contains($bytes,$row['phid']);}
  $checks['filePrivate']=$file->getViewPolicy()===PhabricatorPolicies::POLICY_NOONE && $file->getAuthorPHID()===$actor->getPHID();
  $before=$file->getPHID();$duplicate=queue_run($done->unarchiveTask()->getID());$checks['duplicateRejected']=$duplicate->getResult()===PhabricatorWorkerArchiveTask::RESULT_FAILURE && queue_job($job->getID())->getParameter('filePHID')===$before;
  echo json_encode($checks,JSON_THROW_ON_ERROR);return;
}
if($mode==='cross-seed'){
  $result=array();
  foreach(array('ordinary','milestone') as $kind){
    $make=static function($params) use($creator){return id(new ConduitCall('project.edit',$params))->setUser($creator)->execute();};
    $parent=$make(array('transactions'=>array(array('type'=>'name','value'=>'CrossParent'.$fixture['token'].' '.$kind),array('type'=>'edit','value'=>PhabricatorPolicies::POLICY_USER))));
    $parent_object=route_project($parent['object']['phid']);
    $child=PhabricatorProject::initializeNewProject($creator,$parent_object);
    $child->setPHID($child->generatePHID());
    queue_editor($creator)->applyTransactions($child,array(queue_x(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'CrossChild'.$fixture['token'].' '.$kind),queue_x($kind==='ordinary'?PhabricatorProjectParentTransaction::TRANSACTIONTYPE:PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE,$parent_object->getPHID())));
    if($kind==='ordinary'){$parent_object->setHasSubprojects(1);}else{$parent_object->setHasMilestones(1);}$parent_object->save();
    $child=route_project($child->getPHID());if($kind==='ordinary'){$child->setEditPolicy($creator->getPHID())->save();}
    $result[$kind]=array('parent'=>$parent_object->getPHID(),'child'=>$child->getPHID(),'parentTokenHash'=>hash('sha256',json_encode(queue_token_rows($parent_object))));
  }
  file_put_contents($dir.'/cross.json',json_encode($result));echo json_encode(array('seeded'=>2));return;
}
if(str_starts_with($mode,'cross-')){
  $state=json_decode(file_get_contents($dir.'/cross.json'),true);$kind=$argv[2]??'';
  if(!isset($state[$kind])){throw new Exception('Unknown cross-object case.');}$s=$state[$kind];$editor_actor=queue_actor('childEditor');
  if($mode==='cross-hold'){
    $p=route_project($s['child']);$editor=queue_editor($editor_actor);
    $hook=new class extends PhabricatorEditorExtension {
      const EXTENSIONKEY='owned-cross-barrier';public function getExtensionName(){return 'Owned cross barrier';}public function setObject($object){return $this;}
      public function validateTransactions($object,array $xactions){$d=getenv('PERMISSION_ROUTES_PRIVATE');file_put_contents($d.'/cross-ready','ready');$limit=microtime(true)+40;while(!is_file($d.'/cross-release')){if(microtime(true)>$limit){throw new Exception('Cross barrier timeout.');}usleep(10000);}return array();}
    };
    (new ReflectionProperty(PhabricatorApplicationTransactionEditor::class,'extensions'))->setValue($editor,array($hook->setEditor($editor)->setViewer($editor_actor)));
    $allowed=false;try{$editor->applyTransactions($p,array(queue_x(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'Cross in flight '.$kind)));$allowed=true;}catch(PhabricatorPolicyException $ex){}
    echo json_encode(array('inFlightCommitted'=>$allowed));return;
  }
  if($mode==='cross-lock-wait'){
    $id=json_decode(file_get_contents($dir.'/cross-revoker'),true)['id'];$p=new PhabricatorProject();
    $rows=queryfx_all($p->establishConnection('r'),'SELECT w.REQUESTING_ENGINE_TRANSACTION_ID FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID=%d',$id);
    echo json_encode(array('waiting'=>count($rows)>0));return;
  }
  if($mode==='cross-revoke'){
    $p=route_project($s['parent']);file_put_contents($dir.'/cross-revoker',json_encode(queryfx_one($p->establishConnection('w'),'SELECT CONNECTION_ID() AS id')));
    queue_editor($creator)->applyTransactions(route_project($s['parent']),array(queue_x(PhabricatorTransactions::TYPE_EDIT_POLICY,$creator->getPHID())));
    echo json_encode(array('ancestorRevoked'=>true));return;
  }
  if($mode==='cross-verify'){
    $before=queue_project_state($s['child']);$denied=false;
    try{queue_editor($editor_actor)->applyTransactions(route_project($s['child']),array(queue_x(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,'Forbidden fresh cross edit')));}catch(PhabricatorPolicyException $ex){$denied=true;}
    $checks=array('freshEditDenied'=>$denied,'denialNoBusinessWrite'=>$before===queue_project_state($s['child']),'policyOnlyTokensUnchanged'=>$s['parentTokenHash']===hash('sha256',json_encode(queue_token_rows(route_project($s['parent'])))),'renameTokensUpdated'=>in_array('flight',ipull(queue_token_rows(route_project($s['child'])),'token'),true));
    if($kind==='milestone'){
      $space=PhabricatorSpacesNamespace::initializeNewNamespace($creator)->setNamespaceName('Owned milestone synchronization')->setViewPolicy(PhabricatorPolicies::POLICY_USER)->save();
      PhabricatorSpacesNamespaceQuery::destroySpacesCache();
      queue_editor($creator)->applyTransactions(route_project($s['parent']),array(queue_x(PhabricatorTransactions::TYPE_SPACE,$space->getPHID())));
      $child=route_project($s['child']);$stored=queryfx_one($child->establishConnection('r'),'SELECT spacePHID FROM %T WHERE id=%d',$child->getTableName(),$child->getID());
      $checks['parentSpaceStillSynchronizes']=$stored['spacePHID']===$space->getPHID();
    }
    echo json_encode($checks);return;
  }
}
throw new Exception('Unknown owned queue operation.');
