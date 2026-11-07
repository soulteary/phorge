<?php
/** Actual helper with explicit native storage collaborators; no DB/login proof. */
require dirname(__DIR__).'/calendar-wire-browser.php';
class PhorgePrivateMailPolicy {static function enabled(){return$GLOBALS['enabled'];}}
class PhabricatorUser {
 function __construct(public $phid,public $name,public $flags=array()){}
 function getPHID(){return$this->phid;}function getUsername(){return$this->name;}
 function getIsAdmin(){return$this->flags['admin']??false;}function getIsDisabled(){return$this->flags['disabled']??false;}
 function getIsApproved(){return$this->flags['approved']??true;}function getIsSystemAgent(){return$this->flags['system']??false;}
 function establishConnection($mode){if($mode!=='w')throw new RuntimeException('wrong_writer');return$this;}
}
class PhutilOpaqueEnvelope {function __construct(public $secret){}}
class PhabricatorAuthSession {function save(){throw new RuntimeException('session_creation_forbidden');}}
class PhabricatorContentSource {static function newForSource($source,$data){if($source!=='console'||$data!==[])throw new RuntimeException('wrong_source');return$source;}}
class PhabricatorAuthPassword {
 const PASSWORD_TYPE_ACCOUNT='account';
 static function initializeNewPassword($user,$type){if($type!==self::PASSWORD_TYPE_ACCOUNT)throw new RuntimeException('wrong_password_type');return new self($user);}
 function __construct(public $user){}function setPassword($envelope,$user){if($user!==$this->user)throw new RuntimeException('wrong_actor');$GLOBALS['passwords'][$user->getPHID()]=$envelope->secret;return$this;}
 function save(){return$this;}
}
class PhabricatorAuthPasswordEngine {
 private $user;function setViewer($u){$this->user=$u;return$this;}function setObject($u){if($u!==$this->user)throw new RuntimeException('wrong_object');return$this;}
 function setPasswordType($t){if($t!=='account')throw new RuntimeException('wrong_type');return$this;}function setContentSource($s){return$this;}
 function isValidPassword($e){return($GLOBALS['valid_password']??true)&&($GLOBALS['passwords'][$this->user->getPHID()]??null)===$e->secret;}
}
class PhabricatorAuthProviderConfig {
 public $values=[];
 function setProviderClass($v){$this->values['class']=$v;return$this;}function setProviderType($v){$this->values['type']=$v;return$this;}
 function setProviderDomain($v){$this->values['domain']=$v;return$this;}function setIsEnabled($v){$this->values['enabled']=$v;return$this;}
 function setShouldAllowLogin($v){$this->values['login']=$v;return$this;}function setShouldAllowRegistration($v){$this->values['register']=$v;return$this;}
 function save(){$GLOBALS['provider']=$this->values;return$this;}
}
class PhabricatorPasswordAuthProvider {
 function getProviderType(){return'password';}function getProviderDomain(){return'self';}
 function attachProviderConfig($c){if($c->values!==$GLOBALS['provider'])throw new RuntimeException('wrong_provider');}
 function getLoginURI(){return'/auth/login/password:self/';}
}
function queryfx_one($connection,$sql,$table,$phid){
 if(!($connection instanceof PhabricatorUser)||!($table instanceof PhabricatorAuthSession)||
  $sql!=='SELECT COUNT(*) n FROM %R WHERE userPHID=%s'||$connection->getPHID()!==$phid){throw new RuntimeException('wrong_session_observer');}
 $GLOBALS['observed'][]=$phid;return['n'=>$GLOBALS['session_count']];
}
$fixture=new class {function assertOwned(){$GLOBALS['owned']=true;}};
$make=static fn()=>['recipient'=>new PhabricatorUser('PHID-USER-'.str_repeat('a',20),'syntheticRecipient'),
 'ordinary'=>new PhabricatorUser('PHID-USER-'.str_repeat('b',20),'syntheticOrdinary')];
$reset=static function(){$GLOBALS['enabled']=true;$GLOBALS['valid_password']=true;$GLOBALS['session_count']=0;$GLOBALS['observed']=[];$GLOBALS['passwords']=[];$GLOBALS['provider']=null;};
$checks=[];$check=static function($value,$label)use(&$checks){if($value!==true)throw new RuntimeException('expected_fixture_check_denial');$checks[]=$label;};
$tests=0;$ok=static function($v)use(&$tests){if($v!==true)throw new RuntimeException('browser_host_failed');$tests++;};
$deny=static function(callable $op)use($ok){try{$op();}catch(Throwable$error){$ok(true);return;}throw new RuntimeException('browser_host_missing_denial');};
$reset();$users=$make();$out=governance_calendar_wire_prepare_browser($fixture,$users,$check);
$ok($GLOBALS['owned']&&count($GLOBALS['observed'])===2&&count($checks)===4);
$ok($GLOBALS['provider']===['class'=>'PhabricatorPasswordAuthProvider','type'=>'password','domain'=>'self','enabled'=>1,'login'=>1,'register'=>0]);
$ok($out['scope']==='owned-calendar-native-browser'&&$out['login_path']==='/auth/login/password:self/'&&
 $out['browser_cookie_injected']===false&&$out['zero_preseeded_sessions']===true);
$ok(array_keys($out['roles'])===['recipient','ordinary']&&$out['roles']['recipient']['password']!==$out['roles']['ordinary']['password']);
foreach([1,'1','00',false,0.0,null]as$count){$reset();$GLOBALS['session_count']=$count;$deny(static fn()=>governance_calendar_wire_prepare_browser($fixture,$make(),$check));}
foreach(['admin'=>true,'disabled'=>true,'approved'=>false,'system'=>true]as$flag=>$value){$reset();$users=$make();$users['recipient']->flags[$flag]=$value;$deny(static fn()=>governance_calendar_wire_prepare_browser($fixture,$users,$check));}
$reset();$users=$make();$users['ordinary']=$users['recipient'];$deny(static fn()=>governance_calendar_wire_prepare_browser($fixture,$users,$check));
$reset();$GLOBALS['enabled']=false;$deny(static fn()=>governance_calendar_wire_prepare_browser($fixture,$make(),$check));
$reset();$GLOBALS['valid_password']=false;$deny(static fn()=>governance_calendar_wire_prepare_browser($fixture,$make(),$check));
echo json_encode(['passed'=>$tests,'SQL'=>false,'SMTP'=>false,'browser'=>false,'native_storage_collaborators'=>true],JSON_THROW_ON_ERROR)."\n";
