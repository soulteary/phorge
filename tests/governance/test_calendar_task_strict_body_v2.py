"""AST-only contracts; patched owner is synthetic, never actual source/SQL proof."""
import ast,importlib.util,os,sys,types,unittest,subprocess
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2];REFERENCE=Path(os.environ.get('CALENDAR_TEST_SOURCE_ROOT',str(ROOT))).resolve();sys.path.insert(0,str(REFERENCE/'scripts/governance'))
import check_completion as completion
import check_native_organization as native

def load(path):
 spec=importlib.util.spec_from_file_location('Calendar_contract_'+path.stem,path);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module
adapter=load(ROOT/'scripts/governance/calendar_task_strict_body_v2.py')
class CalendarCurrentControls(unittest.TestCase):
 def fixture(self,drift=False):
  class Context:
   def current(self,path):
    raw=(REFERENCE/path).read_bytes();return raw+b'\n'if drift and path==adapter.CHECKER else raw
   def parent(self,path):
    return subprocess.check_output(['git','--no-replace-objects','-C',str(REFERENCE),'show','5903d98b14926401f0be49cb0a9d6168026a2642:'+path])
  op=types.SimpleNamespace(_task_contexts={REFERENCE:Context()},_modules={'project_description_admission_delta':object()})
  def captured(owner,root,path):self.assertIs(owner,op);self.assertEqual(root,REFERENCE);return load(REFERENCE/path)
  return op,captured
 def test_original_five_functions_have_exact_inverse(self):
  op,captured=self.fixture()
  with patch.object(adapter.source,'require_task_operation',return_value=op):
   model=adapter.model(op,REFERENCE,captured,lambda *a,**k:None,completion,types.SimpleNamespace(FIELDS=native.FIELDS,BLOCKERS=native.BLOCKERS))
  self.assertEqual((model['checks'],model['calendar_obligations'],model['source_inputs']),(32,21,12));self.assertTrue(callable(model['validate']))
 def test_retired_mail_current_and_parent_tamper_refuse(self):
  for path in adapter.MAIL_RETIREMENT_PINS:
   for role in ('current','parent'):
    op,captured=self.fixture();context=op._task_contexts[REFERENCE];original=getattr(context,role)
    def changed(name,original=original,path=path):
     raw=original(name);return raw+b'unknown' if name==path else raw
    setattr(context,role,changed)
    with self.subTest(path=path,role=role),patch.object(adapter.source,'require_task_operation',return_value=op),self.assertRaises(ValueError):
     adapter.model(op,REFERENCE,captured,lambda *a:None,completion,types.SimpleNamespace(FIELDS=native.FIELDS,BLOCKERS=native.BLOCKERS))
 def test_retirement_pin_set_is_closed_and_current(self):
  import hashlib
  self.assertEqual(set(adapter.MAIL_RETIREMENT_PINS),{'src/applications/metamta/storage/PhabricatorMetaMTAMail.php','src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php'})
  for path,(old,new) in adapter.MAIL_RETIREMENT_PINS.items():
   self.assertNotEqual(old,new)
   self.assertEqual(hashlib.sha256((REFERENCE/path).read_bytes()).hexdigest(),new)
 def test_current_checker_drift_refused(self):
  op,captured=self.fixture(True)
  with patch.object(adapter.source,'require_task_operation',return_value=op),self.assertRaises(ValueError):adapter.model(op,REFERENCE,captured,lambda *a:None,completion,types.SimpleNamespace(FIELDS=native.FIELDS,BLOCKERS=native.BLOCKERS))
 def test_missing_owner_refused_before_current_capture(self):
  with patch.object(adapter.source,'require_task_operation',side_effect=ValueError('synthetic-refusal')),self.assertRaises(ValueError):adapter.model(None,REFERENCE,lambda *a:None,lambda *a:None,completion,types.SimpleNamespace(FIELDS=native.FIELDS,BLOCKERS=native.BLOCKERS))
 def test_actual_consumer_gate_order_and_two_independent_binary_reads(self):
  tree=ast.parse((ROOT/'scripts/governance/check_calendar_task_schema_receipt_v2.py').read_bytes());fn=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='accept');calls=[(n.lineno,ast.unparse(n.func))for n in ast.walk(fn)if isinstance(n,ast.Call)];line=lambda name:next(n for n,label in calls if label==name)
  for name in ['schema.validate_envelope','runtime_pair',"full['native_exact_tree']",'adapter.model','checker.file_binary_digest']:self.assertLess(line(name),line("model['validate']"))
  binaries=[n for n in ast.walk(fn)if isinstance(n,ast.Call)and ast.unparse(n.func)=='checker.file_binary_digest'];self.assertEqual([ast.unparse(n)for n in binaries],['checker.file_binary_digest(file_binary, file_sha)','checker.file_binary_digest(queue_binary, queue_sha)'])
 def test_physical_current_source_reads_and_historical_driver_are_separate(self):
  tree=ast.parse((ROOT/'scripts/governance/calendar_task_strict_body_v2.py').read_bytes());fn=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='model');functions={n.name:n for n in ast.walk(fn)if isinstance(n,ast.FunctionDef)}
  read=ast.unparse(functions['read']);prior=ast.unparse(functions['prior']);self.assertIn('context.current(name)',read);self.assertNotIn('context.parent',read);self.assertIn('name in original.SOURCE_INPUTS',read);self.assertIn('context.parent(driver.NATIVE_DRIVER_PATH)',prior);self.assertIn('body == context.current(driver.NATIVE_DRIVER_PATH)',prior);self.assertIn('driver._native_driver_prior',prior)
if __name__=='__main__':unittest.main()
