"""Closed N11 CURRENT source adapter. No SQL/browser or legacy source grant."""
import ast,copy,hashlib,importlib,importlib.util,json,os,re,stat,sys,types
from pathlib import Path
import source_operation_snapshot as source
ROOT=Path(__file__).resolve().parents[2]
TASK='tests/governance/private_mail_ci/task_source.py'
PARENT='tests/governance/private_mail_ci/run_task.py'
PINS={TASK:'bb01e88e5b1cdb04d759a51a399284dbfb2712353f6e4c894bdccb32061ad6d6',PARENT:'e6c602139df56b4dab20f9496f6f25050b6d1d21e046e96fb4485bc45824784f'}
# Four ADDITIONAL dependencies; original typed closure + genuine Task full
# current graph still authenticate all transitive sources and prepared roles.
DEPENDENCIES=frozenset({'scripts/governance/n11_task_source_current_v2.py','scripts/governance/run_n11_task_current_v2.py','scripts/governance/check_directory_transports_task_source_v2.py','scripts/governance/task_ci_native_source_v2.py'})
BASE='3f716e3d66dbe8645ad2041e261c3f389f6a319e';BASE_TREE='275a9fc1e0092de2b98a02bc063e566b0f99744d'
MANIFEST_SHA='768e9a8393ef77713070d9855dbf4e44c025170adbe0e015ae3d57362e616a5b'
def need(value):
 if value is not True:raise ValueError('N11_CURRENT_source_v2_refused')
def dump(node):return ast.dump(node,include_attributes=False)
def expression(text):return ast.parse(text,mode='eval').body
def digest(raw):return hashlib.sha256(raw).hexdigest()
def checkpoint(value):need(type(value)is str and re.fullmatch('[a-f0-9]{40}',value)is not None and value!='0'*40);return value
def directory_receipt(value,revision):
 fields={'schema','source_only_passed','exact_current_checkpoint','current_signed_leaf_count','original_manifest_sha256','original76_pins_verified_against_exact_parent_projection','complete_src_resources_parent_tree_verified','parent_git_tree_inventory_sha256','CURRENT_typed_SourceOperation_issued','source_operation_exit_verified','original_v1_CLI_or_76_test_suite_executed','runtime_SQL_or_business_qualified','SQL_executed','old_manifest_or_pins_modified'}
 need(type(value)is dict and set(value)==fields and value['schema']=='native-directory-transports-Task-source-v2'and value['exact_current_checkpoint']==revision and value['original_manifest_sha256']==MANIFEST_SHA)
 for key in ('source_only_passed','original76_pins_verified_against_exact_parent_projection','complete_src_resources_parent_tree_verified','CURRENT_typed_SourceOperation_issued','source_operation_exit_verified'):need(value[key]is True)
 for key in ('original_v1_CLI_or_76_test_suite_executed','runtime_SQL_or_business_qualified','SQL_executed','old_manifest_or_pins_modified'):need(value[key]is False)
 need(type(value['current_signed_leaf_count'])is int and value['current_signed_leaf_count']>0 and type(value['parent_git_tree_inventory_sha256'])is str and re.fullmatch('[a-f0-9]{64}',value['parent_git_tree_inventory_sha256'])is not None)
 return True

def strict_json(raw):
 def pairs(rows):
  result={}
  for key,value in rows:need(key not in result);result[key]=value
  return result
 return json.loads(raw,object_pairs_hook=pairs,parse_constant=lambda _:need(False))

