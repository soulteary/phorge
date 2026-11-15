"""Genuine S private current graph plus explicit parent DATA; never SQL authority."""
import sys,os,stat,types,unittest,ast,hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import source_operation_snapshot as source
from task_inverse_test_api_v2 import owner
from task_historical_views_test_api_v2 import in_operation

def need(value):
 if value is not True:raise ValueError('Project_current_source_DATA_test_refused')
class GrantSourceDATA:
 def __init__(self,operation,root):
  self.op=operation;self.root=Path(root);self.require();self.context=operation._task_contexts[self.root]
  self.actual=operation._modules['project_description_admission_delta']
  self.legacy,self.r3=in_operation(operation,root)
  # Only the actual CURRENT public module is used on explicit unadmitted E.
  # Its real physical reader/guard is untouched; E is never issued/admitted.
  path=ROOT/'scripts/governance/project_description_admission_delta.py'
  raw=self.context.current(str(path.relative_to(ROOT)))
  public=types.ModuleType('Project_CURRENT_public_E_static_admission');public.__file__=str(path)
  exec(compile(raw,str(path),'exec'),public.__dict__)
  self.public=public
  for name in ('PRODUCTION_PINS','SOURCE_INPUTS','CURRENT_METADATA','MANIFEST','BRIDGE','PREPARATION','PINS','WORKFLOW','TARGET','TARGET_TREE'):
   setattr(self,name,getattr(self.actual,name))
  self.description=self.actual.description
 def require(self):
  source.require_task_operation(self.op,self.root)
  need(self.root==ROOT and self.op.code_root==ROOT and self.op.producer_root==ROOT and source.ROOT==ROOT)
 def parent_data(self,name):
  self.require();need(type(name)is str and name in self.context.parent_pins)
  raw=self.context.parent(name);need(type(raw)is bytes);return raw
 def write_fixture_DATA(self,root,names):
  self.require();root=Path(root);need(root.is_absolute()and root.resolve()==root and root!=ROOT)
  return self.legacy.fixture_DATA(root,names)
 def assert_production_cohort(self,root=ROOT):
  self.require();root=Path(root)
  if root==ROOT:return self.actual.assert_production_cohort(root)
  need(root in self.legacy._session.fixtures)
  return self.public.assert_production_cohort(root)
 def assert_current_recipe(self,root=ROOT):
  self.require();root=Path(root)
  if root==ROOT:return self.actual.assert_current_recipe(root)
  need(root in self.legacy._session.fixtures)
  return self.public.assert_current_recipe(root)
 def reduce_production(self,name,raw):self.require();return self.actual.reduce_production(name,raw)
 def reduce_if_selected(self,name,raw):self.require();return self.actual.reduce_if_selected(name,raw)
 def media_source_delta(self):
  self.require()
  relative='tests/governance/test_project_content_grant_media_source_delta.py'
  raw=self.context.current(relative);actual_legacy=self.op._modules['project_content_grant_source_delta']
  need(hashlib.sha256(raw).hexdigest()==actual_legacy.MEDIA_SOURCE_DELTA_SHA)
  tree=ast.parse(raw)
  imports=[node for node in tree.body if isinstance(node,(ast.Import,ast.ImportFrom))]
  need(len(imports)==1 and isinstance(imports[0],ast.Import)and[(item.name,item.asname)for item in imports[0].names]==[('hashlib',None)])
  helper=types.ModuleType('Project_CURRENT_media_helper_owned_DATA');helper.__file__=str(ROOT/relative)
  exec(compile(raw,helper.__file__,'exec'),helper.__dict__)
  captured=actual_legacy.media_source_delta()
  # All helper data constants and function bodies come from exact CURRENT bytes;
  # mutable negative repins affect this owned DATA namespace only.
  for node in tree.body:
   if isinstance(node,ast.Assign):
    need(len(node.targets)==1 and isinstance(node.targets[0],ast.Name))
    name=node.targets[0].id;need(getattr(helper,name)==getattr(captured,name))
  body=self.actual.media_source_delta
  need(body.__name__=='media_source_delta'and body.__code__.co_filename==str(ROOT/'scripts/governance/project_description_admission_delta.py'))
  class OwnedLegacyMediaDATA:
   def media_source_delta(binding):self.require();return helper
  namespace=dict(body.__globals__);namespace['legacy']=OwnedLegacyMediaDATA()
  view=types.FunctionType(body.__code__,namespace,body.__name__,body.__defaults__,body.__closure__)()
  need(type(view.SOURCE_INPUTS)is dict)
  return view
class GrantSourceCurrentCase(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls._owner=owner(ROOT);cls._op=cls._owner.__enter__();cls.addClassCleanup(cls._owner.__exit__,None,None,None)
  cls.d=GrantSourceDATA(cls._op,ROOT)
 def setUp(self):self.d.require()
