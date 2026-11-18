"""Current-main source views and runnable focused CLI; no physical SQL claim."""
from recovery_oauth_code_test_cohort import complete_source_cohort
from n05_legacy_audit_test_view import historical_source_inputs
from pathlib import Path
import ast,copy,hashlib,json,os,subprocess,sys,tempfile,unittest
from unittest.mock import patch
import yaml
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'scripts/governance'))


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

import sys
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_file_parent_policy as driver_admission
import check_recovery_assessment_credentials as historical
import check_recovery_assessment_credentials_v2 as current
import project_assessment_credential_failure as diagnostic
DRIVER='support/governance/native-organization/test-native-organization-mysql.php'
WORKER='support/governance/native-organization/workers.php'
def _historical_source_inputs_in(operation,root,path):
 import source_operation_snapshot as source
 source.require_task_operation(operation,root);context=operation._task_contexts[Path(root)]
 # Current audit-helper constants and historical module bytes are DATA only.
 # The genuine owner authenticates the whole current tree, modes and aliases.
 audit=context.current('tests/governance/n05_legacy_audit_test_view.py');literals={}
 for node in ast.parse(audit).body:
  if isinstance(node,ast.Assign)and len(node.targets)==1 and isinstance(node.targets[0],ast.Name):
   try:literals[node.targets[0].id]=ast.literal_eval(node.value)
   except(ValueError,TypeError):pass
 if path not in literals['CLOSED_PATHS']or path not in literals['CURRENT_PINS']:raise ValueError('unreviewed_legacy_source_map_path')
 n05=operation._modules['project_description_admission_delta'];n05.assert_current_recipe(root)
 raw=context.parent(path)
 stage=n05._normalize_metadata(path,raw)if path in n05.CURRENT_METADATA else raw
 git=lambda revision:subprocess.check_output(['git','--no-replace-objects','-C',str(root),'show',revision+':'+path])
 if type(stage)is not bytes or hashlib.sha256(stage).hexdigest()!=literals['CURRENT_PINS'][path]or stage!=git(literals['CURRENT_REVISION']):raise ValueError('unknown_legacy_current_map_DATA')
 prior=n05.reduce_if_selected(path,stage)
 if prior!=git(literals['PRIOR_REVISION']):raise ValueError('unknown_legacy_prior_map_DATA')
 return next(ast.literal_eval(n.value)for n in ast.parse(prior).body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='SOURCE_INPUTS'for t in n.targets))
