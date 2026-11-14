<?php
/** Actual lease/fence/AuthSession hook with explicit SQL, route and DAO-insert
 * collaborators. No physical SQL, real login, grant or lock-wait proof. */
$root=$argv[1];$case=$argv[2];
class Phobject {const ATTACHABLE='attachable';}
interface PhabricatorPolicyInterface {}
class LiskDAO extends Phobject {protected static $forcedConnection;}
class PhabricatorAuthDAO extends LiskDAO {
  protected $id,$phid;
  public function __call($name,$args){$key=lcfirst(substr($name,3));if(str_starts_with($name,'set')){$this->$key=$args[0];return$this;}return$this->$key;}
  public function getID(){return$this->id;}
  public function getPHID(){return$this->phid;}
  public function establishConnection($mode){return$GLOBALS['writer'];}
  public function getAphrontRefDatabaseName(){return'governance_native_'.str_repeat('a',16).'_user';}
  protected function insertRecordIntoDatabase($mode){$GLOBALS['DAO_inserts']++;$this->id=1;$this->phid='PHID-AUTH-'.str_repeat('s',20);$GLOBALS['inserted']=$this;return$this;}
}
class PhabricatorUser extends PhabricatorAuthDAO {const SESSION_TABLE='session';public function getTableName(){return'user';}}
class PhorgeGovernanceRecoveryGuard {public static function enabled(){return true;}}
class PhabricatorEnv {public static function getEnvConfig($key){return$GLOBALS['flag'];}}
class PhorgeRecoveryRestoreProfile {
 public static function assertCurrent(){return array('namespace'=>'governance_native_'.str_repeat('a',16));}
 public static function assertOwnedWriter($c){if($c!==$GLOBALS['writer']||!$c->isInsideTransaction()){throw new RuntimeException('host_writer_not_owned');}}
}
class PhorgeRecoveryStopRequest {public static function user($v){if(!is_string($v)||!preg_match('/\APHID-USER-[a-z]{20}\z/D',$v)){throw new RuntimeException('subject_unknown');}return$v;}}
class AphrontDatabaseConnection {
 public $depth=0,$event='20',$physical='ACTIVE',$end=null,$id;
 public function __construct($id){$this->id=$id;}
 protected function getTransactionState(){return new class($this->depth){function __construct(private$d){}function getDepth(){return$this->d;}};}
 public function isInsideTransaction(){return$this->depth>0;}
 public function isReadLocking(){return false;}public function isWriteLocking(){return false;}
 public function openTransaction(){$this->depth++;$GLOBALS['opens']++;}
 public function saveTransaction(){$this->depth--;if($this->depth===0){$this->physical='COMMITTED';$this->end='30';}if($GLOBALS['case']==='lost-commit-ACK'){throw new RuntimeException('host_lost_ACK');}}
 public function killTransaction(){$this->depth=0;$this->physical='ROLLED BACK';$this->end='30';$GLOBALS['rollbacks']++;}
}
class AphrontMySQLiDatabaseConnection extends AphrontDatabaseConnection {}
function phutil_is_utf8($v){return preg_match('//u',$v)===1;}
function queryfx($c,$sql,...$args){$GLOBALS['SQL_calls']++;if(str_starts_with($sql,'SET TRANSACTION')){if($GLOBALS['case']==='raw-outer'){throw new RuntimeException('host_1568');}return;}if($sql==='ROLLBACK'){$c->killTransaction();return;}throw new RuntimeException('unexpected_SQL_write');}
function queryfx_one($c,$sql,...$args){
 $GLOBALS['SQL_calls']++;
 if(str_contains($sql,'CONNECTION_ID()')){
  $key=$c===$GLOBALS['writer']?'writer_id':'observer_id';$r=array($key=>$c->id,'uuid'=>str_repeat('a',8).'-'.str_repeat('a',4).'-'.str_repeat('a',4).'-'.str_repeat('a',4).'-'.str_repeat('a',12));
  if($key==='writer_id'){$r['db']='governance_native_'.str_repeat('a',16).'_user';}
  return$r+array('principal'=>($key==='writer_id'?'gov_c_writer_'.str_repeat('b',12):'gov_c_metadata_'.str_repeat('c',16)).'@%','role'=>$GLOBALS['case']==='role'?'role':'NONE','proxy'=>null);
 }
 if(str_contains($sql,'transaction_isolation')){return array('level'=>'REPEATABLE-READ');}
 if(str_contains($sql,'ENGINE')){return array('ENGINE'=>'InnoDB');}
 if(str_contains($sql,'WHERE 1=0')){return null;}
 if(str_starts_with($sql,'SELECT id FROM')){return array('id'=>'7');}
 if(str_starts_with($sql,'SELECT id,phid FROM')){return array('id'=>'7','phid'=>'PHID-USER-'.str_repeat('a',20));}
 if(str_starts_with($sql,'SELECT phid,userPHID')){
  $s=$GLOBALS['inserted'];return array('phid'=>$s->getPHID(),'userPHID'=>$s->getUserPHID(),'type'=>$s->getType(),'sessionKey'=>$s->getSessionKey(),'sessionStart'=>$s->getSessionStart(),'sessionExpires'=>$s->getSessionExpires(),'isPartial'=>$s->getIsPartial(),'highSecurityUntil'=>$s->getHighSecurityUntil(),'signedLegalpadDocuments'=>$s->getSignedLegalpadDocuments());
 }
 throw new RuntimeException('unexpected_SQL_read');
}
function queryfx_all($c,$sql,...$args){
 $GLOBALS['SQL_calls']++;
 if(str_contains($sql,'events_transactions_current')){if($GLOBALS['unknown']){return array();}$w=$GLOBALS['writer'];return array(array('THREAD_ID'=>'10','EVENT_ID'=>$w->event,'STATE'=>$w->physical,'END_EVENT_ID'=>$w->end,'ISOLATION_LEVEL'=>'READ COMMITTED'));}
 if($sql==='SHOW GRANTS FOR CURRENT_USER()'){
  if($GLOBALS['case']==='unknown-grants'){return array(array('Grants'=>'GRANT SYSTEM_USER ON *.* TO x'));}
  if($c!==$GLOBALS['writer']){return array(array('Grants'=>'GRANT SELECT, PROCESS ON *.* TO `gov_c_metadata_'.str_repeat('c',16).'`@`%`'));}
  $to=' TO `gov_c_writer_'.str_repeat('b',12).'`@`%`';$ns='governance_native_'.str_repeat('a',16);
  return array(array('Grants'=>'GRANT USAGE ON *.*'.$to),array('Grants'=>'GRANT SELECT, INSERT, UPDATE, DELETE ON `'.str_replace('_','\\_',$ns.'_user').'`.*'.$to),array('Grants'=>'GRANT SELECT ON `'.str_replace('_','\\_',$ns.'_governance').'`.*'.$to));
 }
 if(str_contains($sql,'OCTET_LENGTH')){if($GLOBALS['case']==='budget'){return array_fill(0,65,array('id'=>'1','row_bytes'=>'1'));}if($GLOBALS['case']==='oversize'){return array(array('id'=>'1','row_bytes'=>'70000'));}return$GLOBALS['changed']?array(array('id'=>'1','row_bytes'=>'100')):array();}
 if(str_starts_with($sql,'SELECT id,phid,userPHID')){return$GLOBALS['changed']?array(array('id'=>'1','phid'=>'PHID-AUTH-'.str_repeat('s',20),'userPHID'=>'PHID-USER-'.str_repeat('a',20),'type'=>'web','sessionKey'=>str_repeat('b',64),'sessionStart'=>'1','sessionExpires'=>'2','isPartial'=>'0','highSecurityUntil'=>null,'signedLegalpadDocuments'=>'0')):array();}
 throw new RuntimeException('unexpected_SQL_rows');
}
require_once$root.'/src/applications/auth/storage/PhabricatorAuthSession.php';
require_once$root.'/src/infrastructure/governance/recovery/PhorgeRecoveryMetadataGrantDescription.php';
require_once$root.'/src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php';
$writer=new AphrontMySQLiDatabaseConnection('1');$observer=new AphrontMySQLiDatabaseConnection('2');
$flag=$case!=='off';$SQL_calls=0;$opens=0;$rollbacks=0;$DAO_inserts=0;$changed=false;$unknown=false;$scope=null;$facts=null;$terminal=null;$error=null;$reserved=false;
function native_session_hook(){
 $s=(new PhabricatorAuthSession())->setUserPHID('PHID-USER-'.str_repeat('a',20))->setType('web')->setSessionKey(str_repeat('b',64))->setSessionStart(1)->setSessionExpires(2)->setIsPartial(0)->setSignedLegalpadDocuments(0)->setHighSecurityUntil(null);
 return(new ReflectionMethod(PhabricatorAuthSession::class,'insertRecordIntoDatabase'))->invoke($s,'INSERT');
}
// Execute the actual fixture begin/terminal/abort gate statements around the
// actual production lease class. SQL/DAO collaborators remain host-only.
$cases=file_get_contents($root.'/support/governance/recovery/session-lease-cases.php');
$start=strpos($cases,"      $"."GLOBALS['governance_session_lease_physical_cleanup_proved']=false;");
$end=strpos($cases,';$before=$scope->leaseGetFacts();',$start)+1;
$begin=substr($cases,$start,$end-$start);
$begin=str_replace('$subject->getPHID()',var_export('PHID-USER-'.str_repeat('a',20),true),$begin);
$nextStart=strpos($cases,"      $"."GLOBALS['governance_session_lease_physical_cleanup_proved']=false;",$end);
$nextEnd=strpos($cases,';',$nextStart+strlen("      $"."GLOBALS['governance_session_lease_physical_cleanup_proved']=false;"))+1;
$nextBegin=str_replace(['$subject->getPHID()','$next='],[var_export('PHID-USER-'.str_repeat('a',20),true),'$scope='],substr($cases,$nextStart,$nextEnd-$nextStart));
$terminalStart=strpos($cases,'      $terminal=$scope->leaseGetTerminalFacts();');$terminalEnd=strpos($cases,'$scope=null;',$terminalStart)+strlen('$scope=null;');$terminalGate=substr($cases,$terminalStart,$terminalEnd-$terminalStart);
$helperStart=strpos($cases,'function governance_recovery_session_lease_cleanup(');$helperEnd=strpos($cases,'/** SQL6 callable entry',$helperStart);eval(substr($cases,$helperStart,$helperEnd-$helperStart));
$GLOBALS['governance_session_lease_physical_cleanup_proved']=true;
try{
 if(in_array($case,['unknown-begin','unknown-next'],true)){$unknown=true;}
 eval($case==='unknown-next'?$nextBegin:$begin);
 if($case==='abort'){governance_recovery_session_lease_cleanup($scope);$terminal=$scope->leaseGetTerminalFacts();$scope=null;}
 elseif($case==='unknown-abort'){$unknown=true;governance_recovery_session_lease_cleanup($scope);}
 elseif($case==='abort-throws'){$scope=new class{function leaseAbort(){throw new RuntimeException('host_abort_fault');}};governance_recovery_session_lease_cleanup($scope);}
 else{if($case==='rollback'){$scope->leaseRollback();}else{$scope->leaseCommit();}eval($terminalGate);}
}catch(Throwable$e){$error=$e->getMessage();}
// Execute the actual focused authorization handoff and actual driver DROP guard.
$focused=file_get_contents($root.'/support/governance/recovery/session-lease-focused.php');$a=strpos($focused,"      if(($"."GLOBALS['governance_session_lease_physical_cleanup_proved']");$b=strpos($focused,"    }catch(Throwable",$a);$cleanup=['cleanup_proved'=>true];$GLOBALS['governance_session_lease_cleanup']=['cleanup_proved'=>false];$focusedError=null;try{eval(substr($focused,$a,$b-$a));}catch(Throwable$e){$focusedError=$e->getMessage();}
class Fixture{function cleanup(){$GLOBALS['drops']++;return[];}}
$driver=file_get_contents($root.'/support/governance/native-organization/test-native-organization-mysql.php');$a=strpos($driver,'  if($borrowed_unknown){');$borrowed_unknown=false;$b=strpos($driver,'  if(!$borrowed_unknown){if ($control !== null)',$a);$native_rr=false;$workbench_http=false;$notice_history=false;$task_browser=false;$phase='session-producer-lease';$fixture=new Fixture();$cleanup_failures=[];$checks=[];$drops=0;eval(substr($driver,$a,$b-$a));
echo json_encode(['error'=>$error,'terminal'=>$terminal,'opens'=>$opens,'rollbacks'=>$rollbacks,'physical_cleanup_flag'=>$GLOBALS['governance_session_lease_physical_cleanup_proved'],'focused_error'=>$focusedError,'driver_drops'=>$drops,'driver_cleanup_failures'=>$cleanup_failures,'physical_SQL_executed'=>false]);
