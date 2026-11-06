"""Strict synthetic consumer controls. No real SQL/controller/browser acceptance."""
from pathlib import Path
import copy,importlib.util,json,subprocess,sys,tempfile,unittest,os,stat
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_organization_approval_workbench as gate
spec=importlib.util.spec_from_file_location('workbench_native_template',ROOT/'tests/governance/test_native_organization.py');native=importlib.util.module_from_spec(spec);spec.loader.exec_module(native)
def template():
 d=native.template();d.update(batch='native-organization-approval-workbench',scope='owned-native-organization-approval-workbench',checks=sorted(gate.BOOTSTRAP|gate.obligations(ROOT)|gate.CLEANUP),check_count=26,native=gate.summary('a'*40),successful_owned_file_service_startups=0,file_service_cleanup=None)
 d['worker_cleanup']={k:0 for k in ('private_directories_removed','owned_processes_stopped','cleanup_failures','private_directories_remaining','live_processes_after_cleanup','workers_registered')}
 return d
class WorkbenchGate(unittest.TestCase):
 def test_actual_positive_then_every_obligation_and_scope_mutations(self):
  self.assertEqual(26,gate.validate(template(),source_revision='a'*40)['checks'])
  for name in template()['checks']:
   d=template();d['checks'].remove(name)
   with self.assertRaises(ValueError):gate.validate(d,source_revision='a'*40)
  for key,value in [('php_version','unsupported'),('check_count',True),('raw_secret','SENTINEL'),('successful_owned_file_service_startups',False),('file_service_cleanup',{}),('terminal_failure_cleanup_rollbacks',False)]:
   d=template();d[key]=value
   with self.assertRaises(ValueError):gate.validate(d,source_revision='a'*40)
  for key in ['HTTP_server_or_browser_executed','full_approval_family_complete','all_sources_complete','restoration_authorized','wall_clock_expiry_wait_proved']:
   d=template();d['native'][key]=True
   with self.assertRaises(ValueError):gate.validate(d,source_revision='a'*40)
 def test_cleanup_is_typed_and_no_worker_or_external_file_service_adopted(self):
  for key in template()['cleanup']:
   d=template();d['cleanup'][key]=False
   with self.assertRaises(ValueError):gate.validate(d,source_revision='a'*40)
  for key in template()['worker_cleanup']:
   d=template();d['worker_cleanup'][key]=False
   with self.assertRaises(ValueError):gate.validate(d,source_revision='a'*40)
 def run_cli(self,path):return subprocess.run([sys.executable,'-B',str(ROOT/'scripts/governance/check_organization_approval_workbench.py'),'--receipt',str(path),'--source-revision','a'*40],capture_output=True,text=True,timeout=2)
 def test_actual_CLI_positive_then_duplicate_NaN_private_FIFO_symlink(self):
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp);path=root/'receipt';raw=json.dumps(template());path.write_text(raw);path.chmod(0o600);result=self.run_cli(path)
   self.assertEqual(0,result.returncode,result.stderr);self.assertEqual('',result.stderr);self.assertEqual(gate.validate(template(),source_revision='a'*40),json.loads(result.stdout))
   for bad in [raw.replace('"passed": true','"passed": true,"passed": false',1),raw.replace('"check_count": 26','"check_count": NaN',1)]:
    path.write_text(bad);self.assertNotEqual(0,self.run_cli(path).returncode)
   path.unlink();os.mkfifo(path,0o600);before=path.lstat();self.assertNotEqual(0,self.run_cli(path).returncode);after=path.lstat();self.assertEqual((before.st_dev,before.st_ino,before.st_mode),(after.st_dev,after.st_ino,after.st_mode))
   path.unlink();foreign=root/'foreign';foreign.write_text(raw);foreign.chmod(0o600);path.symlink_to(foreign);before=foreign.lstat();self.assertNotEqual(0,self.run_cli(path).returncode);self.assertEqual(raw,foreign.read_text());self.assertEqual(before.st_ino,foreign.lstat().st_ino)
if __name__=='__main__':unittest.main()
