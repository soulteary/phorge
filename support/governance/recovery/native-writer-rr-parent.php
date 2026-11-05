<?php
require_once __DIR__.'/native-writer-rr-protocol.php';
require_once __DIR__.'/organization-grant-fencer-mdl.php';
require_once __DIR__.'/organization-grant-mdl-cutpoint-diagnostic.php';
require_once __DIR__.'/organization-grant-mdl-acquisition-diagnostic.php';
require_once __DIR__.'/organization-grant-mdl-v3.php';

/** Only a genuinely not-yet-visible request may be retried. */
function governance_native_rr_pending_state(array$tickets,$revokerID){
  if(!governance_grant_cutpoint_uint($revokerID)||$revokerID==='0'||count($tickets)>64){throw new RuntimeException('native_rr_pending_shape_unknown');}
  $known=array('SHARED','SHARED_HIGH_PRIO','SHARED_READ','SHARED_UPGRADABLE','SHARED_READ_ONLY','SHARED_NO_WRITE','SHARED_WRITE','SHARED_WRITE_LOW_PRIO','SHARED_NO_READ_WRITE','EXCLUSIVE');$seen=array();$pending=array();
  foreach($tickets as$row){
    if(!is_array($row)||array_keys($row)!==array('OBJECT_INSTANCE_BEGIN','LOCK_TYPE','LOCK_DURATION','LOCK_STATUS','OWNER_THREAD_ID','OWNER_EVENT_ID','PROCESSLIST_ID')||!in_array($row['LOCK_TYPE'],$known,true)||!in_array($row['LOCK_DURATION'],array('TRANSACTION','STATEMENT','EXPLICIT'),true)||!in_array($row['LOCK_STATUS'],array('GRANTED','PENDING'),true)){throw new RuntimeException('native_rr_pending_shape_unknown');}
    foreach(array('OBJECT_INSTANCE_BEGIN','OWNER_THREAD_ID','OWNER_EVENT_ID','PROCESSLIST_ID')as$key){if(!governance_grant_cutpoint_uint($row[$key])||$row[$key]==='0'){throw new RuntimeException('native_rr_pending_shape_unknown');}}
    if(isset($seen[$row['OBJECT_INSTANCE_BEGIN']])){throw new RuntimeException('native_rr_pending_duplicate');}$seen[$row['OBJECT_INSTANCE_BEGIN']]=true;if($row['LOCK_STATUS']==='PENDING'){$pending[]=$row;}
  }
  if(count($pending)===0){return false;}
  if(count($pending)!==1||$pending[0]['PROCESSLIST_ID']!==$revokerID||$pending[0]['LOCK_TYPE']!=='SHARED_READ_ONLY'){throw new RuntimeException('native_rr_pending_ambiguous');}return true;
}
function governance_native_rr_wait_pending(callable$readiness,callable$proof,callable$clock,callable$pause){
  $last=null;$now=static function()use($clock,&$last){$v=$clock();if(!is_int($v)||$v<0||($last!==null&&$v<$last)){throw new RuntimeException('native_rr_pending_clock_unknown');}$last=$v;return$v;};
  $start=$now();if($start>PHP_INT_MAX-4000000000){throw new RuntimeException('native_rr_pending_clock_unknown');}$deadline=$start+4000000000;$finished=null;
  for($i=0;$i<17;$i++){
    $at=$now();if($at>=$deadline){throw new RuntimeException('native_rr_pending_deadline');}if($finished!==null&&$at-$finished<250000000){throw new RuntimeException('native_rr_pending_idle_unproved');}
    $ready=$readiness();if(!is_bool($ready)){throw new RuntimeException('native_rr_pending_shape_unknown');}$finished=$now();if($finished>=$deadline){throw new RuntimeException('native_rr_pending_deadline');}
    if($ready){$result=$proof();if($now()>=$deadline){throw new RuntimeException('native_rr_pending_proof_late');}if(!is_array($result)||!$result){throw new RuntimeException('native_rr_pending_proof_unknown');}return$result;}
    $pause(250000);
  }
  throw new RuntimeException('native_rr_pending_deadline');
}

