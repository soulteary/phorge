<?php
/** Isolated proof orchestration ONLY. No business observer factory is supplied.
 * Real privileged provisioning/ownership stays outside RestoreService. */
function governance_recovery_borrowed_with_observer($fixture,mysqli $control,callable $work) {
  if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||
    get_class($fixture)!==PhorgeGovernanceCompletionFixture::class){throw new RuntimeException('borrowed_fixture_scope_required');}
  $fixture->assertOwned();require_once __DIR__.'/session-lease-observer.php';
  $slot=new ReflectionProperty(PhorgeRecoveryRestoreService::class,'borrowedObserver');
  $ownerSlot=new ReflectionProperty(PhorgeRecoveryRestoreService::class,'borrowedOwner');
  if($slot->getValue()!==null||$ownerSlot->getValue()!==null){throw new RuntimeException('borrowed_fixture_context_reentry');}
  $GLOBALS['governance_recovery_borrowed_cleanup_proved']=false;
  $GLOBALS['governance_recovery_borrowed_fixture_cleanup']=array('cleanup_proved'=>false);
  $owner=null;$observer=null;$primary=null;
  try{
    $owner=GovernanceSessionLeaseObserverOwner::prepare($fixture,$control,getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION'));
    $config=$owner->configuration();$meta=$config['observer'];
    $observer=new AphrontMySQLiDatabaseConnection(array('host'=>$meta['host'],'port'=>$meta['port'],'database'=>'',
      'user'=>$meta['user'],'pass'=>new PhutilOpaqueEnvelope($meta['password']),'timeout'=>3));
    $identity=queryfx_one($observer,'SELECT CURRENT_USER() principal,CURRENT_ROLE() role,@@proxy_user proxy');
    if(!$identity||$identity['principal']!==$meta['user'].'@%'||$identity['role']!=='NONE'||$identity['proxy']!==null){throw new RuntimeException('borrowed_fixture_observer_unknown');}
    $rows=queryfx_all($observer,'SELECT ATTRIBUTE attributes FROM information_schema.USER_ATTRIBUTES WHERE USER=%s AND HOST=%s',$meta['user'],'%');
    if(count($rows)!==1||!is_string($rows[0]['attributes'])||strlen($rows[0]['attributes'])>4096){throw new RuntimeException('borrowed_fixture_observer_owner_unknown');}
    $attributes=json_decode($rows[0]['attributes'],true,8,JSON_THROW_ON_ERROR);
    if(!is_array($attributes)||count($attributes)!==count($config['metadata_owner'])){throw new RuntimeException('borrowed_fixture_observer_owner_unknown');}
    foreach($config['metadata_owner']as$key=>$value){if(($attributes[$key]??null)!==$value){throw new RuntimeException('borrowed_fixture_observer_owner_unknown');}}
    // Explicit support-only reflection seam. Core has no public observer setter
    // and no fixture/control/nonce dependency. This is not production adoption.
    $slot->setValue(null,$observer);
    return$work($observer);
  }catch(Throwable$error){$primary=$error;throw$error;}
  finally{
    $errors=$primary===null?array():array('primary'=>$primary);
    try{
      if(($GLOBALS['governance_recovery_borrowed_cleanup_proved']??null)!==true||$ownerSlot->getValue()!==null){
        throw new RuntimeException('borrowed_fixture_parent_terminal_unproved');}
      $observer->close();$slot->setValue(null,null);
      $cleanup=$owner->cleanup();
      if(($cleanup['cleanup_proved']??null)!==true){throw new RuntimeException('borrowed_fixture_cleanup_unproved');}
      $GLOBALS['governance_recovery_borrowed_fixture_cleanup']=$cleanup;
      if(($GLOBALS['governance_session_lease_observer_owner']??null)===$owner){unset($GLOBALS['governance_session_lease_observer_owner']);}
    }catch(Throwable$error){$errors['observer-cleanup']=$error;}
    if(count($errors)>($primary===null?0:1)){throw new PhutilAggregateException('borrowed_fixture_cleanup_failed',$errors);}
  }
}
