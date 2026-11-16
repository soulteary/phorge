<?php
/** Root-bound actual Task metadata SQL. No Task body editor or public route. */
const N05_TASK_PROFILE_SHA256=null;
const N05_TASK_CONFIGURATION_SHA256=null;
const N05_TASK_SOURCE_WINDOW_SHA256=null;
const N05_TASK_HELPER_ROOT=null;
const N05_TASK_HELPER_SHA256=null;
const N05_TASK_CAPTURED_HELPER_PATH=null;
// Only the existing native helpers are reused: private role capture, actual API
// context construction, raw-row digest and unchanged Project diagnostics.
if(!defined('N05_QUALIFICATION_LIBRARY_ONLY'))define('N05_QUALIFICATION_LIBRARY_ONLY',true);
if(defined('N05_TASK_QUALIFICATION_LIBRARY_ONLY')&&N05_TASK_QUALIFICATION_LIBRARY_ONLY===true){
 require __DIR__.'/../project-content-grants-qualification/native.php';
}else{
 if(!is_string(N05_TASK_HELPER_ROOT)||!is_string(N05_TASK_HELPER_SHA256)||realpath(N05_TASK_HELPER_ROOT)!==N05_TASK_HELPER_ROOT||($argv[1]??null)!==N05_TASK_HELPER_ROOT){fwrite(STDERR,"task_qualification_unbound\n");exit(1);}
 $helper=N05_TASK_CAPTURED_HELPER_PATH;
 if(!is_string($helper)||realpath($helper)!==$helper||is_link($helper)){fwrite(STDERR,"task_qualification_helper_refused\n");exit(1);}
 $observed=lstat($helper);
 if(!$observed||($observed['mode']&0177777)!==0100600||$observed['nlink']!==1||$observed['uid']!==posix_geteuid()||$observed['size']<1||$observed['size']>1048576){fwrite(STDERR,"task_qualification_helper_refused\n");exit(1);}
 $handle=fopen($helper,'rb');if($handle===false){fwrite(STDERR,"task_qualification_helper_refused\n");exit(1);}
 try{
  $helper_raw=stream_get_contents($handle,1048577);$held=fstat($handle);clearstatcache(true,$helper);$current=lstat($helper);$stable=true;
  foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$key)$stable=$stable&&$observed[$key]===$held[$key]&&$observed[$key]===$current[$key];
  if(!$stable||strlen($helper_raw)!==$observed['size']||hash('sha256',$helper_raw)!==N05_TASK_HELPER_SHA256){fwrite(STDERR,"task_qualification_helper_refused\n");exit(1);}
 }finally{fclose($handle);}
 // Execute only captured, hash-checked library bytes. Never require a path
 // again after checking its hash; library-only mode returns before its CLI.
 eval('?>'.$helper_raw);
}
function tqneed($v){if($v!==true)throw new RuntimeException('task_qualification_refused');}
function tqkeys(array$x,array$keys){$a=array_keys($x);sort($a,SORT_STRING);sort($keys,SORT_STRING);tqneed($a===$keys);}
function tqdiscover(){
 $key='phabricator:20261112.task.contentgrants.sql';$all=PhabricatorSQLPatchList::buildAllPatches();
 tqneed(isset($all[$key]));$p=$all[$key];$root=dirname(phutil_get_library_root('phabricator'));
 tqneed($p->getType()==='sql'&&!$p->isDead()&&$p->getKey()==='20261112.task.contentgrants.sql'&&$p->getName()===$root.'/resources/sql/autopatches/20261112.task.contentgrants.sql');
 $manifest=json_decode(file_get_contents($root.'/src/infrastructure/governance/content/task-grant-migration-source.json'),true,16,JSON_THROW_ON_ERROR);
 tqneed($manifest['sql_path']==='resources/sql/autopatches/20261112.task.contentgrants.sql'&&hash_file('sha256',$p->getName())===$manifest['sql_sha256']);
 $specs=(new PhutilClassMapQuery())->setAncestorClass(PhabricatorConfigSchemaSpec::class)->execute();$count=0;
 foreach($specs as$spec)if(get_class($spec)===PhorgeTaskContentGrantSchemaSpec::class)$count++;
 $options=(new PhorgeTaskContentGrantConfigOptions())->getOptions();tqneed(count($options)===1);
 tqneed($count===1&&head($options)->getDefault()===false&&PhorgeTaskContentGrantService::executionStatus()['can_execute']===false);
 return array('native_patch_discovered'=>true,'native_schema_spec_registered'=>true,'default_enabled'=>false,
  'SQL_claim_scope'=>'tqdiscover-function-only-not-native-bootstrap','patch_key'=>$key,'patch_sha256'=>hash_file('sha256',$p->getName()),'SQL_executed'=>false,'execution_authorized'=>false);
}
function tqconfiguration(array$p,array$c){
 foreach(array('load-libraries','cluster.databases','cluster.search','notification.servers')as$key)tqneed(array_key_exists($key,$c)&&$c[$key]===array());
 foreach(array('cluster.instance','gorge.db.uri')as$key)tqneed(array_key_exists($key,$c)&&$c[$key]===null);
 tqneed(($c['mysql.host']??null)==='127.0.0.1'&&is_int($c['mysql.port']??null)&&$c['mysql.port']>=1&&$c['mysql.port']<=65535);
 tqneed(is_string($c['mysql.user']??null)&&preg_match('/\A[a-zA-Z0-9_]{1,64}\z/D',$c['mysql.user'])===1&&is_string($c['mysql.pass']??null)&&strlen($c['mysql.pass'])>=1&&strlen($c['mysql.pass'])<=256);
 tqneed(($c['storage.default-namespace']??null)===$p['namespace']&&($c['governance.task-content-grants.enabled']??null)===true&&($c['governance.organization.enabled']??null)===true&&($c['security.require-multi-factor-auth']??null)===false);
 return array('local_configuration_checked'=>true,'configuration_execution_authority'=>false);
}
function tqprofile($root,$path){
 tqneed(is_string(N05_TASK_PROFILE_SHA256)&&preg_match('/\A[a-f0-9]{64}\z/D',N05_TASK_PROFILE_SHA256)===1);
 $raw=qprivate($path);tqneed(hash('sha256',$raw)===N05_TASK_PROFILE_SHA256);$p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
 tqkeys($p,array('schema','runtime_authorized','source_root','parent_revision','source_window_sha256','configuration_sha256','php','php_sha256','namespace','server_uuid','fixture_nonce_sha256','quickstart_sha256','actors_path','actors_sha256','target_phid','subjects'));
 tqneed($p['schema']==='N05-Task-metadata-native-qualification-v1'&&$p['runtime_authorized']===true&&$p['source_root']===$root&&realpath($root)===$root&&$p['parent_revision']==='5903d98b14926401f0be49cb0a9d6168026a2642');
 foreach(array('source_window_sha256','configuration_sha256','php_sha256','fixture_nonce_sha256','quickstart_sha256','actors_sha256')as$k)tqneed(is_string($p[$k])&&preg_match('/\A[a-f0-9]{64}\z/D',$p[$k])===1);
 tqneed($p['configuration_sha256']===N05_TASK_CONFIGURATION_SHA256&&$p['source_window_sha256']===N05_TASK_SOURCE_WINDOW_SHA256);
 tqneed(in_array($p['php'],array('/usr/bin/php','/usr/local/bin/php'),true)&&realpath(PHP_BINARY)===realpath($p['php'])&&hash_file('sha256',PHP_BINARY)===$p['php_sha256']);
 tqneed(is_string($p['namespace'])&&preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D',$p['namespace'])===1);
 tqneed(is_string($p['server_uuid'])&&preg_match('/\A[a-f0-9-]{36}\z/D',$p['server_uuid'])===1);
 tqneed(is_string($p['target_phid'])&&preg_match('/\APHID-TASK-[a-zA-Z0-9]{20}\z/D',$p['target_phid'])===1);
 tqkeys($p['subjects'],array('user','business-team'));
 tqneed(preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$p['subjects']['user'])===1&&preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D',$p['subjects']['business-team'])===1);
 $config_raw=qprivate($root.'/conf/local/local.json');tqneed(hash('sha256',$config_raw)===$p['configuration_sha256']);tqconfiguration($p,json_decode($config_raw,true,16,JSON_THROW_ON_ERROR));
 tqneed(hash_file('sha256',$root.'/resources/sql/quickstart.sql')===$p['quickstart_sha256']);
 return$p;
}
function tqconnection(array$p){
 tqneed(PHP_VERSION==='8.5.12'&&ini_get('opcache.jit')==='disable'&&ini_get('opcache.jit_buffer_size')==='0'&&ini_get('opcache.enable_cli')==='0');
 tqneed(PhabricatorEnv::getEnvConfig('storage.default-namespace')===$p['namespace']&&PhabricatorEnv::getEnvConfig('cluster.databases')===array());
 $c=(new ManiphestTask())->establishConnection('w');tqneed(get_class($c)===AphrontMySQLiDatabaseConnection::class&&!$c->isInsideTransaction());
 $row=queryfx_one($c,'SELECT @@server_uuid uuid,DATABASE() db,VERSION() version');
 tqneed($row['uuid']===$p['server_uuid']&&$row['db']===$p['namespace'].'_maniphest'&&$row['version']==='8.4.13');
 $guard=queryfx_all($c,'SELECT id,marker,quickstart_sha256,SHA2(CAST(owner_nonce AS BINARY),256) nonce_sha256 FROM %T.%T ORDER BY id',$p['namespace'].'_governance','fixture_guard');
 tqneed(count($guard)===1&&(string)$guard[0]['id']==='1'&&$guard[0]['marker']==='N07.NATIVE-SOURCE.v1:synthetic-only'&&$guard[0]['quickstart_sha256']===$p['quickstart_sha256']&&$guard[0]['nonce_sha256']===$p['fixture_nonce_sha256']);
 return$c;
}
function tqschema(array$p,$before){
 $c=tqconnection($p);$names=array('governance_taskcontentgrant','governance_taskcontentapproval','governance_taskcontentaudit');
 $key='phabricator:20261112.task.contentgrants.sql';
 $patch=queryfx_one($c,'SELECT patch FROM %T.%T WHERE patch=%s',$p['namespace'].'_meta_data','patch_status',$key);
 if($before){
  tqneed($patch===null);
  $epoch_before=queryfx_all($c,'SELECT epoch FROM %T.%T WHERE namespace=%s AND configKey=%s',$p['namespace'].'_config','governance_configepoch','default','governance.task-content-grants.enabled');tqneed($epoch_before===array());
  foreach($names as$n)tqneed(queryfx_one($c,'SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$n)===null);
  return array('new_patch_tables_absent'=>true,'standard_patch_not_yet_recorded'=>true,'execution_authorized'=>false);
 }
 tqneed($patch!==null);
 $server=new PhabricatorConfigServerSchema();$spec=(new PhorgeTaskContentGrantSchemaSpec())->setServer($server)->setUTF8Charset('utf8mb4')->setUTF8BinaryCollation('utf8mb4_bin')->setUTF8SortingCollation('utf8mb4_unicode_ci');$spec->buildSchemata();$db=$server->getDatabase($p['namespace'].'_maniphest');tqneed($db!==null);
 foreach($names as$n){
  $engine=queryfx_one($c,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$n);tqneed($engine!==null&&$engine['ENGINE']==='InnoDB');
  $actual=queryfx_all($c,'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION',$n);$expected=$db->getTable($n)->getColumns();tqneed(count($actual)===count($expected));
  foreach($actual as$r){tqneed(isset($expected[$r['COLUMN_NAME']]));$e=$expected[$r['COLUMN_NAME']];tqneed($e->hasSameColumnTypeAs((new PhabricatorConfigColumnSchema())->setColumnType(strtolower($r['COLUMN_TYPE'])))&&($r['IS_NULLABLE']==='YES')===$e->getNullable()&&str_contains($r['EXTRA'],'auto_increment')===$e->getAutoIncrement());}
  $rows=queryfx_all($c,'SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY INDEX_NAME,SEQ_IN_INDEX',$n);$got=array();
  foreach($rows as$r){tqneed($r['SUB_PART']===null);$k=$r['INDEX_NAME'];if(!isset($got[$k]))$got[$k]=array('columns'=>array(),'unique'=>(string)$r['NON_UNIQUE']==='0','type'=>$r['INDEX_TYPE']);$got[$k]['columns'][]=$r['COLUMN_NAME'];}
  $want=array();foreach($db->getTable($n)->getKeys()as$k=>$v)$want[$k]=array('columns'=>$v->getColumnNames(),'unique'=>$v->getUnique(),'type'=>$v->getIndexType());ksort($got);ksort($want);tqneed($got===$want);
 }
 $epoch=queryfx_all($c,'SELECT epoch FROM %T.%T WHERE namespace=%s AND configKey=%s',$p['namespace'].'_config','governance_configepoch','default','governance.task-content-grants.enabled');tqneed(count($epoch)===1&&(string)$epoch[0]['epoch']==='1');
 return array('standard_patch_recorded'=>true,'production_SchemaSpec_used'=>true,'three_actual_tables_match_schema'=>true,'task_config_epoch_present'=>true,'execution_authorized'=>false);
}
function tqtarget(array$p){
 $c=tqconnection($p);$row=queryfx_one($c,'SELECT * FROM %R WHERE BINARY phid=BINARY %s',new ManiphestTask(),$p['target_phid']);
 tqneed(is_array($row)&&$row['phid']===$p['target_phid']&&is_string($row['description']??null));
 return array('task_row_sha256'=>qrows_digest($row),'body_sha256'=>hash('sha256',$row['description']),
  'native_task_fields_read'=>true,'Task_body_execution_proved'=>false,'execution_authorized'=>false);
}
function tqstate(array$p,$id){
 PhorgeTaskContentGrantRequest::identifier($id);$c=tqconnection($p);queryfx($c,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$c->openTransaction();$owned_read=true;
 try{
  $tables=array('approval'=>'governance_taskcontentapproval','grant'=>'governance_taskcontentgrant','audit'=>'governance_taskcontentaudit');$all=array();
  foreach($tables as$kind=>$table){$order=$kind==='approval'?'approvalID':($kind==='grant'?'grantID':'id');$all[$kind]=queryfx_all($c,'SELECT * FROM %T WHERE targetPHID=%s ORDER BY %C LIMIT 201',$table,$p['target_phid'],$order);tqneed(count($all[$kind])<=200);}
  $a=null;foreach($all['approval']as$r)if($r['approvalID']===$id){tqneed($a===null);$a=$r;}tqneed($a!==null);
  $d=json_decode($a['bindingJSON'],true,16,JSON_THROW_ON_ERROR);tqneed($d['request']['target_phid']===$p['target_phid']&&$d['request']['operation']==='task.description');$g=null;
  foreach($all['grant']as$r)if($r['grantID']===$d['grantID']){$g=$r;break;}
  $audit=array();foreach($all['audit']as$r)if($r['approvalID']===$id)$audit[]=array('actorPHID'=>$r['actorPHID'],'action'=>$r['action']);
  $result=array('approval_id'=>$id,'grant_id'=>$d['grantID'],'subject_kind'=>$d['request']['subject_kind'],'subject_id'=>$d['request']['subject_id'],'approval_state'=>$a['state'],'approval_version'=>(int)$a['version'],'approver_present'=>$a['approverBindingJSON']!==null,'applied_at_present'=>$a['appliedAt']!==null,'grant'=>$g,'audit'=>$audit,'complete_target_rows_sha256'=>qrows_digest($all),'execution_authorized'=>false);
  // Retire before COMMIT. ACK loss stays unknown; never infer rollback.
  $owned_read=false;$c->saveTransaction();return$result;
 }catch(Throwable$e){if($owned_read)$c->killTransaction();throw$e;}
}
function tqinvoke(array$p,PhorgeGovernanceNativeActorContext$context,$action,array$payload){
 $before=tqtarget($p);$result=PhorgeTaskContentGrantService::call($context,$action,$payload);
 tqneed(tqtarget($p)===$before);$result['native_Task_row_unchanged']=true;$result['Task_body_execution_proved']=false;return$result;
}
function tqapprovalPrecondition(array$p,$action,$kind,array$state){
 tqneed(in_array($kind,array('user','business-team'),true));
 tqneed(($state['subject_kind']??null)===$kind&&($state['subject_id']??null)===$p['subjects'][$kind]);
 if($action==='deny-replay')tqneed($state['approval_state']==='applied'&&$state['approver_present']===true&&$state['applied_at_present']===true);
 else tqneed(in_array($action,array('approve','deny-self'),true)&&$state['approval_state']==='submitted'&&$state['approver_present']===false&&$state['applied_at_present']===false);
}
function tqservice(array$p,$action,$kind,array$input){
 tqconnection($p);tqneed(in_array($kind,array('user','business-team'),true));
 tqneed(PhorgeTaskContentGrantService::executionStatus()['can_execute']===false);PhorgeTaskContentGrantService::assertEnabled();
 if(in_array($action,array('submit-grant','submit-revoke'),true)){
  tqkeys($input,$action==='submit-grant'?array():array('grant_id'));$grant=$action==='submit-grant'?null:$input['grant_id'];
  $r=array('action'=>$action==='submit-grant'?'grant':'revoke','grant_id'=>$grant,'reason'=>'Isolated current Task metadata qualification','subject_id'=>$p['subjects'][$kind],'subject_kind'=>$kind,'target_phid'=>$p['target_phid'],'valid_for_seconds'=>$action==='submit-grant'?86400:null);
  $ctx=qcontext($p,'requester');return tqinvoke($p,$ctx,'submit',$r);
 }
 tqkeys($input,array('approval_id'));PhorgeTaskContentGrantRequest::identifier($input['approval_id']);
 if($action==='readback')return tqstate($p,$input['approval_id']);
 tqneed(in_array($action,array('approve','deny-self','deny-replay'),true));
 $before=tqstate($p,$input['approval_id']);tqapprovalPrecondition($p,$action,$kind,$before);$body=tqtarget($p);
 if($action==='approve')return tqinvoke($p,qcontext($p,'approver'),'approve',array('approval_id'=>$input['approval_id']));
 $denied=false;
 try{PhorgeTaskContentGrantService::call(qcontext($p,$action==='deny-self'?'requester':'approver'),'approve',array('approval_id'=>$input['approval_id']));}
 catch(RuntimeException$e){tqneed($e->getMessage()==='content_grant_independent_approver_required');$denied=true;}
 tqneed($denied&&tqstate($p,$input['approval_id'])===$before&&tqtarget($p)===$body);
 return array('denied'=>true,'durable_target_metadata_unchanged'=>true,'native_Task_body_unchanged'=>true,'execution_authorized'=>false);
}
if(defined('N05_TASK_QUALIFICATION_LIBRARY_ONLY')&&N05_TASK_QUALIFICATION_LIBRARY_ONLY===true)return;
$phase='entry';
try{
 tqneed($argc===6);list($script,$root,$profile,$action,$input_path)=$argv;$kind=$argv[5];
 tqneed(in_array($action,array('discover','before-upgrade','schema','target','submit-grant','submit-revoke','approve','deny-self','deny-replay','readback'),true));
 $phase='profile';$p=tqprofile($root,$profile);$input=json_decode(qprivate($input_path),true,16,JSON_THROW_ON_ERROR);tqneed(is_array($input));
 $phase='native-bootstrap';$argv=array($script);$argc=1;require$root.'/scripts/init/init-script.php';
 $phase=$action;
 if($action==='discover'){$out=tqdiscover();unset($out['SQL_executed']);$out['SQL_execution_extent']='native_bootstrap_SQL_not_measured';}
 elseif($action==='before-upgrade')$out=tqschema($p,true);
 elseif($action==='schema')$out=tqschema($p,false);
 elseif($action==='target')$out=tqtarget($p);
 else$out=tqservice($p,$action,$kind,$input);
 tqneed(($out['execution_authorized']??null)===false);echo json_encode($out,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable$e){echo json_encode(array('schema'=>'N05-Task-qualification-failure-v1','passed'=>false,'phase'=>$phase,'exception_family'=>$e instanceof AphrontQueryException?'native-query':'qualification','SQL_execution_extent'=>'unknown','execution_authorized'=>false),JSON_THROW_ON_ERROR)."\n";fwrite(STDERR,"task_qualification_refused_retain_no_retry\n");exit(1);}
