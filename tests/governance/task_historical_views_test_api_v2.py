"""Explicit genuine CURRENT Task owner + historical DATA test views only.

Never executes Git source, opens an owner on DATA fixtures, changes a default
checker/root/reader, or treats these views as runtime/native qualification.
"""
import ast,copy,hashlib,json,os,stat,subprocess,types
from pathlib import Path
from contextlib import contextmanager
from contextlib import ExitStack
import source_operation_snapshot as source
from task_admission_test_api_v2 import checkpoint
ROOT=Path(__file__).resolve().parents[2]
HELPERS={
 'legacy':('tests/governance/n05_legacy_audit_test_view.py','1c544fda943c0e6c4c5c833a73542eecff87d420647809281161d9bb217d6788'),
 'r3':('tests/governance/n05_r3_historical_test_view.py','0cf6e2b192cd51017df2da1903f9436ea4ee02b2a3f8a58662edf3ee4bfa212d')}
MODULE_NAMES=('recovery_oauth_authorization_source_delta','recovery_partial_current_schema_delta',
 'recovery_oauth_code_ci_delta','recovery_oauth_code_source_delta','recovery_oauth_code_coordinator_delta',
 'recovery_oauth_client_lifecycle_delta','recovery_oauth_client_lifecycle_media_delta',
 'recovery_oauth_client_lifecycle_media_warning_delta','recovery_oauth_client_lifecycle_media_provider_delta')
RAW_FUNCTIONS={'historical_metadata_source','historical_coordinator_source','historical_authorization_source',
 'historical_lifecycle_source','_historical_document_source','_historical_batch_scan_source',
 '_historical_reviewed_lifecycle_source','current_descriptor_test_view',
 '_policy_ack_batch_to_ed66','_policy_ack_directory_to_ed66','_policy_ack_lifecycle_to_ed66'}
def need(value):
 if value is not True:raise ValueError('Task_historical_DATA_view_refused')
def sha(raw):return hashlib.sha256(raw).hexdigest()
def parent_row_DATA(current,parent,pin,provided):
 need(type(current)is bytes and type(parent)is bytes and type(provided)is bytes and provided==current)
 need(type(pin)is dict and set(pin)=={'kind','mode','bytes','sha256'}and pin['kind']=='file'and
  type(pin['bytes'])is int and len(parent)==pin['bytes']and sha(parent)==pin['sha256'])
 return parent
def read_fixture_DATA(path,expected,mode):
 path=Path(path);before=path.lstat()
 need(type(expected)is bytes and type(mode)is int and stat.S_ISREG(before.st_mode)and
  before.st_uid==os.getuid()and before.st_nlink==1 and stat.S_IMODE(before.st_mode)==mode and path.resolve()==path)
 def stamp(s):return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  raw=bytearray()
  while len(raw)<=len(expected):
   part=os.read(fd,min(65536,len(expected)+1-len(raw)))
   if not part:break
   raw.extend(part)
  need(stamp(before)==stamp(os.fstat(fd))==stamp(path.lstat())and bytes(raw)==expected)
  return bytes(raw)
 finally:os.close(fd)
