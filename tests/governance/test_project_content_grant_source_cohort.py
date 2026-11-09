"""Exact merged N05 bytes preserve prior native/tooling source cohorts."""
import hashlib,sys,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
BASE='4c2245300dc1d78d042b95190dd537eb6b1f9861'
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_content_grant_source_delta as delta
class NativeGrantCohort(unittest.TestCase):
 def test_reviewed_native_files_restore_exact_current_main_or_new_leaf(self):
  self.assertEqual(len(delta.assert_production_cohort(ROOT)),14)
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
   for n in delta.PRODUCTION_PINS:
    p=root/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/n).read_bytes())
   delta.assert_production_cohort(root)
   n='src/infrastructure/governance/content/PhorgeProjectContentGrantService.php';p=root/n;raw=p.read_bytes();p.write_bytes(raw+b'\n// unknown')
   with self.assertRaises(ValueError):delta.assert_production_cohort(root)
   p.unlink()
   with self.assertRaises(ValueError):delta.assert_production_cohort(root)
 def test_signed_media_sources_and_overlay_are_unchanged_from_current_main(self):
  for n in ('support/governance/media-session-positive/source-pins.json','support/governance/media-session-docker/snapshot.py','support/governance/media-session-ci/project.py'):
   self.assertEqual((ROOT/n).read_bytes(),subprocess.check_output(['git','-C',str(ROOT),'show',BASE+':'+n]),n)
if __name__=='__main__':unittest.main()
