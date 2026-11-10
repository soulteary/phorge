<?php
/** Actual RestoreService owner + actual Session participant; native database,
 * identity and profile collaborators are explicit, no physical SQL executed. */
$root=$argv[1];$mode=$argv[2];$generation=0;$client_prepare_events=array();$reads=[];require$root.'/support/runtime/src/error/PhutilAggregateException.php';
class Phobject{}
class PhorgeGovernanceNativeActorContext {function getUserPHID(){return'PHID-USER-'.str_repeat('a',20);}}
class Snapshot {function isSystemAdmin(){return true;}function getExpiresAt(){return null;}}
class PhorgeGovernanceNativeIdentitySource {static function lockCurrent(...$x){return new Snapshot();}static function revalidate($x,$c){return$x;}}
class PhorgeRecoveryRestoreProfile {static function assertCurrent(){return['namespace'=>'governance_native_'.str_repeat('b',16),'incarnation_digest'=>str_repeat('b',64)];}static function assertOwnedWriter($c){if(!$c->isInsideTransaction())throw new RuntimeException('host_no_owned_transaction');}}
class PhorgeRecoveryStopRequest {static function transactionID($x){return $x;}static function event(...$x){return str_repeat('e',64);}static function user($p){if(!is_string($p)||!preg_match('/\APHID-USER-[a-z]{20}\z/D',$p))throw new RuntimeException('host_subject');return$p;}}
class AphrontDatabaseConnection {public$depth=0;protected function getTransactionState(){return new class($this){function __construct(private$c){}function getDepth(){return$this->c->depth;}};}}
final class AphrontMySQLiDatabaseConnection extends AphrontDatabaseConnection {
 public$state='ACTIVE';function __construct(public$id){}function isInsideTransaction(){return$this->depth>0;}function isReadLocking(){return false;}function isWriteLocking(){return false;}
 function openTransaction(){$GLOBALS['opens']++;$this->state='ACTIVE';$this->depth++;if($GLOBALS['mode']==='open-unknown')throw$GLOBALS['primary'];}
 function saveTransaction(){$GLOBALS['commits']++;$this->depth--;$this->state='COMMITTED';if($GLOBALS['mode']==='commit-ACK')throw$GLOBALS['primary'];}
 function killTransaction(){$GLOBALS['rollbacks']++;$this->depth--;$this->state='ROLLED BACK';if($GLOBALS['mode']==='rollback-error')throw new RuntimeException('host_rollback_failure');}
}
class PhabricatorUser {function establishConnection($m){return$GLOBALS['writer'];}function getTableName(){return'user';}function getAphrontRefDatabaseName(){return'governance_native_'.str_repeat('b',16).'_user';}}
class PhabricatorAuthSession extends PhabricatorUser {function getTableName(){return'session';}}
class PhabricatorConduitToken extends PhabricatorUser {function establishConnection($m){return $GLOBALS['conduit'];}function getTableName(){return 'conduit_token';}function getAphrontRefDatabaseName(){return 'governance_native_'.str_repeat('b',16).'_conduit';}}
class PhabricatorOAuthServerAccessToken extends PhabricatorConduitToken {function establishConnection($m){return $GLOBALS['oauth'];}function getTableName(){return 'oauth_server_accesstoken';}function getAphrontRefDatabaseName(){return 'governance_native_'.str_repeat('b',16).'_oauth_server';}}
class PhabricatorOAuthClientAuthorization extends PhabricatorOAuthServerAccessToken {function getTableName(){return 'oauth_server_oauthclientauthorization';}}
class PhabricatorOAuthServerAuthorizationCode extends PhabricatorOAuthServerAccessToken {function getTableName(){return 'oauth_server_authorizationcode';}}
function queryfx($c,$sql,...$args){$GLOBALS['statement_sql'][]=$sql;}
function queryfx_one($c,$sql,...$args){
 if(str_contains($sql,'SELECT CAST(CONNECTION_ID() AS CHAR) id,@@server_uuid u'))return ['id'=>$GLOBALS['mode']==='same-auth-thread'?'1':($c===$GLOBALS['writer']?'1':((in_array($GLOBALS['mode'],['auth-thread-drift','next-same-source-new-OAuth-thread'],true)&&$GLOBALS['generation'])?'9':'4')),'u'=>$GLOBALS['mode']==='auth-foreign-primary'&&$c!==$GLOBALS['writer']?'foreign':str_repeat('a',36)];
 if(str_contains($sql,'SELECT @@server_uuid u'))return ['u'=>str_repeat('a',36)];
 if(str_contains($sql,'FROM recovery_stop'))return ['nativeTransactionID'=>'1','subjectPHID'=>'PHID-USER-'.str_repeat('a',20),'category'=>'technical-pause','eventDigest'=>str_repeat('e',64)];
 if(str_contains($sql,'SELECT id,objectPHID,authorPHID'))return ['id'=>'1'];
 if(str_contains($sql,'SELECT phid,isDisabled'))return ['phid'=>'PHID-USER-'.str_repeat('a',20),'isDisabled'=>'1'];
 if(str_contains($sql,'UNIX_TIMESTAMP'))return['n'=>(string)($GLOBALS['clock']??100)];
 if(str_contains($sql,'@@SESSION.transaction_isolation'))return['level'=>'REPEATABLE-READ'];
 if(str_contains($sql,'writer_id'))return['writer_id'=>'1','uuid'=>str_repeat('a',36),'db'=>'governance_native_'.str_repeat('b',16).'_user','principal'=>'gov_c_writer_'.str_repeat('c',12).'@%','role'=>'NONE','proxy'=>null];
 if(str_contains($sql,'observer_id'))return['observer_id'=>$GLOBALS['mode']==='observer-end'&&$GLOBALS['writer']->depth===0?'3':'2','uuid'=>str_repeat('a',36),'principal'=>'gov_c_metadata_'.str_repeat('d',16).'@%','role'=>$GLOBALS['mode']==='role'?'foreign':'NONE','proxy'=>null];
 if(str_contains($sql,'SELECT ENGINE'))return['ENGINE'=>'InnoDB'];
 if(str_contains($sql,'SELECT id,phid'))return['id'=>'1','phid'=>'PHID-USER-'.str_repeat('a',20)];
 if(str_contains($sql,'SELECT id FROM')&&str_contains($sql,'WHERE BINARY phid'))return['id'=>'1'];
 return null;
}
function queryfx_all($c,$sql,...$args){
 if(str_contains($sql,'FROM %R')&&($args[0]??null) instanceof PhabricatorOAuthClientAuthorization){$GLOBALS['reads'][]='oauth-authorization';if($GLOBALS['mode']==='unsupported-oauth-authorization')throw new RuntimeException('host_missing_SELECT');if($GLOBALS['mode']==='budget-oauth-authorization')return array_fill(0,65,[]);return [['id'=>'1','phid'=>'PHID-OASA-'.str_repeat('f',20),'userPHID'=>'PHID-USER-'.str_repeat('a',20),'clientPHID'=>'PHID-OASC-'.str_repeat('b',20),'scope'=>($GLOBALS['mode']==='shape-oauth-authorization'?'invalid':(($GLOBALS['generation']&&str_contains($GLOBALS['mode'],'oauth-authorization'))?'{"host_observation":"host_observation"}':'[]')),'dateCreated'=>'1','dateModified'=>'1']];}
 if(str_contains($sql,'FROM %R')&&($args[0]??null) instanceof PhabricatorOAuthServerAuthorizationCode){$GLOBALS['reads'][]='oauth-code';if(in_array($GLOBALS['mode'],array('unsupported-oauth-code','caught-unsupported-oauth-code'),true))throw new RuntimeException('host_missing_SELECT');return [['id'=>'1','userPHID'=>'PHID-USER-'.str_repeat('a',20),'clientPHID'=>'PHID-OASC-'.str_repeat('b',20),'code'=>str_repeat(in_array($GLOBALS['mode'],array('mutate-oauth-code','next-oauth-code','stale-oauth-code'),true)&&$GLOBALS['generation']?'d':'c',32),'clientSecret'=>str_repeat('q',32),'redirectURI'=>'https://synthetic.invalid/','dateCreated'=>'1','dateModified'=>'1']];}
 if(str_contains($sql,'FROM %R')&&($args[0]??null) instanceof PhabricatorConduitToken){$kind=$args[0] instanceof PhabricatorOAuthServerAccessToken?'oauth-access':'conduit';$GLOBALS['reads'][]=$kind;if($GLOBALS['mode']==='unsupported-'.$kind||$GLOBALS['mode']==='caught-unsupported-'.$kind)throw new RuntimeException('host_missing_SELECT');if($GLOBALS['mode']==='budget-'.$kind)return array_fill(0,65,[]);$value=($GLOBALS['mode']==='mutate-'.$kind||($GLOBALS['mode']==='next-'.$kind||$GLOBALS['mode']==='stale-'.$kind))?$GLOBALS['generation']:0;if($kind==='oauth-access')return [['id'=>'1','userPHID'=>'PHID-USER-'.str_repeat('a',20),'clientPHID'=>'PHID-OASC-'.str_repeat('b',20),'token'=>str_repeat('c',40).$value,'dateCreated'=>'1','dateModified'=>'1']];return [['id'=>'1','objectPHID'=>'PHID-USER-'.str_repeat('a',20),'tokenType'=>'api','token'=>str_repeat('c',32).$value,'expires'=>null,'dateCreated'=>'1','dateModified'=>'1','tokenName'=>'selected']];}
 if(str_contains($sql,'performance_schema.events_transactions_current')){
  if($GLOBALS['mode']==='unknown-begin'||in_array($GLOBALS['mode'],['unknown-end','unknown-reentry'],true)&&$GLOBALS['writer']->depth===0)return[];
  return[['THREAD_ID'=>'10','EVENT_ID'=>(string)(20+$GLOBALS['opens']),'STATE'=>$GLOBALS['writer']->state,'END_EVENT_ID'=>$GLOBALS['writer']->depth?null:'30','ISOLATION_LEVEL'=>'READ COMMITTED']]+[];
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
 if(str_contains($sql,'OCTET_LENGTH'))return [['id'=>'1','row_bytes'=>'200']];
 if(str_contains($sql,'SELECT id,phid,userPHID,type,sessionKey')){$GLOBALS['reads'][]='session';return [['id'=>'1','phid'=>'PHID-SESS-'.str_repeat('c',20),'userPHID'=>'PHID-USER-'.str_repeat('a',20),'type'=>'web','sessionKey'=>str_repeat(($GLOBALS['mode']==='mutate-session'||($GLOBALS['mode']==='next-session'||$GLOBALS['mode']==='stale-session'))&&$GLOBALS['generation']?'d':'c',64),'sessionStart'=>'1','sessionExpires'=>'999','isPartial'=>'0','highSecurityUntil'=>null,'signedLegalpadDocuments'=>'0']];}
 return[];
}
require$root.'/src/infrastructure/governance/recovery/PhorgeRecoveryConduitProducerFence.php';
require$root.'/src/infrastructure/governance/recovery/PhorgeRecoveryRestoreRequest.php';
require$root.'/src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php';
require$root.'/src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php';
require$root.'/src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php';
// Minimal schema collaborator uses the real store's fixed profile declaration;
// it does not claim a real schema or SQL compatibility test.
$text=file_get_contents($root.'/src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php');$a=strpos($text,'$shared=array(');$b=strpos($text,'    if (!isset($profiles',$a);eval(substr($text,$a,$b-$a));
$conduit=new AphrontMySQLiDatabaseConnection('3');$oauth=new AphrontMySQLiDatabaseConnection('4');$writer=new AphrontMySQLiDatabaseConnection('1');$observer=new AphrontMySQLiDatabaseConnection('2');$primary=new RuntimeException('host_primary');$opens=0;$commits=0;$rollbacks=0;$statement_sql=[];$error=null;$facts=null;$same=false;$children=[];
// These are explicit SQL/native collaborators around the real owner/participant/Assessment.
class PhabricatorUserTransaction{}class PhabricatorUserDisableTransaction{const TRANSACTIONTYPE='disable';}
class PhorgeGovernanceNativeNoncredentialIdentitySource{static function lockIdentityPHID(...$x){return ['host-current'=>true];}}
class PhabricatorEnv{static function getEnvConfig($x){return false;}}
class PhorgeRecoveryRestoreOrganizationSource{static function capture(...$x){return ['complete'=>false];}}
class PhorgeRecoveryNativeCaptureScope{
 static function prepareBorrowedOAuthClients($token,$writer,$observer,$target){
  // Only this client's storage/version is a collaborator. The actual opaque
  // owner check, participant and Store lifecycle remain real source methods.
  PhorgeRecoveryRestoreService::assertBorrowedOwner($token,$writer,$observer);
  PhorgeRecoveryRestoreProfile::assertOwnedWriter($writer);
  $GLOBALS['client_prepare_events'][]=['prepare',count($GLOBALS['reads'])];
  if($GLOBALS['mode']==='unsupported-client-prep')throw new RuntimeException('host_client_source_unsupported');
  return hash('sha256','client-state-'.($GLOBALS['mode']==='mutate-client-prep'?$GLOBALS['generation']:0));
 }
 static function captureForRestoration(...$x){return ['complete'=>false];}
}
class PhorgeGovernanceRecoverySourceInventory{static function requiredFamilies(){return ['account-stop','application-config','api-credentials','extensions-producers','file-capabilities','media-credentials','object-automatic','organization','policy','project-relationships','restore-lineage','spaces','web-credentials'];}static function inspectRuntime(){return ['host-collaborator'=>true];}}
class Fact{function finish(){return ['complete'=>false,'source_digest'=>str_repeat('f',64)];}}
class PhorgeRecoveryApplicationConfigFacts{static function capture(...$x){return new Fact();}}
class PhorgeRecoveryProjectSourceFacts extends PhorgeRecoveryApplicationConfigFacts{}
class PhorgeRecoverySpacesSourceFacts extends PhorgeRecoveryApplicationConfigFacts{}
class PhorgeRecoveryRestorePolicySource{static function capture(...$x){return ['complete'=>false];}}
if($mode!=='unavailable'){(new ReflectionProperty(PhorgeRecoveryRestoreService::class,'borrowedObserver'))->setValue(null,$observer);}
function captureAssessment($conn){return PhorgeRecoveryRestoreAssessment::capture(new PhorgeGovernanceNativeActorContext(),$conn,'PHID-USER-'.str_repeat('a',20),'1');}
function ownerRun($callback){return (new ReflectionMethod(PhorgeRecoveryRestoreService::class,'withTransaction'))->invoke(null,new PhorgeGovernanceNativeActorContext(),[],$callback);}
try{$facts=ownerRun(static function($conn){try{$a=captureAssessment($conn);}catch(Throwable$e){if(!str_starts_with($GLOBALS['mode'],'caught-unsupported-'))throw$e;return ['result'=>['caught'=>true],'bindings'=>[],'deadline'=>null];}$before=$a->getBinding();if($GLOBALS['mode']==='primary')throw$GLOBALS['primary'];$blocked=false;try{$a->requireCompleteForExecution();}catch(RuntimeException$e){$blocked=true;}
 if(str_starts_with($GLOBALS['mode'],'mutate-')||$GLOBALS['mode']==='auth-thread-drift'){$GLOBALS['generation']=1;captureAssessment($conn);}
 return ['result'=>['before'=>$before,'digest'=>$a->getDigest(),'blocked'=>$blocked],'bindings'=>[],'deadline'=>null];});
 if(str_starts_with($mode,'next-')||str_starts_with($mode,'stale-')){$generation=1;$expected_digest=$facts['digest'];$second=ownerRun(static function($conn){$a=captureAssessment($conn); $plan=['result'=>['binding'=>$a->getBinding(),'digest'=>$a->getDigest()],'bindings'=>[],'deadline'=>null];if(str_starts_with($GLOBALS['mode'],'stale-')){$plan+=['sources'=>$GLOBALS['expected_digest'],'subject'=>'PHID-USER-'.str_repeat('a',20),'stop'=>'1'];}return$plan;});$facts['after']=$second;}
}catch(Throwable$e){$same=$e===$primary;$error=$e->getMessage();if($e instanceof PhutilAggregateException)foreach($e->getExceptions()as$k=>$v)$children[$k]=$v->getMessage();}
 $retryError=null;if($mode==='unknown-reentry'){try{(new ReflectionMethod(PhorgeRecoveryRestoreService::class,'withTransaction'))->invoke(null,new PhorgeGovernanceNativeActorContext(),[],static fn()=>[]);}catch(Throwable$e){$retryError=$e->getMessage();}}
echo json_encode(['client_prepare_events'=>$client_prepare_events,'reads'=>$reads,'retry_error'=>$retryError,'mode'=>$mode,'error'=>$error,'same_primary'=>$same,'aggregate'=>$children,'opens'=>$opens,'commits'=>$commits,'rollbacks'=>$rollbacks,'statement_sql'=>$statement_sql,'depth'=>$writer->depth,'facts'=>$facts,'cleanup_proved'=>$GLOBALS['governance_recovery_borrowed_cleanup_proved']??false,'terminal'=>$GLOBALS['governance_recovery_borrowed_terminal']??null,'retained_owner'=>(new ReflectionProperty(PhorgeRecoveryRestoreService::class,'borrowedOwner'))->getValue()!==null,'physical_SQL_executed'=>false]);
