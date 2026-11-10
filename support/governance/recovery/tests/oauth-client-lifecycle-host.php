<?php
/** Actual final native DAOs/Lisk/cascade. Only the SQL transport/results and
 * isolated configuration/write-guard are collaborators. No physical SQL. */
$root=$argv[1];$mode=$argv[2];
require$root.'/support/runtime/src/init/init-library.php';phutil_load_library($root.'/src/');
$stack=(new PhabricatorConfigStackSource())->pushSource(new PhabricatorConfigDefaultSource())->pushSource(new PhabricatorConfigDictionarySource(array('storage.default-namespace'=>'governance_native_aaaaaaaaaaaaaaaa','governance.account-recovery.enabled'=>false)));
(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
$guard=new AphrontWriteGuard(static function(){});
final class LifecycleTransport extends AphrontDatabaseConnection {
  public $events=array();public $rows=array();public $failure;public $failAuthorization=false;private $result=array();
  public function getInsertID(){return 1;}public function getAffectedRows(){return 1;}public function selectAllResults(){return$this->result;}
  public function executeRawQueries(array$queries){throw new RuntimeException('host_raw_query_unexpected');}
  public function close(){}public function openConnection(){}public function simulateErrorOnNextQuery($error){throw new RuntimeException('host_unused');}
  public function escapeBinaryString($s){return addslashes($s);}public function escapeUTF8String($s){return addslashes($s);}public function escapeColumnName($s){return str_replace('`','``',$s);}public function escapeMultilineComment($s){return'synthetic';}public function escapeStringForLikeClause($s){return addslashes($s);}
  public function executeQuery(PhutilQueryString$query){
    $sql=$query->getUnmaskedString();$this->result=array();
    if(preg_match('/\A(?:START|COMMIT|ROLLBACK|SAVEPOINT)\b/',$sql,$m)){$this->events[]=$m[0];return;}
    if(!preg_match('/\A(SELECT|INSERT|UPDATE|DELETE)\b/',$sql,$m)){throw new RuntimeException('host_query_unexpected');}$verb=$m[1];$kind=null;
    foreach(array('authorization'=>'oauth_server_oauthclientauthorization','access'=>'oauth_server_oauthserveraccesstoken','code'=>'oauth_server_oauthserverauthorizationcode','client'=>'oauth_server_oauthserverclient')as$name=>$table){if(strpos($sql,$table)!==false){$kind=$name;break;}}
    if($kind===null){throw new RuntimeException('host_table_unexpected');}$this->events[]=$kind.'.'.$verb;
    if($this->failAuthorization&&$kind==='authorization'&&$verb==='DELETE'){throw$this->failure;}
    if($verb==='SELECT'){$this->result=$this->rows[$kind]??array();}
  }
}
$c=new LifecycleTransport();$subject='PHID-USER-'.str_repeat('a',20);$clientPHID='PHID-OASC-'.str_repeat('b',20);
$client=(new PhabricatorOAuthServerClient())->setID(1)->setPHID($clientPHID)->setName('Synthetic client')->setSecret(str_repeat('s',32))->setRedirectURI('https://synthetic.invalid/')->setCreatorPHID($subject)->setIsTrusted(0)->setIsDisabled(0)->setViewPolicy('users')->setEditPolicy($subject)->setDateCreated(1)->setDateModified(1);
(new ReflectionMethod(LiskDAO::class,'setEstablishedConnection'))->invoke($client,'w',$c);
$c->rows=array('authorization'=>array(array('id'=>'2','phid'=>'PHID-OASA-'.str_repeat('c',20),'userPHID'=>$subject,'clientPHID'=>$clientPHID,'scope'=>'[]','dateCreated'=>'1','dateModified'=>'1')),
 'access'=>array(array('id'=>'3','userPHID'=>$subject,'clientPHID'=>$clientPHID,'token'=>str_repeat('t',32),'dateCreated'=>'1','dateModified'=>'1')),
 'code'=>array(array('id'=>'4','userPHID'=>$subject,'clientPHID'=>$clientPHID,'code'=>str_repeat('c',32),'clientSecret'=>str_repeat('s',32),'redirectURI'=>'https://synthetic.invalid/','dateCreated'=>'1','dateModified'=>'1')));
if(str_starts_with($mode,'reserved-')){class_exists(PhorgeRecoveryRestoreService::class);(new ReflectionProperty(PhorgeRecoveryRestoreService::class,'borrowedOwner'))->setValue(null,new stdClass());}
if(str_starts_with($mode,'lease-')){class_exists(PhorgeRecoverySessionProducerFence::class);$active=new SplObjectStorage();$active[new stdClass()]=true;(new ReflectionProperty(PhorgeRecoverySessionProducerFence::class,'leaseActive'))->setValue(null,$active);}
$operation=preg_replace('/\A(?:quarantined-|reserved-|lease-)/','',$mode);
$primary=new RuntimeException('synthetic_body_error');$error=null;$returned=null;
if(str_starts_with($mode,'quarantined-')){class_exists(PhorgeRecoveryConduitProducerFence::class);(new ReflectionProperty(PhorgeRecoveryConduitProducerFence::class,'retained'))->setValue(null,array(new stdClass()));}
try {
 if($operation==='create-owned-client'){$actor=(new PhabricatorUser())->setPHID($subject);$client=PhabricatorOAuthServerClient::initializeNewClient($actor)->setName('Synthetic owned client')->setRedirectURI('https://synthetic.invalid/');$returned=$client->save()===$client;}
 elseif($operation==='client-begin'){$client->openTransaction();$returned=true;}
 elseif($operation==='authorization-update'){$row=(new PhabricatorOAuthClientAuthorization())->loadFromArray($c->rows['authorization'][0]);$row->setScope(array())->save();$returned=true;}
 elseif($operation==='client-editor'){(new PhabricatorOAuthServerEditor())->applyTransactions($client,array());$returned=true;}
 elseif($operation==='destroy-engine'){(new PhabricatorDestructionEngine())->destroyObject($client);$returned=true;}
 elseif($operation==='authorization-delete'){$row=(new PhabricatorOAuthClientAuthorization())->loadFromArray($c->rows['authorization'][0]);$returned=$row->delete()===$row;}
 elseif(in_array($operation,array('cascade','cascade-error'),true)){$c->failAuthorization=$mode==='cascade-error';$c->failure=$primary;$client->destroyObjectPermanently(new PhabricatorDestructionEngine());$returned=true;}
 else {
  $field=$operation;
  switch($field){case'disabled':$client->setIsDisabled(1);break;case'secret':$client->setSecret(str_repeat('r',32));break;case'redirect':$client->setRedirectURI('https://changed.synthetic.invalid/');break;case'trust':$client->setIsTrusted(1);break;case'owner-reassignment':$client->setCreatorPHID('PHID-USER-'.str_repeat('e',20));break;default:throw new RuntimeException('host_mode_unknown');}
  $returned=$client->save()===$client;
 }
}catch(Throwable$e){$error=$e;}
echo json_encode(array('physical_SQL_executed'=>false,'actual_final_DAOs'=>(new ReflectionClass(PhabricatorOAuthServerClient::class))->isFinal()&&(new ReflectionClass(PhabricatorOAuthClientAuthorization::class))->isFinal(),
 'creator_matches_initial_subject'=>$client->getCreatorPHID()===$subject,'events'=>$c->events,'returned_same'=>$returned,'failed'=>$error!==null,'blocked_reason'=>$error!==null&&in_array($error->getMessage(),array('recovery_conduit_writer_quarantined','recovery_oauth_lifecycle_borrowed_owner_reserved','recovery_oauth_lifecycle_selected_lease_reserved'),true)?$error->getMessage():null,'same_primary'=>$error===$primary,'transaction_depth'=>(new ReflectionMethod(AphrontDatabaseConnection::class,'getTransactionState'))->invoke($c)->getDepth(),
 'native_scope_map'=>PhabricatorOAuthServerScope::getScopeMap()));
