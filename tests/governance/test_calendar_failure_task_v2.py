"""Original private FD failure transport DATA; no source/SQL/cleanup qualification."""
import contextlib,hashlib,importlib.util,io,json,os,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2];REFERENCE=Path(os.environ.get('CALENDAR_TEST_SOURCE_ROOT',str(ROOT))).resolve();sys.path.insert(0,str(REFERENCE/'scripts/governance'))
def load(path):
 spec=importlib.util.spec_from_file_location('Calendar_failure_DATA_'+path.stem,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
adapter=load(ROOT/'scripts/governance/project_calendar_failure_task_v2.py');original=load(REFERENCE/adapter.ORIGINAL)
class FailureDATA(unittest.TestCase):
 def run_body(self,data,*,source_fail=False,missing=False,foreign=False,wrong_path=False):
  with tempfile.TemporaryDirectory()as temp:
   runner=Path(temp).resolve();rawdir=runner/adapter.RAW_DIRECTORY;rawdir.mkdir(mode=0o700);failure=runner/'governance-private-calendar-failure';failure.mkdir(mode=0o700);receipt=rawdir/adapter.RAW_FILE;output=failure/'diagnostic.json'
   if not missing:receipt.write_text(json.dumps(data));receipt.chmod(0o600)
   if foreign:output.write_bytes(b'foreign');output.chmod(0o600)
   if wrong_path:receipt=runner/'unknown.json'
   calls=[]
   def verify():
    calls.append(True)
    if source_fail:raise ValueError('PRIVATE_SOURCE_MESSAGE')
    return dict(original.calendar.SOURCE_INPUTS)
   before=receipt.read_bytes()if receipt.exists()else None;stderr=io.StringIO();main=adapter.body((REFERENCE/adapter.ORIGINAL).read_bytes(),original,verify,receipt,output)
   with patch.dict(os.environ,{'RUNNER_TEMP':str(runner)}),contextlib.redirect_stderr(stderr):
    if foreign or wrong_path:
     with self.assertRaises(SystemExit):main()
     if foreign:self.assertEqual(output.read_bytes(),b'foreign')
     return
    main()
   safe=json.loads(output.read_bytes());self.assertEqual(stderr.getvalue(),output.read_text());self.assertEqual(output.stat().st_mode&0o777,0o600);self.assertFalse(safe['SQL_success_proved']);self.assertNotIn('PRIVATE',output.read_text())
   self.assertEqual(receipt.read_bytes()if receipt.exists()else None,before);self.assertEqual(calls,[True]);return safe
 def failed(self):return {'passed':False,'batch':'native-private-calendar-reminders','scope':'owned-native-calendar-reminders-no-SMTP','checks':[],'check_count':0,'failure':{'stage':'focused-native-calendar-reminders','error_type':'RuntimeException','source_file':'calendar-reminder-cases.php','source_line':7,'message':'PRIVATE_TOKEN'},'cleanup':{},'worker_cleanup':{},'cleanup_failures':[]}
 def test_exact_raw_path_and_original_safe_projection(self):
  result=self.run_body(self.failed());self.assertTrue(result['diagnostic_available']);self.assertIsNone(result['cleanup']['owned_schemas_removed'])
 def test_missing_wrong_profile_and_source_refusal_only_unavailable(self):
  for kwargs,data in [({'missing':True},self.failed()),({'source_fail':True},self.failed()),({},dict(self.failed(),passed=True)),({},dict(self.failed(),batch='unknown'))]:
   safe=self.run_body(data,**kwargs);self.assertEqual(safe,original.unavailable())
 def test_foreign_output_and_unknown_receipt_role_refuse(self):
  self.run_body(self.failed(),foreign=True);self.run_body(self.failed(),wrong_path=True)
 def test_original_pin_drift_refused(self):
  with self.assertRaises(ValueError):adapter.body((REFERENCE/adapter.ORIGINAL).read_bytes()+b'\n',original,lambda:None,'unused','unused')
 def test_real_CLI_unknown_arguments_never_echo_values_or_create_output(self):
  import subprocess
  with tempfile.TemporaryDirectory()as temp:
   output=Path(temp)/'diagnostic.json';env=dict(os.environ,PYTHONPATH=str(REFERENCE/'scripts/governance'),PYTHONDONTWRITEBYTECODE='1')
   p=subprocess.run([sys.executable,'-B',str(ROOT/'scripts/governance/project_calendar_failure_task_v2.py'),'--root',str(REFERENCE),'--expected-checkpoint','a'*40,'--receipt',str(Path(temp)/'missing.json'),'--output',str(output),'--unreviewed-argument','PRIVATE_SECRET_VALUE'],capture_output=True,env=env,timeout=5)
   self.assertNotEqual(p.returncode,0);self.assertEqual(p.stdout,b'');self.assertEqual(p.stderr,b'Calendar diagnostic invocation unavailable\n');self.assertFalse(output.exists())
 def test_real_owner_refusal_precedes_capture(self):
  with patch.object(adapter,'ROOT',REFERENCE),patch.object(adapter.source,'open_task_source_operation',side_effect=ValueError('closed'))as factory:
   with self.assertRaises(ValueError):adapter.project(REFERENCE,'a'*40,'unused','unused')
   factory.assert_called_once_with(REFERENCE,expected_checkpoint='a'*40)
if __name__=='__main__':unittest.main()
