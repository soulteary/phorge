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
 def test_no_runtime_source_imports_test_view(self):
  for p in (view.ROOT/'scripts/governance').glob('*.py'):
   self.assertNotIn(b'import n05_r3_historical_test_view',p.read_bytes())
if __name__=='__main__':unittest.main()
