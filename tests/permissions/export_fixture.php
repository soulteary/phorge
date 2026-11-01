<?php
// Parent verifies CLI, disposable namespace and configuration ownership.
if(!isset($fixture,$owner,$namespace)){throw new Exception('Owned export fixture required.');}
$operation=$mode;$mode='recovery-bootstrap';require __DIR__.'/queue_fixture.php';$mode=$operation;$path=$dir.'/export-state.json';
function export_members($p,$members){global $creator;id(new ConduitCall('project.edit',array('objectIdentifier'=>$p->getPHID(),'transactions'=>array(array('type'=>'members.set','value'=>$members)))))->setUser($creator)->execute();}
function export_query($viewer,$ids){$engine=id(new ManiphestTaskSearchEngine())->setViewer($viewer);$saved=$engine->newSavedQuery()->setParameter('ids',$ids)->setParameter('order','created');$engine->saveQuery($saved);return array($engine,$saved);}
function export_file($viewer,$ids,$format){list($engine,$saved)=export_query($viewer,$ids);return id(new PhabricatorExportEngine())->setViewer($viewer)->setSearchEngine($engine)->setSavedQuery($saved)->setExportFormat($format)->setFilename('isolated-export')->setTitle('Isolated export')->exportFile();}
if($mode==='export-seed'){
 $tasks=array();foreach(array('public','private') as $kind){$t=ManiphestTask::initializeNewTask($creator)->setTitle($kind==='public'?'HTTP XLSX visible canary':'HTTP XLSX private canary')->setViewPolicy($kind==='public'?PhabricatorPolicies::POLICY_USER:$creator->getPHID())->save();$tasks[$kind]=(int)$t->getID();}
 $queries=array();$columns=array();foreach(array('owner','outsider') as $role){list($engine,$saved)=export_query(queue_actor($role),array_values($tasks));$queries[$role]=$saved->getQueryKey();$columns[$role]=array_values(mpull($engine->newExportFieldList(),'getKey'));}
 $rows=array();for($i=0;$i<1002;$i++){$t=ManiphestTask::initializeNewTask($creator)->setTitle('Mid-export row '.$i)->setViewPolicy(PhabricatorPolicies::POLICY_USER)->setEditPolicy($creator->getPHID())->save();$rows[]=(int)$t->getID();}
 file_put_contents($path,json_encode(array('tasks'=>$tasks,'queries'=>$queries,'rows'=>$rows,'columns'=>$columns)));echo json_encode(array('queries'=>$queries));return;
}
$s=is_file($path)?json_decode(file_get_contents($path),true):array();
if($mode==='export-hold'){
 $format=new class extends PhabricatorExportFormat {
  const EXPORTKEY='isolated-barrier';private $delegate;private $paused=false;
  public function getExportFormatName(){return 'Isolated barrier';}public function getMIMEContentType(){return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';}public function getFileExtension(){return 'xlsx';}
  public function addHeaders(array $fields){$this->delegate=id(new PhabricatorExcelExportFormat())->setViewer($this->getViewer())->setTitle($this->getTitle());$this->delegate->addHeaders($fields);}
  public function addObject($object,array $fields,array $map){$this->delegate->addObject($object,$fields,$map);if(!$this->paused){$this->paused=true;$d=getenv('PERMISSION_ROUTES_PRIVATE');file_put_contents($d.'/export-ready.json',json_encode(array('loadedID'=>(int)$object->getID())));$limit=microtime(true)+40;while(!is_file($d.'/export-release')){if(microtime(true)>$limit){throw new Exception('Export barrier timeout.');}usleep(10000);}}}
  public function newFileData(){return $this->delegate->newFileData();}
 };
 $file=export_file($actor,$s['rows'],$format);file_put_contents($dir.'/mid-export.xlsx',$file->loadFileData());file_put_contents($dir.'/mid-file.json',json_encode(array('phid'=>$file->getPHID())));echo json_encode(array('privateFile'=>$file->getViewPolicy()===PhabricatorPolicies::POLICY_NOONE&&$file->getAuthorPHID()===$actor->getPHID()));return;
}
if($mode==='export-revoke'){
 $loaded=json_decode(file_get_contents($dir.'/export-ready.json'),true)['loadedID'];$unloaded=min($s['rows']);if($loaded===$unloaded){throw new Exception('Descending page boundary required.');}
 foreach(array($loaded,$unloaded) as $id){id(new ConduitCall('maniphest.edit',array('objectIdentifier'=>'T'.$id,'transactions'=>array(array('type'=>'view','value'=>$creator->getPHID())))))->setUser($creator)->execute();}
 $s['revoked']=array($loaded,$unloaded);file_put_contents($path,json_encode($s));echo json_encode(array('independentRevocation'=>true));return;
}
if($mode==='export-fresh'){
 $file=export_file($actor,$s['rows'],new PhabricatorExcelExportFormat());file_put_contents($dir.'/fresh-export.xlsx',$file->loadFileData());$phid=json_decode(file_get_contents($dir.'/mid-file.json'),true)['phid'];$old=id(new PhabricatorFileQuery())->setViewer($actor)->withPHIDs(array($phid))->executeOne();$foreign=null;try{$foreign=id(new PhabricatorFileQuery())->setViewer(queue_actor('member'))->withPHIDs(array($phid))->executeOne();}catch(PhabricatorPolicyException $ex){}echo json_encode(array('authorRetainsCompletedSnapshot'=>$old&&strlen($old->loadFileData())>0,'otherViewerDenied'=>!$foreign,'privateFreshFile'=>$file->getViewPolicy()===PhabricatorPolicies::POLICY_NOONE));return;
}
if($mode==='export-hierarchy'){
 route_default_space($creator);
 $space=PhabricatorSpacesNamespace::initializeNewNamespace($creator)->setNamespaceName('Hierarchy restricted space')->setViewPolicy($creator->getPHID())->save();PhabricatorSpacesNamespaceQuery::destroySpacesCache();
 $make=static function($name,$parent=null,$milestone=false)use($creator){$p=PhabricatorProject::initializeNewProject($creator,$parent);$p->setPHID($p->generatePHID());$xs=array(queue_x(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,$name));if($parent){$xs[]=queue_x($milestone?PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE:PhabricatorProjectParentTransaction::TRANSACTIONTYPE,$parent->getPHID());}queue_editor($creator)->applyTransactions($p,$xs);return route_project($p->getPHID());};
 $grand=$make('Hierarchy grand');$parent=$make('Hierarchy parent',$grand);$leaf=$make('Hierarchy leaf',$parent);$sibling=$make('Hierarchy sibling',$parent);$mile=$make('Hierarchy milestone',$leaf,true);$projects=array($grand,$parent,$leaf,$sibling,$mile);
 export_members($leaf,array($creator->getPHID(),$actor->getPHID()));$checks=array();$member=static function($p)use($actor){return route_project($p->getPHID())->isUserMember($actor->getPHID());};
 foreach(array('grand'=>$grand,'parent'=>$parent,'leaf'=>$leaf,'milestone'=>$mile) as $k=>$p){$checks['addedAggregates'.$k]=$member($p);}$checks['siblingNotMember']=!$member($sibling);
 export_members($leaf,array($creator->getPHID()));foreach(array('grand'=>$grand,'parent'=>$parent,'leaf'=>$leaf,'milestone'=>$mile) as $k=>$p){$checks['removedAggregates'.$k]=!$member($p);}
 $visible=static function($p)use($actor){try{return (bool)id(new PhabricatorProjectQuery())->setViewer($actor)->withPHIDs(array($p->getPHID()))->executeOne();}catch(PhabricatorPolicyException $ex){return false;}};
 foreach(array($grand,$parent,$leaf,$sibling) as $p){queue_editor($creator)->applyTransactions(route_project($p->getPHID()),array(queue_x(PhabricatorTransactions::TYPE_VIEW_POLICY,PhabricatorPolicies::POLICY_USER)));}
 queue_editor($creator)->applyTransactions(route_project($leaf->getPHID()),array(queue_x(PhabricatorTransactions::TYPE_SPACE,$space->getPHID())));
 $checks['leafSpaceDenies']=!$visible($leaf);$checks['milestoneSpaceDenies']=!$visible($mile);$checks['siblingRemainsVisible']=$visible($sibling);$checks['parentRemainsVisible']=$visible($parent);
 $m=route_project($mile->getPHID());$stored=queryfx_one($m->establishConnection('r'),'SELECT spacePHID FROM %T WHERE id=%d',$m->getTableName(),$m->getID());$checks['milestoneStoredSpaceSynchronized']=$stored['spacePHID']===$space->getPHID();
 queue_editor($creator)->applyTransactions(route_project($leaf->getPHID()),array(queue_x(PhabricatorTransactions::TYPE_SPACE,route_default_space($creator)->getPHID())));$checks['restoredLeafVisible']=$visible($leaf);$checks['restoredMilestoneVisible']=$visible($mile);
 queue_editor($creator)->applyTransactions(route_project($parent->getPHID()),array(queue_x(PhabricatorTransactions::TYPE_SPACE,$space->getPHID())));foreach(array('parent'=>$parent,'leaf'=>$leaf,'sibling'=>$sibling,'milestone'=>$mile) as $k=>$p){$checks['restrictedAncestorDenies'.$k]=!$visible($p);}$checks['grandUnaffected']=$visible($grand);echo json_encode($checks);return;
}
throw new Exception('Unknown owned export operation.');
