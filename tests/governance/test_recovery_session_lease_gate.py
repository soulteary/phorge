"""Strict actual public CLI tampering controls; receipts are synthetic, no SQL."""
from recovery_oauth_code_test_cohort import complete_source_cohort
from pathlib import Path
import copy,hashlib,importlib.util,json,os,subprocess,sys,tempfile,unittest
import test_recovery_partial_composition_gate as previous
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('session_gate',ROOT/'scripts/governance/check_recovery_session_lease.py');gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
def template(digest='b'*64):
 d=previous.template();labels=sorted(gate.obligations());d.update(batch='native-session-producer-lease',scope='owned-native-session-producer-lease-no-permit',checks=labels,check_count=len(labels),native=gate.summary('a'*40),worker_cleanup={'private_directories_removed':2,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':2});d['file_service_cleanup']['binary_sha256']=digest;return d
class SessionGate(unittest.TestCase):
 def test_six_actual_source_cases_and_sixteen_total_obligations(self):
  self.assertEqual(len(gate.SOURCE_INPUTS),19);self.assertIn('support/governance/native-organization/native-cases.php',gate.SOURCE_INPUTS)
  self.assertEqual(len(gate.SQL_obligations()),6);self.assertEqual(len(gate.obligations()),16);result=gate.validate(template(),source_revision='a'*40,file_digest='b'*64);self.assertEqual(result['SQL_obligations'],6);self.assertFalse(result['fault_matrix_proved']);self.assertFalse(result['restoration_authorized']);self.assertTrue(result['execution_blocked'])
  code="require$argv[1].'/support/governance/recovery/session-lease-focused.php';echo json_encode(governance_recovery_session_lease_obligations());"
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(set(json.loads(p.stdout)),gate.SQL_obligations())
 def test_each_obligation_and_cleanup_or_permission_tamper_refused(self):
  original=template()
  for label in original['checks']:
   bad=copy.deepcopy(original);bad['checks'].remove(label);bad['check_count']-=1
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
  for path,value in [(('native','fault_matrix_proved'),True),(('native','all_sources_complete'),True),(('native','restoration_authorized'),True),(('native','durable_review_compatible'),True),(('native','worker_SQL_sessions_absent'),False),(('native','observer_public_close_returned'),False),(('native','observer_cleanup','cleanup_proved'),False),(('native','observer_cleanup','native_writer_grants_unchanged'),False),(('native','observer_cleanup','owned_observer_removed'),False),(('worker_cleanup','workers_registered'),0),(('worker_cleanup','live_processes_after_cleanup'),1),(('file_service_cleanup','process_stopped'),False),(('file_service_cleanup','private_storage_removed'),False),(('file_service_cleanup','kind'),'image'),(('production_optional_features_enabled',),True),(('passed',),1)]:
   bad=copy.deepcopy(original);target=bad
   for k in path[:-1]:target=target[k]
   target[path[-1]]=value
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
 def test_real_CLI_requires_independent_binary_receipt_revision_and_strict_shape(self):
  with tempfile.TemporaryDirectory()as temp:
   root=Path(temp).resolve();binary=root/'binary';raw=b'host synthetic paired binary; never executed';binary.write_bytes(raw);binary.chmod(0o700);digest=hashlib.sha256(raw).hexdigest();receipt=root/'receipt';receipt.touch(mode=0o600);cmd=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_session_lease.py'),'--receipt',str(receipt),'--source-revision','a'*40,'--file-binary',str(binary),'--file-binary-sha256',digest];good=json.dumps(template(digest))
   for body,ok in [(good,True),(good.replace('"passed": true','"passed": true,"passed": false',1),False),(good.replace('a'*40,'c'*40),False),(good.replace(digest,'d'*64),False),(good[:-1]+',"unknown":false}',False)]:
    receipt.write_text(body);p=subprocess.run(cmd,capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode==0,ok,p.stderr)
   receipt.write_text(good);binary.write_bytes(raw+b'changed');self.assertNotEqual(subprocess.run(cmd,capture_output=True,text=True,timeout=10).returncode,0)
 def test_pinned_real_source_changes_cannot_self_certify(self):
  with tempfile.TemporaryDirectory()as temp:
   root=Path(temp).resolve()
   for name in gate.SOURCE_INPUTS:
    p=root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/name).read_bytes())
   complete_source_cohort(root)
   self.assertEqual(gate.SQL_obligations(root),gate.SQL_obligations())
   for name in ('support/governance/native-organization/native-cases.php','support/governance/recovery/session-lease-cases.php','support/governance/recovery/session-lease-observer.php','src/applications/auth/storage/PhabricatorAuthSession.php'):
    p=root/name;old=p.read_bytes();p.write_bytes(old+b'\n// unauthorized source drift\n')
    with self.assertRaises(ValueError):gate.SQL_obligations(root)
    p.write_bytes(old)
