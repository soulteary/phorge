<?php
/** Actual NativeCaptureScope readAPICredentials/apiRows + actual APIFacts.
 * Fixed table/routing/schema/row collaborators; private-method invocation is
 * not public admission and does not prove a transaction, grant or SQL locks. */
$root=$argv[1];$mode=$argv[2];$events=array();$ns='governance_native_'.str_repeat('a',16);$target='PHID-USER-'.str_repeat('a',20);$client='PHID-OASC-'.str_repeat('b',20);
class Phobject{}
class AphrontDatabaseConnection{}
final class AphrontMySQLiDatabaseConnection extends AphrontDatabaseConnection {public $db;function __construct($db){$this->db=$db;}function isInsideTransaction(){return$this->db==='user';}function isReadLocking(){return false;}function isWriteLocking(){return false;}}
class PhabricatorUser {protected$app='user';protected$table='user';function establishConnection($m){return$GLOBALS['connections'][$this->app];}function getAphrontRefDatabaseName(){return$GLOBALS['ns'].'_'.$this->app;}function getTableName(){return$this->table;}}
class PhabricatorConduitToken extends PhabricatorUser {protected$app='conduit';protected$table='conduit';}
class PhabricatorOAuthServerAccessToken extends PhabricatorUser {protected$app='oauth_server';protected$table='access';}
class PhabricatorOAuthClientAuthorization extends PhabricatorOAuthServerAccessToken {protected$table='authorization';}
class PhabricatorOAuthServerAuthorizationCode extends PhabricatorOAuthServerAccessToken {protected$table='code';}
class PhabricatorOAuthServerClient extends PhabricatorOAuthServerAccessToken {protected$table='client';}
class PhabricatorAuthSession extends PhabricatorUser {const TYPE_CONDUIT='conduit';protected$table='session';}
class PhorgeRecoveryNativeWebFacts {const MAX_ROWS=64;}
class PhorgeRecoveryRestoreProfile {static function assertCurrent(){return array('namespace'=>$GLOBALS['ns'],'incarnation_digest'=>str_repeat('b',64));}static function assertOwnedWriter($c){if($c!==$GLOBALS['connections']['user'])throw new RuntimeException('host_writer');}}
class PhorgeRecoveryStopRequest {static function user($p){if($p!==$GLOBALS['target'])throw new RuntimeException('host_target');}}
class PhorgeRecoveryRestoreService {static function assertBorrowedOwner($token,$writer,$observer){if($token!==$GLOBALS['owner_token']||$writer!==$GLOBALS['connections']['user']||$observer!==$GLOBALS['observer'])throw new RuntimeException('host_owner_mismatch');}}
function qsprintf($c,$format,...$args){return strpos($format,'%C')===0?implode(',',$args):$format;}
function queryfx_one($c,$sql,...$args){if(strpos($sql,'SELECT @@server_uuid')===0)return strpos($sql,'DATABASE()')!==false?array('uuid'=>'same-collaborator-primary','db'=>$GLOBALS['ns'].'_'.$c->db):array('uuid'=>'same-collaborator-primary');if(strpos($sql,'SELECT ENGINE')===0)return array('ENGINE'=>'InnoDB');if(strpos($sql,'UNIX_TIMESTAMP')!==false)return array('n'=>'100');return null;}
function queryfx_all($c,$sql,...$args){
 if(strpos($sql,'information_schema.COLUMNS')!==false){$table=$args[1];$out=array();foreach($GLOBALS['columns'][$table]as$name=>$type){$nullable=($table==='conduit'&&$name==='expires')||($table==='session'&&$name==='highSecurityUntil');$width=$type==='varbinary'?64:($type==='varchar'?(in_array($name,array('name','redirectURI'),true)?255:($name==='tokenName'||($table==='session'&&$name==='sessionKey')?64:32)):null);$out[]=array('COLUMN_NAME'=>$name,'DATA_TYPE'=>$type,'COLUMN_TYPE'=>$type==='int'?'int unsigned':$type,'IS_NULLABLE'=>$nullable?'YES':'NO','CHARACTER_MAXIMUM_LENGTH'=>$width);}return$out;}
 $table=null;foreach($args as$arg){if($arg instanceof PhabricatorUser){$table=$arg->getTableName();break;}}if($table===null)throw new RuntimeException('host_unrecognized_query');
 if($table==='session')return array();$rows=$GLOBALS['rows'][$table]??array();
 if($GLOBALS['mode']==='plan-budget'&&$table==='authorization'){$rows=array();for($i=1;$i<=65;$i++){$r=$GLOBALS['rows']['authorization'][0];$r['id']=(string)$i;$rows[]=$r;}}
 if($GLOBALS['mode']==='missing-client'&&$table==='client')$rows=array();
 if(strpos($sql,'SELECT id,phid FROM')===0)return array_map(static fn($r)=>array('id'=>$r['id'],'phid'=>$r['phid']),$rows);
 if($table==='authorization'&&strpos($sql,'SELECT %Q FROM')===0&&strpos($sql,'LOCK IN SHARE MODE')===false){$GLOBALS['plans']++;if($GLOBALS['mode']==='ref-drift'&&$GLOBALS['plans']>1){foreach($rows as&$r){$r['clientPHID']='PHID-OASC-'.str_repeat('e',20);}unset($r);}}
 if($table==='client'&&end($args)==='1=0'){$GLOBALS['events'][]='client.constant-empty-predicate';return array();}
 if($rows&&strpos($sql,'LOCK IN SHARE MODE')!==false){$GLOBALS['events'][]=$table.($table==='client'&&strpos($sql,'FORCE INDEX (PRIMARY)')!==false?'.PRIMARY.S':'.S');}
 if(strpos($sql,' bytes,')!==false){return array_map(static fn($row)=>array('id'=>$row['id'],'bytes'=>'100','largest'=>'32'),$rows);}
 if(strpos($sql,'SELECT %Q FROM')===0&&($args[0]??null)==='id,clientPHID')return array_map(static fn($r)=>array('id'=>$r['id'],'clientPHID'=>$r['clientPHID']),$rows);
 return$rows;
}
require$root.'/src/infrastructure/governance/recovery/PhorgeRecoveryNativeAPIFacts.php';require$root.'/src/infrastructure/governance/recovery/PhorgeRecoveryNativeCaptureScope.php';
$connections=array();foreach(array('user','conduit','oauth_server')as$app){$connections[$app]=new AphrontMySQLiDatabaseConnection($app);}
$scope=(new ReflectionClass(PhorgeRecoveryNativeCaptureScope::class))->newInstanceWithoutConstructor();foreach(array('connection'=>$connections['user'],'target'=>$target,'route'=>array('storage.default-namespace'=>$ns))as$name=>$value){(new ReflectionProperty(PhorgeRecoveryNativeCaptureScope::class,$name))->setValue($scope,$value);}
$profiles=(new ReflectionMethod(PhorgeRecoveryNativeCaptureScope::class,'apiProfiles'))->invoke($scope);$columns=array();foreach($profiles as$profile){$columns[$profile[0]->getTableName()]=$profile[3];}$columns['session']=array('id'=>'int','userPHID'=>'varbinary','type'=>'varchar','sessionKey'=>'varchar','sessionStart'=>'int','sessionExpires'=>'int','highSecurityUntil'=>'int','isPartial'=>'tinyint','signedLegalpadDocuments'=>'tinyint','phid'=>'varbinary');
$rows=array('authorization'=>$mode==='owned-no-refs'?array():array(array('id'=>'1','phid'=>'PHID-OASA-'.str_repeat('c',20),'userPHID'=>$target,'clientPHID'=>$client,'scope'=>'[]','dateCreated'=>'1','dateModified'=>'1')),
 'client'=>array(array('id'=>'2','phid'=>$client,'name'=>'Synthetic client','secret'=>str_repeat('s',32),'redirectURI'=>'https://synthetic.invalid/','creatorPHID'=>$mode==='owned-no-refs'?$target:'PHID-USER-'.str_repeat('d',20),'isTrusted'=>'0','viewPolicy'=>'users','editPolicy'=>$target,'dateCreated'=>'1','dateModified'=>'1','isDisabled'=>'0')));
