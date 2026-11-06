<?php
/** Actual native cache/query memoization/DatabaseRef/collector methods.
 * Query execution, policy verdict and SQL rows are explicit collaborators.
 * No physical SQL, supported mapper, authentication or permission proof. */
$root=$argv[1];$paths=array();
function phutil_register_library_map($map){foreach($map['class']as$class=>$path){$GLOBALS['prime_paths'][$class]=$GLOBALS['prime_base'].'/'.$path;}}
foreach(array('/support/runtime/src','/src')as$base){$GLOBALS['prime_base']=$root.$base;require$root.$base.'/__phutil_library_map__.php';}
spl_autoload_register(function($class){if(isset($GLOBALS['prime_paths'][$class])){require_once$GLOBALS['prime_paths'][$class];}});
require_once$root.'/support/runtime/src/utils/utils.php';
class PhabricatorCursorPagedPolicyAwareQuery extends Phobject {
  public function setViewer($viewer){return$this;}
  public function execute(){$GLOBALS['query_executions']++;return array((new PhabricatorSpacesNamespace())->loadFromArray($GLOBALS['prime_row']));}
}
class PhabricatorPolicyFilter {
  public static function hasCapability($viewer,$space,$capability){$GLOBALS['policy_verdicts']++;return true;}
}
class PhorgeGovernanceCompletionFixture {public function assertOwned(){}}
class PhorgeRecoveryRestoreProfile {
  public static function assertOwnedWriter($conn){if($conn!==$GLOBALS['prime_conn']||!$conn->isInsideTransaction()){throw new RuntimeException('prime_writer_unknown');}}
}
function queryfx($conn,$sql){if(!in_array($sql,array('START TRANSACTION','ROLLBACK'),true)){throw new RuntimeException('unexpected_fixture_write');}}
function queryfx_one($conn,$sql,...$args){
  if(str_contains($sql,'DATABASE()')){return array('db'=>'governance_native_'.str_repeat('a',16).'_user');}
  if(str_contains($sql,'ENGINE')){return array('ENGINE'=>'InnoDB');}
  if(str_starts_with($sql,'SELECT id,phid,')){return$GLOBALS['prime_row'];}
  throw new RuntimeException('prime_unexpected_one_query');
}
function queryfx_all($conn,$sql,...$args){
  if(str_contains($sql,'WHERE 1=0')){return array();}
  if(str_contains($sql,'STATISTICS')){return array(array('COLUMN_NAME'=>array('PRIMARY'=>'id','key_phid'=>'phid','key_default'=>'isDefaultNamespace')[$args[2]],'NON_UNIQUE'=>'0','SEQ_IN_INDEX'=>'1'));}
  if(str_contains($sql,'COLUMNS')){return array_map(static fn($key)=>array('COLUMN_NAME'=>$key),array_keys($GLOBALS['prime_row']));}
  if(str_starts_with($sql,'SELECT id,OCTET_LENGTH')){$row=$GLOBALS['prime_row'];return array(array('id'=>$row['id'],'phidBytes'=>strlen($row['phid']),'nameBytes'=>strlen($row['namespaceName']),'viewBytes'=>strlen($row['viewPolicy']),'editBytes'=>strlen($row['editPolicy']),'descriptionBytes'=>strlen($row['description'])));}
  throw new RuntimeException('prime_unexpected_all_query');
}
require_once$root.'/support/governance/recovery/restore-review-cases.php';
$stack_property=new ReflectionProperty(PhabricatorEnv::class,'sourceStack');$env_property=new ReflectionProperty(PhabricatorEnv::class,'cache');$cache_property=new ReflectionProperty(PhabricatorCaches::class,'requestCache');
$old_stack=$stack_property->getValue();$old_env=$env_property->getValue();$old_cache=$cache_property->getValue();
$GLOBALS['prime_row']=array('id'=>1,'phid'=>'PHID-SPCE-'.str_repeat('s',20),'namespaceName'=>'Synthetic Space','viewPolicy'=>'users','editPolicy'=>'admin','isDefaultNamespace'=>1,'dateCreated'=>1,'dateModified'=>1,'description'=>'Synthetic fixture','isArchived'=>0);
$GLOBALS['query_executions']=0;$GLOBALS['policy_verdicts']=0;
$GLOBALS['prime_conn']=$conn=new AphrontIsolatedDatabaseConnection(array());$pool_property=new ReflectionProperty(LiskDAO::class,'connections');$old_pool=$pool_property->getValue();
try {
  $stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDictionarySource(array('mysql.user'=>'synthetic','mysql.pass'=>'synthetic-only','mysql.host'=>'fixture.invalid','mysql.port'=>3306,'cluster.databases'=>array(),'storage.default-namespace'=>'governance_native_'.str_repeat('a',16))));$stack_property->setValue(null,$stack);$env_property->setValue(null,array());$pool_property->setValue(null,array('governance_native_'.str_repeat('a',16).'_user:w'=>$conn));
  $cache_property->setValue(null,null);
  $conn->openTransaction();$capture=PhorgeRecoverySpacesSourceFacts::capture($conn);
  PhabricatorDatabaseRef::getLiveIndividualRef();$unprimed_refused=false;
  try{$capture->finish();}catch(RuntimeException$error){if($error->getMessage()!=='recovery_spaces_facts_changed'){throw$error;}$unprimed_refused=true;}
  $conn->killTransaction();
  // Fresh fixture start only. Production code never clears a bound review cache.
  $cache_property->setValue(null,null);$viewers=array();foreach(array('a','b','c')as$letter){$viewers[]=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat($letter,20));}
  governance_recovery_restore_prime_space_cache(new PhorgeGovernanceCompletionFixture(),$conn,$viewers);
  $collect=static function()use($conn){$conn->openTransaction();try{return PhorgeRecoverySpacesSourceFacts::capture($conn)->finish()['source_digest'];}finally{$conn->killTransaction();}};
  $first=$collect();PhabricatorDatabaseRef::getMasterDatabaseRefForApplication('policy');$second=$collect();
  governance_recovery_restore_prime_space_cache(new PhorgeGovernanceCompletionFixture(),$conn,$viewers);$third=$collect();
  $conn->openTransaction();$capture=PhorgeRecoverySpacesSourceFacts::capture($conn);
  PhabricatorSpacesNamespaceQuery::getViewerSpaces((new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('z',20)));$new_viewer_refused=false;
  try{$capture->finish();}catch(RuntimeException$error){if($error->getMessage()!=='recovery_spaces_facts_changed'){throw$error;}$new_viewer_refused=true;}$conn->killTransaction();
  $conn->openTransaction();$capture=PhorgeRecoverySpacesSourceFacts::capture($conn);$GLOBALS['prime_row']['description']='Changed synthetic DB row';$stale_row_refused=false;
  try{$capture->finish();}catch(RuntimeException$error){if($error->getMessage()!=='recovery_spaces_cache_rows_changed'){throw$error;}$stale_row_refused=true;}$conn->killTransaction();
  echo json_encode(array('unprimed_real_DatabaseRef_refused'=>$unprimed_refused,'primed_repeat_and_late_Policy_master_ref_stable'=>$first===$second&&$second===$third,'real_new_viewer_cache_change_refused'=>$new_viewer_refused,'stale_cached_description_refused'=>$stale_row_refused,'native_query_executions'=>$GLOBALS['query_executions'],'native_policy_verdict_collaborator_calls'=>$GLOBALS['policy_verdicts'],'transaction_active'=>$conn->isInsideTransaction(),'SQL_executed'=>false,'query_execution_and_policy_verdict_are_collaborators'=>true))."\n";
} finally {if($conn->isInsideTransaction()){$conn->killTransaction();}$pool_property->setValue(null,$old_pool);$stack_property->setValue(null,$old_stack);$env_property->setValue(null,$old_env);$cache_property->setValue(null,$old_cache);}
