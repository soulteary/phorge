<?php
/** Actual entire driver finally and actual cleanup-ready predicate; explicit
 * owner-state/connection/fixture collaborators, no SQL or process execution. */
$root=$argv[1];$mode=$argv[2];
class PhorgeRecoveryRestoreService{private static $borrowedOwner;private static $borrowedObserver;}
require$root.'/support/governance/recovery/conduit-focused.php';
class Conn{public$active;function __construct($v){$this->active=$v;}function isInsideTransaction(){return$this->active;}function killTransaction(){$GLOBALS['rollbacks']++;$this->active=false;}function isReadLocking(){return false;}function isWriteLocking(){return false;}}
class LiskDAO{private static $connections;static function closeAllConnections(){$GLOBALS['pool_closes']++;}}
class PhabricatorEnv{private static $setting='current';}
class PhabricatorCaches{private static $requestCache='current';}
class Guard{function dispose(){$GLOBALS['disposes']++;}}
class Fixture{function getSummary(){return[];}function cleanup(){$GLOBALS['drops']++;return[];}}
class Control{function close(){$GLOBALS['control_closes']++;}}
$unknown=in_array($mode,['unknown','forged-flags'],true);$phase=$mode==='old'?'session-producer-lease':'conduit-producer-owner';
$GLOBALS['governance_recovery_conduit_cleanup_proved']=$mode!=='early';
$GLOBALS['governance_recovery_borrowed_cleanup_proved']=$mode==='forged-flags'||!$unknown;
$GLOBALS['governance_recovery_borrowed_fixture_cleanup']=['cleanup_proved'=>$mode==='forged-flags'||!$unknown];
$GLOBALS['governance_session_lease_cleanup']=['cleanup_proved'=>true];
if($unknown){(new ReflectionProperty(PhorgeRecoveryRestoreService::class,'borrowedOwner'))->setValue(null,['retained'=>true]);(new ReflectionProperty(PhorgeRecoveryRestoreService::class,'borrowedObserver'))->setValue(null,new stdClass());}
$rollbacks=0;$pool_closes=0;$disposes=0;$drops=0;$control_closes=0;
(new ReflectionProperty(LiskDAO::class,'connections'))->setValue(null,[new Conn($unknown||$mode==='old')]);
$superglobal_state=['_COOKIE'=>['old'=>'synthetic'],'_SERVER'=>[]];$_COOKIE=['current'=>'synthetic'];$_SERVER=[];
$fixture=new Fixture();$control=new Control();$write_guard=new Guard();$environment_state=['setting'=>'original'];$cache_state='original';$failure=['error_type'=>'RuntimeException'];$cleanup_failures=[];$failure_cleanup_rollbacks=0;$checks=[];$file_service=null;$native_rr=false;$workbench_http=false;$notice_history=false;$task_browser=false;$console_owned=false;$cleanup=[];
$text=file_get_contents($root.'/support/governance/native-organization/test-native-organization-mysql.php');$needle="} finally {\n  $"."borrowed_unknown=";$a=strpos($text,$needle)+strlen("} finally {\n");$b=strpos($text,"\n$"."native_output =",$a);$body=substr($text,$a,$b-$a);$body=substr($body,0,strrpos($body,'}'));eval($body);
echo json_encode(['mode'=>$mode,'rollbacks'=>$rollbacks,'pool_closes'=>$pool_closes,'disposes'=>$disposes,'drops'=>$drops,'control_closes'=>$control_closes,'env'=>(new ReflectionProperty(PhabricatorEnv::class,'setting'))->getValue(),'failures'=>$cleanup_failures,'physical_SQL_executed'=>false]);
