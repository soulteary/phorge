<?php
/** Fixed owner regression, actual native methods with a non-SQL query observer. */
if(PHP_SAPI!=='cli'||count($argv)!==2||!in_array($argv[1],array('owned-rollback','normal-commit','committed-foreign','foreign-owner','new-cache-object','nested-unknown','commit-error','external-ended-unknown','rollback-error','fence-error','fence-with-primary','producer-profile','same-depth-replaced','state-object-replaced','service-wrong-owner','service-old-generation','service-nested','service-lock-mode','generation-storage','didCommit-old-generation'),true)){fwrite(STDERR,"q2_owner_fixed_case_required\n");exit(1);}
$root=dirname(__DIR__,3);require_once $root.'/support/runtime/src/__phutil_library_init__.php';phutil_load_library($root.'/src/');
final class Q2OwnerQueryObserver extends AphrontDatabaseConnection {
 public $queries=array();public $fail=null;
 public function query($pattern){if(!preg_match('/\A(?:START TRANSACTION|COMMIT|ROLLBACK(?: TO SAVEPOINT Aphront_Savepoint_[0-9]+)?|SAVEPOINT Aphront_Savepoint_[0-9]+)\z/D',$pattern)){throw new RuntimeException('q2_query_refused');}$this->queries[]=$pattern;if($pattern===$this->fail){throw new RuntimeException('q2_query_observer_fault');}}
 public function getInsertID(){throw new RuntimeException('q2_no_SQL');}public function getAffectedRows(){throw new RuntimeException('q2_no_SQL');}
 public function selectAllResults(){throw new RuntimeException('q2_no_SQL');}public function executeQuery(PhutilQueryString $query){throw new RuntimeException('q2_no_SQL');}
 public function executeRawQueries(array $raw_queries){throw new RuntimeException('q2_no_SQL');}public function close(){}public function openConnection(){throw new RuntimeException('q2_no_SQL');}
 public function simulateErrorOnNextQuery($error){throw new RuntimeException('q2_no_SQL');}public function escapeBinaryString($string){throw new RuntimeException('q2_no_SQL');}
 public function escapeUTF8String($string){throw new RuntimeException('q2_no_SQL');}public function escapeColumnName($string){throw new RuntimeException('q2_no_SQL');}
 public function escapeMultilineComment($string){throw new RuntimeException('q2_no_SQL');}public function escapeStringForLikeClause($string){throw new RuntimeException('q2_no_SQL');}
}
function qo_set($o,$k,$v){(new ReflectionProperty(PhorgeProjectNativeExecutionScope::class,$k))->setValue($o,$v);}
function qo_get($o,$k){return(new ReflectionProperty(PhorgeProjectNativeExecutionScope::class,$k))->getValue($o);}
function qo_need($v){if(!$v){throw new RuntimeException('q2_owner_regression_failed');}}
function qo_scope($conn,$state='consumed'){$o=(new ReflectionClass(PhorgeProjectNativeExecutionScope::class))->newInstanceWithoutConstructor();foreach(array('connection'=>$conn,'state'=>$state,'transactionOwnership'=>'owned')as$k=>$v){qo_set($o,$k,$v);}(new ReflectionMethod(PhorgeProjectNativeExecutionScope::class,'captureTransactionFrame'))->invoke($o);return$o;}
$registry=new ReflectionProperty(PhorgeProjectNativeExecutionScope::class,'active');$oldRegistry=$registry->getValue();$globalCache=new ReflectionProperty(PhabricatorCaches::class,'requestCache');$oldCache=$globalCache->getValue();$scope=null;$later=null;
try{
 $conn=new Q2OwnerQueryObserver();$conn->openTransaction();$scope=qo_scope($conn);$active=$oldRegistry;$active[spl_object_id($conn)]=WeakReference::create($scope);$registry->setValue(null,$active);
 $cache=new PhutilInRequestKeyValueCache();$cache->setKey('q2.synthetic','temporary');$globalCache->setValue(null,$cache);qo_set($scope,'cache',$cache);qo_set($scope,'cachePrior',array('q2.synthetic'=>'prior'));
 $case=$argv[1];$unknown=false;$foreign=false;
 if(in_array($case,array('normal-commit','committed-foreign','foreign-owner','new-cache-object','fence-error','fence-with-primary'),true)){$conn->saveTransaction();$scope->didCommit();}
 if($case==='committed-foreign'){$conn->openTransaction();$conn->beginReadLocking();$conn->beginWriteLocking();$foreign=true;}
 elseif($case==='foreign-owner'){$conn->openTransaction();$later=qo_scope($conn,'open');$active=$registry->getValue();$active[spl_object_id($conn)]=WeakReference::create($later);$registry->setValue(null,$active);$cache->setKey('q2.synthetic','foreign');$foreign=true;}
 elseif($case==='new-cache-object'){$newCache=new PhutilInRequestKeyValueCache();$newCache->setKey('q2.synthetic','foreign');$globalCache->setValue(null,$newCache);$unknown=true;}
 elseif($case==='didCommit-old-generation'){$conn->killTransaction();$conn->openTransaction();$conn->saveTransaction();$denied=false;try{$scope->didCommit();}catch(RuntimeException $error){$denied=$error->getMessage()==='project_execution_real_commit_required';}qo_need($denied);$unknown=true;}
 elseif($case==='same-depth-replaced'){$conn->killTransaction();$conn->openTransaction();qo_need(!PhorgeProjectNativeExecutionScope::isSourceConnection($conn));$unknown=true;}
 elseif($case==='state-object-replaced'){$conn->killTransaction();(new ReflectionProperty(AphrontDatabaseConnection::class,'transactionState'))->setValue($conn,new AphrontDatabaseTransactionState());$conn->openTransaction();$unknown=true;}
 elseif($case==='nested-unknown'){$conn->openTransaction();$unknown=true;}
 elseif($case==='commit-error'){$conn->fail='COMMIT';try{$conn->saveTransaction();}catch(RuntimeException $expected){}$unknown=true;}
 elseif($case==='external-ended-unknown'){$conn->killTransaction();$unknown=true;}
 elseif($case==='rollback-error'){$conn->fail='ROLLBACK';$unknown=true;}
 elseif($case==='fence-error'||$case==='fence-with-primary'){qo_set($scope,'fence',new class {function release(){throw new RuntimeException('q2_own_fence_fault');}});$unknown=true;}
 if($case==='generation-storage'){
  $state=(new ReflectionProperty(AphrontDatabaseConnection::class,'transactionState'))->getValue($conn);$g1=$state->getGeneration();
  $conn->openTransaction();$nested=$state->getGeneration();$conn->saveTransaction();$conn->killTransaction();$conn->openTransaction();$g2=$state->getGeneration();$conn->saveTransaction();
  $conn->fail='START TRANSACTION';try{$conn->openTransaction();}catch(RuntimeException $expected){}$failedStart=$state->getGeneration();$conn->fail=null;$conn->openTransaction();$g3=$state->getGeneration();$conn->fail='COMMIT';try{$conn->saveTransaction();}catch(RuntimeException $expected){}
  qo_need($g1===1&&$nested===1&&$g2===2&&$failedStart===2&&$g3===3&&!$conn->isInsideTransaction());
  qo_need((new ReflectionProperty(AphrontDatabaseConnection::class,'transactionState'))->getValue($conn)===$state);
  $overflow=new AphrontDatabaseTransactionState();(new ReflectionProperty(AphrontDatabaseTransactionState::class,'generation'))->setValue($overflow,PHP_INT_MAX);$denied=false;
  try{$overflow->increaseDepth();}catch(OverflowException $expected){$denied=true;}qo_need($denied&&$overflow->getDepth()===0);
  echo json_encode(array('schema'=>'N05-Q2-owner-noDB-source-regression-v1','case'=>$case,'passed'=>true,'result'=>array('native_state_object_reused'=>true,'nested_generation_stable'=>true,'outer_reopen_generation_increased'=>true,'failed_START_does_not_create_generation'=>true,'COMMIT_error_not_certified'=>true,'generation_overflow_refused'=>true),'SQL'=>false,'physical_transaction_lease_proved'=>false,'actual_Editor_pipeline_executed'=>false,'execution_authorized'=>false),JSON_THROW_ON_ERROR)."\n";return;
 }
 if(str_starts_with($case,'service-')){
  qo_set($scope,'mode','history');
  if($case==='service-wrong-owner'){$later=qo_scope($conn,'open');$active=$registry->getValue();$active[spl_object_id($conn)]=WeakReference::create($later);$registry->setValue(null,$active);}
  elseif($case==='service-old-generation'){$conn->killTransaction();$conn->openTransaction();}
  elseif($case==='service-nested'){$conn->openTransaction();}
  else{$conn->beginReadLocking();}
  $before=count(array_keys($conn->queries,'COMMIT',true));$denied=false;
  try{$scope->commitService();}catch(RuntimeException $error){$denied=$error->getMessage()==='project_execution_real_commit_required';}
  qo_need($denied&&count(array_keys($conn->queries,'COMMIT',true))===$before);
  if($case==='service-wrong-owner'||$case==='service-old-generation'){
   $ended=false;try{$scope->assertOpen();}catch(RuntimeException $error){$ended=$error->getMessage()==='project_execution_scope_ended';}qo_need($ended);
  }
  echo json_encode(array('schema'=>'N05-Q2-owner-noDB-source-regression-v1','case'=>$case,'passed'=>true,'result'=>array('real_service_mode_refused_before_save'=>true,'COMMIT_queries_added'=>0),'SQL'=>false,'physical_transaction_lease_proved'=>false,'actual_Editor_pipeline_executed'=>false,'execution_authorized'=>false),JSON_THROW_ON_ERROR)."\n";return;
 }
 if($case==='producer-profile'){
  $count=count($conn->queries);$denied=false;try{PhorgeProjectExecutionProducerFence::acquire($conn);}catch(RuntimeException $error){$denied=$error->getMessage()==='project_execution_fence_profile_unsupported';}
  qo_need($denied&&count($conn->queries)===$count);$scope->close();
  echo json_encode(array('schema'=>'N05-Q2-owner-noDB-source-regression-v1','case'=>$case,'passed'=>true,'result'=>array('actual_unadmitted_writer_refused'=>true,'Herald_rule_count_gate_actual_SQL_test'=>'blocked'),'SQL'=>false,'physical_transaction_lease_proved'=>false,'actual_Editor_pipeline_executed'=>false,'execution_authorized'=>false),JSON_THROW_ON_ERROR)."\n";return;
 }
 $beforeRollback=count(array_keys($conn->queries,'ROLLBACK',true));$thrown=false;
 $previous=false;try{if($case==='fence-with-primary'){try{throw new RuntimeException('q2_pending_primary');}finally{$scope->close();}}else{$scope->close();}}catch(RuntimeException $error){$thrown=true;$previous=$error->getPrevious()instanceof RuntimeException&&$error->getPrevious()->getMessage()==='q2_pending_primary';}
 $afterRollback=count(array_keys($conn->queries,'ROLLBACK',true));
 if($unknown){
  qo_need($thrown&&qo_get($scope,'state')==='quarantined'&&qo_get($scope,'cleanupFailed')===true&&isset($registry->getValue()[spl_object_id($conn)]));
  try{$scope->close();throw new Exception('repeat unexpectedly clean');}catch(RuntimeException $expected){}
  if(in_array($case,array('nested-unknown','commit-error','external-ended-unknown','same-depth-replaced','state-object-replaced','didCommit-old-generation'),true)){qo_need($beforeRollback===$afterRollback);}
  if($case==='new-cache-object'){qo_need($newCache->getKey('q2.synthetic')==='foreign'&&$cache->getKey('q2.synthetic')==='temporary');}
  if($case==='fence-with-primary'){qo_need($previous);}
  $result=array('primary_preserved'=>$case!=='fence-with-primary'||$previous,'quarantined'=>true,'source_reuse_blocked_in_this_process'=>true,'automatic_retry'=>false,'physical_outcome_known'=>false,'foreign_cache_untouched'=>$case!=='new-cache-object'||$newCache->getKey('q2.synthetic')==='foreign');
 }else{
  qo_need(!$thrown&&qo_get($scope,'state')==='closed');$scope->close();
  if($case==='normal-commit'){qo_need(!$conn->isInsideTransaction()&&$afterRollback===$beforeRollback&&!isset($registry->getValue()[spl_object_id($conn)])&&$cache->getKey('q2.synthetic')==='prior');}
  if($case==='owned-rollback'){qo_need(!$conn->isInsideTransaction()&&$afterRollback-$beforeRollback===1&&$cache->getKey('q2.synthetic')==='prior');}
  if($case==='committed-foreign'){qo_need($conn->isInsideTransaction()&&$conn->isReadLocking()&&$conn->isWriteLocking()&&$afterRollback===$beforeRollback&&$cache->getKey('q2.synthetic')==='prior');}
  if($case==='foreign-owner'){qo_need($conn->isInsideTransaction()&&$afterRollback===$beforeRollback&&$registry->getValue()[spl_object_id($conn)]->get()===$later&&$cache->getKey('q2.synthetic')==='foreign');}
  $result=array('safe_bounded_owner_cleanup'=>true,'foreign_transaction_untouched'=>!$foreign||$conn->isInsideTransaction(),'foreign_owner_registry_and_cache_untouched'=>$case!=='foreign-owner'||$cache->getKey('q2.synthetic')==='foreign','repeated_close_idempotent'=>true);
 }
 echo json_encode(array('schema'=>'N05-Q2-owner-noDB-source-regression-v1','case'=>$case,'passed'=>true,'result'=>$result,'SQL'=>false,'physical_transaction_lease_proved'=>false,'actual_Editor_pipeline_executed'=>false,'execution_authorized'=>false),JSON_THROW_ON_ERROR)."\n";
}finally{
 // Explicit owner disposal of the test observer's remaining fake stack only.
 // This is not Scope cleanup and makes no database rollback claim.
 if(isset($conn)){$conn->fail=null;$budget=0;while($conn->isInsideTransaction()){if(++$budget>4){throw new RuntimeException('q2_fixture_disposal_budget');}$conn->killTransaction();}if($conn->isReadLocking()){$conn->endReadLocking();}if($conn->isWriteLocking()){$conn->endWriteLocking();}}
 // Test fixture disposal only: never use the reflected object to edit data.
 if($scope!==null){qo_set($scope,'state','closed');qo_set($scope,'cleanupFailed',false);}
 if($later!==null){qo_set($later,'state','closed');qo_set($later,'cleanupFailed',false);}
 $registry->setValue(null,$oldRegistry);$globalCache->setValue(null,$oldCache);
}
