"""Synthetic receipt tampering against actual source-bound public CLI; no SQL evidence."""
from pathlib import Path
import copy,hashlib,importlib.util,json,os,subprocess,sys,tempfile,unittest
from unittest.mock import patch
import test_recovery_partial_composition_gate as predecessor
ROOT=Path(__file__).resolve().parents[2]
SPEC=importlib.util.spec_from_file_location('fileparent_gate',ROOT/'scripts/governance/check_recovery_file_parent_policy.py');gate=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(gate)
def template(digest='b'*64):
 d=predecessor.template();checks=sorted(gate.source_obligations(ROOT));d.update(batch='native-File-parent-policy-endpoints',scope='owned-native-File-parent-policy-endpoints-no-permit',checks=checks,check_count=len(checks),native=gate.summary('a'*40));d['file_service_cleanup']['binary_sha256']=digest;return d
class FileParentGate(unittest.TestCase):
 def test_actual_source_obligations_are_six_cases_plus_real_lifecycle(self):
  self.assertEqual(len(gate.SQL),6);self.assertEqual(len(gate.source_obligations(ROOT)),16)
  d=template();result=gate.validate(d,source_revision='a'*40,file_digest='b'*64);self.assertEqual(result['checks'],16);self.assertEqual(result['SQL_obligations'],6);self.assertFalse(result['same_snapshot_proved']);self.assertFalse(result['restoration_authorized']);self.assertTrue(result['execution_blocked'])
 def test_every_obligation_and_scope_permission_source_mode_tamper_refuses(self):
  data=template()
  for label in data['checks']:
   bad=copy.deepcopy(data);bad['checks'].remove(label);bad['check_count']-=1
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
  for path,value in [(('passed',),False),(('check_count',),True),(('scope',),'owned-native-File-source-partial'),(('source_restore','native_source_revision'),'unrun'),(('successful_owned_file_service_startups',),0),(('native','all_sources_complete'),True),(('native','restoration_authorized'),True),(('native','durable_review_compatible'),True),(('native','dependency_budget_proved'),True),(('native','native_reader_relationships','same_snapshot_proved'),True),(('native','native_reader_relationships','File_reader_is_User_writer'),True),(('native','observer_closed_and_absent'),False),(('native','source_inputs_sha256'),{}),(('native','executed_case_obligations'),[]),(('file_service_cleanup','process_stopped'),False),(('file_service_cleanup','private_storage_removed'),False),(('file_service_cleanup','kind'),'image'),(('file_service_cleanup','binary_sha256'),'c'*64)]:
   bad=copy.deepcopy(data);target=bad
   for key in path[:-1]:target=target[key]
   target[path[-1]]=value
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
 def test_independent_actual_CLI_rejects_duplicate_unknown_source_revision_and_binary_bytes(self):
  with tempfile.TemporaryDirectory()as temp:
   root=Path(temp).resolve();binary=root/'paired-binary';raw=b'synthetic paired binary; never executed';binary.write_bytes(raw);binary.chmod(0o700);digest=hashlib.sha256(raw).hexdigest();receipt=root/'receipt.json';receipt.touch(mode=0o600);cmd=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_file_parent_policy.py'),'--receipt',str(receipt),'--source-revision','a'*40,'--file-binary',str(binary),'--file-binary-sha256',digest]
   good=template(digest);text=json.dumps(good)
   for body,ok in [(text,True),(text.replace('"passed": true','"passed": true,"passed": false',1),False),(text.replace('"complete": false','"complete": true',1),False),(text.replace('a'*40,'d'*40),False),(text.replace(digest,'e'*64),False)]:
    receipt.write_text(body);p=subprocess.run(cmd,capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode==0,ok,p.stderr)
   receipt.write_text(text);binary.write_bytes(raw+b'changed');self.assertNotEqual(subprocess.run(cmd,capture_output=True,text=True,timeout=10).returncode,0)
 def test_changed_implementation_bytes_and_case_addition_cannot_self_certify(self):
  with tempfile.TemporaryDirectory()as temp:
   root=Path(temp).resolve()
   # Authenticate current native bytes before constructing a partial legacy tree.
   driver=gate.NATIVE_DRIVER_PATH
   current=gate.read_native_driver_checked(ROOT/driver)
   prior=gate.admitted_native_driver_source(ROOT,current)
   self.assertEqual(hashlib.sha256(prior).hexdigest(),gate.SOURCE_INPUTS[driver])
   with self.assertRaises(ValueError):gate.admitted_native_driver_source(ROOT,current+b'unknown')
   for name in gate.SOURCE_INPUTS:
    path=root/name;path.parent.mkdir(parents=True,exist_ok=True)
    path.write_bytes(prior if name==driver else (ROOT/name).read_bytes())
   self.assertEqual(gate.source_obligations(root),gate.source_obligations(ROOT))
   for name in ('src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php','support/governance/recovery/file-parent-policy-cases.php'):
    path=root/name;original=path.read_bytes();path.write_bytes(original+b'\n// changed implementation or unreviewed case\n')
    with self.assertRaises(ValueError):gate.source_obligations(root)
    path.write_bytes(original)
 def test_source_and_binary_receipt_FD_reject_alias_fifo_hardlink_and_writable_modes(self):
  with tempfile.TemporaryDirectory()as temp:
   root=Path(temp).resolve();regular=root/'source';regular.write_bytes(b'synthetic');regular.chmod(0o644);self.assertEqual(gate.read_source_checked(regular),b'synthetic')
   alias=root/'alias';alias.symlink_to(regular)
   with self.assertRaises((ValueError,OSError)):gate.read_source_checked(alias)
   fifo=root/'fifo';os.mkfifo(fifo,0o600)
   with self.assertRaises(ValueError):gate.read_source_checked(fifo)
   hard=root/'hard';os.link(regular,hard)
   with self.assertRaises(ValueError):gate.read_source_checked(regular)
   hard.unlink();regular.chmod(0o666)
   with self.assertRaises(ValueError):gate.read_source_checked(regular)
   regular.chmod(0o644)
   for mode in ('chmod','hardlink','replacement'):
    original=os.read;called=[]
    def race(fd,n):
     result=original(fd,n)
     if not called:
      called.append(True)
      if mode=='chmod':regular.chmod(0o600)
      elif mode=='hardlink':os.link(regular,hard)
      else:
       other=root/'replacement';other.write_bytes(b'synthetic');os.replace(other,regular)
     return result
    with patch.object(gate.os,'read',race):
     with self.assertRaises(ValueError):gate.read_source_checked(regular)
    if hard.exists():hard.unlink()
    regular.chmod(0o644)
 def test_v2_rejects_old_profile_missing_admin_unknown_fields_and_self_asserted_source(self):
  data=template()
  for mode in ('old-v1','missing-admin','unknown-native','unknown-outer'):
   bad=copy.deepcopy(data)
   if mode=='old-v1':bad['native']['profile']='native-File-parent-Task-policy-endpoints-v1'
   if mode=='missing-admin':bad['native']['current_account_flags_bound'].remove('isAdmin')
   if mode=='unknown-native':bad['native']['unreviewed_extra']=False
   if mode=='unknown-outer':bad['unreviewed_extra']=False
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
  with tempfile.TemporaryDirectory()as temp:
   root=Path(temp).resolve()
   # Authenticate current native bytes before constructing a partial legacy tree.
   driver=gate.NATIVE_DRIVER_PATH
   current=gate.read_native_driver_checked(ROOT/driver)
   prior=gate.admitted_native_driver_source(ROOT,current)
   self.assertEqual(hashlib.sha256(prior).hexdigest(),gate.SOURCE_INPUTS[driver])
   with self.assertRaises(ValueError):gate.admitted_native_driver_source(ROOT,current+b'unknown')
   for name in gate.SOURCE_INPUTS:
    path=root/name;path.parent.mkdir(parents=True,exist_ok=True)
    path.write_bytes(prior if name==driver else (ROOT/name).read_bytes())
   name='src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php';p=root/name;raw=p.read_text();self.assertIn('id,phid,isAdmin,isApproved',raw);p.write_text(raw.replace('id,phid,isAdmin,isApproved','id,phid,isApproved'))
   bad=copy.deepcopy(data);bad['native']['source_inputs_sha256'][name]=hashlib.sha256(p.read_bytes()).hexdigest()
   with self.assertRaises(ValueError):gate.validate(bad,root=root,source_revision='a'*40,file_digest='b'*64)
