"""Actual native models/GlobalPolicy + consumer; SQL/query execution are collaborators."""
from pathlib import Path
import json,subprocess,unittest,hashlib,tempfile
ROOT=Path(__file__).resolve().parents[2]
class FileParentAdminBinding(unittest.TestCase):
 def case(self,name):
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/file-parent-policy-admin-native-controls.php'),str(ROOT),name],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');v=json.loads(p.stdout);self.assertFalse(v['SQL_executed']);self.assertTrue(v['native_models_and_global_rule_are_actual']);return v
 def test_actual_admin_positive_and_actual_global_rule_nonadmin_denial(self):
  admin=self.case('fresh-admin');self.assertIsNone(admin['error']);self.assertTrue(admin['native_global_rule_before']);self.assertEqual(admin['native_queries'],4);self.assertEqual(next(iter(admin['result']['observations'].values()))['state'],'admitted')
  member=self.case('fresh-member');self.assertIsNone(member['error']);self.assertFalse(member['native_global_rule_before']);self.assertEqual(next(iter(member['result']['observations'].values()))['state'],'denied')
 def test_stale_admin_object_is_still_native_truthy_but_refused_before_queries(self):
  data=self.case('stale-admin');self.assertTrue(data['native_global_rule_before']);self.assertTrue(data['native_global_rule_after']);self.assertEqual(data['error'],'recovery_file_parent_viewer_refused');self.assertEqual(data['native_queries'],0);self.assertIsNone(data['result'])
 def test_SQL_downgrade_after_capture_cannot_reuse_old_admin_object(self):
  data=self.case('downgrade-during');self.assertTrue(data['native_global_rule_after']);self.assertEqual(data['error'],'recovery_file_parent_viewer_refused');self.assertEqual(data['native_queries'],data['native_queries_at_capture']);self.assertIsNone(data['result'])
 def test_admin_flag_alone_changes_binding_when_native_visibility_stays_true(self):
  data=self.case('binding-drift');self.assertTrue(data['native_global_rule_before']);self.assertTrue(data['native_global_rule_after']);self.assertTrue(all(data['actual_rule_results']));self.assertEqual(data['error'],'recovery_file_parent_review_stale');self.assertIsNone(data['result'])
  admin=self.case('bind-admin');member=self.case('bind-member');self.assertEqual(admin['result']['observations'],member['result']['observations']);self.assertNotEqual(admin['result']['review_binding'],member['result']['review_binding'])
 def test_malformed_SQL_or_truthy_viewer_flags_do_not_become_admin(self):
  for name in ('malformed-sql','malformed-viewer','malformed-disabled','malformed-system','malformed-mail'):
   data=self.case(name);self.assertEqual(data['error'],'recovery_file_parent_viewer_refused');self.assertEqual(data['native_queries'],0);self.assertIsNone(data['result'])
 def test_original_disabled_anonymous_and_native_SQL_case_plan_retained(self):
  text=(ROOT/'support/governance/recovery/file-parent-policy-cases.php').read_text()
  for value in ("$state['users']['disabled']",'file-parent-policy-anonymous-refused-before-native-author-grant','governance_recovery_file_parent_policy_admin_cases','PhabricatorPolicies::POLICY_ADMIN',"array('stale-object','visible-binding-drift','malformed-SQL','malformed-viewer')",'UPDATE %R SET isAdmin=%d WHERE id=%d AND phid=%s','file_parent_policy_admin_rollback_unproved'):
   self.assertIn(value,text)
 def test_frozen_old_candidate_reproduces_stale_admin_admission_without_changing_rule(self):
  old=subprocess.check_output(['git','-C',str(ROOT),'show','a223ab9c4e5caa2ecc5a03e57138e72bb92c5dc7:src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php']);self.assertEqual(hashlib.sha256(old).hexdigest(),'3c3c5914e71601778e7e059c2b8887edb32da4d69ee4e5253b0d62dccf161e09')
  code=(ROOT/'support/governance/recovery/tests/file-parent-policy-admin-native-controls.php').read_text()[5:];needle="require_once$root.'/src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php';";self.assertIn(needle,code);code=code.replace(needle,'require_once$argv[3];')
  with tempfile.NamedTemporaryFile(suffix='.php')as captured:
   captured.write(old);captured.flush();p=subprocess.run(['php','-r',code,str(ROOT),'stale-admin',captured.name],capture_output=True,text=True,timeout=10)
  self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');data=json.loads(p.stdout);self.assertIsNone(data['error']);self.assertTrue(data['native_global_rule_after']);self.assertEqual(next(iter(data['result']['observations'].values()))['state'],'admitted');self.assertFalse(data['SQL_executed'])
  self.assertEqual(self.case('stale-admin')['error'],'recovery_file_parent_viewer_refused')
