"""Exact source-only historical test helper negatives; no runtime authority."""
import pathlib,unittest
from unittest.mock import patch
import n05_r3_historical_test_view as view
class R3HistoricalView(unittest.TestCase):
 def test_exact_actual_modules_load_and_unknown_pins_do_not(self):
  self.assertEqual(len(view.PINS),9);self.assertEqual(len(view.checked_modules().SOURCE_INPUTS),16)
  name=next(iter(view.PINS))
  with patch.dict(view.PINS,{name:'0'*64}),self.assertRaises(ValueError):view.checked_modules()
  with patch.dict(view.PINS,{'unreviewed.py':'0'*64}),self.assertRaises(ValueError):view.checked_modules()
 def test_unknown_path_or_mutated_bytes_cannot_be_historical(self):
  name='tests/governance/n05_r3_historical_test_view.py'
  with self.assertRaises(ValueError):view.historical_coordinator_source(name,(view.ROOT/name).read_bytes())
  name=next(iter(view.checked_modules().SOURCE_INPUTS));raw=(view.ROOT/name).read_bytes()
  with self.assertRaises(ValueError):view.historical_coordinator_source(name,raw+b'unknown')
 def test_signed_R3_media_predecessor_rejects_unknown_or_unselected_payload(self):
  import subprocess
  name='support/governance/media-session-docker/snapshot.py'
  raw=subprocess.check_output(['git','--no-replace-objects','-C',str(view.ROOT),'show','432bd0166880b4a6ab4820cfebcd4482cf89bb27:'+name])
  self.assertIsInstance(view.historical_R3_media_recipe_source(name,raw),bytes)
  with self.assertRaises(ValueError):view.historical_R3_media_recipe_source(name,raw+b'unknown')
  with self.assertRaises(ValueError):view.historical_R3_media_recipe_source('tests/unknown.py',raw)
 def test_signed_F4_media_predecessor_is_exact_and_unknown_refused(self):
  import subprocess
  name='support/governance/media-session-docker/snapshot.py'
  raw=subprocess.check_output(['git','--no-replace-objects','-C',str(view.ROOT),'show','0496112f7d6706bb3d854117b7fd94a759978987:'+name])
  self.assertIsInstance(view.historical_F4_media_recipe_source(name,raw),bytes)
  with self.assertRaises(ValueError):view.historical_F4_media_recipe_source(name,raw+b'unknown')
  with self.assertRaises(ValueError):view.historical_F4_media_recipe_source('tests/unknown.py',raw)
 def test_signed_D95_media_predecessor_is_exact_and_unknown_refused(self):
  import subprocess
  name='support/governance/media-session-docker/snapshot.py'
  raw=subprocess.check_output(['git','--no-replace-objects','-C',str(view.ROOT),'show','d95b3bd2253afb6fdce615f4041920bdce5df625:'+name])
  self.assertIsInstance(view.historical_D95_media_recipe_source(name,raw),bytes)
  with self.assertRaises(ValueError):view.historical_D95_media_recipe_source(name,raw+b'unknown')
  with self.assertRaises(ValueError):view.historical_D95_media_recipe_source('tests/unknown.py',raw)
 def test_native577_batch_scan_view_is_exact_and_current_unknown_old_mode_missing_refused(self):
  import subprocess,tempfile
  from pathlib import Path
  from recovery_oauth_code_test_cohort import complete_source_cohort
  name='docs/governance/batch-2-callers.json';raw=(view.ROOT/name).read_bytes()
  prior=subprocess.check_output(['git','--no-replace-objects','-C',str(view.ROOT),'show',view.REVISION+':'+name])
  self.assertEqual(view._historical_batch_scan_source(name,raw),prior)
  for value in (raw+b'unknown',prior):
   with self.assertRaises(ValueError):view._historical_batch_scan_source(name,value)
  with self.assertRaises(ValueError):view._historical_batch_scan_source('docs/unknown.json',raw)
  for mode in ('executable','special-mode','missing','unknown-bytes'):
   with self.subTest(mode=mode),tempfile.TemporaryDirectory()as td:
    root=Path(td).resolve();complete_source_cohort(root);p=root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(raw);p.chmod(0o644)
    if mode=='executable':p.chmod(0o755)
    elif mode=='special-mode':p.chmod(0o1644)
    elif mode=='missing':p.unlink()
    else:p.write_bytes(raw+b'unknown')
    with self.assertRaises((ValueError,OSError)):view._historical_batch_scan_source(name,raw,root)
 def test_two_current_legacy_metadata_paths_are_exact_and_not_double_normalized(self):
  import hashlib
  import n05_legacy_audit_test_view as original
  module=view.checked_modules()
  self.assertEqual(len(original.METADATA_PATHS),2)
  for path in original.METADATA_PATHS:
   raw=(view.ROOT/path).read_bytes();prior=original.historical_metadata_source(path,raw)
   self.assertEqual(hashlib.sha256(prior).hexdigest(),original.METADATA_PRIOR_PINS[path])
   previous=original.git_bytes(original.CURRENT_REVISION,path)
   for bad in (raw+b'unknown',previous,prior):
    with self.subTest(path=path),self.assertRaises(ValueError):original.historical_metadata_source(path,bad)
   if path in module.SOURCE_INPUTS:
    self.assertEqual(hashlib.sha256(view.historical_coordinator_source(path,raw)).hexdigest(),module.BASE_PINS[path])
    for bad in (raw+b'unknown',previous,prior):
     with self.assertRaises(ValueError):view.historical_coordinator_source(path,bad)
   if path in module.authorization_delta.SOURCE_INPUTS:
    expected=module.authorization_delta.reduce_current(path,prior)
    self.assertEqual(view.historical_authorization_source(path,raw),expected)
    for bad in (raw+b'unknown',previous,prior):
     with self.assertRaises(ValueError):view.historical_authorization_source(path,bad)
 def test_no_runtime_source_imports_test_view(self):
  for p in (view.ROOT/'scripts/governance').glob('*.py'):
   self.assertNotIn(b'import n05_r3_historical_test_view',p.read_bytes())
if __name__=='__main__':unittest.main()
