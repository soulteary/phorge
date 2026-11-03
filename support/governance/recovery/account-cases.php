<?php

/** Install only a fixture subcontext; never relax the production scope. */
function governance_recovery_account_fixture_environment(PhorgeGovernanceCompletionFixture $fixture) {
  $fixture->assertOwned();
  $saved=array();foreach(array('sourceStack','cache','overrideSource','repairSource')as$key){
    $saved[$key]=(new ReflectionProperty(PhabricatorEnv::class,$key))->getValue();}
  try {
  $stack=PhabricatorEnv::getConfigSourceStack();
  if(!$stack||get_class($stack)!==PhabricatorConfigStackSource::class){
    throw new RuntimeException('recovery_fixture_environment_refused');}
  $sources=$stack->getStack();$default=null;
  if(count($sources)<2||count($sources)>8){throw new RuntimeException('recovery_fixture_environment_refused');}
  foreach($sources as$index=>$source){
    if(get_class($source)===PhabricatorConfigDefaultSource::class){
      if($default!==null||$index!==count($sources)-1){throw new RuntimeException('recovery_fixture_environment_refused');}
      $default=$source;
    }else if(get_class($source)!==PhabricatorConfigDictionarySource::class){
      throw new RuntimeException('recovery_fixture_environment_refused');}
  }
  if($default===null){throw new RuntimeException('recovery_fixture_environment_refused');}
  $effective=$stack->getAllKeys();
  foreach(array_keys($effective)as$key){$effective[$key]=PhabricatorEnv::getEnvConfig($key);}
  $writer=$fixture->getWriterConfig();
  foreach(array('mysql.host'=>'host','mysql.port'=>'port','mysql.user'=>'user',
    'mysql.pass'=>'password','storage.default-namespace'=>'namespace')as$key=>$field){
    $value=$effective[$key]??null;$current=PhabricatorEnv::getEnvConfig($key);$expected=$writer[$field];
    if($key==='mysql.port'){
      $value=PhorgeProjectExecutionConfigSource::normalizePort($value);
      $current=PhorgeProjectExecutionConfigSource::normalizePort($current);
      $expected=PhorgeProjectExecutionConfigSource::normalizePort($expected);
    }
    if($value!==$current||$value!==$expected){throw new RuntimeException('recovery_fixture_environment_refused');}
  }
  if(($effective['cluster.databases']??null)!==array()||
    !in_array($effective['gorge.db.uri']??null,array('',null),true)){
    throw new RuntimeException('recovery_fixture_environment_refused');}
  $temporary=new PhabricatorConfigStackSource();$temporary->pushSource($default)
    ->pushSource(new PhabricatorConfigDictionarySource($effective));
  (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$temporary);
  (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  (new ReflectionProperty(PhabricatorEnv::class,'overrideSource'))->setValue(null,null);
  (new ReflectionProperty(PhabricatorEnv::class,'repairSource'))->setValue(null,null);
  return $saved;
  } catch (Throwable $error) {
    governance_recovery_account_restore_fixture_environment($saved);
    throw $error;
  }
}
function governance_recovery_account_restore_fixture_environment(array $saved) {
  $keys=array_keys($saved);sort($keys,SORT_STRING);
  if($keys!==array('cache','overrideSource','repairSource','sourceStack')){
    throw new RuntimeException('recovery_fixture_environment_cleanup_failed');}
  foreach($saved as$key=>$value){(new ReflectionProperty(PhabricatorEnv::class,$key))->setValue(null,$value);}
  foreach($saved as$key=>$value){
    if((new ReflectionProperty(PhabricatorEnv::class,$key))->getValue()!==$value){
      throw new RuntimeException('recovery_fixture_environment_cleanup_failed');}
  }
}

/** Prepared actual-SQL cases. Invoked only inside the owned full native fixture. */
function governance_recovery_account_cases($fixture, callable $check) {
  $cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;
  $environment=governance_recovery_account_fixture_environment($fixture);
  try {
  $fixture->assertOwned(); $suffix=substr($fixture->getNamespace(),-10);
  $actor=(new PhabricatorUser())->setUsername('recoveractor'.$suffix)->setRealName('Synthetic Recovery Actor')
    ->setIsAdmin(1)->setIsApproved(1)->save();
  $subject=(new PhabricatorUser())->setUsername('recoversubject'.$suffix)->setRealName('Synthetic Recovery Subject')
    ->setIsApproved(1)->save();
  $sessions=new PhabricatorAuthSessionEngine();
  $authenticated=governance_native_request($actor,$sessions);
  $context=$authenticated['context'];$target=$subject->getPHID();
  $conn=(new PhabricatorUser())->establishConnection('w');
  $capture=function() use($context,$target){
    return PhorgeRecoveryNativeCaptureScope::open($context,$target)->finish();
  };
  $refuse=function($callable,$reason,$label) use($check){
    $rejected=false;
    try{$callable();}catch(RuntimeException $error){
      if(get_class($error)!==RuntimeException::class||$error->getMessage()!==$reason){throw$error;}
      $rejected=true;
    }
    $check($rejected,$label);
  };
  $before=queryfx_one($conn,'SELECT COUNT(*) n FROM %R',new PhorgeGovernanceIdentityEpoch());
  $refuse($capture,'recovery_actor_epoch_uninitialized','recovery-account-uninitialized-actor-refused-without-initialization');
  $after=queryfx_one($conn,'SELECT COUNT(*) n FROM %R',new PhorgeGovernanceIdentityEpoch());
  $check($before===$after&&!$conn->isInsideTransaction(),'recovery-account-unknown-refusal-no-source-write-or-open-transaction');
  // Existing issuer initialization is a real, separate writer transaction. It
  // is deliberately not part of the capture or described as read-only.
  $conn->openTransaction();
  try{PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);$conn->saveTransaction();}
  catch(Throwable$error){$conn->killTransaction();throw$error;}
  $before=queryfx_all($conn,'SELECT userPHID,epoch FROM %R ORDER BY userPHID',new PhorgeGovernanceIdentityEpoch());
  $first=$capture();
  $check($first['capture']==='actual-locked-native-account'&&
    $first['account_facts']['legacy_epoch']===array('state'=>'observed-absence','value'=>null)&&
    $first['all_sources_complete']===false&&$first['can_execute']===false&&count($first['families'])===13,
    'recovery-account-real-storage-capture-keeps-absence-and-thirteen-family-block');
  $after=queryfx_all($conn,'SELECT userPHID,epoch FROM %R ORDER BY userPHID',new PhorgeGovernanceIdentityEpoch());
  $check($before===$after&&!$conn->isInsideTransaction(),'recovery-account-success-no-hidden-epoch-initialization');
  $conn->openTransaction();
  try{
    queryfx($conn,'UPDATE %R SET realName=%s WHERE BINARY phid=BINARY %s',new PhabricatorUser(),'Synthetic Outer Write',$target);
    $refuse($capture,'recovery_outer_transaction_refused','recovery-account-outer-transaction-refused');
    $row=queryfx_one($conn,'SELECT realName FROM %R WHERE BINARY phid=BINARY %s',new PhabricatorUser(),$target);
    $check($conn->isInsideTransaction()&&$row['realName']==='Synthetic Outer Write',
      'recovery-account-refusal-does-not-end-or-rollback-caller-transaction');
  }finally{$conn->killTransaction();}
  queryfx($conn,'START TRANSACTION');
  try{
    queryfx($conn,'UPDATE %R SET realName=%s WHERE BINARY phid=BINARY %s',new PhabricatorUser(),'Synthetic Raw Outer Write',$target);
    $rejected=false;
    try{$capture();}catch(AphrontQueryException$error){
      if(get_class($error)!==AphrontQueryException::class||$error->getCode()!==1568){throw$error;}
      $rejected=true;
    }
    $row=queryfx_one($conn,'SELECT realName FROM %R WHERE BINARY phid=BINARY %s',new PhabricatorUser(),$target);
    $check($rejected&&!$conn->isInsideTransaction()&&$row['realName']==='Synthetic Raw Outer Write',
      'recovery-account-untracked-physical-outer-transaction-refused-without-rollback');
  }finally{queryfx($conn,'ROLLBACK');}
  foreach(array(true,false) as$disabled){
    $current=(new PhabricatorUser())->loadOneWhere('phid=%s',$target);
    (new PhabricatorUserTransactionEditor())->setActor($actor)
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()))
      ->applyTransactions($current,array((new PhabricatorUserTransaction())
        ->setTransactionType(PhabricatorUserDisableTransaction::TRANSACTIONTYPE)->setNewValue($disabled)));
  }
  $last=$capture();
  $check($first['account_facts']['flags']===$last['account_facts']['flags']&&
    $first['account_facts']['native_transaction_id']!==$last['account_facts']['native_transaction_id']&&
    $last['can_execute']===false,'recovery-account-real-native-disable-enable-aba-is-observable-not-recovery-authority');
  $ordinary=governance_native_request($subject,$sessions);
  $conn->openTransaction();
  try{PhorgeGovernanceNativeIdentitySource::lockCurrent($ordinary['context'],$conn);$conn->saveTransaction();}
  catch(Throwable$error){$conn->killTransaction();throw$error;}
  $refuse(function()use($ordinary,$target){
    PhorgeRecoveryNativeCaptureScope::open($ordinary['context'],$target)->finish();
  },'recovery_current_admin_required','recovery-account-real-current-nonadmin-cannot-capture');
  // Actual source corruption remains unknown. A missing table is not silently
  // treated as an absent row. Fixed owned DDL is setup-only and restored.
  $fixture->assertOwned();$admin=$fixture->getAdmin();
  $table=$fixture->table('user','organization_identityepoch');
  $temporary='`'.$fixture->getNamespace().'_user`.`organization_identityepoch_recovery_saved`';
  $renamed=false;
  try{
    governance_native_setup_query($fixture,'RENAME TABLE '.$table.' TO '.$temporary);$renamed=true;
    $rejected=false;
    try{$capture();}catch(AphrontSchemaQueryException$error){$rejected=true;}
    $check($rejected&&!$conn->isInsideTransaction(),'recovery-account-missing-actual-source-table-refused-with-owned-transaction-cleanup');
  }finally{
    if($renamed){governance_native_setup_query($fixture,'RENAME TABLE '.$temporary.' TO '.$table);}
  }
  return array('actual_account_adapter'=>true,'source_writes_by_capture'=>false,
    'all_sources_complete'=>false,'can_execute'=>false,'other_twelve_families_complete'=>false);
  } finally {
    try { governance_recovery_account_restore_fixture_environment($environment); }
    finally {
      $_COOKIE=$cookies;
      if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;}
    }
  }
}
