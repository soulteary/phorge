<?php
/** Fixed independent source preparation probe; never a route or delivery worker. */
$root=dirname(__DIR__,3);$proof=null;$guard=null;$owned=false;$buffer=false;$failure=null;$result=null;
$mode=(new mysqli_driver())->report_mode;
try{
  require_once$root.'/support/runtime/php-version.php';phorge_assert_supported_php_version(PHP_VERSION);
  if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||count($argv)!==2){throw new RuntimeException('private_mail_race_scope_refused');}
  foreach(array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE')as$key){$value=getenv($key);if($value!==false&&$value!==''){throw new RuntimeException('private_mail_race_deployment_refused');}}
  foreach(array('local.json','deployment.json','ENVIRONMENT')as$name){if(file_exists($root.'/conf/local/'.$name)){throw new RuntimeException('private_mail_race_deployment_refused');}}
  require_once __DIR__.'/race-owned-config.php';$directory=$argv[1];$data=governance_private_mail_config($directory);
  $path=$directory.'/probe.json';if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>1024){throw new RuntimeException('private_mail_race_payload_refused');}
  $probe=json_decode(file_get_contents($path),true,4,JSON_THROW_ON_ERROR);
  if(!is_array($probe)||array_keys($probe)!==array('owner','mail_id')||($probe['owner']??null)!==$data['owner']||!is_int($probe['mail_id'])||$probe['mail_id']<1){throw new RuntimeException('private_mail_race_payload_refused');}
  require_once$root.'/support/runtime/bootstrap.php';ob_start();$buffer=true;phutil_load_library($root.'/src/');
  $proof=governance_private_mail_proof($data);governance_private_mail_install_env($data);$owned=true;
  $guard=new AphrontWriteGuard(static function()use($proof,$data){$row=$proof->query('SELECT owner_nonce FROM `'.$data['writer']['namespace'].'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();if(!$row||!hash_equals($data['fixture_nonce'],$row['owner_nonce'])){throw new RuntimeException('private_mail_race_owner_changed');}});
  $mail=(new PhabricatorMetaMTAMail())->load($probe['mail_id']);
  if(!$mail||$mail->getPrivateMailPolicyValue('governance.mail-flow')!=='account.username'){throw new RuntimeException('private_mail_race_target_refused');}
  $binding=$mail->getPrivateMailPolicyValue('governance.mail-binding');
  $event=(new ReflectionMethod(PhorgePrivateMailPolicy::class,'userEvent'))->invoke(null,$binding['source'],$binding['subject'],PhabricatorUserUsernameTransaction::TRANSACTIONTYPE);
  $allowed=PhorgePrivateMailPolicy::authorize($mail);$snapshot=$mail->prepareGorgeDelivery();
  $result=array('passed'=>true,'allowed'=>$allowed,'state'=>($snapshot['state']??null)==='void'?'void':'prepared',
    'source_event_visible'=>$event!==null,'source_event_matches'=>$event!==null&&hash_equals($binding['source_hash'],$event),'quarantined'=>$mail->getPrivateMailPolicyValue('governance.delivery-quarantined')===true,'physical_delivery_attempted'=>false);
}catch(Throwable$error){$failure=array('type'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine());}
finally{try{if($guard!==null){$guard->dispose();}if($owned){LiskDAO::closeAllConnections();}if($proof!==null){$proof->close();}}catch(Throwable$error){$failure=array('type'=>'cleanup_failed');}mysqli_report($mode);if($buffer){ob_end_clean();}}
echo json_encode($failure?array('passed'=>false,'failure'=>$failure):$result,JSON_THROW_ON_ERROR)."\n";exit($failure?1:0);