/** Missing baseline is unknown even if a later read is empty. */
function governance_native_rr_sessions_ended($before,$after){
  if(!is_array($before)||!is_array($after)||count($before)>64||count($after)>64){return false;}
  foreach(array($before,$after)as$rows){foreach($rows as$key=>$row){
    if(!is_array($row)||array_keys($row)!==array('thread','process','principal')||!governance_grant_cutpoint_uint($row['thread'])||$row['thread']==='0'||!governance_grant_cutpoint_uint($row['process'])||$row['process']==='0'||$key!==$row['thread'].':'.$row['process']||!is_string($row['principal'])||!preg_match('/\Agov_c_(?:writer_[a-f0-9]{12}|metadata_[a-f0-9]{16})\z/D',$row['principal'])){return false;}
  }}
  foreach($after as$key=>$row){if(!isset($before[$key])||$before[$key]!==$row){return false;}}return true;
}

/** Fixed single-child owner. Unknown ownership retains evidence, never bare-PID signaling. */
final class GovernanceNativeRRWorker {
  // Actual clean 9a010 Linux/arm64 build and self-pidfd probe, not a TERM/KILL proof.
  private const OWNER_BINARY_SHA256='b4656c9c5cd11a0ccede60eee41ee3f22cc6c3f9f9f97908b0187cc3547c74b4';
  private $ciProfile;private $directory;private $stat;private $owner;private $process;private $exitCode;
  private $config;private $control;private $baseline;private $identity;private $ready;private $closed=false;
  private function __construct(){}
  private function __clone(){}
  public function __serialize(){throw new RuntimeException('native_rr_parent_serialization_refused');}
  public function __unserialize(array$data){throw new RuntimeException('native_rr_parent_serialization_refused');}
  public function isClosed(){return$this->closed;}
  public function __debugInfo(){return array('scope'=>'owned-native-RR-child-no-permit');}
  public static function create(mysqli$control){
    if(self::OWNER_BINARY_SHA256===null){throw new RuntimeException('native_rr_owner_artifact_not_pinned');}
    if(realpath(dirname(__DIR__,3))!=='/tmp/governance-src'){throw new RuntimeException('native_rr_fixed_runtime_root_refused');}
    $self=new self();$profile=getenv('PHORGE_GOVERNANCE_NATIVE_RR_EXECUTION_PROFILE');$ci=getenv('PHORGE_GOVERNANCE_NATIVE_RR_CI_CONTROL');
    if($profile!==false&&$profile!==''){if($profile!=='isolated-native-RR21-linux-amd64-v1'){throw new RuntimeException('native_rr_execution_profile_unknown');}require_once __DIR__.'/native-writer-rr-ci-profile.php';$self->ciProfile=GovernanceNativeRRCIProfile::fromEnvironment();}
    elseif($ci!==false&&$ci!==''){throw new RuntimeException('native_rr_unselected_CI_control_refused');}
    $self->control=$control;$self->owner=bin2hex(random_bytes(32));$self->directory=sys_get_temp_dir().'/governance-native-rr-mdl-'.bin2hex(random_bytes(12));
    if(!mkdir($self->directory,0700)){throw new RuntimeException('native_rr_parent_directory_failed');}$self->stat=lstat($self->directory);
    $GLOBALS['governance_native_rr_workers'][]=$self;
    governance_native_rr_write($self->directory,'owner',$self->owner,$self->owner,$self->stat['dev'],$self->stat['ino']);
    $self->ownerCommand('probe');return$self;
  }
  private function owned(){governance_native_rr_directory($this->directory,$this->stat['dev'],$this->stat['ino']);if(!hash_equals($this->owner,governance_native_rr_read($this->directory,'owner',64))){throw new RuntimeException('native_rr_parent_owner_changed');}}
  private function ownerCommand($action){
    $this->owned();$php=PHP_BINARY;
    if($this->ciProfile){$binary=$this->ciProfile->getBinary();$php=$this->ciProfile->getPHP();}
    else{$binary='/tmp/governance-bin/native-rr-process-owner';
      if(!is_string(self::OWNER_BINARY_SHA256)||is_link($binary)||!is_file($binary)||!is_executable($binary)||!hash_equals(self::OWNER_BINARY_SHA256,hash_file('sha256',$binary))){throw new RuntimeException('native_rr_owner_artifact_not_pinned');}
      if(!in_array(PHP_BINARY,array('/usr/local/bin/php','/usr/bin/php'),true)){throw new RuntimeException('native_rr_php_binary_profile_refused');}}
    $request=array('action'=>$action,'directory'=>$this->directory,'owner'=>$this->owner,'dev'=>$this->stat['dev'],'ino'=>$this->stat['ino'],'targets'=>$action==='probe'?array():array(array('binary'=>$php,'args'=>array('/tmp/governance-src/support/governance/recovery/native-writer-rr-worker.php',$this->directory))));
    $pipes=array();$process=proc_open(array($binary),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,$this->directory,array('PATH'=>'/usr/bin:/bin'));
    if(!is_resource($process)){throw new RuntimeException('native_rr_owner_start_failed');}
    fwrite($pipes[0],json_encode($request,JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1],4097);$err=stream_get_contents($pipes[2],4097);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    if($code!==0||$err!==''||strlen($out)>4096){throw new RuntimeException('native_rr_owner_cleanup_unproved');}$value=json_decode($out,true,8,JSON_THROW_ON_ERROR);
    if($action==='probe'){if($value!==array('passed'=>true,'pidfd_supported'=>true)){throw new RuntimeException('native_rr_owner_probe_unproved');}}
    else{if(!is_array($value)||($value['passed']??null)!==true||($value['remaining_owned']??null)!==0){throw new RuntimeException('native_rr_owner_cleanup_unproved');}foreach(array('matched_handles','signaled_term','signaled_kill')as$key){if(!is_int($value[$key]??null)||$value[$key]<0||$value[$key]>8){throw new RuntimeException('native_rr_owner_cleanup_unproved');}}}
    return$value;
  }
  private function sessionSet(){
    $writer=$this->config['writer']['user'];$meta=$this->config['observer']['user'];
    $rows=governance_recovery_grant_mdl_rows($this->control,"SELECT CAST(THREAD_ID AS CHAR) thread,CAST(PROCESSLIST_ID AS CHAR) process,PROCESSLIST_USER principal FROM performance_schema.threads WHERE PROCESSLIST_USER IN ('".$writer."','".$meta."') ORDER BY THREAD_ID LIMIT 65");
    if(count($rows)>64){throw new RuntimeException('native_rr_session_budget_unknown');}$result=array();foreach($rows as$row){if(!governance_grant_cutpoint_uint($row['thread'])||!governance_grant_cutpoint_uint($row['process'])||!in_array($row['principal'],array($writer,$meta),true)){throw new RuntimeException('native_rr_session_identity_unknown');}$result[$row['thread'].':'.$row['process']]=$row;}return$result;
  }
  public function start(array$config,$fixtureNonce,$subjectPhid,$ending,$variant){
    $this->owned();if($this->process!==null||$this->config!==null){throw new RuntimeException('native_rr_child_reentry_refused');}$this->config=$config;
    foreach(array('writer','observer')as$role){if(!is_array($config[$role]??null)||!preg_match($role==='writer'?'/\Agov_c_writer_[a-f0-9]{12}\z/D':'/\Agov_c_metadata_[a-f0-9]{16}\z/D',$config[$role]['user']??'')){throw new RuntimeException('native_rr_parent_config_refused');}}
    $this->baseline=$this->sessionSet();$revision=getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION');$root=dirname(__DIR__,3);
    $payload=array('directory_dev'=>$this->stat['dev'],'directory_ino'=>$this->stat['ino'],'owner_token'=>$this->owner,'writer'=>$config['writer'],'observer'=>$config['observer'],'metadata_owner'=>$config['metadata_owner'],'source_revision'=>$revision,'quickstart_sha256'=>hash_file('sha256',$root.'/resources/sql/quickstart.sql'),'classmap_sha256'=>hash_file('sha256',$root.'/src/__phutil_library_map__.php'),'fixture_nonce'=>$fixtureNonce,'incarnation'=>bin2hex(random_bytes(16)),'subject_phid'=>$subjectPhid,'ending'=>$ending,'variant'=>$variant);
    governance_native_rr_write($this->directory,'payload.json',$payload,$this->owner,$this->stat['dev'],$this->stat['ino']);
    $logs=array();foreach(array('stdout.log','stderr.log')as$name){$logs[]=fopen($this->directory.'/'.$name,'x');if(!is_resource(end($logs))||!chmod($this->directory.'/'.$name,0600)){throw new RuntimeException('native_rr_log_open_failed');}}
    $php=$this->ciProfile?$this->ciProfile->getPHP():PHP_BINARY;
    try{$this->process=proc_open(array($php,$root.'/support/governance/recovery/native-writer-rr-worker.php',$this->directory),array(0=>array('file','/dev/null','r'),1=>$logs[0],2=>$logs[1]),$pipes,$this->directory,array('PATH'=>getenv('PATH')?:'/usr/bin:/bin','PHORGE_GOVERNANCE_ISOLATED_TEST'=>'1','PHORGE_GOVERNANCE_NATIVE_RR_OWNER'=>$this->owner,'PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION'=>$revision));}finally{foreach($logs as$log){if(is_resource($log)){fclose($log);}}}
    if(!is_resource($this->process)){throw new RuntimeException('native_rr_child_start_failed');}
    $deadline=hrtime(true)+12000000000;while(!is_file($this->directory.'/ready.json')&&hrtime(true)<$deadline&&$this->running()){usleep(20000);}
    if(!is_file($this->directory.'/identity.json')||!is_file($this->directory.'/ready.json')){throw new RuntimeException('native_rr_child_ready_unproved');}
    $this->identity=json_decode(governance_native_rr_read($this->directory,'identity.json'),true,16,JSON_THROW_ON_ERROR);$this->ready=json_decode(governance_native_rr_read($this->directory,'ready.json'),true,16,JSON_THROW_ON_ERROR);
    $server=governance_recovery_grant_mdl_rows($this->control,'SELECT @@server_uuid uuid');$r=$this->ready['role']??null;
    if(($this->identity['owner']??null)!==$this->owner||($this->ready['owner']??null)!==$this->owner||($this->identity['source_revision']??null)!==$revision||($this->ready['source_revision']??null)!==$revision||!is_array($r)||count($server)!==1||$r['uuid']!==$server[0]['uuid']||$r['id']!==($this->identity['writer']['id']??null)||$r['principal']!==$config['writer']['user'].'@%'||$r['db']!==$config['writer']['namespace'].'_user'||($this->ready['subject_phid']??null)!==$subjectPhid||($this->ready['ending']??null)!==$ending){throw new RuntimeException('native_rr_child_identity_unproved');}
    $this->readIdentity();
  }
  private function running(){if(!is_resource($this->process)){return false;}$status=proc_get_status($this->process);if(!$status['running']&&$status['exitcode']>=0){$this->exitCode=$status['exitcode'];}return$status['running'];}
  private function readIdentity(){
    $this->owned();$r=$this->ready['role'];$id=$r['id'];if(!governance_grant_cutpoint_uint($id)||$id==='0'){throw new RuntimeException('native_rr_parent_role_unknown');}
    $threads=governance_recovery_grant_mdl_rows($this->control,'SELECT PROCESSLIST_USER,PROCESSLIST_DB FROM performance_schema.threads WHERE PROCESSLIST_ID='.$id.' LIMIT 2');
    if(count($threads)!==1||$threads[0]['PROCESSLIST_USER']!==$this->config['writer']['user']||$threads[0]['PROCESSLIST_DB']!==$r['db']){throw new RuntimeException('native_rr_parent_role_unknown');}
    $tx=governance_recovery_grant_mdl_rows($this->control,'SELECT CAST(e.THREAD_ID AS CHAR) THREAD_ID,CAST(e.EVENT_ID AS CHAR) EVENT_ID,e.STATE,CAST(e.END_EVENT_ID AS CHAR) END_EVENT_ID,e.ISOLATION_LEVEL FROM performance_schema.events_transactions_current e JOIN performance_schema.threads t ON t.THREAD_ID=e.THREAD_ID WHERE t.PROCESSLIST_ID='.$id.' LIMIT 2');
    if(count($tx)!==1||$tx[0]['ISOLATION_LEVEL']!=='REPEATABLE READ'){throw new RuntimeException('native_rr_parent_isolation_unknown');}unset($tx[0]['ISOLATION_LEVEL']);
    $physical=governance_recovery_grant_mdl_rows($this->control,"SELECT DISTINCT CAST(b.TRX_ID AS CHAR) TRX_ID,CAST(l.THREAD_ID AS CHAR) THREAD_ID,CAST(b.TRX_MYSQL_THREAD_ID AS CHAR) PROCESSLIST_ID FROM performance_schema.data_locks l JOIN information_schema.INNODB_TRX b ON b.TRX_ID=CAST(l.ENGINE_TRANSACTION_ID AS CHAR) WHERE l.ENGINE='INNODB' AND b.TRX_MYSQL_THREAD_ID=".$id." AND l.OBJECT_SCHEMA='mysql' AND l.OBJECT_NAME='user' AND l.INDEX_NAME='PRIMARY' AND l.LOCK_TYPE='RECORD' AND l.LOCK_STATUS='GRANTED' AND l.LOCK_MODE IN ('X','X,REC_NOT_GAP') LIMIT 2");
    $actual=governance_grant_acquisition_identity(governance_recovery_grant_mdl_tickets($this->control),$tx,$physical,$id);governance_grant_mdl_v3_capture($this->ready['capture'],$actual,$id);return$actual;
  }
  public function pendingReady(mysqli$revoker){
    $this->readIdentity(); // Identity/scope errors are terminal, even before a request appears.
    $read=array($revoker);$errors=$reject=array();if(mysqli_poll($read,$errors,$reject,0,0)!==0||$read||$errors||$reject){throw new RuntimeException('native_rr_reply_already_ready');}
    return governance_native_rr_pending_state(governance_recovery_grant_mdl_tickets($this->control),(string)$revoker->thread_id);
  }
  public function pendingProof(mysqli$revoker){
    $before=$this->readIdentity();$tickets=governance_recovery_grant_mdl_tickets($this->control);$pending=array();foreach($tickets as$row){if($row['PROCESSLIST_ID']===(string)$revoker->thread_id&&$row['LOCK_STATUS']==='PENDING'&&$row['LOCK_TYPE']==='SHARED_READ_ONLY'){$pending[]=$row;}}
    if(count($pending)!==1){throw new RuntimeException('native_rr_pending_unknown');}$thread=$pending[0]['OWNER_THREAD_ID'];if(!governance_grant_cutpoint_uint($thread)){throw new RuntimeException('native_rr_pending_unknown');}
    $requests=governance_recovery_grant_mdl_rows($this->control,'SELECT CAST(THREAD_ID AS CHAR) THREAD_ID,CAST(EVENT_ID AS CHAR) EVENT_ID,CAST(END_EVENT_ID AS CHAR) END_EVENT_ID,EVENT_NAME,CAST(NESTING_EVENT_ID AS CHAR) NESTING_EVENT_ID,NESTING_EVENT_TYPE FROM performance_schema.events_statements_current WHERE THREAD_ID='.$thread.' LIMIT 3');
    $read=array($revoker);$error=$reject=array();if(mysqli_poll($read,$error,$reject,0,0)!==0||$read||$error||$reject){throw new RuntimeException('native_rr_reply_not_pending');}
    return governance_grant_mdl_v3_proof($tickets,$this->ready['capture'],$before,$this->readIdentity(),$requests,$this->ready['role']['id'],(string)$revoker->thread_id);
  }
  public function finish(){governance_native_rr_write($this->directory,'finish',$this->owner,$this->owner,$this->stat['dev'],$this->stat['ino']);$deadline=hrtime(true)+18000000000;while($this->running()&&hrtime(true)<$deadline){usleep(20000);}if($this->running()){throw new RuntimeException('native_rr_child_terminal_unknown');}if(is_resource($this->process)){$code=proc_close($this->process);if($code>=0){$this->exitCode=$code;}$this->process=null;}
    $result=json_decode(governance_native_rr_read($this->directory,'result.json'),true,16,JSON_THROW_ON_ERROR);if($this->exitCode!==0||($result['status']??null)!=='finished'||($result['cleanup_failures']??null)!==array()||($result['captured_output_bytes']??null)!==0){throw new RuntimeException('native_rr_child_failed');}return$result;
  }
  public function failureProjection(){
    $this->owned();if(!is_file($this->directory.'/result.json')){return array('result_available'=>false);}
    $value=json_decode(governance_native_rr_read($this->directory,'result.json'),true,16,JSON_THROW_ON_ERROR);$out=array('result_available'=>true);
    foreach(array('error_type','source_file')as$key){if(isset($value[$key])){if(!is_string($value[$key])||!preg_match('/\A[A-Za-z_][A-Za-z0-9_.\\\\-]{0,159}\z/D',$value[$key])){return array('result_available'=>false);}$out[$key]=$value[$key];}}
    if(isset($value['source_line'])){if(!is_int($value['source_line'])||$value['source_line']<1||$value['source_line']>1000000){return array('result_available'=>false);}$out['source_line']=$value['source_line'];}
    if(is_array($value['failure_terminal']??null)){$terminal=$value['failure_terminal'];
      if(array_keys($terminal)!==array('state','failure_physical_state','cleanup_proved','restoration_authorized','outcome_is_authorization')||!in_array($terminal['state'],array('new','active','invalid','committed','rolled-back'),true)||!in_array($terminal['failure_physical_state'],array('none','unknown','active','committed-observed','rollback-observed','ownership-changed'),true)||!is_bool($terminal['cleanup_proved'])||$terminal['restoration_authorized']!==false||$terminal['outcome_is_authorization']!==false){return array('result_available'=>false);}$out['terminal']=$terminal;}
    return$out;
  }
  public function cleanup(){
    if($this->closed){throw new RuntimeException('native_rr_parent_cleanup_reentry');}$this->owned();
    if($this->config===null&&$this->process===null){
      $files=array_values(array_diff(scandir($this->directory),array('.','..')));
      if($files!==array('owner')){throw new RuntimeException('native_rr_partial_directory_unknown');}
      if(!unlink($this->directory.'/owner')||!rmdir($this->directory)){throw new RuntimeException('native_rr_partial_cleanup_unproved');}$this->closed=true;
      return array('owned_processes_stopped'=>true,'owned_SQL_sessions_ended'=>true,'private_directory_removed'=>true,'cleanup_proved'=>true,'pidfd_remaining'=>0);
    }
    $stop=$this->ownerCommand('stop');
    if(is_resource($this->process)){if($this->running()){throw new RuntimeException('native_rr_child_reap_unproved');}proc_close($this->process);$this->process=null;}
    $sessions=true;if($this->config!==null){$deadline=hrtime(true)+3000000000;do{$after=$this->sessionSet();$sessions=governance_native_rr_sessions_ended($this->baseline,$after);if($sessions){break;}usleep(250000);}while(hrtime(true)<$deadline);}
    if(!$sessions){return array('owned_processes_stopped'=>true,'owned_SQL_sessions_ended'=>false,'private_directory_removed'=>false,'cleanup_proved'=>false);}
    $this->owned();$allowed=array('owner','payload.json','identity.json','ready.json','finish','result.json','stdout.log','stderr.log');foreach(scandir($this->directory)as$name){if($name==='.'||$name==='..'){continue;}if(!in_array($name,$allowed,true)||is_link($this->directory.'/'.$name)||!is_file($this->directory.'/'.$name)){throw new RuntimeException('native_rr_cleanup_unknown_entry');}}
    foreach($allowed as$name){if(is_file($this->directory.'/'.$name)&&!unlink($this->directory.'/'.$name)){throw new RuntimeException('native_rr_cleanup_unproved');}}
    if(!rmdir($this->directory)){throw new RuntimeException('native_rr_cleanup_unproved');}$this->closed=true;return array('owned_processes_stopped'=>true,'owned_SQL_sessions_ended'=>true,'private_directory_removed'=>true,'cleanup_proved'=>true,'pidfd_remaining'=>$stop['remaining_owned']);
  }
}
