"""Offline consumer/CI controls; synthetic receipts are not SQL evidence."""
import copy,hashlib,json,re,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]


def _full_trigger_contract(raw,relative):
 # CURRENT invocation remains full-only and exact-head bound. The old PR
 # filter below is immutable historical metadata DATA, never source authority.
 import importlib.util,yaml
 spec=importlib.util.spec_from_file_location('focused_contract_DATA',ROOT/'scripts/ci/focused_workflows.py')
 contract=importlib.util.module_from_spec(spec);spec.loader.exec_module(contract)
 contract.focused_contract(yaml.load(raw,Loader=yaml.BaseLoader),Path(relative).name)
 inventory=contract.inventory(ROOT)
 assert inventory['focused_full_workflows']==12 and inventory['release_full_workflows']==13
 old=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show',
  '7b23c47e329f32e4fe083d0efa23fd82d6eefe6f:'+relative],timeout=10)
 historical=yaml.safe_load(old);events=historical.get('on',historical.get(True))
 return events['pull_request']['paths']

sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_oauth_client_lifecycle as gate
import qualify_oauth_client_lifecycle_mysql84 as qualified
import project_oauth_client_lifecycle_failure as projector
import test_native_organization as baseline
WORKFLOW=ROOT/'.github/workflows/oauth-client-lifecycle-focused.yml'
def _lifecycle_model_in(operation,root):
 import source_operation_snapshot as source
 from check_oauth_task_schema_receipt_v2 import captured_module
 source.require_task_operation(operation,root)
 adapter=captured_module(operation,root,'scripts/governance/oauth_task_strict_body_v2.py')
 consumer=captured_module(operation,root,'scripts/governance/check_oauth_task_schema_receipt_v2.py')
 context=operation._task_contexts[Path(root)];raws={name:context.current('scripts/governance/'+name+'.py')for name in adapter.PINS}
 model=adapter.model(raws,'oauth-client-lifecycle');model['adapter']=adapter;model['raws']=raws
 sql,expected=consumer.source_obligations(operation,Path(root),model,'oauth-client-lifecycle')
 if len(sql)!=52 or len(expected)!=62 or len(model['operations'])!=6:raise ValueError('lifecycle_original_obligations_changed')
 return model,sql,expected

def _validate_lifecycle_contract_in(operation,model,sql,expected,receipt):
 import source_operation_snapshot as source
 source.require_task_operation(operation,ROOT)
 # Synthetic positive JSON is contract DATA only. The original strict restore
 # seam remains, reading actual current-parent sources under this real owner.
 return model['validate'](receipt,ROOT,source_revision='a'*40,file_digest='b'*64,sql_obligations=sql,expected_obligations=expected,operation=operation)

def _qualify_lifecycle_contract_in(operation,model,sql,expected,receipt,before,after):
 observed=model['runtime'](before,after,'a'*40)
 accepted=_validate_lifecycle_contract_in(operation,model,sql,expected,receipt)
 return {**accepted,'database_runtime':observed}

