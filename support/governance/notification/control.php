<?php
/** Fixed, private CLI source mutations for actual callback race tests. */
$root=dirname(__DIR__,3);$proof=null;$guard=null;$owned=false;$mode=(new mysqli_driver())->report_mode;$result=null;$failed=false;
try{
  require_once$root.'/support/runtime/php-version.php';phorge_assert_supported_php_version(PHP_VERSION);
  $security_actions=array('login-disable'=>'security-disable','login-secret-rotate'=>'security-rotate-secret',
    'large-body-revoked'=>'security-rotate-secret','large-attachment-revoked'=>'security-rotate-secret',
    'login-primary-switch'=>'security-switch-primary','welcome-demote'=>'security-demote-admin',
    'queue-demote'=>'security-demote-admin','verify-code-rotate'=>'security-rotate-code',
    'verify-completed'=>'security-complete-verification','invite-code-rotate'=>'security-rotate-invite',
    'invite-demote'=>'security-demote-admin');
  $security_case=count($argv)===3&&isset($security_actions[$argv[2]])&&$security_actions[$argv[2]]===$argv[1];
  if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||count($argv)!==3||
    (!$security_case&&(!in_array($argv[1],array('revoke-source','disable-recipient'),true)||
    !in_array($argv[2],array('allow','before-revoke','between-attempts','unavailable','mutated','unknown-outcome'),true)))){
    throw new RuntimeException('private_mail_control_action_refused');}
  foreach(array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE')as$key){$value=getenv($key);if($value!==false&&$value!==''){throw new RuntimeException('private_mail_control_deployment_refused');}}
  foreach(array('local.json','deployment.json','ENVIRONMENT')as$name){if(file_exists($root.'/conf/local/'.$name)){throw new RuntimeException('private_mail_control_deployment_refused');}}
  require_once __DIR__.'/owned-config.php';$directory=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR');$data=governance_private_mail_config($directory);
  $path=$directory.'/control.json';if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>16384){throw new RuntimeException('private_mail_control_config_refused');}
  $control=json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
  if(!is_array($control)||($control['owner']??null)!==$data['owner']||!isset($control[$security_case?'security_targets':'targets'][$argv[2]])){throw new RuntimeException('private_mail_control_owner_refused');}
  require_once$root.'/support/runtime/bootstrap.php';phutil_load_library($root.'/src/');
  $proof=governance_private_mail_proof($data);governance_private_mail_install_env($data);$owned=true;
  $guard=new AphrontWriteGuard(static function()use($proof,$data){
    $row=$proof->query('SELECT owner_nonce FROM `'.$data['writer']['namespace'].'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();
    if(!$row||!hash_equals($data['fixture_nonce'],$row['owner_nonce'])){throw new RuntimeException('private_mail_control_owner_changed');}});
  if($security_case){
    $target=$control['security_targets'][$argv[2]];
    $load_user=static function($key)use($target){$phid=$target[$key]??null;
      if(!is_string($phid)||!preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/',$phid)){throw new RuntimeException('private_mail_security_control_target_refused');}
      $user=(new PhabricatorUser())->loadOneWhere('BINARY phid=BINARY %s',$phid);
      if(!$user||$user->getPHID()!==$phid){throw new RuntimeException('private_mail_security_control_target_missing');}return$user;
    };
    switch($argv[1]){
      case 'security-disable':$user=$load_user('user_phid');$user->setIsDisabled(1)->save();break;
      case 'security-rotate-secret':$user=$load_user('user_phid');$user->setAccountSecret(Filesystem::readRandomCharacters(64))->save();break;
      case 'security-demote-admin':$user=$load_user('admin_phid');$user->setIsAdmin(0)->save();break;
      case 'security-switch-primary':
      case 'security-rotate-code':
      case 'security-complete-verification':
        $user=$load_user('user_phid');$phid=$target['email_phid']??null;
        if(!is_string($phid)||!preg_match('/\APHID-[A-Z0-9]{4}-[a-zA-Z0-9]{20}\z/',$phid)){throw new RuntimeException('private_mail_security_control_email_refused');}
        $email=(new PhabricatorUserEmail())->loadOneWhere('BINARY phid=BINARY %s',$phid);
        if(!$email||$email->getPHID()!==$phid||$email->getUserPHID()!==$user->getPHID()){throw new RuntimeException('private_mail_security_control_email_missing');}
        if($argv[1]==='security-rotate-code'){$email->setVerificationCode(bin2hex(random_bytes(12)))->save();}
        elseif($argv[1]==='security-complete-verification'){$email->setIsVerified(1)->save();}
        else{$conn=$email->establishConnection('w');$conn->openTransaction();
          try{$email->setIsPrimary(0)->save();
            (new PhabricatorUserEmail())->setUserPHID($user->getPHID())->setAddress('replacement'.substr(hash('sha256',$phid),0,20).'@example.invalid')
              ->setIsPrimary(1)->setIsVerified(1)->save();$conn->saveTransaction();
          }catch(Throwable$error){$conn->killTransaction();throw$error;}}
        break;
      case 'security-rotate-invite':
        $admin=$load_user('admin_phid');$phid=$target['invite_phid']??null;
        if(!is_string($phid)||!preg_match('/\APHID-[A-Z0-9]{4}-[a-zA-Z0-9]{20}\z/',$phid)){throw new RuntimeException('private_mail_security_control_invite_refused');}
        $invite=(new PhabricatorAuthInvite())->loadOneWhere('BINARY phid=BINARY %s',$phid);
        if(!$invite||$invite->getPHID()!==$phid||$invite->getAuthorPHID()!==$admin->getPHID()){throw new RuntimeException('private_mail_security_control_invite_missing');}
        $invite->regenerateVerificationCode();$invite->save();break;
      default:throw new RuntimeException('private_mail_security_control_action_refused');
    }
  }elseif($argv[1]==='revoke-source'){
    $phid=$control['targets'][$argv[2]]['file_phid']??null;
    if(!is_string($phid)||!preg_match('/\APHID-FILE-[a-zA-Z0-9]{20}\z/',$phid)){throw new RuntimeException('private_mail_control_target_refused');}
    $file=(new PhabricatorFile())->loadOneWhere('BINARY phid=BINARY %s',$phid);
    if(!$file||$file->getPHID()!==$phid){throw new RuntimeException('private_mail_control_target_missing');}
    $file->setViewPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
  }else{
    $phid=$control['recipient_phid']??null;if(!is_string($phid)||!preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/',$phid)){throw new RuntimeException('private_mail_control_target_refused');}
    $user=(new PhabricatorUser())->loadOneWhere('BINARY phid=BINARY %s',$phid);
    if(!$user||$user->getPHID()!==$phid){throw new RuntimeException('private_mail_control_target_missing');}$user->setIsDisabled(1)->save();
  }
$result=array('passed'=>true,'scope'=>'owned-synthetic-native-source-mutation-only','action'=>$argv[1],'mock_authorization_used'=>false);
}catch(Throwable$error){$failed=true;}
finally{foreach(array('guard','pool','proof')as$kind){try{
  if($kind==='guard'&&$guard!==null){$guard->dispose();}
  if($kind==='pool'&&$owned){LiskDAO::closeAllConnections();}
  if($kind==='proof'&&$proof!==null){$proof->close();}
}catch(Throwable$error){$failed=true;}}mysqli_report($mode);}
if($failed){echo '{"passed":false,"reason":"private_mail_control_refused"}'."\n";exit(1);}
echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