def write_fixture_DATA(root,rows):
 """Only a new empty private DATA root; no path-based mkdir/write traversal."""
 root=Path(root);need(root.is_absolute()and root.resolve()==root and type(rows)is dict and bool(rows))
 for name,(raw,mode)in rows.items():
  need(type(name)is str and not name.startswith('/')and all(part not in('','.','..','.git')for part in name.split('/'))and
   type(raw)is bytes and type(mode)is int and mode in(0o644,0o755))
 if not root.exists():
  parent=root.parent;before=parent.lstat()
  need(parent.resolve()==parent and stat.S_ISDIR(before.st_mode)and before.st_uid==os.getuid()and stat.S_IMODE(before.st_mode)==0o700)
  parentfd=os.open(parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
  try:
   key=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid)
   need(key(before)==key(os.fstat(parentfd))==key(parent.lstat()))
   os.mkdir(root.name,0o700,dir_fd=parentfd)
   need(key(before)==key(os.fstat(parentfd))==key(parent.lstat()))
  finally:os.close(parentfd)
 initial=root.lstat();need(stat.S_ISDIR(initial.st_mode)and initial.st_uid==os.getuid()and
  stat.S_IMODE(initial.st_mode)==0o700 and not root.is_symlink())
 identity=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid)
 directories={():identity(initial)}
 with ExitStack()as outer:
  rootfd=os.open(root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW);outer.callback(os.close,rootfd)
  need(identity(os.fstat(rootfd))==identity(initial)==identity(root.lstat())and os.listdir(rootfd)==[])
  for name,(raw,mode)in sorted(rows.items()):
   need(identity(os.fstat(rootfd))==identity(initial)==identity(root.lstat()))
   with ExitStack()as held:
    current=rootfd;prefix=()
    for part in name.split('/')[:-1]:
     prefix=prefix+(part,)
     if prefix not in directories:os.mkdir(part,0o700,dir_fd=current)
     nextfd=os.open(part,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=current);held.callback(os.close,nextfd)
     info=os.fstat(nextfd);need(stat.S_ISDIR(info.st_mode)and info.st_uid==os.getuid()and stat.S_IMODE(info.st_mode)==0o700)
     if prefix in directories:need(identity(info)==directories[prefix])
     else:directories[prefix]=identity(info)
     current=nextfd
    leaf=name.split('/')[-1]
    fd=os.open(leaf,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,mode,dir_fd=current);held.callback(os.close,fd)
    os.fchmod(fd,mode);info=os.fstat(fd)
    need(stat.S_ISREG(info.st_mode)and info.st_uid==os.getuid()and info.st_nlink==1 and stat.S_IMODE(info.st_mode)==mode)
    offset=0
    while offset<len(raw):
     count=os.write(fd,raw[offset:]);need(count>0);offset+=count
    need(identity(root.lstat())==identity(initial))
  need(identity(os.fstat(rootfd))==identity(initial)==identity(root.lstat()))
def thaw(value):
 if isinstance(value,(dict,types.MappingProxyType)):return{k:thaw(v)for k,v in value.items()}
 if type(value)in(tuple,list):return type(value)(thaw(x)for x in value)
 if type(value)in(set,frozenset):return set(value)
 return value

class Seams(ast.NodeTransformer):
 """Closed CURRENT test-helper seams; original assertions/reducers stay.
 Only the forbidden historical execution function has a new explicit body.
 """
 def __init__(self,kind):self.kind=kind;self.events=[]
 def visit_FunctionDef(self,node):
  if self.kind=='r3'and node.name=='checked_modules':
   self.events.append('current-private-modules-instead-of-Git-exec')
   node.body=[ast.Return(ast.Call(ast.Attribute(ast.Name('_session',ast.Load()),'modules',ast.Load()),[],[]))]
   return node
  return self.generic_visit(node)
 def visit_Import(self,node):
  if len(node.names)==1 and node.names[0].name=='project_description_admission_delta':
   self.events.append('current-private-admission-facade')
   return ast.Assign([ast.Name(node.names[0].asname or node.names[0].name,ast.Store())],ast.Attribute(ast.Name('_session',ast.Load()),'admission_DATA',ast.Load()))
  if len(node.names)==1 and node.names[0].name=='n05_legacy_audit_test_view':
   self.events.append('current-legacy-DATA-core')
   return ast.Assign([ast.Name(node.names[0].asname or node.names[0].name,ast.Store())],ast.Attribute(ast.Name('_session',ast.Load()),'legacy_core',ast.Load()))
  return node
 def visit_ImportFrom(self,node):
  if node.module=='test_recovery_oauth_client_lifecycle_coordination'and len(node.names)==1 and node.names[0].name=='current_descriptor_test_view':
   self.events.append('current-descriptor-DATA-function')
   return ast.Assign([ast.Name(node.names[0].asname or node.names[0].name,ast.Store())],ast.Attribute(ast.Name('_session',ast.Load()),'descriptor_DATA',ast.Load()))
  return node
 def visit_Expr(self,node):
  if isinstance(node.value,ast.Call)and ast.unparse(node.value.func)=='sys.path.insert':
   self.events.append('no-global-sys-path-mutation');return None
  return self.generic_visit(node)
 def visit_Call(self,node):
  node=self.generic_visit(node)
  if isinstance(node.func,ast.Attribute)and node.func.attr=='read_bytes'and not node.args and not node.keywords:
   self.events.append('explicit-parent-DATA-read')
   return ast.copy_location(ast.Call(ast.Attribute(ast.Name('_session',ast.Load()),'data_bytes',ast.Load()),[node.func.value],[]),node)
  if ast.unparse(node.func)=='current.native_c4_reader'and len(node.args)==2 and ast.unparse(node.args[1])=='Path.read_bytes':
   self.events.append('explicit-typed-parent-reader-argument')
   node.args[1]=ast.Attribute(ast.Name('_session',ast.Load()),'data_bytes',ast.Load())
  return node

