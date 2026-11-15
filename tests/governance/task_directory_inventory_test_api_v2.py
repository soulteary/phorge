"""Explicit Task source-v2 proof plus historical v1 DATA; no runtime authority."""
import json,types,sys
from pathlib import Path
from contextlib import contextmanager
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import source_operation_snapshot as source
from task_inverse_test_api_v2 import owner
from task_historical_views_test_api_v2 import in_operation
def need(value):
 if value is not True:raise ValueError('directory_Task_test_source_refused')
class DirectoryDATA:
 def __init__(self,operation,root):
  self.op=operation;self.root=Path(root);self.require()
  self.context=operation._task_contexts[self.root]
  name='scripts/governance/check_directory_transports_task_source_v2.py'
  raw=self.context.current(name)
  module=types.ModuleType('Task_directory_test_actual_CURRENT_source_v2');module.__file__=str(ROOT/name)
  exec(compile(raw,module.__file__,'exec'),module.__dict__)
  self.proof=module.verify_in_operation(operation,self.root)
  need(self.proof['source_only_passed'] is True)
  need(self.proof['runtime_SQL_or_business_qualified'] is False)
  need(self.proof['original_v1_CLI_or_76_test_suite_executed'] is False)
  self.legacy,self.r3=in_operation(operation,self.root)
 def require(self):
  source.require_task_operation(self.op,self.root)
  need(self.root==ROOT and self.op.code_root==ROOT and self.op.producer_root==ROOT and source.ROOT==ROOT)
 def current_data(self,name):self.require();return self.context.current(name)
 def parent_data(self,name):
  self.require();need(type(name)is str and name in self.context.parent_pins)
  raw=self.context.parent(name);need(type(raw)is bytes);return raw
 def manifest_DATA(self,name):
  self.require();need(name=='tests/governance/native-directory-transports-source-v1.json')
  return json.loads(self.current_data(name))
 def legacy_reduction_DATA(self,name,raw):
  self.require();need(name=='tests/governance/native-directory-transports-source-v1.json')
  need(type(raw)is bytes and raw==self.current_data(name))
  return self.op._modules['project_content_grant_source_delta'].reduce_if_selected(name,self.parent_data(name))
@contextmanager
def directory_DATA(root):
 with owner(root)as operation:
  view=DirectoryDATA(operation,root)
  yield view
  view.require()
