"""CURRENT issuer and functions for historical candidate DATA, never SQL proof.

The legacy public checker remains unchanged and does not auto-adopt Task source.
Only this explicit test API binds its source-only read seam to authenticated
parent DATA held by a genuine live CURRENT SourceOperation.
"""
import ast,copy,types,sys
from collections.abc import Mapping
from contextlib import contextmanager
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
from task_inverse_test_api_v2 import owner
import source_operation_snapshot as source

def need(value):
 if value is not True:raise ValueError('Task_candidate_DATA_API_refused')
def thaw(value):
 if isinstance(value,Mapping):return{k:thaw(v)for k,v in value.items()}
 if isinstance(value,tuple):return tuple(thaw(v)for v in value)
 if isinstance(value,list):return list(map(thaw,value))
 return value
class CandidateDATA:
 def __init__(self,operation,root):
  self.operation=operation;self.root=Path(root);self.guard()
  self.context=operation._task_contexts[self.root]
  self._facades={}
  for name in ('recovery_conduit_source_delta','recovery_assessment_source_delta','recovery_oauth_source_delta'):
   actual=operation._modules[name]
   facade=types.SimpleNamespace(**{k:thaw(v)for k,v in vars(actual).items()if not k.startswith('__')})
   self._facades[name]=facade
  import check_recovery_borrowed_owner_source as original
  raw=self.context.current('scripts/governance/check_recovery_borrowed_owner_source.py')
  nodes=[copy.deepcopy(n)for n in ast.parse(raw).body if isinstance(n,ast.FunctionDef)and n.name=='validate']
  need(len(nodes)==1)
  namespace=dict(original.__dict__)
  namespace.update(code_coord=operation._modules['recovery_oauth_code_coordinator_delta'],
   conduit_delta=self._facades['recovery_conduit_source_delta'],
   assessment_delta=self._facades['recovery_assessment_source_delta'],
   source=types.SimpleNamespace(read_source_checked=self.data_bytes))
  exec(compile(ast.Module(body=nodes,type_ignores=[]),str(self.root/'scripts/governance/check_recovery_borrowed_owner_source.py')+'[CURRENT-functions-parent-DATA-only]','exec'),namespace)
  self._validate=namespace['validate']
 def guard(self):
  source.require_task_operation(self.operation,self.root)
  need(self.root==ROOT and self.operation.code_root==ROOT and self.operation.producer_root==ROOT and source.ROOT==ROOT)
 def data_bytes(self,path):
  self.guard();path=Path(path)
  need(path.is_absolute()and path.is_relative_to(self.root))
  relative=str(path.relative_to(self.root));need(relative in self.context.parent_pins)
  raw=self.context.parent(relative);need(type(raw)is bytes);return raw
 def driver_prior(self,raw):
  """Physical CURRENT equality followed by exact authenticated parent inverse."""
  self.guard();name='support/governance/native-organization/test-native-organization-mysql.php'
  need(type(raw)is bytes and raw==self.context.current(name))
  parent=self.context.parent(name);need(type(parent)is bytes)
  current=self.operation._modules['project_description_admission_delta']
  prior=current._normalize_metadata(name,parent)
  import check_recovery_file_parent_policy as original
  result=original._native_driver_prior(prior);self.guard();return result
 def validate(self,data,root=ROOT):
  self.guard();need(Path(root)==self.root)
  result=self._validate(data,self.root);self.guard();return result
 def delta(self,name):
  self.guard();need(name in self._facades);return self._facades[name]
 def inverse(self,name,path,raw):
  self.guard();need(name in self._facades and type(path)is str and type(raw)is bytes)
  method='reduce_owner'if name=='recovery_conduit_source_delta'else'reduce_current'
  # A private copy gives repin-negative tests their own mutable input pins.
  # Functions execute CURRENT code, with the original graph's strict dependencies.
  facade=self._facades[name];actual=self.operation._modules[name]
  relative='scripts/governance/'+name+'.py';tree=ast.parse(self.context.current(relative))
  nodes=[copy.deepcopy(n)for n in tree.body if isinstance(n,ast.FunctionDef)]
  need(any(n.name==method for n in nodes))
  namespace=dict(actual.__dict__);namespace.update(vars(facade))
  exec(compile(ast.Module(body=nodes,type_ignores=[]),str(self.root/relative)+'[CURRENT-functions-negative-DATA-only]','exec'),namespace)
  result=namespace[method](path,raw);self.guard();return result
@contextmanager
def source_candidates(root):
 with owner(Path(root))as operation:
  api=CandidateDATA(operation,Path(root));yield api;api.guard()
