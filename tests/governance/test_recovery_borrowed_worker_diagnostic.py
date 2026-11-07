"""Failure-only fixed projection and actual source query/owned FS controls; no SQL."""
from pathlib import Path
import hashlib,json,subprocess,sys,tempfile,unittest,os
ROOT=Path(__file__).resolve().parents[2]
class WorkerDiagnostic(unittest.TestCase):
 def run_host(self,mode):
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/borrowed-worker-diagnostic-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertNotIn('SECRET',p.stdout);return json.loads(p.stdout)
 def test_actual_marker_native_identity_querychain_and_typed_observer_metadata(self):
  for mode in ['good','identity-fail','wrong-marker','observer-fail','epoch','session','wrong-schema','malformed']:
   d=self.run_host(mode);self.assertTrue(d['actual_worker_identity_chain']);self.assertFalse(d['physical_SQL_executed']);self.assertIsNone(d['error']);v=d['diagnostic'];self.assertTrue(v['available']);self.assertEqual(v['identity_started'],mode!='wrong-marker');self.assertEqual(v['lock_observation_succeeded'],mode not in ['observer-fail','malformed']);self.assertEqual(v['parent_user_lock_wait'],mode in ['good','identity-fail','wrong-marker']);self.assertEqual(v['parent_epoch_lock_wait'],mode=='epoch');self.assertEqual(v['parent_session_lock_wait'],mode=='session');self.assertEqual(v['result_available'],mode=='identity-fail');self.assertEqual(d['trace'],['identity','observer'])
 def test_owned_record_FIFO_hardlink_refused_before_metadata_query(self):
  for mode in ['fifo','hardlink']:
   d=self.run_host(mode);self.assertIsNotNone(d['error']);self.assertEqual(d['trace'],['identity'])
 def test_projection_rejects_raw_unknown_fields_boolean_numbers_and_unknown_class(self):
  code="require$argv[1].'/support/governance/recovery/borrowed-session-diagnostic.php';echo json_encode(governance_recovery_borrowed_failure_projection(['borrowed_worker'=>json_decode($argv[2],true)]));"
  v={'available':'yes','identity_started':1,'child_status':'SECRET','child_error_type':'SECRET','child_source_file':'/SECRET/private.json','child_source_line':True,'child_query_code':False,'message':'SECRET SQL password','parent_user_lock_wait':'true'}
  p=subprocess.run(['php','-r',code,str(ROOT),json.dumps(v)],capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr);self.assertNotIn('SECRET',p.stdout);d=json.loads(p.stdout)['borrowed_worker'];self.assertFalse(d['available']);self.assertFalse(d['identity_started']);self.assertIsNone(d['child_source_line']);self.assertIsNone(d['child_query_code']);self.assertIsNone(d['child_source_file']);self.assertFalse(d['parent_user_lock_wait'])
 def test_failure_public_CLI_only_fixed_projection_and_no_private_upload(self):
  with tempfile.TemporaryDirectory()as tmp:
   p=Path(tmp).resolve()/'receipt';p.touch(mode=0o600);p.write_text(json.dumps({'passed':False,'failure':{'stage':'focused-native-borrowed-session-owner','source_file':'workers.php','source_line':376,'message':'SECRET','borrowed_worker':{'available':True,'identity_started':True,'child_status':'SECRET'}}}))
   cmd=[sys.executable,str(ROOT/'scripts/governance/project_borrowed_failure.py'),'--receipt',str(p)];v=subprocess.run(cmd,capture_output=True,text=True,timeout=10);self.assertEqual(v.returncode,0,v.stderr);self.assertNotIn('SECRET',v.stdout);self.assertTrue(json.loads(v.stdout)['borrowed_worker']['identity_started'])
   p.write_text('{"passed":false,"passed":true}');v=subprocess.run(cmd,capture_output=True,text=True,timeout=10);self.assertFalse(json.loads(v.stdout)['diagnostic_available'])
  s=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text();self.assertIn("if: failure() && steps.borrowed-owner.outputs.diagnostic_owned == 'true'",s);self.assertIn('mkdir "$RUNNER_TEMP/governance-borrowed-session-failure"',s);self.assertIn('exit "$native_status"',s);self.assertIn('path: ${{ runner.temp }}/governance-borrowed-session-failure/diagnostic.json',s);self.assertNotIn('path: /tmp/governance-native-worker',s)

 def test_actual_failure_shell_foreign_directory_or_symlink_never_marks_owned_upload(self):
  s=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text();line=next(x for x in s.splitlines()if '--session-borrowed-owner >'in x);suffix=line[line.index('|| {'):]
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve();foreign=root/'foreign';foreign.mkdir();(foreign/'secret').write_text('unrelated')
   for mode in ['foreign-dir','foreign-link','good']:
    runner=root/mode;runner.mkdir();dest=runner/'governance-borrowed-session-failure';out=runner/'output';out.touch();receipt=runner/'governance-borrowed-session-owner';receipt.mkdir();p=receipt/'integration.json';p.touch(mode=0o600);p.write_text('{"passed":false,"failure":{"message":"SECRET"}}')
    if mode=='foreign-dir':dest.mkdir()
    elif mode=='foreign-link':dest.symlink_to(foreign,target_is_directory=True)
    env=dict(os.environ,RUNNER_TEMP=str(runner),GITHUB_OUTPUT=str(out));v=subprocess.run(['bash','-c','set -eu; umask 077; (exit 37) '+suffix],cwd=ROOT,env=env,capture_output=True,text=True,timeout=10);self.assertEqual(v.returncode,37);self.assertEqual(out.read_text()=='diagnostic_owned=true\n',mode=='good');self.assertEqual((foreign/'secret').read_text(),'unrelated');self.assertNotIn('SECRET',v.stderr)
