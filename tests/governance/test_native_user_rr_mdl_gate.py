"""Synthetic receipt rejection controls, never native SQL evidence."""
import copy, importlib.util, json, subprocess, sys, tempfile, unittest
from pathlib import Path
from test_recovery_org_metadata_gate import template as metadata_template
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_native_user_rr_mdl as gate
REV='a'*40
def template():
 d=metadata_template();d.update(batch='native-same-User-RR-MDL-component',check_count=21,checks=sorted(gate.BOOTSTRAP|gate.SQL|gate.CLEANUP))
 child=dict(owned_processes_stopped=True,owned_SQL_sessions_ended=True,private_directory_removed=True,cleanup_proved=True,pidfd_remaining=0)
 d['native']=dict(profile='isolated-same-native-User-writer-RR-MDL-v1',source_revision=REV,component_SQL_obligations=12,local_children=2,local_cleanup=[child.copy(),child.copy()],innocuous_native_User_write=True,native_enable_executed=False,minimal_grants_negative_SQL_complete=False,unknown_transport_fault_matrix_complete=False,production_companion_fence_installed=False,role_proxy_dynamic_privileges_complete=False,all13_complete=False,restoration_authorized=False,final_owned_cleanup=dict(cleanup_proved=True,local_children_registered=2,failures=[],revoker_closed=True,principal_cleanup=dict(observer_removed=True,owned_observer_sessions_absent=True,cleanup_proved=True)))
 return d
class Gate(unittest.TestCase):
 def validate(self,d):return gate.validate(d,source_revision=REV)
 def test_valid_and_real_cli(self):
  d=template();expected=self.validate(d);self.assertEqual(12,expected['SQL_obligations'])
  with tempfile.TemporaryDirectory() as tmp:
   p=Path(tmp)/'receipt.json';p.write_text(json.dumps(d))
   r=subprocess.run([sys.executable,'-B',str(ROOT/'scripts/governance/check_native_user_rr_mdl.py'),'--receipt',str(p),'--source-revision',REV],capture_output=True,text=True)
   self.assertEqual(0,r.returncode);self.assertEqual('',r.stderr);self.assertEqual(expected,json.loads(r.stdout))
 def test_each_real_obligation_required(self):
  self.assertEqual(12,len(gate.SQL))
  for name in gate.SQL|gate.CLEANUP|gate.BOOTSTRAP:
   d=template();d['checks'].remove(name)
   with self.subTest(name=name),self.assertRaises(ValueError):self.validate(d)
 def test_unknown_typed_cleanup_never_zero(self):
  for path,value in [(('check_count',),True),(('worker_cleanup','workers_registered'),False),(('native','local_children'),True),(('native','final_owned_cleanup','principal_cleanup'),None),(('native','local_cleanup'),[]),(('native','final_owned_cleanup','revoker_closed'),False)]:
   d=template();t=d
   for k in path[:-1]:t=t[k]
   t[path[-1]]=value
   with self.subTest(path=path),self.assertRaises(ValueError):self.validate(d)
 def test_unproved_authority_stays_false(self):
  for key in ('native_enable_executed','minimal_grants_negative_SQL_complete','unknown_transport_fault_matrix_complete','production_companion_fence_installed','role_proxy_dynamic_privileges_complete','all13_complete','restoration_authorized'):
   d=template();d['native'][key]=True
   with self.subTest(key=key),self.assertRaises(ValueError):self.validate(d)
 def test_source_or_output_unknown_rejected(self):
  for change in ('revision','migration','output','extra'):
   d=template()
   if change=='revision':d['native']['source_revision']='b'*40
   elif change=='migration':d['source_restore']['patch_audit']['migration_source_hashes'].pop(next(iter(d['source_restore']['patch_audit']['migration_source_hashes'])))
   elif change=='output':d['captured_native_output_bytes']=False
   else:d['native']['unproved']=True
   with self.subTest(change=change),self.assertRaises(ValueError):self.validate(d)
if __name__=='__main__':unittest.main()
