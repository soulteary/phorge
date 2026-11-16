"""All PHID permutations through actual native helper/User write/context guard."""
from n05_legacy_audit_test_view import historical_tooling_source,historical_library_map
from pathlib import Path
import itertools,json,subprocess,unittest,hashlib,ast
ROOT=Path(__file__).resolve().parents[2]
import sys
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_file_parent_policy as driver_admission
class SessionActorOrder(unittest.TestCase):
 def php(self,order,mode='good'):
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/session-actor-order-native-host.php'),str(ROOT),json.dumps(order),mode],capture_output=True,text=True,timeout=8);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_all_six_native_identity_orders_persist_only_lowest_admin_for_both_workers(self):
  old_successes=0
  for order in itertools.permutations('abc'):
   with self.subTest(original_order=order):
    d=self.php(order);self.assertIsNone(d['error']);self.assertEqual(d['real_native_user_class'],'PhabricatorUser');self.assertEqual(d['actual_isolated_connection'],'AphrontIsolatedDatabaseConnection');self.assertEqual(d['native_update_statements'],4);self.assertEqual(d['actor'],'a');self.assertEqual(d['admin_flags'],{'actor':True,'subject':False,'other':False,'disabled':False});self.assertEqual(d['subject_by_terminal'],{'commit':'b','rollback':'c'});self.assertEqual(d['both_terminal_worker_guards'],{'commit':True,'rollback':True});self.assertEqual(d['native_context_class'],'PhorgeGovernanceNativeActorContext');self.assertEqual(d['reverse_equal_worker_refused'],[True,True]);self.assertTrue(d['avatar_and_ID_fields_preserved']);self.assertFalse(d['physical_SQL_executed']);self.assertFalse(d['native_SessionEngine_DB_login_proved']);old_successes+=d['original_both_ordered']
  # Original creation-role actor is minimum in only two of six permutations.
  self.assertEqual(old_successes,2)
 def test_recovery_already_enabled_refuses_before_any_native_write(self):
  d=self.php('cab','enabled');self.assertEqual(d['error'],'session_lease_actor_preparation_after_enable');self.assertEqual(d['native_update_statements'],0);self.assertEqual(d['both_terminal_worker_guards'],[])
 def test_duplicate_native_identity_refuses_before_any_native_write(self):
  d=self.php('cab','duplicate');self.assertEqual(d['error'],'session_lease_prepared_users_unknown');self.assertEqual(d['native_update_statements'],0)
 def test_real_worker_only_fixed_identity_marker_and_actor_setup_precedes_enable(self):
  relative='support/governance/native-organization/native-worker.php';old=subprocess.check_output(['git','-C',str(ROOT),'show','4e12882595da85afc8ffc8b7b83aed1feb255c5a:'+relative]).decode();current=(ROOT/relative).read_text()
  def lease_body(s):
   a=s.index("  }else if($kind==='recovery.session.issue'){");b=s.index("  }else if($kind==='recovery.stop.record'",a);return s[a:b]
  self.assertEqual(lease_body(current),lease_body(old))
  focused=(ROOT/'support/governance/recovery/session-lease-focused.php').read_text();self.assertLess(focused.index('governance_recovery_session_lease_prepare_actor_order(governance_task_owner_prepare($fixture))'),focused.index("overrideEnvConfig('governance.account-recovery.enabled',true)"));self.assertIn("'subjects'=>array('commit'=>$users['subject'],'rollback'=>$users['other'])",focused)
 def test_nineteen_source_map_composes_reviewed_focused_fence_and_current_driver(self):
  path='scripts/governance/check_recovery_session_lease.py';old=ast.parse(subprocess.check_output(['git','-C',str(ROOT),'show','586f940370431a3f2a0d1cb7240c9e9803f7fdca:'+path]).decode());new=ast.parse(historical_tooling_source(path))
  def pins(tree):return next(ast.literal_eval(n.value)for n in tree.body if isinstance(n,ast.Assign)and any(isinstance(x,ast.Name)and x.id=='SOURCE_INPUTS'for x in n.targets))
  a=pins(old);b=pins(new);self.assertEqual(len(a),19);self.assertEqual(set(a),set(b));self.assertEqual(set(n for n in a if a[n]!=b[n]),{'src/applications/auth/storage/PhabricatorAuthSession.php','src/__phutil_library_map__.php','support/governance/recovery/session-lease-focused.php','src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php','support/governance/native-organization/test-native-organization-mysql.php','support/governance/native-organization/native-worker.php','support/governance/native-organization/workers.php'});
  for relative,revision in [('support/governance/recovery/session-lease-focused.php','8805c4aa5f533605c1e0004a6691bda458f35202'),('src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php','2ebe6ea2140a836c53497b626f0209be371ab930')]:
   historical=subprocess.check_output(['git','-C',str(ROOT),'show',revision+':'+relative])
   if relative.endswith('/PhorgeRecoverySessionProducerFence.php'):
    import sys
    sys.path.insert(0,str(ROOT/'scripts/governance'))
    import recovery_conduit_source_delta as conduit_delta
    import recovery_oauth_code_source_delta as code_delta
    raw=code_delta.reduce_if_selected(relative,(ROOT/relative).read_bytes());self.assertEqual(b[relative],hashlib.sha256(raw).hexdigest());self.assertEqual(conduit_delta.reduce_owner(relative,raw),historical)
   else:self.assertEqual(b[relative],hashlib.sha256(historical).hexdigest())
  relative='src/applications/auth/storage/PhabricatorAuthSession.php';raw=(ROOT/relative).read_bytes()
  self.assertEqual(b[relative],hashlib.sha256(raw).hexdigest());self.assertEqual(a[relative],hashlib.sha256(conduit_delta.reduce_owner(relative,raw)).hexdigest())
  generated=(ROOT/'src/__phutil_library_map__.php').read_bytes();reduced=historical_library_map(generated)
  for line in [b"    'PhorgeRecoveryConduitProducerFence' => 'infrastructure/governance/recovery/PhorgeRecoveryConduitProducerFence.php',\n",b"    'PhorgeRecoveryConduitProducerFence' => 'Phobject',\n"]:
   self.assertEqual(1,reduced.count(line));reduced=reduced.replace(line,b'',1)
  self.assertEqual(hashlib.sha256(reduced).hexdigest(),a['src/__phutil_library_map__.php'])
  relative='support/governance/native-organization/test-native-organization-mysql.php';physical_driver=(ROOT/relative).read_bytes();driver=driver_admission.admitted_native_driver_source(ROOT,physical_driver);historical_driver=conduit_delta.reduce_policy_cleanup_driver(driver);self.assertNotEqual(physical_driver,historical_driver);self.assertEqual(b[relative],hashlib.sha256(historical_driver).hexdigest());self.assertEqual(conduit_delta.reduce_policy_cleanup_driver(historical_driver),historical_driver)
  for bad in (physical_driver+b'unknown',physical_driver.replace(b'governance_policy_nested_cleanup_permitted',b'governance_policy_nested_cleanup_unknown',1)):
   with self.subTest(stage='policy-cleanup-driver'),self.assertRaises(ValueError):conduit_delta.reduce_policy_cleanup_driver(driver_admission.admitted_native_driver_source(ROOT,bad))
  self.assertEqual(b['support/governance/recovery/session-lease-focused.php'],hashlib.sha256((ROOT/'support/governance/recovery/session-lease-focused.php').read_bytes()).hexdigest())
if __name__=='__main__':unittest.main()
