<?php
/** Actual native classes from fixed source maps; synthetic model/config state only. */
$root=$argv[1];$GLOBALS['task_owner_control_paths']=array();
function phutil_register_library_map($map){foreach($map['class']as$class=>$path){$GLOBALS['task_owner_control_paths'][$class]=$GLOBALS['task_owner_control_base'].'/'.$path;}}
foreach(array('/support/runtime/src','/src')as$base){$GLOBALS['task_owner_control_base']=$root.$base;require $root.$base.'/__phutil_library_map__.php';}
spl_autoload_register(function($class){if(isset($GLOBALS['task_owner_control_paths'][$class])){require_once $GLOBALS['task_owner_control_paths'][$class];}});
require $root.'/support/runtime/src/utils/utils.php';
require $root.'/src/infrastructure/governance/recovery/PhorgeRecoveryTaskOwnerSource.php';
function task_owner_control_expect($value){if(!$value){throw new RuntimeException('task_owner_native_control_failed');}}
$stackProperty=new ReflectionProperty(PhabricatorEnv::class,'sourceStack');$cacheProperty=new ReflectionProperty(PhabricatorEnv::class,'cache');$oldStack=$stackProperty->getValue();$oldCache=$cacheProperty->getValue();$checks=array();
try{
 $stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDictionarySource(array('maniphest.statuses'=>array('open'=>array('locked'=>false),'comments'=>array('locked'=>'comments'),'edits'=>array('locked'=>'edits')),'phabricator.serious-business'=>false)));$stackProperty->setValue(null,$stack);$cacheProperty->setValue(null,array());
 $owner=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('u',20));$author=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('a',20));
 $task=(new ManiphestTask())->setOwnerPHID($owner->getPHID())->setAuthorPHID($author->getPHID())->setStatus('open')->setViewPolicy('users')->setEditPolicy('users');
 foreach($task->getCapabilities()as$cap){task_owner_control_expect($task->hasAutomaticCapability($cap,$owner)===true);task_owner_control_expect($task->hasAutomaticCapability($cap,$author)===false);}$checks[]='native-owner-only-author-not-automatic';
 $owner->setIsDisabled(1);foreach($task->getCapabilities()as$cap){task_owner_control_expect($task->hasAutomaticCapability($cap,$owner)===true);}$checks[]='owner-function-is-not-account-active-gate';
 task_owner_control_expect($task->hasAutomaticCapability('synthetic-unsupported-capability',$owner)===true);task_owner_control_expect(!in_array('synthetic-unsupported-capability',$task->getCapabilities(),true));$checks[]='capability-argument-not-checked-by-owner-function-not-permission-proof';
 foreach(array('open'=>array('users','users'),'comments'=>array('users','no-one'),'edits'=>array('no-one','no-one'))as$status=>$expected){$task->setStatus($status);task_owner_control_expect($task->getPolicy(PhabricatorPolicyCapability::CAN_EDIT)===$expected[0]);task_owner_control_expect($task->getPolicy(PhabricatorPolicyCapability::CAN_INTERACT)===$expected[1]);foreach($task->getCapabilities()as$cap){task_owner_control_expect($task->hasAutomaticCapability($cap,$owner)===true);}}$checks[]='native-comment-edit-lock-policy-distinct-from-owner-function';
 $config=(new ReflectionMethod(ManiphestTask::class,'getConfiguration'))->invoke(new ManiphestTask());$schema=$config[LiskDAO::CONFIG_KEY_SCHEMA];task_owner_control_expect($schema['key_phid']===null);task_owner_control_expect($schema['phid']===array('columns'=>array('phid'),'unique'=>true));task_owner_control_expect($schema['ownerPHID']===array('columns'=>array('ownerPHID','status')));$checks[]='native-task-phid-and-owner-status-index-configuration';
 $task->setOwnerPHID(null);foreach($task->getCapabilities()as$cap){task_owner_control_expect($task->hasAutomaticCapability($cap,$owner)===false);}$checks[]='native-unassigned-task-has-no-owner-automatic-grant';
 echo json_encode(array('passed'=>true,'checks'=>$checks,'SQL_executed'=>false,'full_native_policy_or_account_gate_proved'=>false))."\n";
}finally{$stackProperty->setValue(null,$oldStack);$cacheProperty->setValue(null,$oldCache);}