def source_functions(raw):
 """Only three pinned functions change. Prepared/FD bodies are not recompiled."""
 need(type(raw)is bytes and digest(raw)==PINS[TASK]);tree=ast.parse(raw)
 names={'_native_and_source_cli_gates','_protected_source_witness','admitted_source'}
 nodes=[copy.deepcopy(n)for n in tree.body if isinstance(n,ast.FunctionDef)and n.name in names];need({n.name for n in nodes}==names);original=copy.deepcopy(nodes)
 gate=next(n for n in nodes if n.name=='_native_and_source_cli_gates')
 constants=[n for n in ast.walk(gate)if isinstance(n,ast.Constant)and type(n.value)is str and 'v=m.current_source(r,sys.argv[2])'in n.value];need(len(constants)==1);old_program=constants[0].value;constants[0].value=old_program.replace('v=m.current_source(r,sys.argv[2])','v=m.current_source_task_v2(r,sys.argv[2])')
 json_calls=[n for n in ast.walk(gate)if isinstance(n,ast.Call)and ast.unparse(n.func)=='json.loads'];need(len(json_calls)==2)
 for call in json_calls:call.func=ast.Name('_strict_json',ast.Load())
 old_argv=expression("[sys.executable, '-B', str(root / 'scripts/governance/check_directory_transports_v1.py'), '--source-only', '--source-revision', revision]")
 new_argv=expression("[sys.executable, '-B', str(root / 'scripts/governance/check_directory_transports_task_source_v2.py'), '--root', str(root), '--expected-checkpoint', revision]")
 lists=[n for n in ast.walk(gate)if isinstance(n,ast.List)and dump(n)==dump(old_argv)];need(len(lists)==1);lists[0].elts=copy.deepcopy(new_argv.elts)
 checks=[n for n in ast.walk(gate)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='need'and 'native-directory-transports-source-only-v1'in ast.unparse(n)];need(len(checks)==1);old_condition=copy.deepcopy(checks[0]);checks[0].func=ast.Name('_directory_receipt',ast.Load());checks[0].args=[ast.Name('value',ast.Load()),ast.Name('revision',ast.Load())]
 witness=next(n for n in nodes if n.name=='_protected_source_witness');assigns=[n for n in ast.walk(witness)if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='expected'for t in n.targets)];need(len(assigns)==1);old_expected=copy.deepcopy(assigns[0].value);assigns[0].value=expression('_current_expected(operation,root,revision,snapshot,freshness,base)')
 tries=[n for n in witness.body if isinstance(n,ast.Try)];need(len(tries)==1);final=tries[0].finalbody;index=next(i for i,n in enumerate(final)if ast.unparse(n)=='ledger.verify_exit(operation)');final.insert(index,ast.Expr(expression('_verify_parent_base(operation,root,snapshot,freshness)')))
 admitted=next(n for n in nodes if n.name=='admitted_source');calls=[n for n in ast.walk(admitted)if isinstance(n,ast.Call)and ast.unparse(n.func)=='module.open_source_operation'];need(len(calls)==1);old_open=copy.deepcopy(calls[0]);calls[0].func.attr='open_task_source_operation';calls[0].keywords.append(ast.keyword(arg='expected_checkpoint',value=ast.Name('_N11_CHECKPOINT',ast.Load())))
 requires=[n for n in ast.walk(admitted)if isinstance(n,ast.Call)and ast.unparse(n.func)=='module.require_operation'];need(len(requires)==1);old_require=copy.deepcopy(requires[0]);requires[0].func=ast.Name('_require_current',ast.Load());requires[0].args=[ast.Name('operation',ast.Load()),ast.Name('config',ast.Load())]
 # Reverse every distinct seam and compare whole original function ASTs.
 inverse=copy.deepcopy(nodes)
 class Inverse(ast.NodeTransformer):
  def visit_Constant(self,n):
   if n.value==old_program.replace('v=m.current_source(r,sys.argv[2])','v=m.current_source_task_v2(r,sys.argv[2])'):return ast.Constant(old_program)
   return n
  def visit_List(self,n):return copy.deepcopy(old_argv)if dump(n)==dump(new_argv)else self.generic_visit(n)
  def visit_Call(self,n):
   if isinstance(n.func,ast.Name):
    if n.func.id=='_strict_json':n.func=ast.Attribute(ast.Name('json',ast.Load()),'loads',ast.Load());return self.generic_visit(n)
    if n.func.id=='_directory_receipt':return copy.deepcopy(old_condition)
    if n.func.id=='_current_expected':return copy.deepcopy(old_expected)
    if n.func.id=='_require_current':return copy.deepcopy(old_require)
   if ast.unparse(n.func)=='module.open_task_source_operation':return copy.deepcopy(old_open)
   return self.generic_visit(n)
  def visit_Expr(self,n):
   if isinstance(n.value,ast.Call)and isinstance(n.value.func,ast.Name)and n.value.func.id=='_verify_parent_base':return None
   return self.generic_visit(n)
 inverse=[Inverse().visit(n)for n in inverse];need(dump(ast.Module(inverse,[]))==dump(ast.Module(original,[])))
 return ast.fix_missing_locations(ast.Module(nodes,[]))

def parent_functions(raw):
 need(type(raw)is bytes and digest(raw)==PINS[PARENT]);tree=ast.parse(raw)
 nodes=[copy.deepcopy(n)for n in tree.body if isinstance(n,ast.FunctionDef)and n.name in{'run_task','main'}];need({n.name for n in nodes}=={'run_task','main'})
 # No AST changes at all: only their private globals resolve admitted_source.
 return ast.fix_missing_locations(ast.Module(nodes,[]))

def current_functions(module,raw,path):
 need(type(module)is types.ModuleType)
 expected={item.co_name:item for item in compile(raw,str(path),'exec').co_consts if type(item)is types.CodeType}
 for name,code in expected.items():
  function=getattr(module,name,None);need(type(function)is types.FunctionType)
  # Only the two original contextmanager wrappers are not called directly:
  # their CURRENT functions are replaced by exact reversible private versions.
  if name in{'admitted_source','_protected_source_witness'}:
   function=getattr(function,'__wrapped__',None);need(type(function)is types.FunctionType)
  need(function.__code__==code)
 return True

def modules(root):
 root=Path(root);need(root==ROOT and source.ROOT==root)
 folder=str(root/'tests/governance/private_mail_ci')
 if folder not in sys.path:sys.path.insert(0,folder)
 task=importlib.import_module('task_source');parent=importlib.import_module('run_task')
 for module,path in ((task,TASK),(parent,PARENT)):
  raw=task.read_source(root,path);need(Path(module.__file__).resolve()==root/path and digest(raw)==PINS[path]);current_functions(module,raw,root/path)
 return task,parent

