"""Genuine CURRENT owner with parent DATA for the three partial-history tests.
No historical Python execution, DATA source role, or native/SQL qualification.
"""
import ast,types,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import source_operation_snapshot as source
from task_inverse_test_api_v2 import owner
from task_historical_views_test_api_v2 import in_operation
PARTIAL='scripts/governance/recovery_partial_current_schema_delta.py'
class TaskPartialSchemaCurrentCase(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls._owner=owner(ROOT);cls._operation=cls._owner.__enter__();cls.addClassCleanup(cls._owner.__exit__,None,None,None)
  source.require_task_operation(cls._operation,ROOT)
  if cls._operation.code_root!=ROOT or cls._operation.producer_root!=ROOT or source.ROOT!=ROOT:raise ValueError('Partial_history_unknown_source_role')
  cls._context=cls._operation._task_contexts[ROOT]
  cls._admission=cls._operation._modules['project_description_admission_delta']
  cls.authorization=cls._operation._modules['recovery_oauth_authorization_source_delta']
  cls.legacy,cls.r3=in_operation(cls._operation,ROOT)
  actual=cls._operation._modules['recovery_partial_current_schema_delta']
  raw=cls._context.current(PARTIAL);tree=ast.parse(raw)
  nodes=[node for node in tree.body if isinstance(node,ast.FunctionDef)]
  if {n.name for n in nodes}!={'reduce_if_selected','assert_cohort'}:raise ValueError('Partial_history_unknown_functions')
  module=types.ModuleType('Partial_CURRENT_functions_owned_historical_DATA');module.__file__=str(ROOT/PARTIAL)
  module.__dict__.update(actual.__dict__)
  # Mutable repin-negative control is owned DATA, never an actual graph mutation.
  module.SOURCE_INPUTS=dict(actual.SOURCE_INPUTS)
  if module.SOURCE_INPUTS!=actual.SOURCE_INPUTS or module.authorization_delta is not cls.authorization:raise ValueError('Partial_history_DATA_binding_refused')
  exec(compile(ast.Module(body=nodes,type_ignores=[]),str(ROOT/PARTIAL)+'[CURRENT-historical-DATA-only]','exec'),module.__dict__)
  cls.partial=module
 def setUp(self):self.require_current()
 def require_current(self):
  source.require_task_operation(self._operation,ROOT);self._admission.assert_current_recipe(ROOT)
 def parent_data(self,relative):
  self.require_current();return self._context.parent(relative)
