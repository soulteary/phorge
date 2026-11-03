<?php

/** Actual native Query observations; no HTTP/gateway/stream or SLA proof. */
function governance_native_revocation_measurement_cases($fixture,callable $check,array $state){
  $fixture->assertOwned();
  $revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');
  if(!is_string($revision)||!preg_match('/\A[a-f0-9]{40}\z/',$revision)){
    throw new RuntimeException('measurement_source_revision_required');}
  $boot=trim(file_get_contents('/proc/sys/kernel/random/boot_id'));
  if(!preg_match('/\A[a-f0-9-]{36}\z/',$boot)||PHP_INT_SIZE!==8){throw new RuntimeException('measurement_linux_clock_required');}
  $boot_hash=hash('sha256',$boot);$cookies=$_COOKIE;$method=$_SERVER['REQUEST_METHOD']??null;
  $scope=PhabricatorEnv::beginScopedEnv()->overrideEnvConfig('governance.project-approval.enabled',false);
  try{
    $user=(new PhabricatorUser())->setUsername('n12probe'.substr($fixture->getNamespace(),-12))
      ->setRealName('Synthetic Query Observation')->setIsApproved(1)->save();
    $auth=governance_native_request($user,$state['sessions']);$admin=$state['admin'];
    $project=PhabricatorProject::initializeNewProject($admin['viewer'])
      ->setName('Synthetic N12 Query Transition')->setViewPolicy(PhabricatorPolicies::POLICY_USER)
      ->setEditPolicy($admin['viewer']->getPHID())->setJoinPolicy(PhabricatorPolicies::POLICY_NOONE)->save();
    $conn=$project->establishConnection('w');$user_conn=(new PhabricatorUser())->establishConnection('w');
    if($conn->isInsideTransaction()||$user_conn->isInsideTransaction()){
      throw new RuntimeException('measurement_outer_transaction_refused');}
    $probe=static function()use($fixture,$auth,$project,$boot_hash){
      // One parent clock brackets spawn, fresh authentication/query and reap.
      // The duration includes process startup; it is not native-query CPU time.
      $start=hrtime(true);
      $worker=governance_native_start_worker($fixture,array('action'=>'measurement.project-query',
        'target'=>$project->getPHID(),'token'=>$auth['token']));
      $result=governance_native_finish_worker($worker);$finish=hrtime(true);
      if($result['status']!=='committed'||$result['boot_id_sha256']!==$boot_hash||
        $result['target_binding']!==hash('sha256',$project->getPHID())){
        throw new RuntimeException('measurement_probe_binding_refused');}
      return array('start_ns'=>$start,'finish_ns'=>$finish,'outcome'=>$result['outcome'],
        'source_binding'=>$result['source_binding']);
    };
    $before=$probe();
    $check($before['outcome']==='allowed','n12-native-query-independent-current-session-pretransition-allow');
    $_COOKIE[PhabricatorCookies::COOKIE_SESSION]=$admin['token'];
    $start=hrtime(true);
    $records=(new PhabricatorProjectTransactionEditor())->setActor($admin['viewer'])->setRequest($admin['request'])
      ->setContentSource(PhabricatorContentSource::newForSource('console',array()))->setContinueOnMissingFields(true)
      ->applyTransactions($project,array((new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)->setNewValue($admin['viewer']->getPHID())));
    $ack=hrtime(true);
    $check(count($records)===1&&!$conn->isInsideTransaction()&&!$user_conn->isInsideTransaction()&&$start<=$ack,
      'n12-native-policy-transition-returns-only-after-owned-transaction-commit');
    $stored=queryfx_one($conn,'SELECT phid,viewPolicy FROM %R WHERE BINARY phid=BINARY %s',new PhabricatorProject(),$project->getPHID());
    $last=queryfx_one($conn,'SELECT id,metadata FROM %R WHERE BINARY objectPHID=BINARY %s ORDER BY id DESC LIMIT 1',new PhabricatorProjectTransaction(),$project->getPHID());
    $persisted=$last?(new PhabricatorProjectTransaction())->loadFromArray($last):null;
    $group=$records[0]->getTransactionGroupID();
    $check($stored['viewPolicy']===$admin['viewer']->getPHID()&&$last&&
      (string)$last['id']===(string)$records[0]->getID()&&is_string($group)&&$group!==''&&
      $persisted->getTransactionGroupID()===$group,
      'n12-native-transition-record-and-group-persisted-with-actual-view-policy');
    $after=$probe();$repeat=$probe();$source_binding=hash('sha256',phutil_json_encode(array($stored,$last)));
    $check($after['outcome']==='denied'&&$repeat['outcome']==='denied'&&
      $after['source_binding']===$source_binding&&$repeat['source_binding']===$source_binding&&
      $after['source_binding']!==$before['source_binding'],
      'n12-independent-fresh-native-query-observes-committed-source-and-denial');
    $transition=hash('sha256',phutil_json_encode(array($project->getPHID(),$group,$last['id'],$source_binding)));
    $attempts=array();foreach(array($before,$after,$repeat)as$index=>$row){$attempts[]=array(
      'id'=>$index+1,'transition_id'=>$transition,'boot_id_sha256'=>$boot_hash,
      'start_ns'=>$row['start_ns'],'finish_ns'=>$row['finish_ns'],'outcome'=>$row['outcome']);}
    $check($before['finish_ns']<=$start&&$ack<=$after['start_ns']&&$after['finish_ns']<=$repeat['start_ns'],
      'n12-query-attempts-use-serial-parent-clock-around-actual-commit');
    return array('profile'=>'native-session-authenticated-project-query-only','actual_source_checked'=>true,
      'actual_independent_query_processes'=>3,'HTTP_proved'=>false,'gateway_proved'=>false,
      'long_connection_proved'=>false,'SLA_proved'=>false,'convergence_proved'=>false,
      'event'=>array('schema'=>1,'source_revision'=>$revision,'producer_sha256'=>hash_file('sha256',__FILE__),
        'family'=>'app-session','clock'=>array('kind'=>'monotonic_ns','boot_id_sha256'=>$boot_hash),
        'transition'=>array('id'=>$transition,'action'=>'revoke','native_commit_id_sha256'=>hash('sha256',$group),
          'boundary'=>'native-transaction-operation-call-return',
          'operation_start_ns'=>$start,'commit_confirmed_return_ns'=>$ack),'attempts'=>$attempts),
      'native_source_binding_sha256'=>$source_binding);
  }finally{
    unset($scope);$_COOKIE=$cookies;
    if($method===null){unset($_SERVER['REQUEST_METHOD']);}else{$_SERVER['REQUEST_METHOD']=$method;}
  }
}