class LifecycleReceipt(unittest.TestCase):
 def identity(self,op,end):
  digest=lambda text:hashlib.sha256(text.encode()).hexdigest()
  return {'operation':op,'owner_end':end,'subject_id_digest':digest('id-'+end),'subject_phid_digest':digest('phid-'+end),'client_id_digest':digest(op+end+'client-id'),'client_phid_digest':digest(op+end+'client-phid'),'authorization_id_digest':digest(op+end+'authorization'),'before_client_digest':'a'*64,'after_client_digest':None if op=='client-cascade'else('a'*64 if op=='authorization-delete'else'b'*64),'remaining_rows':[0,0,0]if op=='client-cascade'else([0,1,1]if op=='authorization-delete'else[1,1,1]),'owner_persistent_probe':'committed-probe'if end=='commit'else'rolled-back-original','query_order':[{'phase':phase,'kind':kind}for kind in ['client','authorization','access','code']for phase in ['begin','end']]}
 def fixture(self,model,sql,expected):
   data=baseline.template();facts={'profile':'isolated-native-oauth-client-lifecycle-v1','executed_experiments':12,'executed_case_obligations':sorted(sql),'source_identities':[self.identity(op,end)for op in model['operations'] for end in ['commit','rollback']],'native_scope_registry_unchanged':True,'real_Store_owner_and_native_lifecycle':True,'native_query_order_observed':True,'exact_record_lock_wait_observed':True,'both_owned_native_SQL_threads_observed':True,'owned_child_exit0_proved':True,'production_observer_factory_available':False,'continuous_admin_lease_proved':False,'physical_ACK_loss_proved':False,'all_13_sources_complete':False,'permit':False,'restoration_authorized':False,'execution_blocked':True,'observer_cleanup':{'owned_observer_removed':True,'owned_observer_SQL_sessions_absent':True,'native_writer_grants_unchanged':True,'cleanup_proved':True},'both_native_identity_epochs_prewarmed':True,'source_revision':'a'*40,'default_native_User_writer':True,'forced_connection_used':False}
   data.update(batch='native-oauth-client-lifecycle-owner',scope='owned-native-oauth-client-lifecycle-no-permit',checks=sorted(expected),check_count=62,native=facts,worker_cleanup={'private_directories_removed':12,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':12},file_service_cleanup={'kind':'file','binary_sha256':'b'*64,'process_stopped':True,'private_storage_removed':True},successful_owned_file_service_startups=1,remaining_blocked=['all-thirteen-source-families','creator-and-absence-phantoms','unreviewed-external-writers','physical-ACK-loss','production-provider-and-activation'])
   return data
 def test_strict_twelve_experiments_and_fifty_two_SQL_obligations(self):
   from task_inverse_test_api_v2 import owner
   with owner(ROOT)as operation:
    model,sql,expected=_lifecycle_model_in(operation,ROOT)
    result=_validate_lifecycle_contract_in(operation,model,sql,expected,self.fixture(model,sql,expected));self.assertEqual(result['SQL_obligations'],52);self.assertEqual(result['native_experiments'],12);self.assertEqual(result['checks'],62);self.assertFalse(result['permit']or result['runtime_authority'])
 def test_missing_order_wrong_cleanup_identity_and_authority_are_rejected(self):
   from task_inverse_test_api_v2 import owner
   with owner(ROOT)as operation:
    model,sql,expected=_lifecycle_model_in(operation,ROOT)
    for mode in ['label','order','phase','subject','client','rows','bool','cleanup','authority','ACK','extra','restore']:
     data=self.fixture(model,sql,expected);row=data['native']['source_identities'][0]
     if mode=='label':data['checks'].pop()
     elif mode=='order':row['query_order']=row['query_order'][2:]+row['query_order'][:2]
     elif mode=='phase':row['query_order'][0]['phase']='end'
     elif mode=='subject':data['native']['source_identities'][2]['subject_id_digest']='f'*64
     elif mode=='client':data['native']['source_identities'][1]['client_id_digest']=row['client_id_digest']
     elif mode=='rows':row['remaining_rows']=[0,0,0]
     elif mode=='bool':row['remaining_rows']=[True,1,1]
     elif mode=='cleanup':data['worker_cleanup']['private_directories_remaining']=1
     elif mode=='authority':data['native']['permit']=True
     elif mode=='ACK':data['native']['physical_ACK_loss_proved']=True
     elif mode=='restore':data['source_restore']['quickstart_sha256']='0'*64
     else:row['token']='CANARY_SECRET'
     with self.subTest(mode=mode),self.assertRaises(ValueError):_validate_lifecycle_contract_in(operation,model,sql,expected,data)
 def test_raw_source_unknown_byte_requires_exact_pin(self):
   # Real current-source boundary, not a patched legacy native_source reader.
   from task_inverse_test_api_v2 import owner
   from task_ci_workflow_test_api_v2 import workflow_data_in_operation
   with owner(ROOT)as operation:
    model,sql,expected=_lifecycle_model_in(operation,ROOT)
    self.assertGreaterEqual(len(model['source_inputs']),17)
    name=next(iter(model['source_inputs']));raw=operation._task_contexts[ROOT].current(name)
    self.assertEqual(workflow_data_in_operation(operation,ROOT,[name],{name:raw})['workflows'][name]['current'],raw)
    with self.assertRaises(ValueError):workflow_data_in_operation(operation,ROOT,[name],{name:raw+b'// unknown'})
    poisoned=dict(model['raws']);poisoned[model['checker_name']]+=b'\n# unknown current checker bytes'
    with self.assertRaises(ValueError):model['adapter'].model(poisoned,'oauth-client-lifecycle')

 def test_database_version_UUID_and_raw_receipt_remain_independent(self):
   from task_inverse_test_api_v2 import owner
   with owner(ROOT)as operation:
    model,sql,expected=_lifecycle_model_in(operation,ROOT)
    before={'schema':'native-oauth-authorization-mysql-runtime-v1','phase':'before','source_revision':'a'*40,'version':'8.4.13','server_uuid_sha256':'c'*64,'observed_service_image':qualified.IMAGE};after={**before,'phase':'after'}
    self.assertEqual(_qualify_lifecycle_contract_in(operation,model,sql,expected,self.fixture(model,sql,expected),before,after)['database_runtime']['mysql_version'],'8.4.13')
    for mutation in [{'version':'8.0.44'},{'server_uuid_sha256':'d'*64},{'observed_service_image':'mysql:8.4.13'}]:
     with self.assertRaises(ValueError):_qualify_lifecycle_contract_in(operation,model,sql,expected,self.fixture(model,sql,expected),before,{**after,**mutation})
    with self.assertRaises(ValueError):_qualify_lifecycle_contract_in(operation,model,sql,expected,{'passed':True},before,after)
class LifecycleWorkflow(unittest.TestCase):
 def test_only_exact_named_workflow_is_admitted_and_unknown_bytes_refuse(self):
  import recovery_oauth_client_lifecycle_ci_delta as delta
  from task_inverse_test_api_v2 import owner
  from task_ci_workflow_test_api_v2 import workflow_data_in_operation
  with owner(ROOT)as operation:
   view=workflow_data_in_operation(operation,ROOT,[delta.WORKFLOW]);self.assertFalse(view['source_operation_exit_verified']);raw=view['workflows'][delta.WORKFLOW]['current'];parent=view['workflows'][delta.WORKFLOW]['parent'];self.assertEqual(raw,WORKFLOW.read_bytes())
   self.assertTrue(delta.assert_workflow(delta.WORKFLOW,parent))
   self.assertEqual(workflow_data_in_operation(operation,ROOT,[delta.WORKFLOW],{delta.WORKFLOW:raw})['workflows'][delta.WORKFLOW]['current'],raw)
   for name,body in [(delta.WORKFLOW,raw+b'\n# unknown mutation'),('.github/workflows/unknown.yml',raw)]:
    with self.assertRaises(ValueError):workflow_data_in_operation(operation,ROOT,[name],{name:body})
   with self.assertRaises(ValueError):delta.assert_workflow(delta.WORKFLOW,parent+b'\n# unknown parent mutation')
   with self.assertRaises(ValueError):delta.assert_workflow('.github/workflows/unknown.yml',parent)

 def test_path_triggers_include_source_and_all_new_control_files_not_unrelated_docs(self):
  import fnmatch,yaml
  workflow=yaml.safe_load(WORKFLOW.read_text());on=workflow.get('on',workflow.get(True));paths=_full_trigger_contract(WORKFLOW.read_bytes(),'.github/workflows/oauth-client-lifecycle-focused.yml');matches=lambda p:any(fnmatch.fnmatchcase(p,x)for x in paths)
  for path in ['.github/workflows/oauth-client-lifecycle-focused.yml','src/applications/system/engine/PhabricatorDestructionEngine.php','src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php','tests/governance/test_recovery_oauth_client_lifecycle.py','tests/governance/test_recovery_oauth_client_lifecycle_sql.py','tests/governance/test_recovery_oauth_client_lifecycle_ci.py']:
   self.assertTrue(matches(path),path)
  self.assertFalse(matches('src/applications/oauthserver/storage/PhabricatorOAuthServerClient.php'))
  self.assertFalse(matches('docs/unrelated.md'));self.assertIn('workflow_dispatch',on);self.assertEqual(workflow['permissions'],{'contents':'read'})
 def test_real_File_only_builder_exact_database_and_no_oldmatrix_substitution(self):
  from task_ci_workflow_test_api_v2 import workflow_data
  name='.github/workflows/oauth-client-lifecycle-focused.yml';proof=workflow_data(ROOT,name);self.assertTrue(proof['source_operation_exit_verified']);current=proof['current'].decode();text=proof['parent'].decode();self.assertIn('module[\'build_file\'](',text);self.assertNotIn("module['build'](",text);self.assertIn(qualified.IMAGE,text);self.assertIn('test-oauth-client-lifecycle-mysql.php --oauth-client-lifecycle-owner',text);self.assertIn('qualify_oauth_client_lifecycle_mysql84.py',text);self.assertNotIn('secrets.',text)
  self.assertLess(text.index('PyYAML==6.0.3'),text.index('-p \'test_recovery_oauth_client_lifecycle*.py\''));self.assertLess(text.index('--source-only --source-revision'),text.index('php scripts/runtime/build.php all'))
  for required in ['run_task_directory_native_v2.py','--role oauth-client-lifecycle','check_oauth_task_schema_receipt_v2.py','--expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT"','probe_oauth_authorization_mysql84.php before','probe_oauth_authorization_mysql84.php after',qualified.IMAGE,"module['build_file'](",'tests/atomic/build_directory_file.py']:
   self.assertIn(required,current)
  self.assertNotIn("module['build'](",current);self.assertNotIn('secrets.',current)
  self.assertLess(current.index('check_directory_transports_task_source_v2.py --expected-checkpoint'),current.index('php scripts/runtime/build.php all'))
  self.assertLess(current.index('PyYAML==6.0.3'),current.index('-p \'test_recovery_oauth_client_lifecycle*.py\''))
 def test_actual_workflow_shells_parse_and_failure_artifact_is_fixed_safe_path(self):
  import yaml
  workflow=yaml.safe_load(WORKFLOW.read_text());job=workflow['jobs']['oauth-client-lifecycle-owner'];runs=[v['run']for v in job['steps']if'run'in v]
  for body in runs:
   result=subprocess.run(['bash','-n'],input=body,capture_output=True,text=True);self.assertEqual(result.returncode,0,result.stderr)
  uploads=[v for v in job['steps']if'upload-artifact@'in v.get('uses','')];self.assertEqual(len(uploads),2);self.assertEqual(uploads[1]['with']['path'],'${{ runner.temp }}/oauth-client-lifecycle-focused-failure/diagnostic.json');self.assertIn("diagnostic_owned == 'true'",uploads[1]['if'])
 def actual_failed_envelope(self,label):
  # Execute only the real CLI's final JSON expression with inert local values.
  # No bootstrap/config/fixture/SQL/component code from the CLI is executed.
  code=r'''$s=file_get_contents($argv[1].'/support/governance/native-organization/test-oauth-client-lifecycle-mysql.php');$a=strpos($s,"echo json_encode(array('batch'");$b=strpos($s,'exit($passed?0:1);',$a);if($a===false||$b===false)exit(2);$passed=false;$checks=[];$summary=[];$native=null;$reader_adapter_exercised=false;$worker_cleanup=null;$file_service_cleanup=null;$cleanup=[];$cleanup_failures=[];$native_output='CANARY_PRIVATE_TRACE';$failure=['stage'=>'focused-native-oauth-client-lifecycle-producer-owner','error_type'=>'RuntimeException','source_file'=>'oauth-client-lifecycle-cases.php','source_line'=>10,'diagnostic'=>['stage'=>'focused-native-oauth-client-lifecycle-producer-owner','attempt_stage'=>$argv[2],'error_type'=>'RuntimeException','source_file'=>'oauth-client-lifecycle-cases.php','source_line'=>10,'message'=>'CANARY_SECRET','token'=>'CANARY_SECRET']];eval(substr($s,$a,$b-$a));'''
  result=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen','-r',code,str(ROOT),label],capture_output=True,text=True,timeout=5)
  self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(result.stderr,'');value=json.loads(result.stdout)
  self.assertEqual(value['batch'],'native-oauth-client-lifecycle-owner');self.assertFalse(value['passed']);return value
 def test_actual_CLI_failed_envelope_old_projector_red_new_green_no_SQL(self):
  label='oauth-client-lifecycle-trust-commit-native-writer-exact-record-wait'
  data=self.actual_failed_envelope(label)
  current=(ROOT/'scripts/governance/project_oauth_client_lifecycle_failure.py').read_bytes()
  before=b"data.get('batch')!='native-oauth-client-lifecycle-owner'";after=b"data.get('batch')!='native-oauth-client-lifecycle-producer-owner'"
  self.assertEqual(current.count(before),1);old=current.replace(before,after,1)
  self.assertEqual(hashlib.sha256(old).hexdigest(),'896e30dd4cdbecf28a7989b35388dacf008f6b9320f08ca49a0c064814757378')
  prior={'__name__':'reviewed_old_projection'};exec(compile(old,'reviewed-old-projector','exec'),prior)
  with tempfile.TemporaryDirectory()as td:
   p=Path(td).resolve()/'receipt.json';p.write_text(json.dumps(data));p.chmod(0o600)
   red=prior['project'](p);self.assertFalse(red['diagnostic_available'])
   green=projector.project(p);self.assertTrue(green['diagnostic_available']);self.assertEqual(green['attempt_stage'],label);self.assertFalse(green['passed']);self.assertNotIn('CANARY_SECRET',json.dumps(green));self.assertNotIn('CANARY_PRIVATE_TRACE',json.dumps(green))
 def test_wrong_batch_is_not_adopted_from_valid_stderr_and_missing_receipt_can_fallback(self):
  label='oauth-client-lifecycle-trust-commit-native-writer-exact-record-wait';data=self.actual_failed_envelope(label)
  protocol={'scope':'native-oauth-client-lifecycle-failure-v1','passed':False,'diagnostic_available':True,**data['failure']['diagnostic']}
  with tempfile.TemporaryDirectory()as td:
   root=Path(td).resolve();p=root/'receipt.json';err=root/'stderr.private';err.write_text(json.dumps(protocol)+'\n');err.chmod(0o600)
   data['batch']='native-oauth-client-lifecycle-producer-owner';p.write_text(json.dumps(data));p.chmod(0o600)
   self.assertFalse(projector.project(p,err)['diagnostic_available'])
   p.unlink();out=projector.project(p,err);self.assertTrue(out['diagnostic_available']);self.assertEqual(out['attempt_stage'],label);self.assertNotIn('CANARY_SECRET',json.dumps(out))
if __name__=='__main__':unittest.main()
