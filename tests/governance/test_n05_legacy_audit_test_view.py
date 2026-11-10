"""Negative coverage for exact historical-only test views; no native SQL."""
import ast,hashlib,subprocess,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
import n05_legacy_audit_test_view as view
ROOT=Path(__file__).resolve().parents[2]
class LegacyView(unittest.TestCase):
 def fixture(self,root):
  for n in set(view.CLOSED_PATHS)|set(view.n05.PRODUCTION_PINS)|set(view.n05.FIXTURE_METADATA_PINS)|set(view.REPAIR_SOURCE_PINS)|set(view.METADATA_PATHS):
   p=root/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/n).read_bytes())
 def test_six_whole_sources_equal_real_prior_Git_without_changing_runtime_files(self):
  for path in view.CLOSED_PATHS:
   raw=(ROOT/path).read_bytes();self.assertEqual(raw,view.git_bytes(view.CURRENT_REVISION,path))
   self.assertEqual(view.historical_tooling_source(path),view.git_bytes(view.PRIOR_REVISION,path))
   self.assertEqual((ROOT/path).read_bytes(),raw)
  raw=(ROOT/'src/__phutil_library_map__.php').read_bytes();self.assertEqual(view.historical_library_map(raw),view.git_bytes(view.PRIOR_REVISION,'src/__phutil_library_map__.php'))
  self.assertNotEqual(raw,view.historical_library_map(raw))
 def test_repair_guard_is_exact_actual_combined_target_and_test_only(self):
  self.assertEqual(view.CURRENT_REVISION,'512282be298c7996fc6370156c333d7557b1685a');self.assertEqual(len(view.REPAIR_SOURCE_PINS),9)
  for path,pin in view.REPAIR_SOURCE_PINS.items():
   raw=(ROOT/path).read_bytes();self.assertEqual(raw,view.git_bytes(view.CURRENT_REVISION,path));self.assertEqual(hashlib.sha256(raw).hexdigest(),pin)
  source=ROOT/'scripts/governance'
  for p in source.glob('*.py'):
   for node in ast.walk(ast.parse(p.read_text())):
    if isinstance(node,ast.Import):self.assertFalse(any(a.name.endswith('n05_legacy_audit_test_view')for a in node.names),str(p))
    elif isinstance(node,ast.ImportFrom):self.assertFalse((node.module or '').endswith('n05_legacy_audit_test_view'),str(p))
    elif isinstance(node,ast.Call)and node.args and isinstance(node.args[0],ast.Constant)and node.args[0].value=='n05_legacy_audit_test_view':
     self.assertFalse(isinstance(node.func,ast.Name)and node.func.id=='__import__'or isinstance(node.func,ast.Attribute)and node.func.attr=='import_module',str(p))
 def test_two_metadata_views_require_exact_combined_current_and_real_prior(self):
  self.assertEqual(set(view.METADATA_PATHS),{'docs/governance/native-directory-callers.json','tests/governance/test_recovery_partial_schema_coordination.py'})
  for path in view.METADATA_PATHS:
   raw=(ROOT/path).read_bytes();old=view.git_bytes(view.METADATA_PRIOR_REVISION,path)
   self.assertEqual(view.historical_metadata_source(path,raw),old);self.assertEqual((ROOT/path).read_bytes(),raw)
   with self.assertRaises(ValueError):view.historical_metadata_source(path,raw+b'unknown')
  with self.assertRaises(ValueError):view.historical_metadata_source('tests/unreviewed.py',b'unknown')
 def test_missing_mixed_and_double_patched_metadata_cannot_become_historical(self):
  for mode in ('missing','mixed','double-patched'):
   with self.subTest(mode=mode),tempfile.TemporaryDirectory()as td:
    root=Path(td);self.fixture(root);path=view.METADATA_PATHS[-1];p=root/path
    if mode=='missing':p.unlink()
    elif mode=='mixed':p.write_bytes(view.git_bytes(view.METADATA_PRIOR_REVISION,path))
    else:
     bad=p.read_bytes()+b'unknown';p.write_bytes(bad)
     with patch.dict(view.METADATA_CURRENT_PINS,{path:hashlib.sha256(bad).hexdigest()}),patch.dict(view.n05.SOURCE_INPUTS,{path:hashlib.sha256(bad).hexdigest()}),self.assertRaises(ValueError):view.historical_metadata_source(path,bad,root)
     continue
    with self.assertRaises((ValueError,OSError)):view.historical_metadata_source(path,b'unknown',root)
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
  for mode in ['missing','mixed','fixture','repair-missing','repair-unknown','repair-patchpin']:
   with self.subTest(mode=mode),tempfile.TemporaryDirectory()as td:
    root=Path(td);self.fixture(root)
    if mode=='missing':(root/view.CLOSED_PATHS[-1]).unlink()
    elif mode=='mixed':(root/view.CLOSED_PATHS[-1]).write_bytes(view.git_bytes(view.PRIOR_REVISION,view.CLOSED_PATHS[-1]))
    elif mode.startswith('repair-'):
     name=next(iter(view.REPAIR_SOURCE_PINS));f=root/name
     if mode=='repair-missing':f.unlink()
     else:
      f.write_bytes(f.read_bytes()+b'unknown')
      if mode=='repair-patchpin':
       with patch.dict(view.REPAIR_SOURCE_PINS,{name:hashlib.sha256(f.read_bytes()).hexdigest()}),self.assertRaises(ValueError):view.historical_tooling_source(path,root)
       continue
    else:
     f=root/'support/governance/completion/fixture.php';f.write_bytes(f.read_bytes()+b'unknown')
    with self.assertRaises((ValueError,OSError)):view.historical_tooling_source(path,root)
if __name__=='__main__':unittest.main()
