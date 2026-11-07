<?php
/** Actual case function/callback/finally; worker, owner and identity collaborators; no SQL. */
$root=$argv[1];$mode=$argv[2];$trace=array();$primary=new RuntimeException('primary');$cleanup=new RuntimeException('cleanup');$writer=new stdClass();
require$root.'/support/runtime/src/error/PhutilAggregateException.php';
class PhorgeGovernanceNativeActorContext{function __construct(private$phid){}function getUserPHID(){return$this->phid;}}
class PhabricatorUser{function __construct(private$phid='default'){}function getPHID(){return$this->phid;}function getID(){return 1;}function establishConnection($mode){return$GLOBALS['writer'];}}
class PhorgeRecoveryRestoreService{
 private static function withTransaction($context,$subjects,$callback){$GLOBALS['trace'][]='owner-enter';if($GLOBALS['mode']==='owner-fail')throw$GLOBALS['primary'];try{return$callback($GLOBALS['mode']==='writer-changed'?new stdClass():$GLOBALS['writer']);}finally{$GLOBALS['trace'][]='owner-end';}}
 static function captureBorrowedSessionFacts(...$args){$GLOBALS['trace'][]='capture';if($GLOBALS['mode']==='capture-fail')throw$GLOBALS['primary'];return array('source_binding'=>'before');}
}
function governance_recovery_borrowed_with_observer($fixture,$control,$callback){return$callback(new stdClass());}
function governance_native_start_worker($fixture,$action){$GLOBALS['trace'][]='start';return array('index'=>7,'mysql_thread'=>11);}
function governance_native_wait_borrowed_session_preflight($handle,$observer){$GLOBALS['trace'][]='ready';if(in_array($GLOBALS['mode'],array('ready-fail','ready-dual'),true))throw$GLOBALS['primary'];}
function governance_native_continue_borrowed_session($handle){$GLOBALS['trace'][]='continue';throw$GLOBALS['primary'];}
function governance_native_stop_fixture_handles($handles){if($handles!==array(array('index'=>7,'mysql_thread'=>11)))throw new LogicException('handlechanged');$GLOBALS['trace'][]='stop';if(in_array($GLOBALS['mode'],array('dual','ready-dual'),true))throw$GLOBALS['cleanup'];}
function governance_recovery_session_lease_workers_ended($observer,$handles){$GLOBALS['trace'][]='worker-SQL-ended';}
$raw=file_get_contents($root.'/support/governance/recovery/borrowed-session-cases.php');
foreach(array("require_once __DIR__.'/borrowed-session-observer.php';","require_once __DIR__.'/borrowed-session-producer.php';","require_once __DIR__.'/session-lease-cases.php';","require_once dirname(__DIR__).'/native-organization/workers.php';")as$import){if(substr_count($raw,$import)!==1)throw new RuntimeException('actual_dependency_shape_changed');$raw=str_replace($import,'',$raw);}
$raw=str_replace('__DIR__',var_export($root.'/support/governance/recovery',true),substr($raw,6));eval($raw);
$fixture=new class{function assertOwned(){}function getNamespace(){return'governance_native_'.str_repeat('a',16);}};$issuer=new PhorgeGovernanceNativeActorContext('a');$owner=new PhorgeGovernanceNativeActorContext('b');$error=null;try{governance_recovery_borrowed_session_cases($fixture,new mysqli(),array('issuer_auth'=>array('context'=>$issuer,'token'=>'synthetic'),'owner_auth'=>array('context'=>$owner),'subjects'=>array('commit'=>new PhabricatorUser('c'),'rollback'=>new PhabricatorUser('d'))),static function(...$args){throw new LogicException('assertionreached');});}catch(Throwable$e){$error=$e;}
$children=$error instanceof PhutilAggregateException?$error->getExceptions():array();echo json_encode(array('trace'=>$trace,'primary_exact'=>$error===$primary,'aggregate_exact'=>array_keys($children)===array('primary','workers')&&$children['primary']===$primary&&$children['workers']===$cleanup,'writer_changed_refused'=>$error instanceof RuntimeException&&$error->getMessage()==='borrowed_preflight_writer_changed','physical_SQL_executed'=>false,'actual_case_function'=>true));
