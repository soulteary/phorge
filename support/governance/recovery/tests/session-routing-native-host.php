<?php
/** Real Env/ScopedEnv/config sources/routing; owned-fixture assertion/config
 * are explicit collaborators. No SQL, native login or worker process executes. */
$root=$argv[1];$mode=$argv[2];putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=1');
function phutil_register_library_map($map){foreach($map['class']as$c=>$p){$GLOBALS['paths'][$c]=$GLOBALS['base'].'/'.$p;}}
foreach(array('/support/runtime/src','/src')as$b){$GLOBALS['base']=$root.$b;require$root.$b.'/__phutil_library_map__.php';}
spl_autoload_register(static function($c){if(isset($GLOBALS['paths'][$c])){require_once$GLOBALS['paths'][$c];}});
require_once$root.'/support/runtime/src/utils/utils.php';
class PhorgeGovernanceCompletionFixture {
  public function assertOwned(){$GLOBALS['owned_assertions']++;}
  public function getWriterConfig(){return$GLOBALS['config'];}
}
$config=array('host'=>'127.0.0.1','port'=>3306,'user'=>'gov_c_writer_'.str_repeat('a',12),'password'=>'synthetic-not-a-credential','namespace'=>'governance_native_'.str_repeat('b',16));
$settings=array('mysql.host'=>$config['host'],'mysql.port'=>$config['port'],'mysql.user'=>$config['user'],'mysql.pass'=>$config['password'],'storage.default-namespace'=>$config['namespace'],'cluster.databases'=>array(),'gorge.db.uri'=>null);
// The minimal map autoloader does not discover config option subclasses. This
// one read-only native default is supplied explicitly; no route method is replaced.
$default=(new PhabricatorConfigDefaultSource())->setKeys(array('cluster.read-only'=>false));
$stack=(new PhabricatorConfigStackSource())->pushSource($default)->pushSource(new PhabricatorConfigDictionarySource($settings));
(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
$route=new ReflectionMethod(PhorgeRecoveryNativeCaptureScope::class,'routing');
$baseRoute=$route->invoke(null);$baseStack=$stack;$owned_assertions=0;
require_once$root.'/support/governance/recovery/account-cases.php';
$text=file_get_contents($root.'/support/governance/recovery/session-lease-focused.php');
$a=strpos($text,'$flags=PhabricatorEnv::beginScopedEnv()');$b=strpos($text,';',$a);$flagsCode=substr($text,$a,$b-$a+1);
eval($flagsCode);
$beforeFlatten=count(PhabricatorEnv::getConfigSourceStack()->getStack());
$fixture=new PhorgeGovernanceCompletionFixture();$saved=governance_recovery_account_fixture_environment($fixture);
$nested=null;$caught=null;$site=null;$during=null;$primary=new RuntimeException('explicit-host-primary');$samePrimary=false;
try{
  if($mode==='old-third'){$nested=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.account-recovery.session-lease.enabled',true);}
  $during=$route->invoke(null);
  if($mode==='primary'){throw$primary;}
}catch(Throwable$e){$caught=$e->getMessage();$site=array('file'=>basename($e->getFile()),'line'=>$e->getLine());$samePrimary=$e===$primary;}
$classes=array_map('get_class',PhabricatorEnv::getConfigSourceStack()->getStack());$enabled=PhabricatorEnv::getEnvConfig('governance.account-recovery.session-lease.enabled');
unset($nested);governance_recovery_account_restore_fixture_environment($saved);unset($flags);
$restored=PhabricatorEnv::getConfigSourceStack()===$baseStack&&$route->invoke(null)===$baseRoute;
$worker=null;
if($mode==='worker'){
  // Execute the actual worker config construction only, before its write guard
  // or any producer/query. It creates its own single dictionary, no ScopedEnv.
  $payload=array('action'=>array('action'=>'recovery.session.issue'),'recovery_incarnation'=>str_repeat('c',32),'media_issuer'=>array('key'=>'synthetic','incarnation'=>str_repeat('d',32)));
  $measurement_file=null;$api_phase=static function(){};
  $text=file_get_contents($root.'/support/governance/native-organization/native-worker.php');
  $a=strpos($text,'$stack=new PhabricatorConfigStackSource();');$b=strpos($text,'  if (AphrontWriteGuard::isGuardActive()',$a);eval(substr($text,$a,$b-$a));
  // Same explicit read-only default as above, without another dictionary.
  PhabricatorEnv::getConfigSourceStack()->getStack()[1]->setKeys(array('cluster.read-only'=>false));
  $worker=array('classes'=>array_map('get_class',PhabricatorEnv::getConfigSourceStack()->getStack()),'route_equal'=>$route->invoke(null)===$baseRoute,'recovery_enabled'=>PhabricatorEnv::getEnvConfig('governance.account-recovery.enabled'),'incarnation_equal'=>PhabricatorEnv::getEnvConfig('governance.account-recovery.incarnation')===$payload['recovery_incarnation']);
}
echo json_encode(array('mode'=>$mode,'before_flatten_count'=>$beforeFlatten,'classes'=>$classes,'lease_enabled'=>$enabled,'route_equal'=>$during===$baseRoute,'error'=>$caught,'site'=>$site,'primary_preserved'=>$samePrimary,'restored'=>$restored,'owned_assertions'=>$owned_assertions,'worker'=>$worker,'SQL_executed'=>false));
