"""CURRENT helper functions over typed parent DATA, never native delivery proof."""
import ast,copy,hashlib,subprocess,sys,types,unittest,os,stat
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import source_operation_snapshot as source
from task_inverse_test_api_v2 import owner
from task_historical_views_test_api_v2 import in_operation
HELPERS=('reviewed_partial_sources','reviewed_sources','validate_notice_product_delta','validate_historical_notice_product_delta')
def need(value):
 if value is not True:raise ValueError('notice_Task_source_DATA_refused')
HELPER_AST_SHA256='be5c4761c21f575081bb64d25ae2cec7716c26b762d3851ee06935e0b472b290'
def ast_shape(value):
 # Versioned structural encoding: unlike ast.dump, retain empty fields on all
 # supported Python versions. New generic type-parameter semantics are refused.
 if isinstance(value,ast.AST):
  fields=[]
  for name,child in ast.iter_fields(value):
   if name=='type_params':
    need(child==[])
    continue
   fields.append((name,ast_shape(child)))
  return(type(value).__name__,fields)
 if type(value)is list:return[ast_shape(child)for child in value]
 need(type(value)in(str,bytes,int,float,complex,bool,type(None)))
 return(type(value).__name__,repr(value))
def current_helper_nodes(raw):
 need(type(raw)is bytes)
 tree=ast.parse(raw)
 nodes=[copy.deepcopy(n)for n in tree.body if isinstance(n,(ast.Assign,ast.Import,ast.ImportFrom))and not(isinstance(n,ast.ImportFrom)and n.module=='task_notice_native_source_test_api_v2')or isinstance(n,ast.FunctionDef)and n.name in HELPERS]
 need(hashlib.sha256(repr(ast_shape(ast.Module(body=nodes,type_ignores=[]))).encode()).hexdigest()==HELPER_AST_SHA256)
 return nodes
def owned_metadata_DATA(raw,actual):
 # One named constant only, from authenticated CURRENT source. Never thaw the
 # graph or relax _constants: the original complete hash check still executes.
 need(type(raw)is bytes and type(actual)in(dict,types.MappingProxyType))
 nodes=[node for node in ast.parse(raw).body if isinstance(node,ast.Assign)and any(isinstance(target,ast.Name)and target.id=='CURRENT_METADATA'for target in node.targets)]
 need(len(nodes)==1 and len(nodes[0].targets)==1)
 data=ast.literal_eval(nodes[0].value)
 need(type(data)is dict and data==actual)
 return data
def closed_dependency_tree(raw,expected):
 need(type(raw)is bytes and type(expected)is dict)
 tree=ast.parse(raw);removed=[]
 for node in list(tree.body):
  if isinstance(node,ast.ImportFrom):raise ValueError('unknown_notice_dependency_import')
  if not isinstance(node,ast.Import):continue
  if [(item.name,item.asname)for item in node.names]==[('hashlib',None)]:continue
  need(len(node.names)==1)
  item=node.names[0]
  need(item.name in expected and item.asname==expected[item.name])
  need(item.name not in removed);removed.append(item.name);tree.body.remove(node)
 need(set(removed)==set(expected))
 return tree
class HelperSeams(ast.NodeTransformer):
 def __init__(self):self.events=[]
 def visit_Import(self,node):
  if len(node.names)==1 and node.names[0].name in ('project_description_admission_delta','check_recovery_oauth_authorization_producer_v1'):
   alias=node.names[0];name=alias.asname or alias.name;field='n05'if alias.name=='project_description_admission_delta'else'actor_DATA'
   self.events.append(('import',alias.name))
   return ast.copy_location(ast.Assign([ast.Name(name,ast.Store())],ast.Attribute(ast.Name('_view',ast.Load()),field,ast.Load())),node)
  return node
 def visit_ImportFrom(self,node):
  if node.module=='n05_r3_historical_test_view':
   need([(n.name,n.asname)for n in node.names]==[('checked_modules',None)])
   self.events.append(('importfrom','checked_modules'))
   return ast.copy_location(ast.Assign([ast.Name('checked_modules',ast.Store())],ast.Attribute(ast.Name('_view',ast.Load()),'checked_modules_DATA',ast.Load())),node)
  return node
 def visit_Call(self,node):
  node=self.generic_visit(node)
  if isinstance(node.func,ast.Attribute)and node.func.attr=='read_bytes'and not node.args and not node.keywords:
   self.events.append(('read','DATA'))
   return ast.copy_location(ast.Call(ast.Attribute(ast.Name('_view',ast.Load()),'read_DATA',ast.Load()),[node.func.value],[]),node)
  if isinstance(node.func,ast.Name)and node.func.id=='__import__'and len(node.args)==1 and isinstance(node.args[0],ast.Constant)and node.args[0].value=='recovery_assessment_source_delta':
   self.events.append(('importcall','assessment'))
   return ast.copy_location(ast.Attribute(ast.Name('_view',ast.Load()),'assessment_DATA',ast.Load()),node)
  return node
