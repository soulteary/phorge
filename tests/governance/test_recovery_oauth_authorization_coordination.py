from n05_r3_historical_test_view import historical_authorization_source
"""Exact authorization historical inverse, not SQL or current runtime evidence."""
import hashlib,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import recovery_oauth_authorization_source_delta as delta
import n05_legacy_audit_test_view as legacy_view
from task_oauth_coordination_test_api_v2 import TaskOAuthCurrentCase
class AuthorizationCoordination(TaskOAuthCurrentCase):
 def test_exact_whole_bytes_match_real_0436_git_blobs(self):
  for path in delta.SOURCE_INPUTS:
   old=subprocess.check_output(['git','-C',str(ROOT),'show','0436e865b8431b9f064fe7b8a92dcad5e6623997:'+path])
   current=(ROOT/path).read_bytes()
   self.assertEqual(self._r3.historical_authorization_source(path,current),old)
   self.assertEqual(hashlib.sha256(old).hexdigest(),delta.BASE_PINS[path])
   bad=current+b'\n// unknown mutation'
   with self.assertRaises(ValueError):self._r3.historical_authorization_source(path,bad)
   with patch.object(delta,'SOURCE_INPUTS',{**delta.SOURCE_INPUTS,path:hashlib.sha256(bad).hexdigest()}):
    with self.assertRaises(ValueError):self._r3.historical_authorization_source(path,bad)
 def test_six_source_cohort_missing_mixed_and_unknown_refuse(self):
  with tempfile.TemporaryDirectory()as td:
   root=Path(td).resolve()
   self._task_api.fixture_DATA(root)
   for path in delta.PRODUCTION_PATHS:
    p=root/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(self._task_api.native_reader(ROOT/path))
   self.assertTrue(self._task_api.fixture_authorization_cohort(root))
   for path in delta.PRODUCTION_PATHS:
    p=root/path;raw=p.read_bytes();p.write_bytes(self._task_api.authorization_fixture_inverse(path,raw))
    with self.assertRaises(ValueError):self._task_api.fixture_authorization_cohort(root)
    p.write_bytes(raw+b'//unknown')
    with self.assertRaises(ValueError):self._task_api.fixture_authorization_cohort(root)
    p.unlink()
    with self.assertRaises(OSError):self._task_api.fixture_authorization_cohort(root)
    p.write_bytes(raw)
if __name__=='__main__':unittest.main()
