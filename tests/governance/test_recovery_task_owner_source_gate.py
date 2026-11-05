"""Actual public CLI and typed synthetic controls, never real SQL results."""
from pathlib import Path
import copy,importlib.util,json,subprocess,sys,tempfile,unittest,os,stat
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_task_owner_source as gate
spec=importlib.util.spec_from_file_location('task_native_template',ROOT/'tests/governance/test_native_organization.py');native=importlib.util.module_from_spec(spec);spec.loader.exec_module(native)
def template():
 d=native.template();d.update(batch='native-task-owner-source-facts',scope='owned-native-task-owner-source-partial',checks=sorted(gate.BOOTSTRAP|gate.SQL|gate.CLEANUP),check_count=28,native=gate.summary('a'*40),file_service_cleanup=None,successful_owned_file_service_startups=0)
 d['cleanup']['ownedAuxiliarySetupConnectionsClosed']=6
 d['worker_cleanup']={k:0 for k in ('private_directories_removed','owned_processes_stopped','cleanup_failures','private_directories_remaining','live_processes_after_cleanup','workers_registered')}
 return d
class TaskOwnerSourceGate(unittest.TestCase):
 def test_positive_then_every_missing_obligation_and_false_authority_rejected(self):
  d=template();self.assertEqual(gate.validate(d,source_revision='a'*40)['checks'],28);self.assertEqual(len(gate.source_obligations(ROOT)),19)
  for name in d['checks']:
   bad=copy.deepcopy(d);bad['checks'].remove(name)
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40)
  for key,value in [('php_version','unsupported'),('raw_secret','SYNTHETIC_SECRET'),('check_count',True),('check_count',27),('terminal_failure_cleanup_rollbacks',False)]:
   bad=copy.deepcopy(d);bad[key]=value
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40)
  for key in ('complete','all_sources_complete','restoration_authorized','minimum_production_grants_proved','DDL_and_fault_matrix_proved','native_owner_function_is_full_policy','all_automatic_object_classes_supported'):
   bad=copy.deepcopy(d);bad['native'][key]=True
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40)
 def test_cleanup_and_positive_native_facts_are_strict(self):
  for key in ('native_Editor_assign_unassign_and_history_rollback','disabled_assignee_native_Editor_not_account_admission','owned_native_User_RR_source_observed','raw_update_insert_delete_waits_and_reaps_observed','observer_closed_and_absent'):
   d=template();d['native'][key]=False
   with self.assertRaises(ValueError):gate.validate(d,source_revision='a'*40)
  for value in (False,None,0,5,7,'6'):
   d=template();d['cleanup']['ownedAuxiliarySetupConnectionsClosed']=value
   with self.assertRaises(ValueError):gate.validate(d,source_revision='a'*40)
  d=template();d['worker_cleanup']['workers_registered']=False
  with self.assertRaises(ValueError):gate.validate(d,source_revision='a'*40)
 def test_actual_public_CLI_positive_duplicate_and_nonfinite(self):
  with tempfile.TemporaryDirectory()as tmp:
   p=Path(tmp)/'receipt.json';raw=json.dumps(template());p.write_text(raw);p.chmod(0o600);cmd=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_task_owner_source.py'),'--receipt',str(p),'--source-revision','a'*40]
   result=subprocess.run(cmd,capture_output=True,text=True);self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(result.stderr,'');self.assertEqual(json.loads(result.stdout),gate.validate(template(),source_revision='a'*40))
   for value in (raw.replace('"check_count": 28','"check_count": 28,"check_count": 28'),raw.replace('"check_count": 28','"check_count": NaN')):
    p.write_text(value);self.assertNotEqual(subprocess.run(cmd,capture_output=True).returncode,0)
 def test_real_entry_and_no_production_wiring(self):
  driver=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text();self.assertIn("'--task-owner-source-facts'=>'task-owner-source-facts'",driver);self.assertIn('governance_recovery_task_owner_focused($fixture,$control,$check)',driver)
  self.assertNotIn('PhorgeRecoveryTaskOwnerSource',(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php').read_text())

 def test_actual_CLI_nonblocking_FIFO_symlink_and_oversize_refused(self):
  with tempfile.TemporaryDirectory()as tmp:
   directory=Path(tmp);regular=directory/'regular';regular.write_text(json.dumps(template()));regular.chmod(0o600);link=directory/'link';link.symlink_to(regular);fifo=directory/'fifo';os.mkfifo(fifo,0o600);large=directory/'large';large.write_bytes(b'x'*(2097152+1));large.chmod(0o600)
   def stamp(path):
    s=path.lstat();return(s.st_dev,s.st_ino,s.st_mode,s.st_nlink,s.st_uid,s.st_gid,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
   for path in (fifo,link,large):
    before=stamp(path);cmd=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_task_owner_source.py'),'--receipt',str(path),'--source-revision','a'*40];p=subprocess.run(cmd,capture_output=True,text=True,timeout=2);self.assertNotEqual(p.returncode,0);self.assertEqual(stamp(path),before)
   self.assertEqual(json.loads(gate.read_receipt(regular)),template())
   for value in (True,None,0,-1,4194305):
    with self.assertRaises(ValueError):gate.read_receipt(regular,value)
