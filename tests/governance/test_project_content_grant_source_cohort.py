"""Exact merged N05 bytes preserve prior native/tooling source cohorts."""
import shutil,hashlib,sys,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
BASE='4c2245300dc1d78d042b95190dd537eb6b1f9861'
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_description_admission_delta as delta
from project_grant_source_task_test_api_v2 import GrantSourceCurrentCase
class NativeGrantCohort(GrantSourceCurrentCase):
 def test_reviewed_native_files_restore_exact_current_main_or_new_leaf(self):
  self.assertEqual(len(self.d.assert_production_cohort(ROOT)),19)
  for n in self.d.PRODUCTION_PINS:
   raw=self.d.parent_data(n);old=subprocess.run(['git','-C',str(ROOT),'show',BASE+':'+n],capture_output=True)
   restored=self.d.reduce_production(n,raw)
   if old.returncode:self.assertIsNone(restored)
   else:self.assertEqual(restored,old.stdout,n)
   with self.assertRaises(ValueError):self.d.reduce_production(n,raw+b'\n// unreviewed native change')
 def test_live_tooling_pins_are_real_and_inverse_preserves_all_prior_bytes(self):
  for n,pin in self.d.SOURCE_INPUTS.items():
   raw=self.d.parent_data(n);self.assertEqual(hashlib.sha256(raw).hexdigest(),pin,n)
   self.assertEqual(self.d.reduce_if_selected(n,raw),subprocess.check_output(['git','-C',str(ROOT),'show',BASE+':'+n]),n)
   with self.assertRaises(ValueError):self.d.reduce_if_selected(n,raw+b'\n# unreviewed tooling change')
 def test_complete_cohort_refuses_missing_or_unknown_new_source(self):
  with tempfile.TemporaryDirectory()as t:
   root=Path(t).resolve()
   names=set(self.d.PRODUCTION_PINS)|set(self.d.CURRENT_METADATA)|{self.d.MANIFEST,self.d.BRIDGE,self.d.PREPARATION,self.d.description.LEGACY_REDUCER['path'],'scripts/governance/project_content_grant_ci_source_delta.py'}
   self.d.write_fixture_DATA(root,names)
   self.d.assert_production_cohort(root)
   n='src/infrastructure/governance/content/PhorgeProjectContentGrantService.php';p=root/n;raw=p.read_bytes();p.write_bytes(raw+b'\n// unknown')
   with self.assertRaises(ValueError):self.d.assert_production_cohort(root)
   p.unlink()
   with self.assertRaises(ValueError):self.d.assert_production_cohort(root)
 def test_signed_media_sources_and_overlay_are_unchanged_from_current_main(self):
  for n in ('support/governance/media-session-positive/source-pins.json','support/governance/media-session-docker/snapshot.py','support/governance/media-session-ci/project.py'):
   raw=self.d.media_source_delta().reduce_if_selected(n,self.d.parent_data(n));self.assertEqual(raw,subprocess.check_output(['git','-C',str(ROOT),'show',BASE+':'+n]),n)
if __name__=='__main__':unittest.main()

class ExactMediaSourceRebind(GrantSourceCurrentCase):
 def test_six_current_metadata_files_restore_exact_signed_recipe_and_reject_mutation(self):
  from unittest.mock import patch
  m=self.d.media_source_delta();self.assertEqual(len(m.SOURCE_INPUTS),8)
  for name,pin in m.SOURCE_INPUTS.items():
   raw=self.d.parent_data(name);self.assertEqual(hashlib.sha256(raw).hexdigest(),pin);old=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','4c2245300dc1d78d042b95190dd537eb6b1f9861:'+name]);self.assertEqual(m.reduce_current(name,raw),old)
   bad=raw+b'\n# unknown metadata'
   with self.assertRaises(ValueError):m.reduce_current(name,bad)
   with patch.dict(m.SOURCE_INPUTS,{name:hashlib.sha256(bad).hexdigest()}),self.assertRaises(ValueError):m.reduce_current(name,bad)
 def test_SQL_runtime_binding_uses_T3_and_exclusions_require_current_recipe_guard(self):
  import importlib.util
  p=ROOT/'support/governance/project-content-grants-ci/ci.py';spec=importlib.util.spec_from_file_location('exact_N05_runtime_ci_source',p);ci=importlib.util.module_from_spec(spec);spec.loader.exec_module(ci)
  self.assertEqual(ci.BASE,'4c2245300dc1d78d042b95190dd537eb6b1f9861');self.assertEqual(ci.TARGET,self.d.TARGET);self.assertEqual(ci.TREE,self.d.TARGET_TREE);self.assertRegex(ci.TARGET,r'^[0-9a-f]{40}$');self.assertRegex(ci.TREE,r'^[0-9a-f]{40}$');self.assertEqual(subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'rev-parse',ci.TARGET+'^{tree}']).decode().strip(),ci.TREE);self.assertEqual(set(ci.OFFLINE_METADATA),{'scripts/governance/check_recovery_oauth_authorization_producer_v1.py','scripts/governance/project_content_grant_source_delta.py','scripts/governance/project_content_grant_ci_source_delta.py','scripts/governance/project_description_admission_delta.py','scripts/governance/recovery_oauth_authorization_source_delta.py','scripts/governance/check_directory_transports_v1.py'})
  self.d.assert_current_recipe(ROOT)

class StageCExactRecipeBinding(GrantSourceCurrentCase):
 def test_exact_current_metadata_restores_actual_signed_StageB_and_rejects_mixed_bytes(self):
  m=self.d.media_source_delta()
  for name in m.SOURCE_INPUTS:
   raw=self.d.parent_data(name);old=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','8df48ed5273fc7d534d93a3d220025389c6a011c:'+name])
   self.assertEqual(m.reduce_stageB(name,raw),old,name)
   with self.assertRaises(ValueError):m.reduce_stageB(name,raw+b'unknown')
   if old!=raw:
    with self.assertRaises(ValueError):m.reduce_stageB(name,old)
