<?php
/** Actual RestoreService owner + actual Session participant; native database,
 * identity and profile collaborators are explicit, no physical SQL executed. */
$root=$argv[1];$mode=$argv[2];require$root.'/support/runtime/src/error/PhutilAggregateException.php';
class Phobject{}
class PhorgeGovernanceNativeActorContext {function getUserPHID(){return'PHID-USER-'.str_repeat('a',20);}}
class Snapshot {function isSystemAdmin(){return true;}function getExpiresAt(){return null;}}
class PhorgeGovernanceNativeIdentitySource {static function lockCurrent(...$x){return new Snapshot();}static function revalidate($x,$c){return$x;}}
class PhorgeRecoveryRestoreProfile {static function assertCurrent(){return['namespace'=>'governance_native_'.str_repeat('b',16)];}static function assertOwnedWriter($c){if(!$c->isInsideTransaction())throw new RuntimeException('host_no_owned_transaction');}}
class PhorgeRecoveryStopRequest {static function user($p){if(!is_string($p)||!preg_match('/\APHID-USER-[a-z]{20}\z/D',$p))throw new RuntimeException('host_subject');return$p;}}
class AphrontDatabaseConnection {public$depth=0;protected function getTransactionState(){return new class($this){function __construct(private$c){}function getDepth(){return$this->c->depth;}};}}
final class AphrontMySQLiDatabaseConnection extends AphrontDatabaseConnection {
 public$state='ACTIVE';function __construct(public$id){}function isInsideTransaction(){return$this->depth>0;}function isReadLocking(){return false;}function isWriteLocking(){return false;}
 function openTransaction(){$GLOBALS['opens']++;$this->depth++;if($GLOBALS['mode']==='open-unknown')throw$GLOBALS['primary'];}
 function saveTransaction(){$GLOBALS['commits']++;$this->depth--;$this->state='COMMITTED';if($GLOBALS['mode']==='commit-ACK')throw$GLOBALS['primary'];}
 function killTransaction(){$GLOBALS['rollbacks']++;$this->depth--;$this->state='ROLLED BACK';if($GLOBALS['mode']==='rollback-error')throw new RuntimeException('host_rollback_failure');}
}
class PhabricatorUser {function establishConnection($m){return$GLOBALS['writer'];}function getTableName(){return'user';}function getAphrontRefDatabaseName(){return'governance_native_'.str_repeat('b',16).'_user';}}
class PhabricatorOAuthServerAuthorizationCode extends PhabricatorUser {function establishConnection($m){return $GLOBALS['oauth_code'];}function getAphrontRefDatabaseName(){return 'governance_native_'.str_repeat('b',16).'_oauth_server';}}
class PhabricatorAuthSession extends PhabricatorUser {function getTableName(){return'session';}}
function queryfx($c,$sql,...$args){$GLOBALS['statement_sql'][]=$sql;}
function queryfx_one($c,$sql,...$args){
 if($sql==='SELECT @@server_uuid u')return ['u'=>$GLOBALS['mode']==='different-primary'&&$c===$GLOBALS['oauth_code']?'foreign':'same'];
 if(str_contains($sql,'UNIX_TIMESTAMP'))return['n'=>(string)($GLOBALS['clock']??100)];
 if(str_contains($sql,'@@SESSION.transaction_isolation'))return['level'=>'REPEATABLE-READ'];
 if(str_contains($sql,'writer_id'))return['writer_id'=>'1','uuid'=>str_repeat('a',36),'db'=>'governance_native_'.str_repeat('b',16).'_user','principal'=>'gov_c_writer_'.str_repeat('c',12).'@%','role'=>'NONE','proxy'=>null];
 if(str_contains($sql,'observer_id'))return['observer_id'=>$GLOBALS['mode']==='observer-end'&&$GLOBALS['writer']->depth===0?'3':'2','uuid'=>str_repeat('a',36),'principal'=>'gov_c_metadata_'.str_repeat('d',16).'@%','role'=>$GLOBALS['mode']==='role'?'foreign':'NONE','proxy'=>null];
 if(str_contains($sql,'WHERE 1=0')&&($args[0]??null) instanceof PhabricatorOAuthServerAuthorizationCode){
  if($c!==$GLOBALS['writer']||!$c->isInsideTransaction())throw new RuntimeException('host_wrong_OAuthCode_MDL_owner');
  $GLOBALS['oauth_code_steps'][]='MDL';$GLOBALS['oauth_code_MDL']=true;return null;}
 if(str_contains($sql,'SELECT ENGINE')){
  if(($args[0]??null)==='governance_native_'.str_repeat('b',16).'_oauth_server'){
   if($c!==$GLOBALS['writer']||empty($GLOBALS['oauth_code_MDL']))throw new RuntimeException('host_OAuthCode_ENGINE_before_MDL');
   $GLOBALS['oauth_code_steps'][]='ENGINE';return['ENGINE'=>$GLOBALS['mode']==='oauth_code-engine'?'MyISAM':'InnoDB'];}
  return['ENGINE'=>'InnoDB'];}

 if(str_contains($sql,'SELECT id,phid'))return['id'=>'1','phid'=>'PHID-USER-'.str_repeat('a',20)];
 if(str_contains($sql,'SELECT id FROM')&&str_contains($sql,'WHERE BINARY phid'))return['id'=>'1'];
 return null;
}
function queryfx_all($c,$sql,...$args){
 if(str_contains($sql,'SELECT id,userPHID,clientPHID')){
   if(empty($GLOBALS['oauth_code_MDL']))throw new RuntimeException('host_OAuthCode_read_without_MDL');$GLOBALS['oauth_code_steps'][]='READ';
   $GLOBALS['oauth_code_reads']=($GLOBALS['oauth_code_reads']??0)+1;
   $row=['id'=>'1','userPHID'=>'PHID-USER-'.str_repeat('a',20),'clientPHID'=>'PHID-OASC-'.str_repeat('c',20),'code'=>str_repeat('s',32),'clientSecret'=>str_repeat('q',32),'redirectURI'=>'https://synthetic.invalid/','dateCreated'=>'1','dateModified'=>'1'];
   if($GLOBALS['mode']==='oauth_code-drift'&&$GLOBALS['oauth_code_reads']>1)$row['code']=str_repeat('t',32);
   if(in_array($GLOBALS['mode'],array('oauth_code-budget','catch-source-failure'),true))return array_fill(0,65,$row);
   if($GLOBALS['mode']==='oauth_code-shape')$row['unknown']='bad';
   if($GLOBALS['mode']==='oauth_code-permission')throw new RuntimeException('host_SELECT_permission_refused');
   return [$row];
 }

 if(str_contains($sql,'performance_schema.events_transactions_current')){
  if($GLOBALS['mode']==='unknown-begin'||in_array($GLOBALS['mode'],['unknown-end','unknown-reentry'],true)&&$GLOBALS['writer']->depth===0)return[];
  return[['THREAD_ID'=>'10','EVENT_ID'=>'20','STATE'=>$GLOBALS['writer']->state,'END_EVENT_ID'=>$GLOBALS['writer']->depth?null:'30','ISOLATION_LEVEL'=>$GLOBALS['mode']==='RR-owner'?'REPEATABLE READ':'READ COMMITTED']]+[];
 }
 if($sql==='SHOW GRANTS FOR CURRENT_USER()'){
  $ns='governance_native_'.str_repeat('b',16);$to=' TO `gov_c_writer_'.str_repeat('c',12).'`@`%`';
  if($c===$GLOBALS['observer'])return[['g'=>'GRANT SELECT, PROCESS ON *.* TO `gov_c_metadata_'.str_repeat('d',16).'`@`%`']];
  return[['g'=>'GRANT USAGE ON *.*'.$to],['g'=>'GRANT SELECT, INSERT, UPDATE, DELETE ON `'.str_replace('_','\\_',$ns.'_user').'`.*'.$to],['g'=>'GRANT SELECT ON `'.str_replace('_','\\_',$ns.'_governance').'`.*'.$to]];
 }
 if(str_contains($sql,'information_schema.COLUMNS')){
  $table=$args[0];$out=[];foreach($GLOBALS['profiles'][$table]as$n=>$t){$nullable=$table==='recovery_restore_request'&&in_array($n,['approverPHID','approverBindingJSON','reviewedAt'],true);$out[]=['COLUMN_NAME'=>$n,'COLUMN_TYPE'=>$t,'IS_NULLABLE'=>$nullable?'YES':'NO','EXTRA'=>$table==='recovery_restore_audit'&&$n==='id'?'auto_increment':''];}return$out;
 }
 if(str_contains($sql,'information_schema.STATISTICS')){
  $t=$args[0];$keys=$t==='recovery_restore_request'?['PRIMARY'=>['requestID'],'key_operation'=>['operationID']]:($t==='recovery_restore_operation'?['PRIMARY'=>['operationID']]:['PRIMARY'=>['id'],'key_operation'=>['operationID']]);$out=[];foreach($keys as$k=>$cols)foreach($cols as$n)$out[]=['INDEX_NAME'=>$k,'COLUMN_NAME'=>$n];return$out;
 }
 if(str_contains($sql,'OCTET_LENGTH')){$GLOBALS['capture_reads']=($GLOBALS['capture_reads']??0)+1;if($GLOBALS['mode']==='slow-finish'&&$GLOBALS['capture_reads']>1)$GLOBALS['clock']=131;}
 if(str_contains($sql,'OCTET_LENGTH')&&in_array($GLOBALS['mode'],['budget','catch-budget'],true))return array_fill(0,65,['id'=>'1','row_bytes'=>'1']);
 return[];
}
require$root.'/src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php';
require$root.'/src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php';
// Minimal schema collaborator uses the real store's fixed profile declaration;
// it does not claim a real schema or SQL compatibility test.
$text=file_get_contents($root.'/src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php');$a=strpos($text,'$shared=array(');$b=strpos($text,'    if (!isset($profiles',$a);eval(substr($text,$a,$b-$a));
$oauth_code=new AphrontMySQLiDatabaseConnection('3');$writer=new AphrontMySQLiDatabaseConnection('1');$observer=new AphrontMySQLiDatabaseConnection('2');$primary=new RuntimeException('host_primary');$opens=0;$commits=0;$rollbacks=0;$statement_sql=[];$error=null;$facts=null;$same=false;$children=[];
if($mode==='fake-token'){
 try{PhorgeRecoverySessionProducerFence::beginBorrowed(new stdClass(),$writer,$observer);}catch(Throwable$e){$error=$e->getMessage();}
}else{
 (new ReflectionProperty(PhorgeRecoveryRestoreService::class,'borrowedObserver'))->setValue(null,$observer);
 try{$facts=(new ReflectionMethod(PhorgeRecoveryRestoreService::class,'withTransaction'))->invoke(null,new PhorgeGovernanceNativeActorContext(),[],static function($conn){try{$facts=PhorgeRecoveryRestoreService::captureBorrowedOAuthCodeFacts($conn,'PHID-USER-'.str_repeat('a',20));}catch(Throwable$e){if($GLOBALS['mode']!=='catch-source-failure')throw$e;$facts=['caller_caught_source_failure'=>true];}if(in_array($GLOBALS['mode'],['primary','rollback-error'],true))throw$GLOBALS['primary'];return['result'=>$facts,'bindings'=>[],'deadline'=>null];});}
 catch(Throwable$e){$error=$e->getMessage();$same=$e===$primary;if($e instanceof PhutilAggregateException){foreach($e->getExceptions()as$k=>$v){$children[$k]=$v->getMessage();}}}
}
 $retryError=null;if($mode==='unknown-reentry'){try{(new ReflectionMethod(PhorgeRecoveryRestoreService::class,'withTransaction'))->invoke(null,new PhorgeGovernanceNativeActorContext(),[],static fn()=>[]);}catch(Throwable$e){$retryError=$e->getMessage();}}
echo json_encode(['oauth_code_steps'=>$GLOBALS['oauth_code_steps']??[],'retry_error'=>$retryError,'mode'=>$mode,'error'=>$error,'same_primary'=>$same,'aggregate'=>$children,'opens'=>$opens,'commits'=>$commits,'rollbacks'=>$rollbacks,'statement_sql'=>$statement_sql,'depth'=>$writer->depth,'facts'=>$facts,'cleanup_proved'=>$GLOBALS['governance_recovery_borrowed_cleanup_proved']??false,'terminal'=>$GLOBALS['governance_recovery_borrowed_terminal']??null,'retained_owner'=>(new ReflectionProperty(PhorgeRecoveryRestoreService::class,'borrowedOwner'))->getValue()!==null,'physical_SQL_executed'=>false]);
