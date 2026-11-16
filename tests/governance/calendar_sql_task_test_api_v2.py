"""Explicit Task CURRENT source consumer for synthetic Calendar controls only."""
import hashlib,sys,types,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
if str(ROOT/'tests/governance')not in sys.path:sys.path.insert(0,str(ROOT/'tests/governance'))
import source_operation_snapshot as source
from task_inverse_test_api_v2 import owner
from test_calendar_conduit_union import _current_native_driver_prior
DRIVER='support/governance/native-organization/test-native-organization-mysql.php'
GATE='scripts/governance/check_private_calendar_reminders.py'
SELF='tests/governance/calendar_sql_task_test_api_v2.py'
def need(value):
 if value is not True:raise ValueError('Calendar_Task_test_source_refused')
class CalendarCurrent:
 def __init__(self,operation):
  self.op=operation;self.require();self.context=operation._task_contexts[ROOT]
  raw=self.context.current(GATE);gate=types.ModuleType('Calendar_CURRENT_Task_test_consumer');gate.__file__=str(ROOT/GATE)
  exec(compile(raw,gate.__file__,'exec'),gate.__dict__)
  self.gate=gate;self.original_source_views=gate._source_views
  # Exact CURRENT consumer, fixed genuine CURRENT dependency only. No public
  # module, filesystem reader, old pin or default CLI is changed.
  gate.conduit_delta=operation._modules['recovery_conduit_source_delta']
  gate._source_views=self.source_views
 def require(self):
  source.require_task_operation(self.op,ROOT)
  need(self.op.code_root==ROOT and self.op.producer_root==ROOT and source.ROOT==ROOT)
 def admitted_native_driver_source(self,root,raw):
  self.require();need(Path(root)==ROOT)
  return _current_native_driver_prior(self.op,ROOT,raw)
 def source_views(self,root=ROOT):
  self.require();root=Path(root)
  if root!=ROOT:return self.original_source_views(root)
  actual={};wire={};self.op._modules['project_description_admission_delta'].assert_current_recipe(ROOT)
  for name,pin in self.gate.SOURCE_INPUTS.items():
   captured=self.context.current(name)
   need(type(captured)is bytes and captured==self.gate.source_reader.read_source_checked(ROOT/name))
   raw=captured
   if name==DRIVER:
    raw=self.admitted_native_driver_source(ROOT,raw)
    raw=self.gate.conduit_delta.reduce_policy_cleanup_driver(raw)
   need(hashlib.sha256(raw).hexdigest()==pin)
   actual[name]=pin;wire[name]=hashlib.sha256(captured).hexdigest()
  need(len(actual)==12 and len(self.gate.CASES)==21)
  self.op._modules['project_description_admission_delta'].assert_current_recipe(ROOT)
  return actual,wire
 def cli_path(self,original):
  self.require();need(Path(original)==ROOT/GATE)
  # Explicit successor, never claim the original implicit CLI accepted Task.
  return ROOT/SELF
class CalendarTaskCase(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls._owner=owner(ROOT);cls._operation=cls._owner.__enter__();cls.addClassCleanup(cls._owner.__exit__,None,None,None)
  cls.calendar=CalendarCurrent(cls._operation)
 def setUp(self):self.calendar.require()
def main():
 with owner(ROOT)as operation:
  current=CalendarCurrent(operation)
  # Original CURRENT main: exact same independent-binary, duplicate JSON and
  # receipt validation; source binding is the explicit genuine Task route.
  current.gate.main()
if __name__=='__main__':main()
