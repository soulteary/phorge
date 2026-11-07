<?php
// Actual native transaction methods/state and aggregate; transport/identity are explicit no-SQL collaborators.
$root=$argv[1];$mode=$argv[2];
class PhorgeGovernanceNativeIdentitySource{static function lockCurrent(...$args){if(in_array($GLOBALS['mode'],array('primary','dual'),true)){throw$GLOBALS['primary'];}if($GLOBALS['mode']==='depth'){$args[1]->openTransaction();throw$GLOBALS['primary'];}return new class{function isSystemAdmin(){return true;}};}}
function phutil_register_library_map($map){foreach($map['class']as$c=>$p){$GLOBALS['paths'][$c]=$GLOBALS['base'].'/'.$p;}}
foreach(array('/support/runtime/src','/src')as$b){$GLOBALS['base']=$root.$b;require$root.$b.'/__phutil_library_map__.php';}spl_autoload_register(static function($c){if(isset($GLOBALS['paths'][$c]))require_once$GLOBALS['paths'][$c];});
class Transport extends AphrontDatabaseConnection{
 function escapeBinaryString($s){}function escapeUTF8String($s){}function escapeColumnName($s){}function escapeMultilineComment($s){}function escapeStringForLikeClause($s){}
 public $queries=array();function query($pattern){$this->queries[]=$pattern;if(($pattern==='START TRANSACTION'&&$GLOBALS['mode']==='begin')||($pattern==='COMMIT'&&$GLOBALS['mode']==='commit'))throw$GLOBALS['primary'];if($pattern==='ROLLBACK'&&$GLOBALS['mode']==='dual')throw$GLOBALS['cleanup'];return$this;}
 function getInsertID(){}function getAffectedRows(){}function selectAllResults(){}function executeQuery(PhutilQueryString$query){}function executeRawQueries(array$queries){}function close(){}function openConnection(){}function simulateErrorOnNextQuery($error){}
}
$raw=file_get_contents($root.'/support/governance/recovery/borrowed-session-focused.php');$a=strpos($raw,'    $prewarmOwned=false;');$b=strpos($raw,'    $result=governance_recovery_borrowed_session_cases',$a);if($a===false||$b===false)throw new RuntimeException('missing_actual_block');$code=substr($raw,$a,$b-$a);
$writer=new Transport(array());$primary=new RuntimeException('primary');$cleanup=new RuntimeException('cleanup');$issuer=$owner=array('context'=>new stdClass());$caught=null;if($mode==='unowned')$writer->openTransaction();$writer->queries=array();try{eval($code);}catch(Throwable$e){$caught=$e;}
$children=$caught instanceof PhutilAggregateException?$caught->getExceptions():array();$out=array('primary_exact'=>$caught===$primary,'children_exact'=>array_keys($children)===array('primary','cleanup')&&$children['primary']===$primary&&$children['cleanup']===$cleanup,'queries'=>$writer->queries,'inside'=>$writer->isInsideTransaction(),'refused_unowned'=>$caught instanceof RuntimeException&&$caught->getMessage()==='borrowed_prewarm_outer_transaction_refused','native_transaction_method'=>(new ReflectionMethod($writer,'openTransaction'))->getDeclaringClass()->getName(),'physical_SQL_executed'=>false);
// Host collaborator teardown only, after recording actual block behavior; no SQL.
$state=(new ReflectionMethod(AphrontDatabaseConnection::class,'getTransactionState'))->invoke($writer);while($state->getDepth()>0){$state->decreaseDepth();}echo json_encode($out);
