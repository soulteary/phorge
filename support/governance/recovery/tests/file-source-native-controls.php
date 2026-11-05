<?php
/** Actual native model methods only; no queries, policy authorization or grants. */
$root=$argv[1];$GLOBALS['file_control_paths']=array();
function phutil_register_library_map($map){foreach($map['class']as$class=>$path){$GLOBALS['file_control_paths'][$class]=$GLOBALS['file_control_base'].'/'.$path;}}
foreach(array('/support/runtime/src','/src')as$base){$GLOBALS['file_control_base']=$root.$base;require$root.$base.'/__phutil_library_map__.php';}
spl_autoload_register(function($class){if(isset($GLOBALS['file_control_paths'][$class])){require_once$GLOBALS['file_control_paths'][$class];}});
require$root.'/support/runtime/src/utils/utils.php';require$root.'/src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php';
function file_control($ok){if($ok!==true){throw new RuntimeException('file_native_control_failed');}}
$stackProperty=new ReflectionProperty(PhabricatorEnv::class,'sourceStack');$cacheProperty=new ReflectionProperty(PhabricatorEnv::class,'cache');$oldStack=$stackProperty->getValue();$oldCache=$cacheProperty->getValue();$checks=array();
try{
 $stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDictionarySource(array('policy.allow-public'=>false)));$stackProperty->setValue(null,$stack);$cacheProperty->setValue(null,array());
 $author=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('a',20));$other=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('o',20));
 $file=(new PhabricatorFile())->setAuthorPHID($author->getPHID())->setViewPolicy('no-one')->attachObjects(array())->attachOriginalFile(null);
 foreach(array(PhabricatorPolicyCapability::CAN_VIEW,PhabricatorPolicyCapability::CAN_EDIT,'synthetic-unlisted')as$cap){file_control($file->hasAutomaticCapability($cap,$author)===true);file_control($file->hasAutomaticCapability($cap,$other)===false);}$checks[]='native-File-author-automatic-distinct-from-nonauthor';
 $author->setIsDisabled(1);file_control($file->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$author)===true);file_control($file->getPolicy(PhabricatorPolicyCapability::CAN_EDIT)===PhabricatorPolicies::POLICY_NOONE);$checks[]='author-function-does-not-prove-account-or-full-policy';
 $task=(new ManiphestTask())->setPHID('PHID-TASK-'.str_repeat('t',20));$file->attachObjects(array($task));file_control($file->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$other)===true);file_control($file->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_EDIT,$other)===false);$checks[]='already-attached-objects-only-affect-VIEW-not-query-authorization';
 $file->attachObjects(array())->attachOriginalFile(new PhabricatorFile());file_control($file->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_VIEW,$other)===true);file_control($file->hasAutomaticCapability(PhabricatorPolicyCapability::CAN_EDIT,$other)===false);$checks[]='already-attached-original-is-VIEW-input-not-original-policy-proof';
 foreach(array('attach'=>true,'reference'=>false,'detach'=>false,'unknown'=>false)as$mode=>$expected){file_control((new PhabricatorFileAttachment())->setAttachmentMode($mode)->isPolicyAttachment()===$expected);}$checks[]='only-attach-mode-is-policy-attachment';
 $file->attachOriginalFile(null)->setIsProfileImage(true);file_control($file->getPolicy(PhabricatorPolicyCapability::CAN_VIEW)===PhabricatorPolicies::getMostOpenPolicy());$file->setIsProfileImage(false)->setBuiltinKey('not-native-builtin-name');file_control($file->isBuiltin()===false);$file->setBuiltinName('synthetic-fixed');file_control($file->isBuiltin()===true);$checks[]='profile-and-metadata-builtin-name-differ-from-builtin-key';
 $spec=(new ReflectionMethod(PhabricatorFile::class,'getConfiguration'))->invoke(new PhabricatorFile());$keys=$spec[LiskDAO::CONFIG_KEY_SCHEMA];file_control($keys['key_phid']===null&&$keys['phid']===array('columns'=>array('phid'),'unique'=>true)&&$keys['authorPHID']===array('columns'=>array('authorPHID')));
 $spec=(new ReflectionMethod(PhabricatorFileAttachment::class,'getConfiguration'))->invoke(new PhabricatorFileAttachment());file_control($spec[LiskDAO::CONFIG_KEY_SCHEMA]['key_file']===array('columns'=>array('filePHID')));
 $spec=(new ReflectionMethod(PhabricatorTransformedFile::class,'getConfiguration'))->invoke(new PhabricatorTransformedFile());file_control($spec[LiskDAO::CONFIG_KEY_SCHEMA]['originalPHID']===array('columns'=>array('originalPHID','transform'),'unique'=>true));$checks[]='actual-native-File-attachment-transform-index-contracts';
 echo json_encode(array('passed'=>true,'checks'=>$checks,'SQL_executed'=>false,'FileQuery_or_parent_policy_authorized'=>false,'all_sources_complete'=>false,'restoration_authorized'=>false))."\n";
}finally{$stackProperty->setValue(null,$oldStack);$cacheProperty->setValue(null,$oldCache);}