$plans=0;$facts=null;$error=null;$prepared=null;$changed=null;$owner_token=new stdClass();$observer=new AphrontMySQLiDatabaseConnection('observer');
try{
 if(str_starts_with($mode,'prepare')){$prepared=PhorgeRecoveryNativeCaptureScope::prepareBorrowedOAuthClients($mode==='prepare-wrong-token'?new stdClass():$owner_token,$connections['user'],$observer,$target);}
 else{$facts=(new ReflectionMethod(PhorgeRecoveryNativeCaptureScope::class,'readAPICredentials'))->invoke($scope);
  if(str_starts_with($mode,'client-change-')){$before=$facts['storage_set_digest'];$field=substr($mode,14);$map=array('secret'=>str_repeat('r',32),'redirectURI'=>'https://changed.synthetic.invalid/','isTrusted'=>'1','isDisabled'=>'1','creatorPHID'=>'PHID-USER-'.str_repeat('e',20));if(!array_key_exists($field,$map))throw new RuntimeException('host_field');$rows['client'][0][$field]=$map[$field];$facts=(new ReflectionMethod(PhorgeRecoveryNativeCaptureScope::class,'readAPICredentials'))->invoke($scope);$changed=$before!==$facts['storage_set_digest'];}
 }
}catch(Throwable$e){$error=$e->getMessage();}
echo json_encode(array('physical_SQL_executed'=>false,'public_owner_admission_exercised'=>false,'events'=>$events,'unknown_reasons'=>$facts['unknown_reasons']??array(),'prepared_digest'=>$prepared,'changed_digest'=>$changed,'error'=>$error,'client_observations'=>$facts===null?null:count($facts['api_credentials']['oauth_clients']),'family_complete'=>$facts['api_family_complete']??false,'all_sources_complete'=>$facts['all_sources_complete']??false,'can_execute'=>$facts['can_execute']??false));
