<?php

/** Owned synthetic organization integration. No production identity authority. */
require_once dirname(__DIR__,2).'/runtime/php-version.php';
$root=dirname(__DIR__,3);
$stage='preflight'; $checks=array(); $failure=null; $cleanup_failures=array();
$control=null; $setup=null; $store=null; $reader=null; $database=null;
$created=false; $principals=array(); $private=null; $nonce=null; $config=null;
$workers=array(); $cleanup=array('owned_schema_removed'=>false,'principals_removed'=>0,'private_directories_removed'=>0);
$guard_initialized=false;
$worker_mode=($argv[1] ?? null)==='--worker';
function organization_check($ok,$name) { global $stage,$checks; $stage=$name;
  if (!$ok) { throw new RuntimeException('organization_fixture_assertion'); } $checks[]=$name; }
function organization_sql(mysqli $db,$sql,array $values=array()) {
  $stmt=$db->prepare($sql); $stmt->execute($values); return $stmt; }
function organization_phid($letter) { return 'PHID-USER-'.str_repeat($letter,20); }
function organization_reject($callback,$reason,$name) {
  $rejected=false; try { $callback(); } catch (RuntimeException $error) {
    if ($error->getMessage()!==$reason) { throw $error; } $rejected=true;
  } organization_check($rejected,$name);
}
function organization_row(mysqli $db,$table,$team,$user=null) {
  $where=$user===null ? 'unit_id=?' : 'team_id=? AND user_id=?';
  $stmt=organization_sql($db,'SELECT * FROM `'.$table.'` WHERE '.$where,
    $user===null ? array($team) : array($team,$user));
  $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); return $row;
}
function organization_mutation(mysqli $db,$action,$team,$target,$from=null,$until=null,$approval=null) {
  $unit=organization_row($db,'org_unit',$team);
  $member=organization_row($db,'org_member',$team,$target);
  $appointment=organization_row($db,'org_appointment',$team,$target);
  return array('action'=>$action,'team'=>$team,'target_user'=>$target,
    'expected_team_version'=>(int)$unit['version'],
    'expected_member_version'=>(int)($member['version'] ?? 0),
    'expected_appointment_version'=>(int)($appointment['version'] ?? 0),
    'valid_from'=>$from,'valid_until'=>$until,'approval_id'=>$approval);
}
function organization_request($id,$actor,array $mutations) {
  return array('operation_id'=>$id,'actor'=>$actor,'mutations'=>$mutations);
}
function organization_fingerprint(mysqli $db) {
  $all=array(); foreach (array('fixture_guard','org_user','org_unit','org_member',
    'org_appointment','org_operation','org_audit','org_approval') as $table) {
    $rows=$db->query('SELECT * FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC);
    usort($rows,static fn($a,$b)=>strcmp(json_encode($a),json_encode($b))); $all[$table]=$rows;
  } return hash('sha256',json_encode($all,JSON_THROW_ON_ERROR));
}
function organization_proposal($id,$operation,$actor,$action,$team,$target,$from,$until,$ttl=120) {
  return array('approval_id'=>$id,'operation_id'=>$operation,'actor'=>$actor,'action'=>$action,
    'team'=>$team,'target_user'=>$target,'valid_from'=>$from,'valid_until'=>$until,'ttl_seconds'=>$ttl);
}
function organization_start_worker(array $config,array $request,$nonce,$method='execute',$spawn_fault=false) {
  if (!in_array($method,array('execute','proposeApproval','approveApproval'),true)) {
    throw new RuntimeException('organization_worker_method_refused'); }
  global $workers;
  $private=sys_get_temp_dir().'/governance-org-'.bin2hex(random_bytes(12));
  if (!mkdir($private,0700)) { throw new RuntimeException('organization_worker_private_failed'); }
  $identity=lstat($private);
  $index=count($workers);
  $workers[]=array('process'=>null,'private'=>$private,'nonce'=>$nonce,
    'device'=>$identity['dev'],'inode'=>$identity['ino'],'owner_ready'=>false,'cleaned'=>false);
  if (file_put_contents($private.'/owner',$nonce,LOCK_EX)!==strlen($nonce) || !chmod($private.'/owner',0600)) {
    throw new RuntimeException('organization_worker_marker_failed'); }
  $workers[$index]['owner_ready']=true;
  file_put_contents($private.'/worker.json',json_encode(array('owner_nonce'=>$nonce,
    'config'=>$config,'request'=>$request,'method'=>$method),JSON_THROW_ON_ERROR),LOCK_EX); chmod($private.'/worker.json',0600);
  // Fixed fixture-only fault: no caller command or host path is accepted.
  $command=$spawn_fault?array($private.'/missing-worker'):array(PHP_BINARY,__FILE__,'--worker',$private);
  try {
    $process=proc_open($command,array(
    0=>array('file','/dev/null','r'),1=>array('file',$private.'/stdout.log','a'),
    2=>array('file',$private.'/stderr.log','a')),$pipes,null,array(
      'PATH'=>getenv('PATH') ?: '/usr/bin:/bin','PHORGE_GOVERNANCE_ISOLATED_TEST'=>'1'));
  } catch (Throwable $error) {
    throw new RuntimeException('organization_worker_spawn_failed');
  }
  $workers[$index]['process']=$process;
  if (!is_resource($process)) { throw new RuntimeException('organization_worker_spawn_failed'); }
  $worker=$workers[$index];
  $deadline=microtime(true)+5;
  while (!is_file($private.'/ready') && microtime(true)<$deadline && proc_get_status($process)['running']) { usleep(20000); }
  if (!is_file($private.'/ready')) { throw new RuntimeException('organization_worker_not_ready'); }
  return $worker;
}
function organization_cleanup_worker(array &$worker) {
  if ($worker['cleaned']) { return false; }
  if (is_resource($worker['process'])) {
    if (proc_get_status($worker['process'])['running']) { proc_terminate($worker['process']); }
    proc_close($worker['process']); $worker['process']=null;
  }
  $path=$worker['private']; $identity=lstat($path);
  if (is_link($path) || !is_array($identity) || $identity['dev']!==$worker['device'] ||
      $identity['ino']!==$worker['inode'] ||
      !preg_match('/\Agovernance-org-[a-f0-9]{24}\z/',basename($path)) ||
      ($worker['owner_ready'] && (!is_file($path.'/owner') || is_link($path.'/owner') ||
       !hash_equals($worker['nonce'],file_get_contents($path.'/owner'))))) {
    throw new RuntimeException('organization_worker_cleanup_owner_refused'); }
  foreach (glob($path.'/*') as $file) {
    if (is_dir($file) && !is_link($file)) { throw new RuntimeException('organization_worker_cleanup_subdirectory_refused'); }
    if (!unlink($file)) { throw new RuntimeException('organization_worker_cleanup_file_failed'); }
  }
  if (!rmdir($path)) { throw new RuntimeException('organization_worker_cleanup_directory_failed'); }
  $worker['cleaned']=true; return true;
}
function organization_finish_worker(array &$worker) {
  $deadline=microtime(true)+10;
  while (proc_get_status($worker['process'])['running'] && microtime(true)<$deadline) { usleep(20000); }
  if (proc_get_status($worker['process'])['running']) { throw new RuntimeException('organization_worker_timeout'); }
  proc_close($worker['process']); $worker['process']=null;
  if (!is_file($worker['private'].'/result.json')) { throw new RuntimeException('organization_worker_result_missing'); }
  $result=json_decode(file_get_contents($worker['private'].'/result.json'),true,32,JSON_THROW_ON_ERROR);
  if (!is_array($result) || !in_array($result['status'] ?? null,array('applied','replayed','rejected'),true)) {
    throw new RuntimeException('organization_worker_result_refused'); }
  return $result;
}
function organization_wait_query(mysqli $control,$writer,$database,$pattern) {
  $deadline=microtime(true)+4;
  do {
    foreach ($control->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC) as $row) {
      if ($row['User']===$writer && $row['db']===$database && is_string($row['Info']) &&
          preg_match($pattern,$row['Info'])) { return; }
    }
    usleep(20000);
  } while (microtime(true)<$deadline);
  throw new RuntimeException('organization_real_lock_wait_unobserved');
}
function organization_wait_dbtime(mysqli $db,$until) {
  $deadline=microtime(true)+6;
  while ((int)$db->query('SELECT UNIX_TIMESTAMP()')->fetch_row()[0] <= $until) {
    if (microtime(true)>$deadline) { throw new RuntimeException('organization_expiry_wait_timeout'); }
    usleep(50000);
  }
}
try {
  phorge_assert_supported_php_version(PHP_VERSION);
  if (PHP_SAPI!=='cli' || getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1') {
    throw new RuntimeException('organization_fixture_scope_required'); }
  foreach (array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE') as $name) {
    $value=getenv($name); if ($value!==false && $value!=='') {
      throw new RuntimeException('organization_deployment_refused'); }}
  foreach (array('local.json','deployment.json','ENVIRONMENT') as $name) {
    if (file_exists($root.'/conf/local/'.$name)) { throw new RuntimeException('organization_deployment_refused'); }}
  require_once $root.'/support/runtime/bootstrap-cli.php'; phutil_load_library($root.'/src/');
  mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
  if ($worker_mode) {
    $private=$argv[2] ?? null;
    if (count($argv)!==3 || !is_string($private) || is_link($private) ||
        !str_starts_with($private,sys_get_temp_dir().'/governance-org-') ||
        !is_file($private.'/worker.json')) { throw new RuntimeException('organization_worker_scope_refused'); }
    $work=json_decode(file_get_contents($private.'/worker.json'),true,32,JSON_THROW_ON_ERROR);
    if (!hash_equals(file_get_contents($private.'/owner'),$work['owner_nonce'])) {
      throw new RuntimeException('organization_worker_owner_refused'); }
    $method=$work['method'] ?? null;
    if (!in_array($method,array('execute','proposeApproval','approveApproval'),true)) {
      throw new RuntimeException('organization_worker_method_refused'); }
    // Input readiness is not authentication: factory may itself wait on guard.
    file_put_contents($private.'/ready','worker-input-validated-before-factory',LOCK_EX);
    $store=PhorgeGovernanceOrganizationStore::newFixture($work['config']);
    try {
      switch ($method) {
        case 'execute': $result=$store->execute($work['request']); break;
        case 'proposeApproval': $result=$store->proposeApproval($work['request']); break;
        case 'approveApproval': $result=$store->approveApproval($work['request']); break;
      }
      $worker=array('status'=>$method==='execute'?$result['status']:'applied');
    }
    catch (RuntimeException $error) {
      if (!preg_match('/\A(?:organization|approval)_[a-z_]+\z/',$error->getMessage())) { throw $error; }
      $worker=array('status'=>'rejected','reason'=>$error->getMessage()); }
    $store->close(); $store=null;
    file_put_contents($private.'/result.json',json_encode($worker,JSON_THROW_ON_ERROR),LOCK_EX);
    exit(0);
  }
  if (count($argv)!==1) { throw new RuntimeException('organization_arguments_refused'); }
  $dsn=getenv('PHORGE_GOVERNANCE_FIXTURE_DSN');
  PhorgeGovernanceIsolatedDirectoryStore::validateFixtureDSN($dsn);
  $options=array(); foreach (explode(';',substr($dsn,6)) as $part) {
    if ($part!=='') { list($key,$value)=explode('=',$part,2); $options[$key]=$value; }}
  if ($options['host']!=='127.0.0.1') { throw new RuntimeException('organization_loopback_required'); }
  $port=(int)($options['port'] ?? 3306);
  $username=getenv('PHORGE_GOVERNANCE_FIXTURE_USER'); $password=getenv('PHORGE_GOVERNANCE_FIXTURE_PASSWORD');
  if (!is_string($username) || !is_string($password)) { throw new RuntimeException('organization_explicit_credentials_required'); }
  $control=new mysqli('127.0.0.1',$username,$password,'',$port); $control->set_charset('utf8mb4');
  $database='governance_org_'.bin2hex(random_bytes(8)); $nonce=bin2hex(random_bytes(16));
  $stage='create-owned-organization-schema';
  $control->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'); $created=true;
  $setup_user='gov_org_setup_'.bin2hex(random_bytes(6)); $setup_password=bin2hex(random_bytes(24));
  $control->query("CREATE USER '".$setup_user."'@'%' IDENTIFIED BY '".$setup_password."'"); $principals[]=$setup_user;
  $escaped=str_replace('_','\\_',$database);
  $control->query('GRANT ALL PRIVILEGES ON `'.$escaped."`.* TO '".$setup_user."'@'%'");
  $setup=new mysqli('127.0.0.1',$setup_user,$setup_password,$database,$port); $setup->set_charset('utf8mb4');
  $schema=file_get_contents(__DIR__.'/schema.sql');
  $sql=preg_replace('/^--.*$/m','',$schema);
  foreach (array_filter(array_map('trim',explode(';',$sql))) as $statement) { $setup->query($statement); }
  organization_sql($setup,'INSERT INTO fixture_guard VALUES(1,?,1,?,?,1)',
    array('N03.ORG-WRITE.v1:synthetic-only',hash('sha256',$schema),$nonce))->close();
  $guard_initialized=true;
  $writer='gov_org_write_'.bin2hex(random_bytes(6)); $writer_password=bin2hex(random_bytes(24));
  $control->query("CREATE USER '".$writer."'@'%' IDENTIFIED BY '".$writer_password."'"); $principals[]=$writer;
  foreach (array('fixture_guard'=>'SELECT, UPDATE(epoch_version)','org_user'=>'SELECT',
    'org_unit'=>'SELECT, UPDATE(version)','org_member'=>'SELECT, INSERT, UPDATE',
    'org_appointment'=>'SELECT, INSERT, UPDATE','org_operation'=>'SELECT, INSERT',
    'org_audit'=>'SELECT, INSERT','org_approval'=>'SELECT, INSERT, UPDATE') as $table=>$grants) {
    $control->query('GRANT '.$grants.' ON `'.$database.'`.`'.$table."` TO '".$writer."'@'%'"); }
  $config=array('host'=>'127.0.0.1','port'=>$port,'dbname'=>$database,'user'=>$writer,
    'password'=>$writer_password,'owner_nonce'=>$nonce);
  $ro_user='gov_org_read_'.bin2hex(random_bytes(6)); $ro_password=bin2hex(random_bytes(24));
  $control->query("CREATE USER '".$ro_user."'@'%' IDENTIFIED BY '".$ro_password."'"); $principals[]=$ro_user;
  $control->query('GRANT SELECT ON `'.$escaped."`.* TO '".$ro_user."'@'%'");
  $reader_config=$config; $reader_config['user']=$ro_user; $reader_config['password']=$ro_password;
  $now=(int)$setup->query('SELECT UNIX_TIMESTAMP()')->fetch_row()[0];
  $users=array(); foreach (array('sys1','sys2','admin','member','outsider','disabled','target','target2','childadmin','raceone','racetwo','racethree','racefour') as $i=>$role) {
    $users[$role]=organization_phid(chr(97+$i));
    organization_sql($setup,'INSERT INTO org_user VALUES(?,?,1,?,0,0,1)',
      array($users[$role],in_array($role,array('sys1','sys2'),true)?1:0,$role==='disabled'?1:0))->close(); }
  foreach (array(array('dept','department',null,null),array('team-a','team',null,'dept'),
    array('team-b','team',null,'dept'),array('child-a','team','team-a','dept')) as $u) {
    organization_sql($setup,'INSERT INTO org_unit VALUES(?,?,\'active\',?,?,?,1)',
      array($u[0],$u[1],'Synthetic '.$u[0],$u[2],$u[3]))->close(); }
  foreach (array(array('team-a','admin',true),array('team-a','member',false),
    array('team-b','member',false),array('team-a','outsider',false),
    array('team-a','disabled',true),array('child-a','childadmin',true)) as $m) {
    organization_sql($setup,'INSERT INTO org_member VALUES(?,?,\'active\',?,NULL,1)',
      array($m[0],$users[$m[1]],$now-3600))->close();
    if ($m[2]) { organization_sql($setup,'INSERT INTO org_appointment VALUES(?,?,\'team_admin\',\'active\',?,NULL,1)',
      array($m[0],$users[$m[1]],$now-3600))->close(); }}
  $store=PhorgeGovernanceOrganizationStore::newFixture($config);
  $reader=PhorgeGovernanceOrganizationReader::newFixture($reader_config);
  organization_check(true,'organization-writer-and-select-reader-actual-factories');
  $report_mode=(new mysqli_driver())->report_mode;
  $bad_nonce=$config; $bad_nonce['owner_nonce']=str_repeat('0',32);
  organization_reject(fn()=>PhorgeGovernanceOrganizationStore::newFixture($bad_nonce),
    'organization_factory_refused','organization-factory-wrong-owner-nonce-refused');
  $ddl_config=$config; $ddl_config['user']=$setup_user; $ddl_config['password']=$setup_password;
  organization_reject(fn()=>PhorgeGovernanceOrganizationStore::newFixture($ddl_config),
    'organization_factory_refused','organization-factory-extra-ddl-grants-refused');
  organization_check((new mysqli_driver())->report_mode===$report_mode,
    'organization-rejected-factories-preserve-mysqli-report-mode');
  $setup->query('ALTER TABLE org_user MODIFY user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL');
  try {
    organization_reject(fn()=>PhorgeGovernanceOrganizationStore::newFixture($config),
      'organization_factory_refused','organization-factory-physical-case-folding-schema-refused');
    organization_reject(fn()=>$reader->listUnits($users['sys1']),
      'organization_reader_observation_refused','organization-reader-physical-case-folding-schema-refused');
  } finally {
    $setup->query('ALTER TABLE org_user MODIFY user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
  }
  $restored=PhorgeGovernanceOrganizationStore::newFixture($config); $restored->close();
  organization_check((new mysqli_driver())->report_mode===$report_mode,
    'organization-factory-after-collation-restoration-no-report-leak');

  $direct=new mysqli('127.0.0.1',$writer,$writer_password,$database,$port);
  try { foreach (array('DELETE FROM org_member WHERE 1=0','UPDATE org_user SET is_disabled=0 WHERE 1=0',
      'UPDATE fixture_guard SET owner_nonce=owner_nonce WHERE 1=0','UPDATE org_unit SET name=name WHERE 1=0') as $i=>$denied_sql) {
    $denied=false; try { $direct->query($denied_sql); }
    catch (mysqli_sql_exception $error) { $denied=in_array($error->getCode(),array(1142,1143,1044),true); }
    organization_check($denied,'organization-runtime-role-forbidden-write-'.$i); }
  } finally { $direct->close(); }
  $fault_request=organization_request('spawn-fault',$users['admin'],array(
    organization_mutation($setup,'member.add','team-a',$users['target'],$now,null)));
  $spawn_refused=false;
  try { organization_start_worker($config,$fault_request,$nonce,'execute',true); }
  catch (RuntimeException $error) {
    if (!in_array($error->getMessage(),array('organization_worker_spawn_failed',
        'organization_worker_not_ready'),true)) { throw $error; }
    $spawn_refused=true;
  }
  organization_check($spawn_refused,'organization-worker-fixed-spawn-failure-observed');
  $last=count($workers)-1; $fault_path=$workers[$last]['private'];
  organization_check(organization_cleanup_worker($workers[$last]) && !file_exists($fault_path),
    'organization-worker-start-failure-removes-owned-credential-directory');
  $cleanup['private_directories_removed']++;
  $mutation=organization_mutation($setup,'member.add','team-a',$users['target'],$now-10,null);
  $request=organization_request('members-first',$users['admin'],array($mutation));
  $result=$store->execute($request);
  organization_check($result['status']==='applied' && !$result['can_execute'] &&
    !$result['production_execution'],'organization-own-team-member-add-real-commit');
  $member=organization_row($setup,'org_member','team-a',$users['target']);
  organization_check($member['state']==='active' && (int)$member['version']===1,
    'organization-member-readback-real-row');
  $before=organization_fingerprint($setup); $replay=$store->execute($request);
  organization_check($replay['status']==='replayed' && organization_fingerprint($setup)===$before,
    'organization-identical-operation-replay-no-extra-write');
  $alias=$request; $alias['actor']='PHID-USER-'.strtoupper(substr($users['admin'],10));
  organization_reject(fn()=>$store->execute($alias),'organization_account_ineligible',
    'organization-replay-caller-case-alias-not-current-identity');
  $alias=organization_request('team-case-alias',$users['admin'],array($mutation));
  $alias['mutations'][0]['team']='TEAM-A';
  organization_reject(fn()=>$store->execute($alias),'organization_team_unavailable',
    'organization-team-case-alias-not-authorized-team');
  $conflict=$request; $conflict['mutations'][0]['valid_until']=$now+100;
  organization_reject(fn()=>$store->execute($conflict),'organization_operation_conflict',
    'organization-operation-replay-different-payload-refused');
  foreach (array(array('cross-team','admin','team-b'),array('parent-not-child','admin','child-a'),
    array('ordinary-no-appointment','outsider','team-a'),array('disabled-actor','disabled','team-a')) as $negative) {
    $m=organization_mutation($setup,'member.add',$negative[2],$users['target2'],$now-10,null);
    $reason=$negative[1]==='disabled'?'organization_account_ineligible':'organization_actor_forbidden';
    organization_reject(fn()=>$store->execute(organization_request($negative[0],$users[$negative[1]],array($m))),
      $reason,'organization-'.$negative[0].'-refused'); }
  $stale=organization_mutation($setup,'member.update','team-a',$users['target'],$now-10,null);
  $stale['expected_member_version']--;
  organization_reject(fn()=>$store->execute(organization_request('stale-member',$users['admin'],array($stale))),
    'organization_stale_version','organization-stale-member-version-refused');
  foreach (array('member.end','member.suspend','member.update') as $i=>$action) {
    $m=organization_mutation($setup,$action,'team-a',$users['admin'],
      $action==='member.update'?$now-10:null,$action==='member.update'?$now+30:null);
    organization_reject(fn()=>$store->execute(organization_request('protect-appointment-'.$i,$users['sys1'],array($m))),
      'organization_appointment_member_prerequisite','organization-active-appointment-prerequisite-'.$i.'-protected'); }
  $visible=$reader->listUserTeams($users['admin'],$users['member']);
  organization_check(array_column($visible,'unit_id')===array('team-a'),
    'organization-reader-other-team-relations-hidden');
  organization_check($reader->listUnits($users['member'])===array() &&
    $reader->countUnits($users['member'])===0 &&
    $reader->selectUnits($users['member'],'')===array() &&
    $reader->exportUnitsPage($users['member'])===array(),
    'organization-reader-no-self-or-selector-count-export-exception');
  $child=$reader->listUnits($users['childadmin']);
  organization_check(count($child)===1 && $child[0]['unit_id']==='child-a' &&
    $child[0]['parent_id']===null && $child[0]['department_id']===null,
    'organization-reader-hidden-parent-and-department-redacted');
  organization_check($reader->listMembers($users['admin'],'child-a')===array() &&
    $reader->listUnits($users['disabled'])===array(),
    'organization-reader-parent-no-child-inheritance-disabled-no-directory');
  organization_sql($setup,'UPDATE org_appointment SET state=\'ended\',version=version+1 WHERE team_id=? AND user_id=?',
    array('child-a',$users['childadmin']))->close();
  organization_check($reader->listUnits($users['childadmin'])===array(),
    'organization-reader-revoked-appointment-immediately-hidden');
  organization_sql($setup,'UPDATE org_user SET user_id=? WHERE user_id=?',
    array($users['admin'].' ',$users['admin']))->close();
  try {
    organization_check($reader->listUnits($users['admin'])===array() &&
      $reader->listMembers($users['admin'],'team-a')===array(),
      'organization-reader-trailing-pad-actor-not-current-identity');
  } finally { organization_sql($setup,'UPDATE org_user SET user_id=? WHERE BINARY user_id=BINARY ?',
    array($users['admin'],$users['admin'].' '))->close(); }
  organization_sql($setup,'UPDATE org_member SET user_id=? WHERE team_id=? AND user_id=?',
    array($users['member'].' ','team-a',$users['member']))->close();
  try {
    organization_check(!in_array($users['member'].' ',array_column($reader->listMembers($users['admin'],'team-a'),'user_id'),true) &&
      $reader->listUserTeams($users['admin'],$users['member'])===array(),
      'organization-reader-trailing-pad-subject-relation-not-current-fact');
  } finally { organization_sql($setup,'UPDATE org_member SET user_id=? WHERE team_id=? AND BINARY user_id=BINARY ?',
    array($users['member'],'team-a',$users['member'].' '))->close(); }
  organization_sql($setup,'UPDATE org_appointment SET user_id=? WHERE team_id=? AND user_id=?',
    array($users['admin'].' ','team-a',$users['admin']))->close();
  try {
    organization_check($reader->listUnits($users['admin'])===array() &&
      $reader->listMembers($users['admin'],'team-a')===array(),
      'organization-reader-trailing-pad-appointment-not-authority');
  } finally { organization_sql($setup,'UPDATE org_appointment SET user_id=? WHERE team_id=? AND BINARY user_id=BINARY ?',
    array($users['admin'],'team-a',$users['admin'].' '))->close(); }
  $member_version=organization_row($setup,'org_member','team-a',$users['member'])['version'];
  organization_sql($setup,'UPDATE org_member SET version=0 WHERE team_id=? AND user_id=?',
    array('team-a',$users['member']))->close();
  try {
    organization_check(!in_array($users['member'],array_column($reader->listMembers($users['admin'],'team-a'),'user_id'),true) &&
      $reader->listUserTeams($users['admin'],$users['member'])===array(),
      'organization-reader-unknown-subject-membership-version-not-current-fact');
  } finally { organization_sql($setup,'UPDATE org_member SET version=? WHERE team_id=? AND user_id=?',
    array($member_version,'team-a',$users['member']))->close(); }
  $disabled_rows=array_values(array_filter($reader->listMembers($users['admin'],'team-a'),
    fn($row)=>$row['user_id']===$users['disabled']));
  organization_check(count($disabled_rows)===1 && (int)$disabled_rows[0]['account_eligible']===0,
    'organization-reader-disabled-subject-retained-for-admin-audit-not-access');
  $setup->query('ALTER TABLE org_unit ENGINE=MyISAM');
  try { organization_reject(fn()=>$reader->listUnits($users['sys1']),
    'organization_reader_observation_refused','organization-reader-nontransactional-source-refused'); }
  finally { $setup->query('ALTER TABLE org_unit ENGINE=InnoDB'); }
  organization_check(count($reader->listUnits($users['sys1']))===3,
    'organization-reader-after-engine-restoration-real-read');
  $before=organization_fingerprint($setup);
  $a=organization_mutation($setup,'member.add','team-a',$users['target2'],$now-10,null);
  $b=organization_mutation($setup,'member.add','team-a',$users['childadmin'],$now-10,null);
  $trigger='`'.$database.'`.`organization_batch_fault`';
  $setup->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON org_audit FOR EACH ROW BEGIN '.
    "IF NEW.target_id='team-a/".$setup->real_escape_string($users['childadmin'])."' THEN ".
    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic batch fault'; END IF; END");
  try { organization_reject(fn()=>$store->execute(organization_request('fault-batch',$users['admin'],array($a,$b))),
    'organization_write_failed','organization-sql-fault-batch-rejected'); }
  finally { $setup->query('DROP TRIGGER '.$trigger); }
  organization_check(organization_fingerprint($setup)===$before,
    'organization-sql-fault-rolls-back-members-versions-journal-and-audit');
  foreach (array('member.update','member.end','member.add') as $i=>$action) {
    $m=organization_mutation($setup,$action,'team-a',$users['target'],
      $action==='member.end'?null:$now-20,$action==='member.update'?$now+300:null);
    $result=$store->execute(organization_request('positive-member-lifecycle-'.$i,$users['admin'],array($m)));
    organization_check($result['status']==='applied','organization-member-lifecycle-'.$action.'-committed');
  }
  $p=organization_proposal('approval-grant','grant-target',$users['sys1'],'appointment.grant',
    'team-a',$users['target'],$now-10,null);
  $proposed=$store->proposeApproval($p);
  organization_check($proposed['state']==='submitted' && !$proposed['native_account_freshness_proved'],
    'organization-approval-proposal-real-synthetic-source-not-native-authority');
  organization_reject(fn()=>$store->approveApproval(array('actor'=>$users['sys1'],'approval_id'=>$p['approval_id'])),
    'approval_independent_admin_required','organization-approval-self-approval-refused');
  organization_reject(fn()=>$store->approveApproval(array('actor'=>$users['admin'],'approval_id'=>$p['approval_id'])),
    'organization_actor_forbidden','organization-team-admin-cannot-approve-appointment');
  organization_reject(fn()=>$store->approveApproval(array(
    'actor'=>'PHID-USER-'.strtoupper(substr($users['sys1'],10)),'approval_id'=>$p['approval_id'])),
    'organization_account_ineligible','organization-requester-case-alias-not-independent-approver');
  organization_reject(fn()=>$store->approveApproval(array(
    'actor'=>'PHID-USER-'.strtoupper(substr($users['sys2'],10)),'approval_id'=>$p['approval_id'])),
    'organization_account_ineligible','organization-approver-case-alias-not-current-identity');
  organization_reject(fn()=>$store->approveApproval(array('actor'=>$users['sys2'],
    'approval_id'=>strtoupper($p['approval_id']))),'approval_not_available',
    'organization-approval-case-alias-does-not-reference-existing-ticket');
  $approved=$store->approveApproval(array('actor'=>$users['sys2'],'approval_id'=>$p['approval_id']));
  organization_check($approved['state']==='approved','organization-independent-sysadmin-approval-stored');
  $before=organization_fingerprint($setup);
  $again=$store->proposeApproval($p);
  $approve_again=$store->approveApproval(array('actor'=>$users['sys2'],'approval_id'=>$p['approval_id']));
  organization_check($again['replayed'] && $approve_again['replayed'] &&
    organization_fingerprint($setup)===$before,'organization-approval-replays-no-extension-or-new-audit');

  $grant=organization_request('grant-target',$users['sys1'],array(organization_mutation($setup,
    'appointment.grant','team-a',$users['target'],$now-10,null,$p['approval_id'])));
  $before=organization_fingerprint($setup);
  $setup->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON org_audit FOR EACH ROW BEGIN '.
    "IF NEW.action='appointment.grant' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic consume fault'; END IF; END");
  try { organization_reject(fn()=>$store->execute($grant),'organization_write_failed',
    'organization-approval-consume-audit-fault-refused'); }
  finally { $setup->query('DROP TRIGGER '.$trigger); }
  organization_check(organization_fingerprint($setup)===$before,
    'organization-approval-consume-target-audit-journal-all-rollback');
  $result=$store->execute($grant);
  $ticket=organization_sql($setup,'SELECT state,consumed_by_operation FROM org_approval WHERE approval_id=?',
    array($p['approval_id']))->get_result()->fetch_assoc();
  organization_check($result['status']==='applied' && $ticket['state']==='consumed' &&
    $ticket['consumed_by_operation']==='grant-target' &&
    organization_row($setup,'org_appointment','team-a',$users['target'])['state']==='active',
    'organization-appointment-and-approval-consume-atomic-commit');
  $before=organization_fingerprint($setup); $result=$store->execute($grant);
  organization_check($result['status']==='replayed' && organization_fingerprint($setup)===$before,
    'organization-sensitive-operation-idempotent-no-second-consume');
  $reused=organization_request('reused-consumed-ticket',$users['sys1'],array(organization_mutation($setup,
    'appointment.revoke','team-a',$users['target'],null,null,$p['approval_id'])));
  organization_reject(fn()=>$store->execute($reused),'organization_write_failed',
    'organization-consumed-approval-cannot-authorize-new-operation');
  foreach (array('expired','stale-subject','revoked-approver') as $case) {
    $p=organization_proposal('approval-'.$case,'revoke-'.$case,$users['sys1'],
      'appointment.revoke','team-a',$users['target'],null,null,$case==='expired'?2:120);
    $store->proposeApproval($p); $store->approveApproval(array('actor'=>$users['sys2'],'approval_id'=>$p['approval_id']));
    if ($case==='expired') {
      $row=organization_sql($setup,'SELECT binding_json FROM org_approval WHERE approval_id=?',array($p['approval_id']))->get_result()->fetch_assoc();
      organization_wait_dbtime($setup,json_decode($row['binding_json'],true)['expires_at']);
    } else if ($case==='stale-subject') {
      organization_sql($setup,'UPDATE org_user SET version=version+1 WHERE user_id=?',array($users['target']))->close();
    } else {
      organization_sql($setup,'UPDATE org_user SET is_admin=0,version=version+1 WHERE user_id=?',array($users['sys2']))->close();
    }
    try {
      $request=organization_request($p['operation_id'],$users['sys1'],array(organization_mutation($setup,
        'appointment.revoke','team-a',$users['target'],null,null,$p['approval_id'])));
      $before=organization_fingerprint($setup);
      organization_reject(fn()=>$store->execute($request),'organization_write_failed',
        'organization-approval-'.$case.'-consume-refused');
      organization_check(organization_fingerprint($setup)===$before,
        'organization-approval-'.$case.'-keeps-ticket-and-target-unchanged');
    } finally { if ($case==='revoked-approver') {
      organization_sql($setup,'UPDATE org_user SET is_admin=1,version=version+1 WHERE user_id=?',array($users['sys2']))->close(); }}
  }
  organization_sql($setup,'UPDATE org_user SET is_disabled=1,version=version+1 WHERE user_id=?',array($users['target']))->close();
  $p=organization_proposal('approval-disabled-revoke','disabled-revoke',$users['sys1'],
    'appointment.revoke','team-a',$users['target'],null,null);
  $store->proposeApproval($p); $store->approveApproval(array('actor'=>$users['sys2'],'approval_id'=>$p['approval_id']));
  $store->execute(organization_request($p['operation_id'],$users['sys1'],array(organization_mutation($setup,
    'appointment.revoke','team-a',$users['target'],null,null,$p['approval_id']))));
  $store->execute(organization_request('disabled-member-end',$users['admin'],array(organization_mutation($setup,
    'member.end','team-a',$users['target']))));
  organization_check(organization_row($setup,'org_appointment','team-a',$users['target'])['state']==='ended' &&
    organization_row($setup,'org_member','team-a',$users['target'])['state']==='ended',
    'organization-disabled-subject-can-be-revoked-with-history-retained');
  $additions=array(); foreach (array('target2','childadmin','raceone','racetwo') as $role) {
    $additions[]=organization_mutation($setup,'member.add','team-a',$users[$role],$now-20,null); }
  $store->execute(organization_request('positive-member-batch',$users['admin'],$additions));
  organization_check(organization_row($setup,'org_member','team-a',$users['target2'])['state']==='active' &&
    organization_row($setup,'org_member','team-a',$users['childadmin'])['state']==='active',
    'organization-real-multi-member-batch-commit');
  $make_approved=function($approval,$operation,$target,$ttl=120) use ($store,$users,$now) {
    $p=organization_proposal($approval,$operation,$users['sys1'],'appointment.grant','team-a',$target,$now,null,$ttl);
    $store->proposeApproval($p); $store->approveApproval(array('actor'=>$users['sys2'],'approval_id'=>$approval));
    return $p;
  };
  $batch=array(); foreach (array('target2','childadmin') as $i=>$role) {
    $p=$make_approved('approval-multi-'.$i,'sensitive-batch',$users[$role]);
    $batch[]=organization_mutation($setup,'appointment.grant','team-a',$users[$role],$now,null,$p['approval_id']); }
  $store->execute(organization_request('sensitive-batch',$users['sys1'],$batch));
  $states=organization_sql($setup,'SELECT state FROM org_approval WHERE operation_id=?',array('sensitive-batch'))
    ->get_result()->fetch_all(MYSQLI_ASSOC);
  organization_check(count($states)===2 && array_column($states,'state')===array('consumed','consumed') &&
    organization_row($setup,'org_appointment','team-a',$users['target2'])['state']==='active' &&
    organization_row($setup,'org_appointment','team-a',$users['childadmin'])['state']==='active',
    'organization-two-sensitive-approvals-and-appointments-atomic-commit');
  $batch=array(); foreach (array('raceone','racetwo') as $i=>$role) {
    $p=$make_approved('approval-sensitive-fault-'.$i,'sensitive-fault-batch',$users[$role]);
    $batch[]=organization_mutation($setup,'appointment.grant','team-a',$users[$role],$now,null,$p['approval_id']); }
  $before=organization_fingerprint($setup);
  $setup->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON org_audit FOR EACH ROW BEGIN '.
    "IF NEW.target_id='team-a/".$setup->real_escape_string($users['racetwo'])."' THEN ".
    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic multi consume fault'; END IF; END");
  try { organization_reject(fn()=>$store->execute(organization_request('sensitive-fault-batch',$users['sys1'],$batch)),
    'organization_write_failed','organization-sensitive-batch-second-audit-fault-rejected'); }
  finally { $setup->query('DROP TRIGGER '.$trigger); }
  organization_check(organization_fingerprint($setup)===$before,
    'organization-sensitive-batch-both-consumes-and-targets-full-rollback');
  $batch=array(); $expires=null;
  foreach (array('raceone','racetwo') as $i=>$role) {
    $p=$make_approved('approval-expired-batch-'.$i,'expired-sensitive-batch',$users[$role],$i===1?2:120);
    $batch[]=organization_mutation($setup,'appointment.grant','team-a',$users[$role],$now,null,$p['approval_id']);
    if ($i===1) { $row=organization_sql($setup,'SELECT binding_json FROM org_approval WHERE approval_id=?',array($p['approval_id']))->get_result()->fetch_assoc();
      $expires=json_decode($row['binding_json'],true)['expires_at']; }
  }
  organization_wait_dbtime($setup,$expires); $before=organization_fingerprint($setup);
  organization_reject(fn()=>$store->execute(organization_request('expired-sensitive-batch',$users['sys1'],$batch)),
    'organization_write_failed','organization-sensitive-batch-second-expired-ticket-rejected');
  organization_check(organization_fingerprint($setup)===$before,
    'organization-sensitive-batch-first-consume-rollback-on-second-expiry');
  foreach (array('approver-row','audit-gap') as $i=>$wait_case) {
    $operation='ttl-wait-'.$wait_case;
    $p=$make_approved('approval-'.$operation,$operation,$users['raceone'],2);
    $row=organization_sql($setup,'SELECT binding_json FROM org_approval WHERE approval_id=?',array($p['approval_id']))->get_result()->fetch_assoc();
    $expires=json_decode($row['binding_json'],true)['expires_at'];
    $request=organization_request($operation,$users['sys1'],array(organization_mutation($setup,
      'appointment.grant','team-a',$users['raceone'],$now,null,$p['approval_id'])));
    $before=organization_fingerprint($setup);
    $setup->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $setup->begin_transaction();
    try {
      if ($wait_case==='approver-row') {
        organization_sql($setup,'SELECT user_id FROM org_user WHERE user_id=? FOR UPDATE',array($users['sys2']))->close();
      } else {
        organization_sql($setup,'SELECT id FROM org_audit FORCE INDEX(operation_id) WHERE operation_id=? FOR UPDATE',array($operation))->close();
      }
      $worker=organization_start_worker($config,$request,$nonce);
      organization_wait_query($control,$writer,$database,$wait_case==='approver-row'?
        '/\bFROM\s+org_user\b.*FOR SHARE/is':'/\AINSERT\s+INTO\s+org_audit\b/is');
      organization_check(true,'organization-real-'.$wait_case.'-lock-wait-observed');
      organization_wait_dbtime($control,$expires); $setup->commit();
      $reply=organization_finish_worker($worker);
      organization_check($reply['status']==='rejected' && $reply['reason']==='organization_write_failed',
        'organization-approval-'.$wait_case.'-expiry-after-wait-rejected');
    } finally { $setup->rollback(); }
    organization_check(organization_fingerprint($setup)===$before,
      'organization-approval-'.$wait_case.'-expiry-all-effects-rolled-back');
  }
  foreach (array('propose','approve') as $phase) {
    $id='audit-ttl-'.$phase;
    $p=organization_proposal($id,'audit-ttl-business-'.$phase,$users['sys1'],
      'appointment.grant','team-a',$users['raceone'],$now,null,2);
    if ($phase==='approve') { $store->proposeApproval($p); }
    $before=organization_fingerprint($setup);
    $audit_operation='approval-'.$phase.':'.hash('sha256',$id);
    $setup->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $setup->begin_transaction();
    try {
      organization_sql($setup,'SELECT id FROM org_audit FORCE INDEX(operation_id) WHERE operation_id=? FOR UPDATE',
        array($audit_operation))->close();
      $request=$phase==='propose'?$p:array('actor'=>$users['sys2'],'approval_id'=>$id);
      $worker=organization_start_worker($config,$request,$nonce,
        $phase==='propose'?'proposeApproval':'approveApproval');
      organization_wait_query($control,$writer,$database,'/\AINSERT\s+INTO\s+org_audit\b/is');
      organization_check(true,'organization-'.$phase.'-audit-gap-real-wait-observed');
      // At the blocked INSERT the adapter has already assigned its DB-clock TTL.
      $wait_until=(int)$control->query('SELECT UNIX_TIMESTAMP()')->fetch_row()[0]+3;
      organization_wait_dbtime($control,$wait_until); $setup->commit();
      $reply=organization_finish_worker($worker);
      organization_check($reply['status']==='rejected' && $reply['reason']==='approval_expired_or_invalid_time',
        'organization-'.$phase.'-audit-gap-final-expiry-refused');
    } finally { $setup->rollback(); }
    organization_check(organization_fingerprint($setup)===$before,
      'organization-'.$phase.'-audit-gap-ticket-state-and-audit-fully-rolled-back');
  }
  foreach (array('guard','identity') as $wait_case) {
    $expires=(int)$setup->query('SELECT UNIX_TIMESTAMP()')->fetch_row()[0]+2;
    organization_sql($setup,'UPDATE org_appointment SET valid_until=?,version=version+1 WHERE team_id=? AND user_id=?',
      array($expires,'team-a',$users['admin']))->close();
    $request=organization_request('actor-expiry-'.$wait_case,$users['admin'],array(organization_mutation($setup,
      'member.add','team-a',$users['racethree'],$now,null)));
    $before=organization_fingerprint($setup); $setup->begin_transaction();
    try {
      if ($wait_case==='guard') { $setup->query('SELECT id FROM fixture_guard WHERE id=1 FOR UPDATE')->fetch_all(); }
      else { organization_sql($setup,'SELECT user_id FROM org_user WHERE user_id=? FOR UPDATE',array($users['admin']))->close(); }
      $worker=organization_start_worker($config,$request,$nonce);
      organization_wait_query($control,$writer,$database,$wait_case==='guard'?
        '/\bFROM\s+fixture_guard\b.*FOR UPDATE/is':'/\bFROM\s+org_user\b.*FOR SHARE/is');
      organization_check(true,'organization-real-actor-'.$wait_case.'-wait-observed');
      organization_wait_dbtime($control,$expires); $setup->commit();
      $reply=organization_finish_worker($worker);
      organization_check($reply['status']==='rejected' && $reply['reason']==='organization_actor_forbidden',
        'organization-actor-'.$wait_case.'-expired-after-lock-wait-refused');
    } finally { $setup->rollback(); }
    organization_check(organization_fingerprint($setup)===$before,
      'organization-actor-'.$wait_case.'-expiry-no-partial-effects');
    organization_sql($setup,'UPDATE org_appointment SET valid_until=NULL,version=version+1 WHERE team_id=? AND user_id=?',
      array('team-a',$users['admin']))->close();
  }
  foreach (array('same-payload','different-payload','same-version') as $race) {
    $first=organization_request('race-'.$race,$users['sys1'],array(organization_mutation($setup,
      'member.add','team-b',$users['racethree'],$now,null)));
    $second=$first;
    if ($race!=='same-payload') { $second['mutations'][0]['target_user']=$users['racefour']; }
    if ($race==='same-version') { $second['operation_id']='race-distinct-operation'; }
    $setup->begin_transaction();
    try {
      $setup->query('SELECT id FROM fixture_guard WHERE id=1 FOR UPDATE')->fetch_all();
      $one=organization_start_worker($config,$first,$nonce);
      $two=organization_start_worker($config,$second,$nonce);
      $setup->commit();
      $a=organization_finish_worker($one); $b=organization_finish_worker($two);
      $states=array($a['status'],$b['status']); sort($states);
      organization_check($states===array('applied',$race==='same-payload'?'replayed':'rejected'),
        'organization-real-concurrency-'.$race.'-linearized-once');
      if ($race!=='same-payload') {
        $rejected=$a['status']==='rejected'?$a:$b;
        organization_check($rejected['reason']===($race==='different-payload'?
          'organization_operation_conflict':'organization_stale_version'),
          'organization-real-concurrency-'.$race.'-expected-refusal');
      }
    } finally { $setup->rollback(); }
    // Reset only synthetic race memberships between independent scenarios.
    organization_sql($setup,'DELETE FROM org_member WHERE team_id=? AND user_id IN (?,?)',
      array('team-b',$users['racethree'],$users['racefour']))->close();
  }

} catch (Throwable $error) {
  $failure=array('stage'=>$stage,'error_type'=>get_class($error),
    'source_file'=>basename($error->getFile()),'source_line'=>$error->getLine());
  if ($error instanceof RuntimeException && preg_match('/\Aorganization_[a-z_]+\z/',$error->getMessage())) {
    $failure['reason']=$error->getMessage(); }
} finally {
  if ($store!==null) {
    try { $store->close(); }
    catch (Throwable $error) { $cleanup_failures[]=array('stage'=>'writer-close','error_type'=>get_class($error)); }
  }
  if ($reader!==null && !$reader->close()) {
    $cleanup_failures[]=array('stage'=>'reader-close','error_type'=>'RuntimeException');
  }
  if ($worker_mode) { if ($failure!==null && is_string($private) && is_dir($private)) {
      file_put_contents($private.'/result.json',json_encode(array('status'=>'failed','failure'=>$failure)),LOCK_EX); }
  } else {
    foreach ($workers as &$worker) {
      try {
        if (organization_cleanup_worker($worker)) { $cleanup['private_directories_removed']++; }
      } catch (Throwable $error) { $cleanup_failures[]=array('stage'=>'worker-cleanup','error_type'=>get_class($error)); }
    }
    unset($worker);
    if ($setup!==null) { $setup->close(); }
    if ($control!==null && $created) {
      try {
        $control->select_db($database);
        if ($guard_initialized) {
          $row=$control->query('SELECT owner_nonce FROM fixture_guard WHERE id=1')->fetch_assoc();
          if (!$row || !hash_equals($nonce,$row['owner_nonce'])) { throw new RuntimeException('organization_cleanup_owner_refused'); }
        }
        $control->query('DROP DATABASE `'.$database.'`'); $cleanup['owned_schema_removed']=true;
        foreach ($principals as $principal) { $control->query("DROP USER '".$principal."'@'%'"); $cleanup['principals_removed']++; }
        $checks[]='organization-only-owned-schema-and-three-principals-cleaned';
      } catch (Throwable $error) { $cleanup_failures[]=array('stage'=>'cleanup','error_type'=>get_class($error)); }
    }
    if ($control!==null) { $control->close(); }
  }
}
if (!$worker_mode) {
  $passed=$failure===null && !$cleanup_failures;
  echo json_encode(array('passed'=>$passed,'checks'=>$checks,'check_count'=>count($checks),
    'failure'=>$failure,'cleanup_failures'=>$cleanup_failures,'cleanup'=>$cleanup,'schema_sha256'=>hash_file('sha256',__DIR__.'/schema.sql'),
    'scope'=>'owned-synthetic-organization-integration','identity_source'=>'synthetic-native-account-projection-not-production-authority',
    'production_execution'=>false,'optional_features_enabled'=>false,'native_permission_axes_changed'=>false),
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
  exit($passed?0:1);
}
exit(1);
