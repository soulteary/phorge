<?php
/** Actual native classes via fixed source maps; synthetic rows/cache, no SQL. */
$root=$argv[1];$GLOBALS['spaces_control_paths']=array();
function phutil_register_library_map($map){foreach($map['class']as$class=>$path){$GLOBALS['spaces_control_paths'][$class]=$GLOBALS['spaces_control_base'].'/'.$path;}}
foreach(array('/support/runtime/src','/src')as$base){$GLOBALS['spaces_control_base']=$root.$base;require $root.$base.'/__phutil_library_map__.php';}
spl_autoload_register(function($class){if(isset($GLOBALS['spaces_control_paths'][$class])){require_once $GLOBALS['spaces_control_paths'][$class];}});
require $root.'/support/runtime/src/utils/utils.php';
require $root.'/support/runtime/src/internationalization/pht.php';
require $root.'/support/governance/recovery/spaces-editor-diagnostic.php';
function expect_diag($v){if(!$v){throw new RuntimeException('diagnostic_control_failed');}}
$x=(new PhabricatorSpacesNamespaceTransaction())->setTransactionType('core:view-policy')->setNewValue('SYNTHETIC_SECRET');
$e=(new PhabricatorApplicationTransactionValidationError('core:view-policy','SYNTHETIC_SECRET','SYNTHETIC_SECRET',$x))->setIsMissingFieldError(false);
$error=new PhabricatorApplicationTransactionValidationException(array($e));
$p=governance_spaces_editor_failure('create-restricted-noone',$error);
expect_diag($p['validation_errors'][0]===array('type'=>'core:view-policy','missing_field'=>'false','transaction_present'=>true,'transaction_type'=>'core:view-policy'));
expect_diag(strpos(json_encode($p),'SYNTHETIC_SECRET')===false);
$caught=null;try{governance_spaces_editor_attempt('create-restricted-noone',function()use($error){throw $error;});}catch(Throwable $observed){$caught=$observed;}expect_diag($caught===$error);
expect_diag($GLOBALS['governance_spaces_editor_failure']===$p);
$unknown=new PhabricatorApplicationTransactionValidationException(array(new PhabricatorApplicationTransactionValidationError('SYNTHETIC_SECRET','SYNTHETIC_SECRET','SYNTHETIC_SECRET')));
$p=governance_spaces_editor_failure('create-user-visible',$unknown);expect_diag($p['validation_errors'][0]['type']==='unknown'&&$p['validation_errors'][0]['missing_field']==='unset');
$bad=(new PhabricatorApplicationTransactionValidationError('core:view-policy','hidden','hidden'))->setIsMissingFieldError(1);
$baderror=new PhabricatorApplicationTransactionValidationException(array($bad));
$caught=null;try{governance_spaces_editor_attempt('create-user-visible',function()use($baderror){throw $baderror;});}catch(Throwable $observed){$caught=$observed;}expect_diag($caught===$baderror);
expect_diag($GLOBALS['governance_spaces_editor_failure']===array('diagnostic_unavailable'=>true));
try{governance_spaces_editor_failure('SYNTHETIC_SECRET',$error);throw new LogicException('accepted');}catch(RuntimeException $ok){}
$large=new PhabricatorApplicationTransactionValidationException(array_fill(0,17,$e));
try{governance_spaces_editor_failure('archive',$large);throw new LogicException('accepted');}catch(RuntimeException $ok){}
expect_diag(governance_spaces_editor_attempt('archive',function(){return 7;})===7);
unset($GLOBALS['governance_spaces_editor_failure']);$plain=new RuntimeException('SYNTHETIC_SECRET');$caught=null;try{governance_spaces_editor_attempt('archive',function()use($plain){throw $plain;});}catch(Throwable $observed){$caught=$observed;}expect_diag($caught===$plain);expect_diag(!isset($GLOBALS['governance_spaces_editor_failure']));
echo json_encode(array('controls'=>7,'passed'=>true));
