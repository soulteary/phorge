"""Exact historical view only; current protected media gate remains raw."""
import hashlib, subprocess, sys, tempfile, unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import recovery_oauth_client_lifecycle_delta as delta
import project_content_grant_source_delta as n05_delta

def current_descriptor_test_view(path,raw):
 # One exact audit-metadata view only; real N01 source inventory remains current.
 selected='tests/governance/native-directory-transports-source-v1.json'
 if path!=selected:return raw
 new,old=n05_delta.REPLACEMENTS[selected][0]
 if type(raw)is not bytes or hashlib.sha256(raw).hexdigest()!=n05_delta.SOURCE_INPUTS[selected]or raw!=new or hashlib.sha256(old).hexdigest()!=delta.SOURCE_INPUTS[selected]:
  raise ValueError('unreviewed descriptor test metadata')
 return old

class LifecycleCoordination(unittest.TestCase):
 def test_all_nine_whole_byte_inverses_match_real_main_blobs(self):
  self.assertEqual(len(delta.PRODUCTION_PATHS),9)
  for path in delta.SOURCE_INPUTS:
   raw=current_descriptor_test_view(path,(ROOT/path).read_bytes())
   old=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','4c2245300dc1d78d042b95190dd537eb6b1f9861:'+path])
   self.assertEqual(delta.reduce_current(path,raw),old)
   self.assertEqual(hashlib.sha256(old).hexdigest(),delta.BASE_PINS[path])
   bad=raw+b'\n// unreviewed lifecycle edit\n'
   with self.assertRaises(ValueError):delta.reduce_current(path,bad)
   with patch.object(delta,'SOURCE_INPUTS',{**delta.SOURCE_INPUTS,path:hashlib.sha256(bad).hexdigest()}):
    with self.assertRaises(ValueError):delta.reduce_current(path,bad)
 def test_current_descriptor_view_is_exact_and_never_changes_the_real_inventory(self):
  path='tests/governance/native-directory-transports-source-v1.json';raw=(ROOT/path).read_bytes()
  old=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','05a45fa349d7afc63a89846ad663538bf994ef38:'+path])
  self.assertEqual(current_descriptor_test_view(path,raw),old)
  self.assertEqual(hashlib.sha256(subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'ls-tree','-r','08629b78bc8b05f3b4e378bab591c303c053185a','--','src','resources'])).hexdigest(),'d9032eb0282befb7739b0b088b282c1d7bed25389e8d6229da6cb9011beb767c')
  with self.assertRaises(ValueError):current_descriptor_test_view(path,raw+b'unknown')
  with patch.dict(n05_delta.SOURCE_INPUTS,{path:hashlib.sha256(raw+b'unknown').hexdigest()}),self.assertRaises(ValueError):current_descriptor_test_view(path,raw+b'unknown')
  with self.assertRaises(ValueError):current_descriptor_test_view(path,old)
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
