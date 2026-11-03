<?php

/** Owned native SQL only; no fake clocks, production callback or inherited socket. */
function governance_native_concurrency_sql($fixture, $sql, array $values=array()) {
  $fixture->assertOwned(); $mode=(new mysqli_driver())->report_mode; $statement=null;
  try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $statement=$fixture->getAdmin()->prepare($sql);
    if (!$statement || !$statement->execute($values)) {
      throw new RuntimeException('native_concurrency_setup_failed');
    }
    return $statement->field_count ? $statement->get_result()->fetch_all(MYSQLI_ASSOC)
      : $statement->affected_rows;
  } finally { if ($statement) {$statement->close();} mysqli_report($mode); }
}
function governance_native_concurrency_wait($fixture, mysqli $observer, array $worker, $table) {
  if (!in_array($table,array('organization_guard','organization_appointment','organization_transfer'),true)) {
    throw new RuntimeException('native_concurrency_wait_target_refused');
  }
  governance_native_wait_worker_query($observer,array($worker),'/'.preg_quote($table,'/').'.*FOR UPDATE/is');
  $deadline=microtime(true)+5;
  do {
    $result=$observer->query('SELECT COUNT(*) n FROM performance_schema.data_lock_waits w '.
      'JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID '.
      'JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID '.
      'WHERE r.PROCESSLIST_ID='.(int)$worker['mysql_thread'].' AND b.PROCESSLIST_ID='.(int)$fixture->getAdmin()->thread_id);
    if (!$result) { throw new RuntimeException('native_concurrency_lock_observation_failed'); }
    $row=$result->fetch_assoc();$result->free();
    if ((int)$row['n']>0) { return; }
    usleep(20000);
  } while (microtime(true)<$deadline);
  throw new RuntimeException('native_concurrency_lock_wait_not_proved');
}
function governance_native_concurrency_now($fixture) {
  return (int)governance_native_concurrency_sql($fixture,'SELECT UNIX_TIMESTAMP() n')[0]['n'];
}
function governance_native_concurrency_expire($fixture, $until) {
  $deadline=microtime(true)+15;
  while (governance_native_concurrency_now($fixture)<$until && microtime(true)<$deadline) { usleep(20000); }
  if (governance_native_concurrency_now($fixture)<$until) {
    throw new RuntimeException('native_concurrency_database_clock_wait_failed');
  }
}
function governance_native_concurrency_cases($fixture, callable $check, array $state) {
  global $control;
  if (!($control instanceof mysqli)) { throw new RuntimeException('native_concurrency_observer_required'); }
  $fixture->assertOwned();$setup=$fixture->getAdmin();$service=$state['service'];
  $user=(new PhabricatorUser())->setUsername('gnrace'.substr($fixture->getNamespace(),-12))
    ->setRealName('Synthetic Native Race Manager')->setIsApproved(1)->setIsEmailVerified(1)->save();
  (new PhabricatorUserEmail())->setUserPHID($user->getPHID())
    ->setAddress($user->getUsername().'@example.invalid')->setIsPrimary(1)->setIsVerified(1)->save();
  $cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;
  try { $credential=governance_native_request($user,$state['sessions']); }
  finally { $_COOKIE=$cookies; if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;} }
  $service->createUnit(array('operation_id'=>'race-create','unit_id'=>'race-team',
    'kind'=>'team','name'=>'Synthetic Race Team','parent_id'=>null));
  governance_native_privacy_add($service,'race-add','race-team',$user->getPHID(),1);
  governance_native_privacy_seed($fixture,'race-team',$user->getPHID());
  $guard=$fixture->table('user','organization_guard');$appointments=$fixture->table('user','organization_appointment');
  $native=(new PhabricatorUser())->establishConnection('w');
  $default=queryfx_one($native,'SELECT @@SESSION.transaction_isolation AS isolationLevel')['isolationLevel'];
  $check($default==='REPEATABLE-READ','native-directory-native-session-default-remains-repeatable-read');
  $locked=false;
  try {
    if (!$setup->begin_transaction()) { throw new RuntimeException('native_concurrency_setup_begin_failed'); }
    $locked=true;
    governance_native_concurrency_sql($fixture,'SELECT id FROM '.$guard.' WHERE id=1 FOR UPDATE');
    $worker=governance_native_start_worker($fixture,array('action'=>'org.list','token'=>$credential['token']));
    governance_native_concurrency_wait($fixture,$control,$worker,'organization_guard');
    $check(true,'native-directory-guard-revocation-real-writer-wait-observed');
    governance_native_concurrency_sql($fixture,'UPDATE '.$appointments.
      ' SET state=\'ended\',version=version+1 WHERE unitID=? AND userPHID=?',array('race-team',$user->getPHID()));
    if (!$setup->commit()) { throw new RuntimeException('native_concurrency_setup_commit_failed'); } $locked=false;
    $result=governance_native_finish_worker($worker);
    $check($result['status']==='committed' && $result['value']===array(),
      'native-directory-guard-wait-revocation-no-pre-lock-read-view');
    governance_native_concurrency_sql($fixture,'UPDATE '.$appointments.
      ' SET state=\'active\',validUntil=NULL,version=version+1 WHERE unitID=? AND userPHID=?',
      array('race-team',$user->getPHID()));
    if (!$setup->begin_transaction()) { throw new RuntimeException('native_concurrency_setup_begin_failed'); } $locked=true;
    governance_native_concurrency_sql($fixture,'SELECT id FROM '.$guard.' WHERE id=1 FOR UPDATE');
    $worker=governance_native_start_worker($fixture,array('action'=>'org.count','token'=>$credential['token']));
    governance_native_concurrency_wait($fixture,$control,$worker,'organization_guard');
    $check(true,'native-directory-count-guard-revocation-real-writer-wait-observed');
    governance_native_concurrency_sql($fixture,'UPDATE '.$appointments.
      ' SET state=\'ended\',version=version+1 WHERE unitID=? AND userPHID=?',array('race-team',$user->getPHID()));
    if (!$setup->commit()) { throw new RuntimeException('native_concurrency_setup_commit_failed'); } $locked=false;
    $result=governance_native_finish_worker($worker);
    $check($result['status']==='committed' && $result['value']===0,
      'native-directory-count-guard-wait-revocation-no-pre-lock-read-view');
    $until=governance_native_concurrency_now($fixture)+10;
    governance_native_concurrency_sql($fixture,'UPDATE '.$appointments.
      ' SET state=\'active\',validUntil=?,version=version+1 WHERE unitID=? AND userPHID=?',
      array($until,'race-team',$user->getPHID()));
    if (!$setup->begin_transaction()) { throw new RuntimeException('native_concurrency_setup_begin_failed'); } $locked=true;
    governance_native_concurrency_sql($fixture,'SELECT id FROM '.$appointments.
      ' WHERE unitID=? AND userPHID=? FOR UPDATE',array('race-team',$user->getPHID()));
    $worker=governance_native_start_worker($fixture,array('action'=>'org.list','token'=>$credential['token']));
    governance_native_concurrency_wait($fixture,$control,$worker,'organization_appointment');
    $check(true,'native-directory-list-expiry-current-source-row-wait-observed');
    governance_native_concurrency_expire($fixture,$until);
    if (!$setup->commit()) { throw new RuntimeException('native_concurrency_setup_commit_failed'); } $locked=false;
    $result=governance_native_finish_worker($worker);
    $check($result['status']==='rejected' && $result['reason']==='organization_read_authority_expired',
      'native-directory-list-expiry-after-lock-wait-refused');
  } finally { if ($locked && !$setup->rollback()) { throw new RuntimeException('native_concurrency_setup_rollback_failed'); } }
  // Fixture-only seam: execute the very same private count capture and final
  // transaction gate, separated by real DB time. This does not claim a full
  // public count route was interrupted inside its own method.
  $until=governance_native_concurrency_now($fixture)+4;
  governance_native_concurrency_sql($fixture,'UPDATE '.$appointments.
    ' SET state=\'active\',validUntil=?,version=version+1 WHERE unitID=? AND userPHID=?',
    array($until,'race-team',$user->getPHID()));
  $count=PhorgeOrganizationService::newForActor($credential['context']);
  $begin=new ReflectionMethod(PhorgeOrganizationService::class,'begin');
  $start=new ReflectionMethod(PhorgeOrganizationService::class,'startRead');
  $capture=new ReflectionMethod(PhorgeOrganizationService::class,'captureUnitCount');
  $finish=new ReflectionMethod(PhorgeOrganizationService::class,'finish');
  try {
    $begin->invoke($count,array());$start->invoke($count);
    $check($capture->invoke($count)===1,'native-directory-count-real-capture-before-expiry-fixture-seam');
    governance_native_concurrency_expire($fixture,$until);
    governance_native_refused(static fn()=>$finish->invoke($count,true),
      'organization_read_authority_expired',$check,
      'native-directory-count-final-db-clock-expiry-refused-fixture-seam');
  } finally { $finish->invoke($count,false); }
  $check(queryfx_one($native,'SELECT @@SESSION.transaction_isolation AS isolationLevel')['isolationLevel']===$default,
    'native-directory-organization-transaction-does-not-change-session-isolation');
  // Do not set the next isolation here: this proves the normal next native
  // transaction really retains RR semantics, not just the SESSION variable.
  $native->openTransaction();
  try {
    $before=(int)queryfx_one($native,'SELECT version FROM organization_guard WHERE id=1')['version'];
    governance_native_concurrency_sql($fixture,'UPDATE '.$guard.' SET version=version+1 WHERE id=1');
    $after=(int)queryfx_one($native,'SELECT version FROM organization_guard WHERE id=1')['version'];
    $check($before===$after,'native-directory-next-native-transaction-retains-repeatable-read-view');
  } finally { $native->killTransaction(); }
  return array('separate_exec_workers'=>true,'real_guard_lock_wait'=>true,
    'real_list_period_lock_wait'=>true,'count_evidence'=>'fixture-only-private-real-capture-and-final-gate',
    'full_count_route_midrequest_wait_proved'=>false,'response_delivery_instant_freshness_promised'=>false);
}
