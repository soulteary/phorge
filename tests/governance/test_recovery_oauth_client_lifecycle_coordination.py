"""Exact historical view only; current protected media gate remains raw."""
import hashlib, subprocess, sys, tempfile, unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import recovery_oauth_client_lifecycle_delta as delta

class LifecycleCoordination(unittest.TestCase):
 def test_all_nine_whole_byte_inverses_match_real_main_blobs(self):
  self.assertEqual(len(delta.PRODUCTION_PATHS),9)
  for path in delta.SOURCE_INPUTS:
   raw=(ROOT/path).read_bytes()
   old=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','4c2245300dc1d78d042b95190dd537eb6b1f9861:'+path])
   self.assertEqual(delta.reduce_current(path,raw),old)
   self.assertEqual(hashlib.sha256(old).hexdigest(),delta.BASE_PINS[path])
   bad=raw+b'\n// unreviewed lifecycle edit\n'
   with self.assertRaises(ValueError):delta.reduce_current(path,bad)
   with patch.object(delta,'SOURCE_INPUTS',{**delta.SOURCE_INPUTS,path:hashlib.sha256(bad).hexdigest()}):
    with self.assertRaises(ValueError):delta.reduce_current(path,bad)
 def test_complete_nine_file_cohort_rejects_missing_mixed_and_unknown(self):
  with tempfile.TemporaryDirectory()as td:
   root=Path(td).resolve()
   for path in delta.PRODUCTION_PATHS:
    p=root/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/path).read_bytes())
   self.assertTrue(delta.assert_cohort(root,Path.read_bytes))
   for path in delta.PRODUCTION_PATHS:
    p=root/path;raw=p.read_bytes();p.write_bytes(delta.reduce_current(path,raw))
    with self.assertRaises(ValueError):delta.assert_cohort(root,Path.read_bytes)
    p.write_bytes(raw+b'//unknown')
    with self.assertRaises(ValueError):delta.assert_cohort(root,Path.read_bytes)
    p.unlink()
    with self.assertRaises(OSError):delta.assert_cohort(root,Path.read_bytes)
    p.write_bytes(raw)
 def test_raw56_consumer_requires_complete_nine_file_lifecycle_admission(self):
  import check_recovery_oauth_authorization_producer_v1 as gate
  with tempfile.TemporaryDirectory()as td:
   root=Path(td).resolve()
   for path in delta.PRODUCTION_PATHS:
    p=root/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/path).read_bytes())
   first=delta.PRODUCTION_PATHS[0];(root/first).unlink()
   with self.assertRaises(OSError):gate.SQL_obligations(root)
 def test_new_source_additions_are_independently_pinned(self):
  self.assertEqual(len(delta.SOURCE_ADDITIONS),12)
  for path,pin in delta.SOURCE_ADDITIONS.items():
   raw=(ROOT/path).read_bytes();self.assertEqual(hashlib.sha256(raw).hexdigest(),pin)
   self.assertNotEqual(hashlib.sha256(raw+b'//unknown').hexdigest(),pin)
 def test_unrelated_or_unknown_input_is_not_silently_normalized(self):
  for path in delta.PRODUCTION_PATHS:
   raw=(ROOT/path).read_bytes()+b'unknown'
   self.assertEqual(delta.reduce_if_selected(path,raw),raw)
  self.assertEqual(delta.reduce_if_selected('src/unknown.php',b'x'),b'x')

if __name__=='__main__':unittest.main()
