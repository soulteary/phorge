"""Exact published media13/native union boundaries; host only, no runtime authority."""
from pathlib import Path
import subprocess,unittest,sys,ast,hashlib
ROOT=Path(__file__).resolve().parents[2]
MEDIA='a449a3a0dff23a52ca49810022114fadd96d244c'
NATIVE='698fc899f204ef4e210e99fdede26f66c923dbd3'
WIRE='9710b9edd56b9acf99b7c79b8c7d84669f7f2559'
def git(*args):return subprocess.check_output(['git','-C',str(ROOT),*args])
class MediaNativeUnion(unittest.TestCase):
 def test_production_native_hooks_workflows_and_unknown_guards_exact_native(self):
  self.assertEqual(b'',git('diff',NATIVE,'--','src','resources','.github/workflows','support/governance/recovery','support/governance/native-organization/test-native-organization-mysql.php','support/governance/native-organization/workers.php'))
 def test_all_published_media_inputs_remain_exact_and_unpublished_producer_absent(self):
  paths=git('diff','--name-only',WIRE,MEDIA).decode().splitlines();self.assertGreater(len(paths),35)
  for p in paths:
   if p=='tests/governance/test_media_remote_native_profile.py':continue
   self.assertEqual(git('show',MEDIA+':'+p),(ROOT/p).read_bytes(),p)
  for name in ['media_browser_bound_artifacts.py','media_browser_binding_observers.py']:
   self.assertFalse((ROOT/'tests/governance'/name).exists())
 def test_auto_merged_profile_retains_both_parent_assertions_exactly(self):
  name='tests/governance/test_media_remote_native_profile.py';base=git('merge-base',MEDIA,NATIVE).decode().strip()
  import tempfile
  with tempfile.TemporaryDirectory()as td:
   p=Path(td);files=[]
   for i,revision in enumerate([MEDIA,base,NATIVE]):
    path=p/str(i);path.write_bytes(git('show',revision+':'+name));files.append(str(path))
   merged=subprocess.run(['git','merge-file','-p',*files],capture_output=True,timeout=5)
   self.assertEqual(merged.returncode,0,merged.stderr);self.assertEqual(merged.stdout,(ROOT/name).read_bytes())