def _copy_current_producer_tree_in(operation,root,destination):
 import stat
 import source_operation_snapshot as source
 root=Path(root);destination=Path(destination)
 source.require_task_operation(operation,root)
 if not destination.is_absolute()or destination.resolve()!=destination or any(destination.iterdir()):raise ValueError('producer_copy_destination_refused')
 context=operation._task_contexts[root]
 for relative,row in context.entries.items():
  target=destination/relative;target.parent.mkdir(parents=True,exist_ok=True)
  if row['kind']=='symlink':
   actual=root/relative
   if source.task_admission.stamp(actual.lstat())!=context.files[relative]or os.readlink(actual)!=row['target']:raise ValueError('producer_copy_alias_drift')
   os.symlink(row['target'],target)
  else:
   raw=context.current(relative)
   fd=os.open(target,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
   with os.fdopen(fd,'wb')as stream:stream.write(raw)
   target.chmod(row['mode'])
class CurrentAssessment(unittest.TestCase):
 def test_historical_source_map_and_consumer_preserved_current_exact_inverse_only(self):
   from task_inverse_test_api_v2 import owner
   from check_assessment_task_schema_receipt_v2 import captured_module
   with owner(ROOT)as operation:
    current=captured_module(operation,ROOT,'scripts/governance/check_recovery_assessment_credentials_v2.py')
    path='scripts/governance/check_recovery_assessment_credentials.py'
    raw=operation.coordinator.source_view(path,operation.read_producer(ROOT/path))
    self.assertEqual(hashlib.sha256(raw).hexdigest(),'d9cc649ed637a80abbc8d962ee2eeaf740920c472d10d21fc17b2d4c564bb609')
    self.assertEqual(historical.SOURCE_INPUTS,current.SOURCE_INPUTS);self.assertEqual(len(current.SOURCE_INPUTS),220)
    self.assertEqual(len(current.ADAPTER_SOURCE_INPUTS),2);self.assertEqual(len(current.SQL_obligations(ROOT,_source_operation=operation)),8)
    manifest=json.loads((ROOT/'docs/governance/recovery-assessment-credential-SQL-source-v2.json').read_bytes());self.assertEqual(manifest['source_sha256'],_historical_source_inputs_in(operation,ROOT,'scripts/governance/check_recovery_assessment_credentials_v2.py'));self.assertEqual(manifest['reviewed_source_adapter_sha256'],current.ADAPTER_SOURCE_INPUTS);self.assertFalse(manifest['physical_SQL_cases_executed'])
    with self.assertRaises(ValueError):historical.SQL_obligations()
    for path in (DRIVER,WORKER):
     body=operation.coordinator.source_view(path,operation.read_producer(ROOT/path))
     old=current.source_view(path,body,current.SOURCE_INPUTS[path],_source_operation=operation)
     self.assertEqual(hashlib.sha256(old).hexdigest(),{DRIVER:'adc0a4016c22c836942905c2072ded5e52975932699e58971597edb52056b42a',WORKER:'11df3cffa66837d56ffaf720a65846ed33e4aede6213893ffb5db817436f169d'}[path])
     self.assertEqual(current.source_view(path,body,current.SOURCE_INPUTS[path],_source_operation=operation),old)
     self.assertEqual(current.source_view(path,old,current.SOURCE_INPUTS[path]),old)
     with self.assertRaises(ValueError):current.source_view(path,body+b'\n// unrelated',current.SOURCE_INPUTS[path],_source_operation=operation)
    # Frozen genuine graph hashes cannot be poisoned; pure reducer negative
    # controls below remain independent and retain the original mutable modules.
    metadata=operation._modules['project_description_admission_delta'].CURRENT_METADATA
    selected=next(iter(metadata))
    with self.assertRaises(TypeError):metadata[selected]=dict(metadata[selected])
    with self.assertRaises(TypeError):metadata[selected]['sha256']='0'*64
   worker=current.worker_terminal;bad=(ROOT/WORKER).read_bytes()+b'\n// unrelated'
   with patch.object(worker,'CURRENT_SHA256',hashlib.sha256(bad).hexdigest()):
    with self.assertRaises(ValueError):current.source_view(WORKER,bad,current.SOURCE_INPUTS[WORKER])
   calendar=current.calendar_delta;bad=(ROOT/DRIVER).read_bytes()+b'\n// unrelated'
   with patch.object(calendar,'DRIVER_SHA256',hashlib.sha256(bad).hexdigest()):
    with self.assertRaises(ValueError):current.source_view(DRIVER,bad,current.SOURCE_INPUTS[DRIVER])
 def test_actual_captured_adapter_corruption_refuses_before_case_obligations(self):
   from task_inverse_test_api_v2 import owner
   from check_assessment_task_schema_receipt_v2 import captured_module
   with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as temp:
    root=Path(temp)
    with owner(ROOT)as operation:
     current=captured_module(operation,ROOT,'scripts/governance/check_recovery_assessment_credentials_v2.py')
     expected=current.SQL_obligations(ROOT,_source_operation=operation)
     _copy_current_producer_tree_in(operation,ROOT,root)
    # New owner captures this complete, mode-exact producer. No old partial
    # recipe/root switch; no source reader or issuer replacement.
    with owner(root)as producer:
     self.assertEqual(current.SQL_obligations(root,_source_operation=producer),expected)
    p=root/next(iter(current.ADAPTER_SOURCE_INPUTS));p.write_bytes(p.read_bytes()+b'\n# unrelated')
    entered=False
    with self.assertRaises(ValueError):
     with owner(root)as producer:
      entered=True
      current.SQL_obligations(root,_source_operation=producer)
    self.assertFalse(entered)

 def test_real_fixed_failure_projection_only_for_assessment_no_canary_export(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as temp:
   p=Path(temp)/'receipt';p.touch(mode=0o600)
   failure={'stage':'focused-native-oauth-access-producer-owner','attempt_stage':'physical-cases','error_type':'RuntimeException','source_file':'PhorgeRecoveryRestoreService.php','source_line':123,'message':'PRIVATE_CANARY','raw_token':'PRIVATE_CANARY'}
   p.write_text(json.dumps({'batch':'native-assessment-credential-readset','passed':False,'failure':{'oauth_access_diagnostic':failure}}));out=diagnostic.project(p);self.assertEqual(out['scope'],'native-assessment-credential-failure-v1');self.assertEqual(out['source_line'],123);self.assertNotIn('PRIVATE_CANARY',json.dumps(out));self.assertFalse(out['passed'])
   p.write_text(json.dumps({'batch':'native-oauth-access-producer-owner','passed':False,'failure':failure}));self.assertFalse(diagnostic.project(p)['diagnostic_available'])
 def test_focused_real_entry_binary_map_and_success_only_artifact_contract(self):
  path=ROOT/'.github/workflows/assessment-credential-focused.yml';workflow=yaml.safe_load(path.read_text());steps=workflow['jobs']['assessment-credential-owner']['steps'];run='\n'.join(s.get('run','')for s in steps)
  events=workflow.get('on',workflow.get(True));self.assertEqual(set(events),{'workflow_call','workflow_dispatch'});paths=_full_trigger_contract(path.read_bytes(),'.github/workflows/assessment-credential-focused.yml')
  self.assertIn('.github/workflows/assessment-credential-focused.yml',paths)
  for source in ('auth','conduit','oauthserver'):self.assertNotIn('src/applications/'+source+'/**',paths)
  self.assertIn('tests/governance/test_recovery_assessment_credential*.py',paths)
  self.assertIn('workflow_dispatch',events)
  self.assertFalse(any('assessment-credential-producer-focused' in x or 'src/applications/assessment-credential/' in x for x in paths))
  from task_ci_workflow_test_api_v2 import workflow_data
  view=workflow_data(ROOT,'.github/workflows/assessment-credential-focused.yml')
  self.assertEqual(path.read_bytes(),view['current'])
  parent=yaml.safe_load(view['parent']);old_run='\n'.join(step.get('run','')for step in parent['jobs']['assessment-credential-owner']['steps'])
  self.assertIn('test-assessment-credential-readset-mysql.php --assessment-credential-readset',old_run);self.assertIn('check_recovery_assessment_credentials_v2.py',old_run);self.assertIn('--file-binary-sha256',old_run);self.assertIn('sha256sum --check',run)
  self.assertIn('scripts/governance/run_task_directory_native_v2.py',run);self.assertIn('--role assessment-credential',run)
  self.assertIn('scripts/governance/check_assessment_task_schema_receipt_v2.py',run);self.assertIn('--file-sha',run)
  self.assertIn('probe_assessment_task_mysql84.php before',run);self.assertIn('probe_assessment_task_mysql84.php after',run)
  self.assertIn('--expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT"',run)
  self.assertEqual(workflow['jobs']['assessment-credential-owner']['services']['mysql']['image'],'mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7')
  self.assertEqual({k:v for k,v in workflow.items()if k not in ('jobs','on',True,'concurrency')},{k:v for k,v in parent.items()if k not in ('jobs','on',True,'concurrency')})
  self.assertEqual([step.get('name')for step in steps if step.get('name')not in ('Bind external event head and isolated PUBLIC verification home','Require exact full-tier caller checkpoint')],[step.get('name')for step in parent['jobs']['assessment-credential-owner']['steps']])
  self.assertEqual(run.count('php bin/rebuild-library-map'),2);self.assertNotIn('unittest discover',run)
  for step in steps:
   if step.get('run'):self.assertEqual(subprocess.run(['bash','-n'],input=step['run'],text=True,capture_output=True).returncode,0)
   if step.get('uses','').startswith('actions/upload-artifact'):
    self.assertIn(step['if'],['success()',"failure() && steps.assessment-credential-owner.outputs.diagnostic_owned == 'true'"])
    if step['if']!='success()':self.assertTrue(step['with']['path'].endswith('/diagnostic.json'))
 def test_actual_failure_shell_retains_native37_if_safe_print_or_marker_fails(self):
  workflow=yaml.safe_load((ROOT/'.github/workflows/assessment-credential-focused.yml').read_text());steps=workflow['jobs']['assessment-credential-owner']['steps'];body=next(s['run']for s in steps if s.get('id')=='assessment-credential-owner')
  # Execute the actual workflow failure handler with own directories and real
  # shell failures; native phase itself is an explicit status37 collaborator.
  block=body[body.index('native_status=$?'):body.index('\n}',body.index('native_status=$?'))] if '\n}'in body[body.index('native_status=$?'):] else body[body.index('native_status=$?'):body.index('\n          }',body.index('native_status=$?'))]
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as temp:
   d=Path(temp);(d/'governance-assessment-credential-focused').mkdir();(d/'governance-assessment-credential-focused/integration.json').write_text('{}')
   for mode in ['cat-fails','marker-fails','foreign-directory']:
    fail=d/'assessment-credential-focused-failure'
    if fail.exists():
     import shutil;shutil.rmtree(fail)
    if mode=='foreign-directory':fail.mkdir()
    script='set -euo pipefail\npython3(){\n if [[ "$#" != 6 || "$1" != -B || "$2" != scripts/governance/project_assessment_credential_failure.py || "$3" != --receipt || "$4" != "$RUNNER_TEMP/task-assessment-credential-v2-private/capture-native-body.private.json" || "$5" != --stderr || "$6" != "$RUNNER_TEMP/assessment-credential-focused-native.private.stderr" ]]; then printf \'%s\\n\' unexpected-offline-projector-argv >&2; return 61; fi\n printf \'%s\\n\' \'{"scope":"safe"}\'\n}\n'
    if mode=='cat-fails':script+='cat(){ return 52; }\n'
    script+='(exit 37) || {\n'+block+'\n}\n'
    r=subprocess.run(['bash','-c',script],env={**os.environ,'RUNNER_TEMP':str(d),'GITHUB_OUTPUT':str(d)},capture_output=True,text=True);self.assertEqual(r.returncode,37,r.stderr);self.assertNotIn('unexpected-offline-projector-argv',r.stderr)
if __name__=='__main__':unittest.main()
