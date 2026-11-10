<?php
// Root-only real native qualification. No table creation, fake context or source provider.
// Failure-only projection: exact public labels, never native message/args/identities.
function qphase($phase){
 $allowed=array('approve-service','before-upgrade','credential-profile','deny-service','discover','entry','native-api-context','native-bootstrap','native-user','profile','readback','schema','service-preflight','submit-request','submit-service','target');
 $GLOBALS['n05_qualification_diagnostic_phase']=in_array($phase,$allowed,true)?$phase:'entry';
}
function qcomponent($file,$root){
 if($file===__FILE__)return'qualification-helper';
 $map=array(
  '/src/infrastructure/governance/execution/PhorgeProjectNativeExecutionScope.php'=>'project-scope',
  '/src/applications/people/governance/auth/PhorgeGovernanceNativeIdentitySource.php'=>'identity-source',
  '/src/infrastructure/governance/execution/PhorgeProjectExecutionConfigSource.php'=>'configuration-source',
  '/src/infrastructure/governance/execution/PhorgeProjectConfigEpochScope.php'=>'configuration-epochs',
  '/src/infrastructure/governance/content/PhorgeProjectContentGrantService.php'=>'content-service',
  '/src/infrastructure/governance/content/PhorgeProjectContentGrantStore.php'=>'content-store',
  '/src/infrastructure/governance/content/PhorgeProjectContentSource.php'=>'content-source',
  '/src/infrastructure/governance/content/PhorgeProjectContentGrantRequest.php'=>'content-request',
  '/src/infrastructure/governance/content/PhorgeProjectContentGrantPlan.php'=>'content-plan',
  '/src/infrastructure/storage/connection/mysql/AphrontBaseMySQLDatabaseConnection.php'=>'query-driver',
  '/src/infrastructure/governance/consistency/PhorgeGovernanceNativeRuntimeProfile.php'=>'runtime-profile');
 foreach($map as$path=>$label)if(is_string($root)&&$root!==''&&$file===$root.$path)return$label;
 return'other';
}
function qfailure_line($line){return is_int($line)&&$line>=1&&$line<=100000?$line:null;}
function qfailure(Throwable$error,$root){
 qphase($GLOBALS['n05_qualification_diagnostic_phase']??'entry');
 $exceptions=[];
 for($i=0;$i<2&&$error!==null;$i++,$error=$error->getPrevious()){
  $class=get_class($error);$family='other';
  if($class==='RuntimeException')$family='runtime';
  else if($class==='InvalidArgumentException')$family='argument';
  else if($class==='PhorgeProjectPolicyRefusalException')$family='policy';
  else if(in_array($class,array('AphrontQueryException','AphrontConnectionQueryException','AphrontAccessDeniedQueryException','AphrontDeadlockQueryException'),true))$family='query';
  $allowed=array('content_grant_admin_required','content_grant_disabled','content_grant_identity_plan_required','content_grant_native_scope_required','content_grant_schema_required','content_grant_subject_ineligible','content_grant_target_refused','content_grant_target_unavailable','governance_native_credential_ineligible','native_grant_qualification_refused','native_runtime_library_profile_refused','native_runtime_root_or_extension_refused','other','project_execution_configuration_cache_stale','project_execution_configuration_control_type_refused','project_execution_configuration_database_not_loaded','project_execution_configuration_database_stale','project_execution_configuration_precedence_unsupported','project_execution_configuration_scope_required','project_execution_configuration_stack_unsupported','project_execution_configuration_unknown','project_execution_existing_scope_refused','project_execution_guard_required','project_execution_hierarchy_unsupported','project_execution_identity_plan_changed','project_execution_materialized_members_inconsistent','project_execution_profile_unsupported','project_execution_scope_cleanup_failed','project_execution_source_budget','qualification_action_refused');$message=$error->getMessage();$reason=in_array($message,$allowed,true)?$message:'other';
  $code=$error->getCode();$code=$family==='query'&&is_int($code)&&$code>=0&&$code<=65535?$code:null;
  $trace=[];foreach(array_slice($error->getTrace(),0,2)as$frame)$trace[]=['component'=>qcomponent($frame['file']??null,$root),'line'=>qfailure_line($frame['line']??null)];
  $exceptions[]=['family'=>$family,'component'=>qcomponent($error->getFile(),$root),'line'=>qfailure_line($error->getLine()),'reason'=>$reason,'query_code'=>$code,'trace'=>$trace];
 }
 return['schema'=>'N05-native-failure-v1','passed'=>false,'phase'=>$GLOBALS['n05_qualification_diagnostic_phase']??'entry','exceptions'=>$exceptions,'execution_authorized'=>false,'SQL_execution_status'=>'unknown'];
}
function qneed($v){if(!$v)throw new RuntimeException('native_grant_qualification_refused');}
function qprivate($p){
 qneed(is_string($p)&&realpath($p)===$p);$s=lstat($p);
 qneed($s&&($s['mode']&0177777)===0100600&&$s['uid']===posix_geteuid()&&$s['nlink']===1&&$s['size']>0&&$s['size']<=65536);
 $h=fopen($p,'rb');qneed($h!==false);try{$raw=stream_get_contents($h,65537);$f=fstat($h);clearstatcache(true,$p);$n=lstat($p);foreach(['dev','ino','mode','uid','gid','nlink','size','mtime','ctime']as$k)qneed($s[$k]===$f[$k]&&$s[$k]===$n[$k]);qneed(strlen($raw)===$s['size']);return$raw;}finally{fclose($h);}
}
function qload($root){require $root.'/support/runtime/src/__phutil_library_init__.php';phutil_load_library($root.'/src');}
function qdiscover(){
 $key='phabricator:20261110.project.contentgrants.sql';$all=PhabricatorSQLPatchList::buildAllPatches();qneed(isset($all[$key]));$p=$all[$key];
 qneed($p->getType()==='sql'&&$p->getKey()==='20261110.project.contentgrants.sql'&&$p->getName()===dirname(phutil_get_library_root('phabricator')).'/resources/sql/autopatches/20261110.project.contentgrants.sql');
 $specs=(new PhutilClassMapQuery())->setAncestorClass(PhabricatorConfigSchemaSpec::class)->execute();$n=0;foreach($specs as$s)if(get_class($s)===PhorgeProjectContentGrantSchemaSpec::class)$n++;
 qneed($n===1);$flag=head((new PhorgeProjectContentGrantConfigOptions())->getOptions());qneed($flag->getDefault()===false&&PhorgeProjectContentGrantService::executionStatus()['can_execute']===false);
 return['native_patch_discovered'=>true,'native_schema_spec_registered'=>true,'default_enabled'=>false,'execution_authorized'=>false,'patch_key'=>$key,'patch_sha256'=>hash_file('sha256',$p->getName())];
}
function qconnection(array$p){
 qneed(PhabricatorEnv::getEnvConfig('storage.default-namespace')===$p['namespace']);qneed(PhabricatorEnv::getEnvConfig('cluster.databases')===[]);
 $c=(new PhabricatorProject())->establishConnection('w');qneed(get_class($c)===AphrontMySQLiDatabaseConnection::class);
 $r=queryfx_one($c,'SELECT @@server_uuid uuid,DATABASE() db');qneed($r['uuid']===$p['server_uuid']&&$r['db']===$p['namespace'].'_project');return$c;
}
function qschema(array$p,$before){
 $c=qconnection($p);$names=['governance_contentgrant','governance_contentapproval','governance_contentaudit'];
 if($before){foreach($names as$n){qneed(queryfx_one($c,'SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$n)===null);}return['new_patch_tables_absent'=>true,'execution_authorized'=>false];}
 $patch=queryfx_one($c,'SELECT patch FROM %T.%T WHERE patch=%s',$p['namespace'].'_meta_data','patch_status','phabricator:20261110.project.contentgrants.sql');qneed($patch!==null);
 $server=new PhabricatorConfigServerSchema();$spec=(new PhorgeProjectContentGrantSchemaSpec())->setServer($server)->setUTF8Charset('utf8mb4')->setUTF8BinaryCollation('utf8mb4_bin')->setUTF8SortingCollation('utf8mb4_unicode_ci');$spec->buildSchemata();$db=$server->getDatabase($p['namespace'].'_project');qneed($db!==null);
 foreach($names as$n){
  $e=queryfx_one($c,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$n);qneed($e&&$e['ENGINE']==='InnoDB');
  $actual=queryfx_all($c,'SELECT COLUMN_NAME, COLUMN_TYPE,IS_NULLABLE,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION',$n);$expected=$db->getTable($n)->getColumns();qneed(count($actual)===count($expected));
  foreach($actual as$r){qneed(isset($expected[$r['COLUMN_NAME']]));$e=$expected[$r['COLUMN_NAME']];qneed($e->hasSameColumnTypeAs((new PhabricatorConfigColumnSchema())->setColumnType(strtolower($r['COLUMN_TYPE'])))&&($r['IS_NULLABLE']==='YES')===$e->getNullable()&&str_contains($r['EXTRA'],'auto_increment')===$e->getAutoIncrement());}
  $keyrows=queryfx_all($c,'SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY INDEX_NAME,SEQ_IN_INDEX',$n);$got=[];foreach($keyrows as$r){qneed($r['SUB_PART']===null);$k=$r['INDEX_NAME'];if(!isset($got[$k]))$got[$k]=['columns'=>[],'unique'=>(string)$r['NON_UNIQUE']==='0','type'=>$r['INDEX_TYPE']];$got[$k]['columns'][]=$r['COLUMN_NAME'];} $want=[];foreach($db->getTable($n)->getKeys()as$k=>$v)$want[$k]=['columns'=>$v->getColumnNames(),'unique'=>$v->getUnique(),'type'=>$v->getIndexType()];ksort($got);ksort($want);qneed($got===$want);
 }
 return['standard_patch_recorded'=>true,'productionSchemaSpec'=>true,'actual_tables_match_native_schema'=>true,'execution_authorized'=>false];
}
function qcontext(array$p,$role){
 qphase('credential-profile');
 $raw=qprivate($p['actors_path']);qneed(hash('sha256',$raw)===$p['actors_sha256']);$a=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
 qneed(array_keys($a)===['requester','approver']&&$a['requester']['user_phid']!==$a['approver']['user_phid']);
 foreach($a as$r){qneed(array_keys($r)===['user_phid','token']&&preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$r['user_phid'])&&preg_match('/\A(?:api|cli)-[a-zA-Z0-9]{28}\z/D',$r['token']));}
 qphase('native-user');$r=$a[$role];$u=(new PhabricatorUser())->loadOneWhere('phid = %s',$r['user_phid']);qneed($u!==null&&$u->getIsAdmin()&&!$u->getIsDisabled()&&$u->getIsApproved());
 // The real native source revalidates token, account and source locks inside service.
 qphase('native-api-context');return PhorgeGovernanceNativeActorContext::newFromAuthenticatedAPIToken($u,new PhutilOpaqueEnvelope($r['token']));
}
function qrequest(array$p,$kind,$action,$grant=null){
 qneed(in_array($kind,['user','business-team'],true));return['action'=>$action,'grant_id'=>$grant,'reason'=>'Fresh isolated native SQL qualification','subject_id'=>$p['subjects'][$kind],'subject_kind'=>$kind,'target_phid'=>$p['target_phid'],'valid_for_seconds'=>$action==='grant'?86400:null];
}
/** Binary-safe digest retains every selected byte, null, row key and row ordering. */
function qrows_digest(array$rows){return hash('sha256',serialize($rows));}
function qtarget_plan(array$row){
 $dao=new PhabricatorProject();$columns=$dao->getSchemaColumns();qneed(!isset($columns['description']));foreach(array_keys($row)as$k)qneed(array_key_exists($k,$columns));
 qneed(isset($row['phid'])&&is_string($row['phid']));$object=$dao->loadFromArray($row);
 $field=PhabricatorCustomField::getObjectField($object,PhabricatorCustomField::ROLE_VIEW,'std:project:internal:description');
 qneed($field!==null&&$field->getFieldKey()==='std:project:internal:description');
 $storage=$field->newStorageObject();qneed(get_class($storage)===PhabricatorProjectCustomFieldStorage::class);
 return[$object,$field,$storage];
}
function qtarget(array$p,$test_read=null){
 if($test_read!==null)qneed(defined('N05_QUALIFICATION_LIBRARY_ONLY')&&N05_QUALIFICATION_LIBRARY_ONLY===true&&is_callable($test_read));
 $c=$test_read===null?qconnection($p):null;
 $read=$test_read??static function($kind,$storage=null,$index=null)use($c,$p){
  if($kind==='project')return queryfx_one($c,'SELECT * FROM %R WHERE phid=%s',new PhabricatorProject(),$p['target_phid']);
  return queryfx_one($c,'SELECT * FROM %R WHERE objectPHID=%s AND fieldIndex=%s',$storage,$p['target_phid'],$index);
 };
 $row=$read('project');qneed(is_array($row)&&$row['phid']===$p['target_phid']);[$object,$field,$storage]=qtarget_plan($row);
 $stored=$read('description',$storage,$field->getFieldIndex());
 if($stored!==null)qneed($stored['objectPHID']===$object->getPHID()&&$stored['fieldIndex']===$field->getFieldIndex()&&array_key_exists('fieldValue',$stored));
 // Decode through the real native field. No object/field value is written.
 $field->setValueFromStorage($stored===null?null:$stored['fieldValue']);
 return['project_row_sha256'=>qrows_digest($row),'native_description_source_sha256'=>qrows_digest(['field_key'=>$field->getFieldKey(),'stored_row'=>$stored,'decoded_value'=>$field->getValueForStorage()]),'execution_authorized'=>false];
}
function qstate(array$p,$id){
 PhorgeProjectContentGrantRequest::identifier($id);$c=qconnection($p);qneed(!$c->isInsideTransaction());
 queryfx($c,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$c->openTransaction();
 try{
  // Complete rows for the entire isolated target catch changes beyond the visible projection.
  $tables=['approval'=>'governance_contentapproval','grant'=>'governance_contentgrant','audit'=>'governance_contentaudit'];$all=[];
  foreach($tables as$kind=>$table){$order=$kind==='approval'?'approvalID':($kind==='grant'?'grantID':'id');$all[$kind]=queryfx_all($c,'SELECT * FROM %T WHERE targetPHID=%s ORDER BY %C LIMIT 201',$table,$p['target_phid'],$order);qneed(count($all[$kind])<=200);}
  $a=null;foreach($all['approval']as$row)if($row['approvalID']===$id){qneed($a===null);$a=$row;}qneed($a!==null);
  $d=json_decode($a['bindingJSON'],true,16,JSON_THROW_ON_ERROR);qneed($d['request']['target_phid']===$p['target_phid']);$g=null;
  foreach($all['grant']as$row)if($row['grantID']===$d['grantID']){$g=$row;break;}
  $audit=[];foreach($all['audit']as$row)if($row['approvalID']===$id)$audit[]=['actorPHID'=>$row['actorPHID'],'action'=>$row['action']];
  $result=['approval_id'=>$id,'grant_id'=>$d['grantID'],'approval_state'=>$a['state'],'approval_version'=>(int)$a['version'],'approver_present'=>$a['approverBindingJSON']!==null,'applied_at_present'=>$a['appliedAt']!==null,'grant'=>$g,'audit'=>$audit,'complete_target_rows_sha256'=>qrows_digest($all),'execution_authorized'=>false];
  $c->saveTransaction();return$result;
 }catch(Throwable$e){$c->killTransaction();throw$e;}
}
function qservice(array$p,$action,$kind,$input){
 qphase('service-preflight');
 qconnection($p);qneed(PhorgeProjectContentGrantService::executionStatus()['can_execute']===false);PhorgeProjectContentGrantService::assertEnabled();
 if($action==='submit-grant'){$ctx=qcontext($p,'requester');qphase('submit-request');$request=qrequest($p,$kind,'grant');qphase('submit-service');return PhorgeProjectContentGrantService::call($ctx,'submit',$request);}
 if($action==='submit-revoke'){qneed(isset($input['grant_id']));$ctx=qcontext($p,'requester');qphase('submit-request');$request=qrequest($p,$kind,'revoke',$input['grant_id']);qphase('submit-service');return PhorgeProjectContentGrantService::call($ctx,'submit',$request);}
 if($action==='readback'){qphase('readback');return qstate($p,$input['approval_id']);}
 if(in_array($action,['approve','deny-self','deny-replay'],true)){
  $before=qstate($p,$input['approval_id']);
  if($action==='approve'){$ctx=qcontext($p,'approver');qphase('approve-service');return PhorgeProjectContentGrantService::call($ctx,'approve',['approval_id'=>$input['approval_id']]);}
  $denied=false;try{$ctx=qcontext($p,$action==='deny-self'?'requester':'approver');qphase('deny-service');PhorgeProjectContentGrantService::call($ctx,'approve',['approval_id'=>$input['approval_id']]);}catch(RuntimeException$e){qneed($e->getMessage()==='content_grant_independent_approver_required');$denied=true;}
  qneed($denied&&qstate($p,$input['approval_id'])===$before);return['denied'=>true,'durable_state_unchanged'=>true,'execution_authorized'=>false];
 }
 throw new RuntimeException('qualification_action_refused');
}
// Library-only permits actual pure native registration tests, never actor injection.
if(defined('N05_QUALIFICATION_LIBRARY_ONLY')&&N05_QUALIFICATION_LIBRARY_ONLY===true)return;
qphase('entry');
try{
 qneed($argc===6);[$script,$root,$profile,$action,$input_path,$kind]=$argv;
 qneed(in_array($action,['discover','before-upgrade','schema','target','submit-grant','submit-revoke','approve','deny-self','deny-replay','readback'],true));
 if($action==='discover'){qphase('discover');qload($root);$out=qdiscover();}
 else{
  qphase('profile');$p=json_decode(qprivate($profile),true,8,JSON_THROW_ON_ERROR);qneed($p['runtime_authorized']===true&&$p['source_root']===$root);
  $input=json_decode(qprivate($input_path),true,8,JSON_THROW_ON_ERROR);
  qphase('native-bootstrap');$argv=[$script];$argc=1;require $root.'/scripts/init/init-script.php';
  if($action==='before-upgrade'){qphase('before-upgrade');$out=qschema($p,true);}
  else if($action==='schema'){qphase('schema');$out=qschema($p,false);}
  else if($action==='target'){qphase('target');$out=qtarget($p);}
  else $out=qservice($p,$action,$kind,$input);
 }
 qneed(($out['execution_authorized']??null)===false);echo json_encode($out,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable$e){echo json_encode(qfailure($e,isset($root)&&is_string($root)?$root:''),JSON_THROW_ON_ERROR)."\n";fwrite(STDERR,"qualification_refused\n");exit(1);}
