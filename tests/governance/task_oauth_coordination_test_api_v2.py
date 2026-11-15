"""Genuine CURRENT source plus explicitly historical fixture DATA test API.
No SQL, native grant, historical module execution, fake issuer or reader patch.
The original strict receipt/runtime bodies are captured CURRENT, with the already
reviewed Task adapter and its exact source-obligation seams, never a fake model.
"""
import ast,copy,unittest
from pathlib import Path
import source_operation_snapshot as source
from task_inverse_test_api_v2 import owner
ROOT=Path(__file__).resolve().parents[2]

def need(value):
 if value is not True:raise ValueError('Task_OAuth_test_API_refused')
class CurrentOAuthTests:
 def __init__(self,operation,root):
  root=Path(root);source.require_task_operation(operation,root)
  need(root==ROOT and operation.code_root==ROOT and operation.producer_root==ROOT and source.ROOT==ROOT)
  self.op=operation;self.root=root;self.context=operation._task_contexts[root]
  self.n05=operation._modules['project_description_admission_delta']
  self.lifecycle=operation._modules['recovery_oauth_client_lifecycle_delta']
  self.authorization=operation._modules['recovery_oauth_authorization_source_delta']
  from task_historical_views_test_api_v2 import in_operation
  self.legacy,self.r3=in_operation(operation,root)
  import check_oauth_task_schema_receipt_v2 as consumer
  adapter=consumer.captured_module(operation,root,'scripts/governance/oauth_task_strict_body_v2.py')
  raws={name:self.context.current('scripts/governance/'+name+'.py')for name in adapter.PINS}
  self.model=adapter.model(raws,'oauth-authorization');self.model['adapter']=adapter
  self.sql,self.labels=consumer.source_obligations(operation,root,self.model,'oauth-authorization')
  need(len(self.sql)==20 and len(self.labels)==30 and len(self.model['source_inputs'])==56)
  self._fixtures=set()
 def guard(self):
  source.require_task_operation(self.op,self.root);self.op._assert_current_recipe(self.root)
 def native_reader(self,path):
  self.guard();reader=self.n05.native_c4_reader(self.root,self.op._read_task_parent_producer)
  return reader(path)
 def fixture_DATA(self,destination):
  self.guard();destination=Path(destination)
  need(destination.is_absolute()and destination.resolve()==destination and destination!=self.root and
   not destination.is_relative_to(self.root)and not self.root.is_relative_to(destination))
  self.legacy.fixture_DATA(destination,paths=sorted(set(self.lifecycle.PRODUCTION_PATHS)|set(self.authorization.PRODUCTION_PATHS)))
  self._fixtures.add(destination);return destination
 def fixture_lifecycle_SQL_obligations(self,destination):
  # Complete real source56/SQL20 was authenticated above. This additional test
  # fixture exercises ONLY the original nine-file closed cohort's DATA failures.
  # It cannot be an owner or a current runtime/source-admission root.
  self.guard();destination=Path(destination)
  need(destination in self._fixtures)
  need(self.lifecycle.assert_cohort(destination,Path.read_bytes)is True)
  self.guard();return set(self.sql)
 def fixture_authorization_cohort(self,destination):
  self.guard();destination=Path(destination);need(destination in self._fixtures)
  relative='scripts/governance/recovery_oauth_authorization_source_delta.py'
  tree=ast.parse(self.context.current(relative))
  functions=[copy.deepcopy(n)for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='assert_cohort'];need(len(functions)==1)
  function=functions[0];need(ast.unparse(function.body[0])=='n05_delta.assert_current_recipe(root)')
  # Explicit fixture seam: original complete cohort/hash/mixed rules remain;
  # the actual CURRENT source obligation is guarded on S, never on DATA clone.
  function.body[0]=ast.Expr(value=ast.Call(func=ast.Name(id='_real_current_source_guard',ctx=ast.Load()),args=[],keywords=[]))
  namespace={**self.authorization.__dict__,'_real_current_source_guard':self.guard}
  exec(compile(ast.fix_missing_locations(ast.Module(body=[function],type_ignores=[])),str(self.root/relative)+'[fixture-DATA-only]','exec'),namespace)
  result=namespace['assert_cohort'](destination,Path.read_bytes);self.guard();return result
 def authorization_fixture_inverse(self,path,raw):
  self.guard();return self.authorization.reduce_current(path,raw)
 def auth_SQL_obligations(self):self.guard();return set(self.sql)
 def auth_obligations(self):self.guard();return set(self.labels)
 def auth_summary(self,revision,identities):
  self.guard();summary=self.model['validate'].__globals__['summary']
  result=summary(revision,identities,sql_obligations=self.sql);self.guard();return result
 def auth_validate(self,receipt,*,source_revision,file_digest):
  self.guard();result=self.model['validate'](receipt,self.root,source_revision=source_revision,
   file_digest=file_digest,sql_obligations=self.sql,expected_obligations=self.labels,operation=self.op)
  self.guard();return result
 def auth_qualify(self,receipt,before,after,*,revision,file_digest):
  self.guard();observed=self.model['runtime'](before,after,revision)
  accepted=self.auth_validate(receipt,source_revision=revision,file_digest=file_digest)
  return {**accepted,'database_runtime':observed}

class TaskOAuthCurrentCase(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  super().setUpClass();scope=owner(ROOT);operation=scope.__enter__()
  cls.addClassCleanup(scope.__exit__,None,None,None)
  cls._task_api=CurrentOAuthTests(operation,ROOT)
  cls._legacy=cls._task_api.legacy;cls._r3=cls._task_api.r3
