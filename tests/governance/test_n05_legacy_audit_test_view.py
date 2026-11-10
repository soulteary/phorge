"""Negative coverage for exact historical-only test views; no native SQL."""
import ast,hashlib,subprocess,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
import n05_legacy_audit_test_view as view
ROOT=Path(__file__).resolve().parents[2]
class LegacyView(unittest.TestCase):
 def fixture(self,root):
  for n in set(view.CLOSED_PATHS)|set(view.n05.PRODUCTION_PINS)|set(view.n05.FIXTURE_METADATA_PINS):
   p=root/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/n).read_bytes())
 def test_six_whole_sources_equal_real_prior_Git_without_changing_runtime_files(self):
  for path in view.CLOSED_PATHS:
   raw=(ROOT/path).read_bytes();self.assertEqual(raw,view.git_bytes(view.CURRENT_REVISION,path))
   self.assertEqual(view.historical_tooling_source(path),view.git_bytes(view.PRIOR_REVISION,path))
   self.assertEqual((ROOT/path).read_bytes(),raw)
  raw=(ROOT/'src/__phutil_library_map__.php').read_bytes();self.assertEqual(view.historical_library_map(raw),view.git_bytes(view.PRIOR_REVISION,'src/__phutil_library_map__.php'))
  self.assertNotEqual(raw,view.historical_library_map(raw))
 def test_unknown_path_payload_and_patched_pin_never_become_historical_evidence(self):
  with self.assertRaises(ValueError):view.historical_tooling_source('scripts/governance/unreviewed.py')
  with self.assertRaises(ValueError):view.historical_library_map((ROOT/'src/__phutil_library_map__.php').read_bytes()+b'unknown')
  path=view.CLOSED_PATHS[0]
  with tempfile.TemporaryDirectory()as td:
   root=Path(td);self.fixture(root);raw=(root/path).read_bytes();bad=raw+b'unknown';(root/path).write_bytes(bad)
   with self.assertRaises(ValueError):view.historical_tooling_source(path,root)
   with patch.dict(view.CURRENT_PINS,{path:hashlib.sha256(bad).hexdigest()}),patch.dict(view.n05.SOURCE_INPUTS,{path:hashlib.sha256(bad).hexdigest()}),self.assertRaises(ValueError):view.historical_tooling_source(path,root)
 def test_missing_mixed_or_unreviewed_actual_fixture_cohort_refuses(self):
  path=view.CLOSED_PATHS[0]
  for mode in ['missing','mixed','fixture']:
   with self.subTest(mode=mode),tempfile.TemporaryDirectory()as td:
    root=Path(td);self.fixture(root)
    if mode=='missing':(root/view.CLOSED_PATHS[-1]).unlink()
    elif mode=='mixed':(root/view.CLOSED_PATHS[-1]).write_bytes(view.git_bytes(view.PRIOR_REVISION,view.CLOSED_PATHS[-1]))
    else:
     f=root/'support/governance/completion/fixture.php';f.write_bytes(f.read_bytes()+b'unknown')
    with self.assertRaises((ValueError,OSError)):view.historical_tooling_source(path,root)
if __name__=='__main__':unittest.main()
