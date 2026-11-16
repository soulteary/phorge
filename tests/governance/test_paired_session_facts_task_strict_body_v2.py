"""AST-only controls: synthetic owner seam, never source or SQL qualification."""
import importlib.util,sys,types,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
# DATA controls use a separate explicit source tree, not an alternate authority.
SOURCE_ROOT=Path(__import__('os').environ.get('PAIRED_SESSION_TEST_SOURCE_ROOT',str(ROOT)))
sys.path.insert(0,str(SOURCE_ROOT/'scripts/governance'))
def load(path):
 spec=importlib.util.spec_from_file_location('paired_session_control_'+path.stem,path);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module
adapter=load(ROOT/'scripts/governance/paired_session_facts_task_strict_body_v2.py')
class Controls(unittest.TestCase):
 def fixture(self,tamper=False):
  class Context:
   def current(self,path):
    raw=(SOURCE_ROOT/path).read_bytes();return raw+b'\n'if tamper else raw
  op=types.SimpleNamespace(_task_contexts={SOURCE_ROOT:Context()},_modules={'project_description_admission_delta':object()})
  def captured(owner,root,path):
   self.assertIs(owner,op);self.assertEqual(root,SOURCE_ROOT);return load(SOURCE_ROOT/path)
  return op,captured
 def test_all_three_original_bodies_have_exact_inverse(self):
  op,captured=self.fixture()
  with patch.object(adapter.source,'require_task_operation',return_value=op):
   for role in adapter.PROFILES:
    result=adapter.model(op,SOURCE_ROOT,role,captured,lambda *a,**k:None)
    self.assertEqual((result['checks'],result['SQL_obligations']),(16,6));self.assertTrue(callable(result['validate']))
 def test_current_byte_drift_refused(self):
  op,captured=self.fixture(True)
  with patch.object(adapter.source,'require_task_operation',return_value=op):
   for role in adapter.PROFILES:
    with self.assertRaises(ValueError):adapter.model(op,SOURCE_ROOT,role,captured,lambda *a,**k:None)
 def test_owner_refusal_precedes_capture(self):
  op,captured=self.fixture()
  with patch.object(adapter.source,'require_task_operation',side_effect=ValueError('closed')):
   with self.assertRaises(ValueError):adapter.model(op,SOURCE_ROOT,'file-parent-policy',captured,lambda *a,**k:None)
 def test_unknown_role_refused(self):
  op,captured=self.fixture()
  with patch.object(adapter.source,'require_task_operation',return_value=op):
   with self.assertRaises(ValueError):adapter.model(op,SOURCE_ROOT,'other',captured,lambda *a,**k:None)
if __name__=='__main__':unittest.main()