def sidecar(root,revision,original_manifest):
 root=Path(root);checkpoint(revision);task,parent=modules(root)
 need(type(original_manifest)is dict and original_manifest.get('source_revision')==revision)
 all_rows=task.typed_manifest(root,revision,task.PARENT_FILES|DEPENDENCIES)
 return {'schema':'N11-CURRENT-adapter-dependencies-v2','source_root':str(root),'source_revision':revision,'source_tree':all_rows['source_tree'],'original_task_manifest_sha256':digest((json.dumps(original_manifest,sort_keys=True,separators=(',',':'))+'\n').encode()),'files':{k:v for k,v in all_rows['files'].items()if k in DEPENDENCIES},'runtime_qualified':False}

def exact(value,expected):
 need(type(value)is type(expected))
 if type(expected)is dict:
  need(set(value)==set(expected))
  for key in expected:exact(value[key],expected[key])
 elif type(expected)in(list,tuple):
  need(len(value)==len(expected))
  for first,second in zip(value,expected):exact(first,second)
 else:need(value==expected)
 return True

def require_sidecar(value,root,revision,original_manifest):
 need(type(value)is dict and value.get('runtime_qualified')is False and set(value)=={'schema','source_root','source_revision','source_tree','original_task_manifest_sha256','files','runtime_qualified'})
 exact(value,sidecar(root,revision,original_manifest));return value

def _guard(operation,root,revision):
 source.require_task_operation(operation,root);source.require_operation(operation,root)
 need(operation.code_root==ROOT==root and operation.producer_root==root and operation._task_expected_checkpoint==revision)
 return operation._task_contexts[root]
def verify_parent_base(operation,root,snapshot,freshness):
 context=_guard(operation,root,operation._task_expected_checkpoint)
 need(snapshot.BASE==BASE and snapshot.ROOT_TREE==BASE_TREE and len(snapshot.OVERLAY)==8)
 base,proof=snapshot.base_entries(root);expected={n:v for n,v in base.items()if freshness.protected(n)}
 need(len(expected)==8419 and set(expected)=={n for n in context.parent_pins if freshness.protected(n)})
 for name,row in expected.items():
  pin=context.parent_pins[name]
  if pin['kind']=='symlink':need(context.entries[name]==pin);actual=('symlink',pin['target'],0o777)
  else:actual=('file',operation._task_parent_bytes(root/name),pin['mode'])
  need(actual==row)
 for name,pin in snapshot.OVERLAY.items():need(digest(context.current(name))==pin)
 return True

def current_expected(operation,root,revision,snapshot,freshness,base):
 context=_guard(operation,root,revision);verify_parent_base(operation,root,snapshot,freshness)
 current={}
 for name,pin in context.entries.items():
  if not freshness.protected(name):continue
  current[name]=('symlink',pin['target'],0o777)if pin['kind']=='symlink'else('file',context.current(name),pin['mode'])
 need({n:v for n,v in freshness.head_entries(root,revision).items()if freshness.protected(n)}==current)
 return current

def bind(root,revision,config,manifest,dependency_sidecar):
 """Bindings are not authority: admission still requires real factory + exit."""
 root=Path(root);checkpoint(revision);task,parent=modules(root)
 need(config['source_root']==str(root)and config['source_revision']==revision)
 task.validate_manifest(manifest,config,task.declared_files(root,parent.TASK_FILES,parent.BROWSER_FILES if parent.BROWSER_FILES<=set(manifest['files'])else frozenset()))
 require_sidecar(dependency_sidecar,root,revision,manifest)
 namespace=dict(task.__dict__);bound_config=json.dumps(config,sort_keys=True,separators=(',',':'))
 def require_current(operation,actual):
  need(type(actual)is dict and json.dumps(actual,sort_keys=True,separators=(',',':'))==bound_config);context=_guard(operation,root,revision)
  require_sidecar(dependency_sidecar,root,revision,manifest)
  for name,row in dependency_sidecar['files'].items():need(digest(context.current(name))==row['sha256'])
 namespace.update(_strict_json=strict_json,_N11_CHECKPOINT=revision,_require_current=require_current,_directory_receipt=directory_receipt,_verify_parent_base=verify_parent_base,_current_expected=current_expected)
 exec(compile(source_functions(task.read_source(root,TASK)),str(root/TASK),'exec'),namespace)
 parent_namespace=dict(parent.__dict__);parent_namespace['admitted_source']=namespace['admitted_source']
 exec(compile(parent_functions(task.read_source(root,PARENT)),str(root/PARENT),'exec'),parent_namespace)
 need(parent_namespace['_run_task_with_source_operation']is parent._run_task_with_source_operation)
 need(parent_namespace['main'].__globals__ is parent_namespace and parent_namespace['run_task'].__globals__ is parent_namespace)
 need(sys.modules.get('task_source')is task and sys.modules.get('run_task')is parent)
 return {'admitted_source':namespace['admitted_source'],'parent':parent_namespace,'original_task_source':task,'original_parent':parent}
