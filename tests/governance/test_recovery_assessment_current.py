"""Current-main source views and runnable focused CLI; no physical SQL claim."""
from recovery_oauth_code_test_cohort import complete_source_cohort
from n05_legacy_audit_test_view import historical_source_inputs
from pathlib import Path
import ast,copy,hashlib,json,os,subprocess,sys,tempfile,unittest
from unittest.mock import patch
import yaml
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_assessment_credentials as historical
import check_recovery_assessment_credentials_v2 as current
import project_assessment_credential_failure as diagnostic
DRIVER='support/governance/native-organization/test-native-organization-mysql.php'
WORKER='support/governance/native-organization/workers.php'
class CurrentAssessment(unittest.TestCase):
 def test_historical_source_map_and_consumer_preserved_current_exact_inverse_only(self):
  path='scripts/governance/check_recovery_assessment_credentials.py'
  raw=current.code_coord.source_view(path,(ROOT/path).read_bytes())
  self.assertEqual(hashlib.sha256(raw).hexdigest(),'d9cc649ed637a80abbc8d962ee2eeaf740920c472d10d21fc17b2d4c564bb609')
  self.assertEqual(historical.SOURCE_INPUTS,current.SOURCE_INPUTS);self.assertEqual(len(current.SOURCE_INPUTS),220)
  self.assertEqual(len(current.ADAPTER_SOURCE_INPUTS),2);self.assertEqual(len(current.SQL_obligations()),8)
  manifest=json.loads((ROOT/'docs/governance/recovery-assessment-credential-SQL-source-v2.json').read_bytes());self.assertEqual(manifest['source_sha256'],historical_source_inputs('scripts/governance/check_recovery_assessment_credentials_v2.py'));self.assertEqual(manifest['reviewed_source_adapter_sha256'],current.ADAPTER_SOURCE_INPUTS);self.assertFalse(manifest['physical_SQL_cases_executed'])
  with self.assertRaises(ValueError):historical.SQL_obligations()
  for path in (DRIVER,WORKER):
   body=(ROOT/path).read_bytes();old=current.source_view(path,body,current.SOURCE_INPUTS[path])
   self.assertEqual(hashlib.sha256(old).hexdigest(),{DRIVER:'adc0a4016c22c836942905c2072ded5e52975932699e58971597edb52056b42a',WORKER:'11df3cffa66837d56ffaf720a65846ed33e4aede6213893ffb5db817436f169d'}[path])
   self.assertEqual(current.source_view(path,body,current.SOURCE_INPUTS[path]),old)
   self.assertEqual(current.source_view(path,old,current.SOURCE_INPUTS[path]),old)
   with self.assertRaises(ValueError):current.source_view(path,body+b'\n// unrelated',current.SOURCE_INPUTS[path])
  worker=current.worker_terminal;bad=(ROOT/WORKER).read_bytes()+b'\n// unrelated'
  with patch.object(worker,'CURRENT_SHA256',hashlib.sha256(bad).hexdigest()):
   with self.assertRaises(ValueError):current.source_view(WORKER,bad,current.SOURCE_INPUTS[WORKER])
  calendar=current.calendar_delta;bad=(ROOT/DRIVER).read_bytes()+b'\n// unrelated'
  with patch.object(calendar,'DRIVER_SHA256',hashlib.sha256(bad).hexdigest()):
   with self.assertRaises(ValueError):current.source_view(DRIVER,bad,current.SOURCE_INPUTS[DRIVER])
 def test_actual_captured_adapter_corruption_refuses_before_case_obligations(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as temp:
   root=Path(temp)
   for name in set(current.SOURCE_INPUTS)|set(current.ADAPTER_SOURCE_INPUTS):
    p=root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/name).read_bytes());p.chmod(0o600)
   complete_source_cohort(root)
   self.assertEqual(current.SQL_obligations(root),current.SQL_obligations())
   p=root/next(iter(current.ADAPTER_SOURCE_INPUTS));p.write_bytes(p.read_bytes()+b'\n# unrelated')
   with self.assertRaises(ValueError):current.SQL_obligations(root)
 def test_real_fixed_failure_projection_only_for_assessment_no_canary_export(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as temp:
   p=Path(temp)/'receipt';p.touch(mode=0o600)
   failure={'stage':'focused-native-oauth-access-producer-owner','attempt_stage':'physical-cases','error_type':'RuntimeException','source_file':'PhorgeRecoveryRestoreService.php','source_line':123,'message':'PRIVATE_CANARY','raw_token':'PRIVATE_CANARY'}
   p.write_text(json.dumps({'batch':'native-assessment-credential-readset','passed':False,'failure':{'oauth_access_diagnostic':failure}}));out=diagnostic.project(p);self.assertEqual(out['scope'],'native-assessment-credential-failure-v1');self.assertEqual(out['source_line'],123);self.assertNotIn('PRIVATE_CANARY',json.dumps(out));self.assertFalse(out['passed'])
   p.write_text(json.dumps({'batch':'native-oauth-access-producer-owner','passed':False,'failure':failure}));self.assertFalse(diagnostic.project(p)['diagnostic_available'])
 def test_focused_real_entry_binary_map_and_success_only_artifact_contract(self):
  path=ROOT/'.github/workflows/assessment-credential-focused.yml';workflow=yaml.safe_load(path.read_text());steps=workflow['jobs']['assessment-credential-owner']['steps'];run='\n'.join(s.get('run','')for s in steps)
  events=workflow.get('on',workflow.get(True));paths=events['pull_request']['paths']
  self.assertIn('.github/workflows/assessment-credential-focused.yml',paths)
  for source in ('auth','conduit','oauthserver'):self.assertIn('src/applications/'+source+'/**',paths)
  self.assertFalse(any('assessment-credential-producer-focused' in x or 'src/applications/assessment-credential/' in x for x in paths))
  self.assertIn('test-assessment-credential-readset-mysql.php --assessment-credential-readset',run);self.assertIn('check_recovery_assessment_credentials_v2.py',run);self.assertIn('--file-binary-sha256',run);self.assertIn('sha256sum --check',run)
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
    script='set -euo pipefail\npython3(){ printf "%s\\n" \'{"scope":"safe"}\'; }\n'
    if mode=='cat-fails':script+='cat(){ return 52; }\n'
    script+='(exit 37) || {\n'+block+'\n}\n'
    r=subprocess.run(['bash','-c',script],env={**os.environ,'RUNNER_TEMP':str(d),'GITHUB_OUTPUT':str(d)},capture_output=True,text=True);self.assertEqual(r.returncode,37,r.stderr)
if __name__=='__main__':unittest.main()