class DataView:
 def __init__(self,session,core):object.__setattr__(self,'_session',session);object.__setattr__(self,'_core',core)
 def __setattr__(self,name,value):setattr(self._core,name,value)
 def __getattr__(self,name):
  if name=='fixture_DATA':return self._session.fixture_DATA
  if name=='parent_data':return self._session.parent_data
  if name=='current_data':return self._session.current_data
  if name=='validation_DATA_root':return self._session.validation_DATA_root
  if name=='close_DATA':return self._session.close_DATA
  if name=='current_descriptor_test_view':return self._session.descriptor_public
  value=getattr(self._core,name)
  if not isinstance(value,types.FunctionType):return value
  def call(*args,**kwargs):
   self._session.require()
   if name in RAW_FUNCTIONS and len(args)>=2:
    path,raw=args[:2];root=kwargs.get('root',args[2]if len(args)>2 else ROOT)
    args=(path,self._session.input_parent(path,raw,root),*args[2:])
   elif name=='historical_library_map'and args:
    args=(self._session.input_parent('src/__phutil_library_map__.php',args[0],ROOT),*args[1:])
   return value(*args,**kwargs)
  call.__name__=name;return call

class Session:
 def __init__(self,operation,root):
  self.op=operation;self.root=Path(root);self.fixtures={};self.facades=[];self._validation=None;self._validation_manager=None;self._git_DATA={}
  self.require();self.context=operation._task_contexts[self.root]
  self.actual=operation._modules['project_description_admission_delta']
  self.admission_DATA=self.facade(self.actual)
  description=self.facade(self.actual.description)
  description.checked_bytes=self.data_bytes
  description.current_snapshot=self.data_snapshot
  self.admission_DATA.description=description
  self.admission_DATA.assert_current_recipe=self.require_DATA
  self.legacy_core=self.compile_current('legacy')
  self.r3_core=self.compile_current('r3')
  self.legacy=DataView(self,self.legacy_core);self.r3=DataView(self,self.r3_core)
  self._descriptor=self.compile_descriptor_current()
 def require(self):
  source.require_task_operation(self.op,self.root)
  need(self.root==ROOT and source.ROOT==ROOT and self.op.code_root==ROOT and self.op.producer_root==ROOT)
  for module in self.op._graph_objects:
   need({name:id(value)for name,value in module.__dict__.items()}==self.op._graph_seals[id(module)])
  for facade,original,keys in self.facades:
   for key in keys:need(thaw(getattr(facade,key))==thaw(getattr(original,key)))
 def facade(self,module):
  result=types.ModuleType('Task_test_DATA_contract_'+module.__name__);keys=[]
  for name,value in module.__dict__.items():
   if name.startswith('__'):continue
   if type(value)in(str,int,bool,bytes,tuple,list,dict,set,frozenset)or isinstance(value,types.MappingProxyType):
    setattr(result,name,thaw(value));keys.append(name)
   else:setattr(result,name,value)
  self.facades.append((result,module,tuple(keys)));return result
 def compile_current(self,kind):
  relative,pin=HELPERS[kind];raw=self.context.current(relative);need(sha(raw)==pin)
  tree=ast.parse(raw);seams=Seams(kind);tree=seams.visit(tree);ast.fix_missing_locations(tree)
  module=types.ModuleType('Task_current_helper_parent_DATA_'+kind);module.__file__=str(ROOT/relative)
  module.__dict__['_session']=self
  exec(compile(tree,str(ROOT/relative),'exec'),module.__dict__)
  module.__dict__['_Task_seam_events_DATA']=tuple(seams.events)
  return module
 def current_data(self,relative):self.require();return self.context.current(relative)
 def parent_data(self,relative):
  self.require();need(type(relative)is str and relative in self.context.parent_pins)
  value=self.context.parent(relative);need(type(value)is bytes);return value
 def data_bytes(self,path):
  self.require();path=Path(path);need(path.is_absolute())
  if path.is_relative_to(ROOT):return self.parent_data(str(path.relative_to(ROOT)))
  matches=[root for root in self.fixtures if path.is_relative_to(root)];need(len(matches)==1)
  root=matches[0];name=str(path.relative_to(root));need(name in self.fixtures[root])
  expected,mode=self.fixtures[root][name];return read_fixture_DATA(path,expected,mode)
 def require_DATA(self,root=ROOT,*args,**kwargs):
  self.require();root=Path(root)
  if root==ROOT:return None
  need(root in self.fixtures)
  for name,(expected,mode)in self.fixtures[root].items():read_fixture_DATA(root/name,expected,mode)
  self.require()
 def data_snapshot(self,root=ROOT,*args,**kwargs):
  self.require_DATA(root)
  rows={name:self.data_bytes(Path(root)/name)for name in self.actual.description.CURRENT_PINS}
  self.actual.description.validate_snapshot(rows);return rows
 def input_parent(self,path,raw,root=ROOT):
  self.require();need(type(path)is str and type(raw)is bytes);root=Path(root)
  if root==ROOT:
   return parent_row_DATA(self.context.current(path),self.parent_data(path),self.context.parent_pins[path],raw)
  self.require_DATA(root);need(raw==self.data_bytes(root/path));return raw
 def fixture_DATA(self,root,paths=None):
  self.require();root=Path(root);need(root.is_absolute()and root.resolve()==root and root!=ROOT and
   not root.is_relative_to(ROOT)and not ROOT.is_relative_to(root)and root not in self.fixtures)
  names=set(self.actual.CURRENT_METADATA)|set(self.actual.description.CURRENT_PINS)|{
   self.actual.MANIFEST,self.actual.BRIDGE,self.actual.PREPARATION,self.actual.description.LEGACY_REDUCER['path'],
   'scripts/governance/project_content_grant_ci_source_delta.py'}
  names.update(self.legacy_core.CLOSED_PATHS);names.update(self.legacy_core.REPAIR_SOURCE_PINS)
  names.update(self.legacy_core.METADATA_PATHS);names.update(self.actual.FIXTURE_METADATA_PINS)
  names.update(self.actual.PRODUCTION_PINS)
  names.update('scripts/governance/'+n+'.py'for n in MODULE_NAMES)
  names.update(self.r3_core._DOCUMENT_PATHS);names.update(self.r3_core._LIFECYCLE_REVIEWED_ED66)
  if paths is not None:
   need(type(paths)in(list,tuple,set,frozenset)and all(type(name)is str for name in paths));names.update(paths)
  rows={}
  for name in sorted(names):
   need(name in self.context.parent_pins);raw=self.context.parent(name);pin=self.context.parent_pins[name]
   need(type(raw)is bytes and pin['kind']=='file')
   rows[name]=(raw,pin['mode'])
  write_fixture_DATA(root,rows)
  self.fixtures[root]=rows;self.require_DATA(root);return root
 def validation_DATA_root(self):
  self.require()
  if self._validation is None:
   import tempfile
   self._validation_manager=tempfile.TemporaryDirectory(prefix='Task-legacy-scan-DATA-')
   root=Path(self._validation_manager.name).resolve()
   names={name for name,pin in self.context.parent_pins.items()if name.startswith('src/')and pin['kind']=='file'}
   self.fixture_DATA(root,names);self._validation=root
  return self._validation
 def close_DATA(self):
  if self._validation_manager is not None:
   self._validation_manager.cleanup();self.fixtures.pop(self._validation,None)
   self._validation_manager=None;self._validation=None
 def modules(self):
  self.require();core=self.r3_core
  need(sha(json.dumps(core.PINS,sort_keys=True,separators=(',',':')).encode())==core.PINS_SHA256)
  for name,pin in core.PINS.items():
   relative='scripts/governance/'+name;raw=self.context.current(relative)
   if relative not in self._git_DATA:
    self._git_DATA[relative]=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show',core.REVISION+':'+relative])
   prior=self._git_DATA[relative]
   need(sha(prior)==pin)
   if name=='recovery_oauth_authorization_source_delta.py':self.authorization_ast_DATA(raw,prior)
   else:need(raw==prior and sha(raw)==pin)
  need(set(core.PINS)=={name+'.py'for name in MODULE_NAMES})
  return self.op._modules['recovery_oauth_code_coordinator_delta']
 def authorization_ast_DATA(self,current,prior):
  tree=ast.parse(current);old=ast.parse(prior);removed=[]
  for node in tree.body:
   if isinstance(node,ast.Import)and len(node.names)==1 and node.names[0].name=='project_description_admission_delta':node.names[0].name='project_content_grant_source_delta'
   if isinstance(node,ast.FunctionDef)and node.name in('reduce_current','reduce_if_selected','assert_cohort'):
    expected='n05_delta.assert_current_recipe(root)'if node.name=='assert_cohort'else'n05_delta.assert_current_recipe()'
    need(ast.unparse(node.body[0])==expected);removed.append(node.name);node.body.pop(0)
  need(set(removed)=={'reduce_current','reduce_if_selected','assert_cohort'}and ast.dump(tree,include_attributes=False)==ast.dump(old,include_attributes=False))
 def compile_descriptor_current(self):
  relative='tests/governance/test_recovery_oauth_client_lifecycle_coordination.py';tree=ast.parse(self.context.current(relative))
  function=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='current_descriptor_test_view')
  previous=next(ast.literal_eval(n.value)for n in tree.body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='PREVIOUS_RECIPE_CODE_CI'for t in n.targets))
  projected=Seams('descriptor').visit(ast.Module(body=[function],type_ignores=[]));ast.fix_missing_locations(projected)
  namespace={'__file__':str(ROOT/relative),'ROOT':ROOT,'_session':self,'hashlib':hashlib,
   'n05_delta':self.admission_DATA,'legacy_view':self.legacy_core,
   'delta':self.op._modules['recovery_oauth_client_lifecycle_delta'],'PREVIOUS_RECIPE_CODE_CI':previous}
  exec(compile(projected,str(ROOT/relative),'exec'),namespace);return namespace['current_descriptor_test_view']
 def descriptor_DATA(self,path,raw):self.require();need(raw==self.parent_data(path));return self._descriptor(path,raw)
 def descriptor_public(self,path,raw):return self.descriptor_DATA(path,self.input_parent(path,raw))

def in_operation(operation,root):
 session=Session(operation,root);return session.legacy,session.r3
@contextmanager
def bound_views(root):
 with source.open_task_source_operation(Path(root),expected_checkpoint=checkpoint())as operation:
  yield in_operation(operation,root)