class NoticeDATA:
 def __init__(self,operation,root):
  self.op=operation;self.root=Path(root);self.require();self.context=operation._task_contexts[self.root]
  self.legacy,self.r3=in_operation(operation,root);self.session=self.legacy._session
  self.coordinator=self.session.modules()
  self.actor_DATA=types.SimpleNamespace(read_source_checked=self.read_DATA)
  self.n05=self.admission_functions_DATA()
  self.assessment_DATA=self.assessment_functions_DATA()
  self.oauth_DATA=self.dependent_functions_DATA('recovery_oauth_source_delta')
  self.conduit_DATA=self.dependent_functions_DATA('recovery_conduit_source_delta')
  self.helpers=self.helper_functions_DATA()
 def require(self):
  source.require_task_operation(self.op,self.root)
  need(self.root==ROOT and self.op.code_root==ROOT and self.op.producer_root==ROOT and source.ROOT==ROOT)
 def data_guard(self,root=ROOT):
  root=Path(root)
  need(root.is_absolute()and root.resolve()==root and root==ROOT)
  self.require()
 def read_DATA(self,path):
  self.require();path=Path(path)
  if path.is_relative_to(ROOT):return self.session.parent_data(str(path.relative_to(ROOT)))
  # Explicit mutation inputs only; never a source owner, issuer or credential.
  need(path.is_absolute()and path.resolve()==path)
  allowed=self.helpers.PARTIAL_SOURCE_PINS
  matches=[name for name in allowed if str(path).endswith('/'+name)]
  need(len(matches)==1)
  fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
  try:
   before=os.fstat(fd);need(stat.S_ISREG(before.st_mode)and before.st_nlink==1)
   raw=b''
   while True:
    part=os.read(fd,1048576)
    if not part:break
    raw+=part;need(len(raw)<=16*1024*1024)
   after=os.fstat(fd);named=path.lstat()
   stamp=lambda x:(x.st_dev,x.st_ino,x.st_mode,x.st_nlink,x.st_size,x.st_mtime_ns,x.st_ctime_ns)
   need(stamp(before)==stamp(after)==stamp(named));return raw
  finally:os.close(fd)
 def checked_modules_DATA(self):self.require();return self.session.modules()
 def admission_functions_DATA(self):
  self.require();name='project_description_admission_delta';actual=self.op._modules[name]
  namespace=dict(actual.__dict__);namespace['description']=self.session.admission_DATA.description
  namespace['CURRENT_METADATA']=owned_metadata_DATA(self.context.current('scripts/governance/'+name+'.py'),actual.CURRENT_METADATA)
  namespace['__name__']='Task_CURRENT_admission_functions_parent_DATA'
  tree=ast.parse(self.context.current('scripts/governance/'+name+'.py'))
  functions=[copy.deepcopy(n)for n in tree.body if isinstance(n,ast.FunctionDef)and n.name!='assert_current_recipe']
  need(len([n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='assert_current_recipe'])==1)
  namespace['assert_current_recipe']=self.data_guard
  exec(compile(ast.fix_missing_locations(ast.Module(body=functions,type_ignores=[])),str(ROOT/'scripts/governance'/ (name+'.py'))+'[CURRENT-functions-DATA]', 'exec'),namespace)
  return types.SimpleNamespace(**namespace)
 def assessment_functions_DATA(self):
  self.require();name='scripts/governance/recovery_assessment_source_delta.py';tree=ast.parse(self.context.current(name))
  imports=[n for n in tree.body if isinstance(n,ast.Import)and any(a.name=='recovery_oauth_code_source_delta'for a in n.names)]
  need(len(imports)==1 and len(imports[0].names)==1 and imports[0].names[0].asname=='code_delta')
  tree.body.remove(imports[0]);namespace={'__file__':str(ROOT/name),'code_delta':self.coordinator.code_delta}
  exec(compile(ast.fix_missing_locations(tree),str(ROOT/name)+'[CURRENT-functions-DATA]','exec'),namespace)
  return types.SimpleNamespace(**namespace)
 def dependent_functions_DATA(self,name):
  self.require()
  if name=='recovery_oauth_source_delta':
   imports={'recovery_oauth_code_source_delta':'code_delta','recovery_assessment_source_delta':'assessment_delta'}
   bindings={'code_delta':self.coordinator.code_delta,'assessment_delta':self.assessment_DATA}
  elif name=='recovery_conduit_source_delta':
   imports={'recovery_oauth_code_source_delta':'code_delta','recovery_oauth_source_delta':'oauth_delta'}
   bindings={'code_delta':self.coordinator.code_delta,'oauth_delta':self.oauth_DATA}
  else:raise ValueError('unknown_notice_dependency_role')
  relative='scripts/governance/'+name+'.py'
  tree=closed_dependency_tree(self.context.current(relative),imports)
  namespace={'__file__':str(ROOT/relative),'__name__':'Task_CURRENT_functions_parent_DATA_'+name,**bindings}
  exec(compile(ast.fix_missing_locations(tree),str(ROOT/relative)+'[CURRENT-functions-DATA]','exec'),namespace)
  return types.SimpleNamespace(**namespace)
 def helper_functions_DATA(self):
  self.require();name='tests/governance/test_notice_native_admin_wire_source.py';raw=self.context.current(name)
  # Constants and harmless current imports; never classes, tests or __main__.
  nodes=current_helper_nodes(raw)
  namespace={'__file__':str(ROOT/name),'_view':self}
  # Fixed DATA graph roles; initial imports remain CURRENT source modules, then
  # explicit bindings below replace only this private namespace before helpers run.
  seams=HelperSeams();projected=ast.fix_missing_locations(seams.visit(ast.Module(body=nodes,type_ignores=[])))
  need(len(seams.events)==20)
  exec(compile(projected,str(ROOT/name)+'[CURRENT-functions-parent-DATA]','exec'),namespace)
  namespace['conduit_delta']=self.conduit_DATA
  namespace['code_delta']=self.coordinator.code_delta;namespace['authorization_delta']=self.coordinator.authorization_delta
  namespace['lifecycle_delta']=self.op._modules['recovery_oauth_client_lifecycle_delta']
  self.helper_seams=tuple(seams.events)
  return types.SimpleNamespace(**namespace)
 def changed_parent_DATA(self,baseline):
  self.require();changed=set(subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'diff','--name-only',baseline,'--','src','resources'],text=True).splitlines())
  import json
  from task_historical_views_test_api_v2 import retired_source_DATA
  retired={row['path']for row in json.loads(self.context.current('docs/operations/retirement/wiki.json'))['removed_files']if row['path'].startswith(('src/','resources/'))}
  need(retired<=changed)
  for name in retired:
   need(retired_source_DATA(self.context,name)==subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show',baseline+':'+name]))
  changed-=retired
  raw={name:self.context.current(name)for name in changed}
  changed,parent=self.op.historical_task_inputs(ROOT,baseline,changed,raw)
  return changed
class TaskNoticeSourceCase(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls._owner=owner(ROOT);cls._operation=cls._owner.__enter__();cls.addClassCleanup(cls._owner.__exit__,None,None,None)
  cls._view=NoticeDATA(cls._operation,ROOT)
 def setUp(self):self._view.require()
