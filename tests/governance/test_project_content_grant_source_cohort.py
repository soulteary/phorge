"""Exact merged N05 bytes preserve prior native/tooling source cohorts."""
import shutil,hashlib,sys,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
BASE='4c2245300dc1d78d042b95190dd537eb6b1f9861'
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_description_admission_delta as delta
class NativeGrantCohort(unittest.TestCase):
 def test_reviewed_native_files_restore_exact_current_main_or_new_leaf(self):
  self.assertEqual(len(delta.assert_production_cohort(ROOT)),19)
  for n in delta.PRODUCTION_PINS:
   raw=(ROOT/n).read_bytes();old=subprocess.run(['git','-C',str(ROOT),'show',BASE+':'+n],capture_output=True)
   restored=delta.reduce_production(n,raw)
   if old.returncode:self.assertIsNone(restored)
   else:self.assertEqual(restored,old.stdout,n)
   with self.assertRaises(ValueError):delta.reduce_production(n,raw+b'\n// unreviewed native change')
 def test_live_tooling_pins_are_real_and_inverse_preserves_all_prior_bytes(self):
  for n,pin in delta.SOURCE_INPUTS.items():
   raw=(ROOT/n).read_bytes();self.assertEqual(hashlib.sha256(raw).hexdigest(),pin,n)
   self.assertEqual(delta.reduce_if_selected(n,raw),subprocess.check_output(['git','-C',str(ROOT),'show',BASE+':'+n]),n)
   with self.assertRaises(ValueError):delta.reduce_if_selected(n,raw+b'\n# unreviewed tooling change')
 def test_complete_cohort_refuses_missing_or_unknown_new_source(self):
  with tempfile.TemporaryDirectory()as t:
   root=Path(t).resolve()
   names=set(delta.PRODUCTION_PINS)|set(delta.CURRENT_METADATA)|{delta.MANIFEST,delta.BRIDGE,delta.PREPARATION,delta.description.LEGACY_REDUCER['path'],'scripts/governance/project_content_grant_ci_source_delta.py'}
   for n in names:
    p=root/n;p.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(ROOT/n,p,follow_symlinks=False)
   delta.assert_production_cohort(root)
   n='src/infrastructure/governance/content/PhorgeProjectContentGrantService.php';p=root/n;raw=p.read_bytes();p.write_bytes(raw+b'\n// unknown')
   with self.assertRaises(ValueError):delta.assert_production_cohort(root)
   p.unlink()
   with self.assertRaises(ValueError):delta.assert_production_cohort(root)
 def test_signed_media_sources_and_overlay_are_unchanged_from_current_main(self):
  for n in ('support/governance/media-session-positive/source-pins.json','support/governance/media-session-docker/snapshot.py','support/governance/media-session-ci/project.py'):
   raw=delta.media_source_delta().reduce_if_selected(n,(ROOT/n).read_bytes());self.assertEqual(raw,subprocess.check_output(['git','-C',str(ROOT),'show',BASE+':'+n]),n)
if __name__=='__main__':unittest.main()

class ExactMediaSourceRebind(unittest.TestCase):
 def test_six_current_metadata_files_restore_exact_signed_recipe_and_reject_mutation(self):
  from unittest.mock import patch
  m=delta.media_source_delta();self.assertEqual(len(m.SOURCE_INPUTS),8)
  for name,pin in m.SOURCE_INPUTS.items():
   raw=(ROOT/name).read_bytes();self.assertEqual(hashlib.sha256(raw).hexdigest(),pin);old=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','4c2245300dc1d78d042b95190dd537eb6b1f9861:'+name]);self.assertEqual(m.reduce_current(name,raw),old)
   bad=raw+b'\n# unknown metadata'
   with self.assertRaises(ValueError):m.reduce_current(name,bad)
   with patch.dict(m.SOURCE_INPUTS,{name:hashlib.sha256(bad).hexdigest()}),self.assertRaises(ValueError):m.reduce_current(name,bad)
 def test_SQL_runtime_binding_uses_T3_and_exclusions_require_current_recipe_guard(self):
  import importlib.util
  p=ROOT/'support/governance/project-content-grants-ci/ci.py';spec=importlib.util.spec_from_file_location('exact_N05_runtime_ci_source',p);ci=importlib.util.module_from_spec(spec);spec.loader.exec_module(ci)
  self.assertEqual(ci.BASE,'4c2245300dc1d78d042b95190dd537eb6b1f9861');self.assertEqual(ci.TARGET,'ed66a10e3a2a034bcb9ac52e120c946b20ed2f29');self.assertEqual(ci.TREE,'2a3f916d09e44fd7e132e146b5a846fbcd1b5282');self.assertEqual(set(ci.OFFLINE_METADATA),{'scripts/governance/check_recovery_oauth_authorization_producer_v1.py','scripts/governance/project_content_grant_source_delta.py','scripts/governance/project_content_grant_ci_source_delta.py','scripts/governance/project_description_admission_delta.py','scripts/governance/recovery_oauth_authorization_source_delta.py','scripts/governance/check_directory_transports_v1.py'})
  delta.assert_current_recipe(ROOT)

class StageCExactRecipeBinding(unittest.TestCase):
 def test_exact_current_metadata_restores_actual_signed_StageB_and_rejects_mixed_bytes(self):
  m=delta.media_source_delta()
  for name in m.SOURCE_INPUTS:
   raw=(ROOT/name).read_bytes();old=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','8df48ed5273fc7d534d93a3d220025389c6a011c:'+name])
   self.assertEqual(m.reduce_stageB(name,raw),old,name)
   with self.assertRaises(ValueError):m.reduce_stageB(name,raw+b'unknown')
   if old!=raw:
    with self.assertRaises(ValueError):m.reduce_stageB(name,old)
