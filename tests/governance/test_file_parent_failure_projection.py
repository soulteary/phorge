import importlib.util,json,subprocess,sys,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
SPEC=importlib.util.spec_from_file_location('projection',ROOT/'scripts/governance/project_file_parent_failure.py');P=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(P)
class FailureProjection(unittest.TestCase):
 def test_location_only_and_no_sensitive_fields(self):
  data={'passed':False,'failure':{'stage':'focused-native-File-parent-policy','error_type':'RuntimeException','source_file':'PhorgeRecoveryFileSourceFacts.php','source_line':123,'sql_errno':1142,'message':'PRIVATE','password':'PRIVATE'},'native':'PRIVATE'}
  out=P.project(data);self.assertEqual(out['source_line'],123);self.assertEqual(out['sql_errno'],1142);self.assertNotIn('PRIVATE',json.dumps(out));self.assertFalse(out['passed'])
  for key in ('stage','error_type','source_file'):data['failure'][key]='PRIVATE'
  data['failure']['source_line']=True;out=P.project(data);self.assertNotIn('PRIVATE',json.dumps(out));self.assertNotIn('source_line',out)
 def test_missing_or_success_never_accepted(self):
  for data in (None,{},[],{'passed':True,'failure':{}},{'passed':False,'failure':None}):
   out=P.project(data);self.assertFalse(out['diagnostic_available']);self.assertFalse(out['passed'])
 def test_actual_cli_malformed_and_symlink_do_not_dump_input(self):
  with tempfile.TemporaryDirectory()as temp:
   p=Path(temp)/'receipt';p.write_text('PRIVATE');link=Path(temp)/'alias';link.symlink_to(p)
   for path in (p,link,Path(temp)/'missing'):
    r=subprocess.run([sys.executable,str(ROOT/'scripts/governance/project_file_parent_failure.py'),'--receipt',str(path)],text=True,capture_output=True,timeout=5)
    self.assertEqual(r.returncode,0);self.assertFalse(json.loads(r.stdout)['diagnostic_available']);self.assertNotIn('PRIVATE',r.stdout+r.stderr)
