from n05_r3_historical_test_view import historical_authorization_source
"""Exact authorization historical inverse, not SQL or current runtime evidence."""
import hashlib,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import recovery_oauth_authorization_source_delta as delta
import n05_legacy_audit_test_view as legacy_view
class AuthorizationCoordination(unittest.TestCase):
 def test_exact_whole_bytes_match_real_0436_git_blobs(self):
  for path in delta.SOURCE_INPUTS:
   old=subprocess.check_output(['git','-C',str(ROOT),'show','0436e865b8431b9f064fe7b8a92dcad5e6623997:'+path])
   current=(ROOT/path).read_bytes()
   self.assertEqual(historical_authorization_source(path,current),old)
   self.assertEqual(hashlib.sha256(old).hexdigest(),delta.BASE_PINS[path])
   bad=current+b'\n// unknown mutation'
   with self.assertRaises(ValueError):historical_authorization_source(path,bad)
   with patch.object(delta,'SOURCE_INPUTS',{**delta.SOURCE_INPUTS,path:hashlib.sha256(bad).hexdigest()}):
    with self.assertRaises(ValueError):historical_authorization_source(path,bad)
 def test_six_source_cohort_missing_mixed_and_unknown_refuse(self):
  with tempfile.TemporaryDirectory()as td:
   root=Path(td).resolve()
   from recovery_oauth_code_test_cohort import complete_source_cohort
   complete_source_cohort(root)
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
if __name__=='__main__':unittest.main()
