"""Owned CURRENT AST construction prototype; no issuer, native or SQL execution."""
import ast,copy,hashlib,types,os,stat
from pathlib import Path
import source_operation_snapshot as source
PINS={'scripts/governance/check_native_organization.py': 'c2c614ab497e6590c13455bd1b495f9f9230c62acd066884b738d460023be300', 'scripts/governance/check_private_notifications.py': 'fedee90587b21c70b65b29b70b7dc6e2a4307e984dccb029ba8ef8912ac87e8b', 'scripts/governance/check_completion.py': 'b412555b95a1f450540474807c044e0c20dd3305b3e17e5635c9812122e08296', 'support/governance/revocation-measurement/consumer.py': 'abf79fb0e64e099e72dd3b6ad50d9d727eb758d33963f47f2923f91b754346a3'}
def need(v):
 if v is not True:raise ValueError('full_native_current_AST_refused')
class Reads(ast.NodeTransformer):
 def __init__(self,actor,root):self.actor=actor;self.root=root;self.count=0
 def visit_Call(self,node):
  node=self.generic_visit(node)
  if isinstance(node.func,ast.Attribute)and node.func.attr=='read_bytes':
   need(not node.args and not node.keywords);self.count+=1
   return ast.copy_location(ast.Call(func=ast.Name(id='_task_read',ctx=ast.Load()),args=[ast.Name(id=self.actor,ctx=ast.Load()),ast.Name(id=self.root,ctx=ast.Load()),node.func.value],keywords=[]),node)
  return node
class RestoreCall(ast.NodeTransformer):
 def __init__(self):self.count=0
 def visit_Call(self,node):
  node=self.generic_visit(node)
  if isinstance(node.func,ast.Name)and node.func.id=='validate_restore':
   self.count+=1;node.func.id='_task_restore';node.keywords.append(ast.keyword(arg='operation',value=ast.Name(id='operation',ctx=ast.Load())))
  return node
DROP_IMPORTS=(
 "_spec = importlib.util.spec_from_file_location('native_completion_gate', ROOT / 'scripts/governance/check_completion.py')",
 'completion = importlib.util.module_from_spec(_spec)',
 '_spec.loader.exec_module(completion)',
 "_measurement_spec = importlib.util.spec_from_file_location('n12_observation_consumer', ROOT / 'support/governance/revocation-measurement/consumer.py')",
 'measurement = importlib.util.module_from_spec(_measurement_spec)',
 '_measurement_spec.loader.exec_module(measurement)')
def native_pair_parent_DATA(raw,expected_parent_sha):
 # Exact CURRENT pure helper, never ambient import or historical executable code.
 path=Path(__file__).resolve(strict=True).with_name('current_native_pair_metadata_v1.py')
 before=path.lstat();stamp=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
 if not stat.S_ISREG(before.st_mode)or before.st_nlink!=1 or stat.S_IMODE(before.st_mode)!=0o644 or before.st_size>4096:raise ValueError('current_native_pair_helper_refused')
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  if stamp(os.fstat(fd))!=stamp(before):raise ValueError('current_native_pair_helper_refused')
  helper=os.read(fd,4097)
  if len(helper)!=before.st_size or stamp(os.fstat(fd))!=stamp(before)or stamp(path.lstat())!=stamp(before)or hashlib.sha256(helper).hexdigest()!='37e7857d61c35f89129c558e7d952c86d9f98d19ad412d52b9145d92e52beb02':raise ValueError('current_native_pair_helper_refused')
 finally:os.close(fd)
 namespace={'__file__':str(path),'__name__':'CURRENT_native_pair_metadata_DATA'}
 exec(compile(helper,str(path),'exec'),namespace)
 return namespace['parent_DATA'](raw,expected_parent_sha)

def native_tree(raw):
 native_pair_parent_DATA(raw,PINS['scripts/governance/check_native_organization.py'])
 tree=ast.parse(raw);nodes=[];removed=[];counts={}
 for node in tree.body:
  text=ast.unparse(node)
  if text in DROP_IMPORTS:removed.append(text);continue
  if isinstance(node,ast.FunctionDef)and node.name in('validate_restore','validate'):
   name=node.name;original=copy.deepcopy(node);reader=Reads('operation','root');node=reader.visit(node);counts[name]=reader.count
   node.args.kwonlyargs.append(ast.arg(arg='operation'));node.args.kw_defaults.append(None)
   if name=='validate_restore':node.name='_task_restore'
   else:
    seam=RestoreCall();node=seam.visit(node);counts['restore_call']=seam.count
   node.body.insert(0,ast.Expr(value=ast.Call(func=ast.Name(id='_require_task',ctx=ast.Load()),args=[ast.Name(id='operation',ctx=ast.Load()),ast.Name(id='root',ctx=ast.Load())],keywords=[])))
   # Only real source read/call/interface seams; no constants or old statements disappear.
   need(len(node.body)==len(original.body)+1)
   need([x.value for x in ast.walk(ast.Module(body=node.body[1:],type_ignores=[]))if isinstance(x,ast.Constant)]==[x.value for x in ast.walk(ast.Module(body=original.body,type_ignores=[]))if isinstance(x,ast.Constant)])
  elif not isinstance(node,(ast.FunctionDef,ast.ClassDef)):
   read=Reads('_task_operation','ROOT');node=read.visit(node);counts['module_reads']=counts.get('module_reads',0)+read.count
  nodes.append(node)
 need(set(removed)==set(DROP_IMPORTS)and len(removed)==6)
 need(counts=={'module_reads':4,'validate_restore':2,'validate':4,'restore_call':1})
 return ast.fix_missing_locations(ast.Module(body=nodes,type_ignores=[])),counts
class NotificationReads(ast.NodeTransformer):
 def __init__(self):self.count=0
 def visit_Call(self,node):
  node=self.generic_visit(node)
  if isinstance(node.func,ast.Attribute)and node.func.attr=='read_bytes':
   need(not node.args and not node.keywords);self.count+=1
   return ast.copy_location(ast.Call(func=ast.Name(id='_task_read',ctx=ast.Load()),args=[ast.Name(id='operation',ctx=ast.Load()),ast.Name(id='root',ctx=ast.Load()),node.func.value],keywords=[]),node)
  return node
def notification_tree(raw):
 need(type(raw)is bytes and hashlib.sha256(raw).hexdigest()==PINS['scripts/governance/check_private_notifications.py'])
 tree=ast.parse(raw);functions=[n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='validate'];need(len(functions)==1);node=functions[0];original=copy.deepcopy(node)
 read=NotificationReads();node=read.visit(node);need(read.count==2)
 annotated=[(i,n)for i,n in enumerate(node.body)if isinstance(n,ast.Assign)and len(n.targets)==1 and isinstance(n.targets[0],ast.Name)and n.targets[0].id=='annotated'];need(len(annotated)==1)
 i,assignment=annotated[0];need(isinstance(assignment.value,ast.SetComp)and sum(isinstance(n,ast.Call)and isinstance(n.func,ast.Attribute)and n.func.attr=='rglob'for n in ast.walk(assignment))==1)
 expected=ast.parse("annotated = {str(p.relative_to(root)) for p in (root / 'src').rglob('*.php') if '__tests__' not in p.parts and '->setPrivateMailProducer(' in p.read_text()}").body[0].value
 need(ast.dump(assignment.value,include_attributes=False)==ast.dump(expected,include_attributes=False))
 assignment.value=ast.Call(func=ast.Name(id='_task_annotation_inventory',ctx=ast.Load()),args=[ast.Name(id='operation',ctx=ast.Load()),ast.Name(id='root',ctx=ast.Load())],keywords=[])
 node.args.kwonlyargs.append(ast.arg(arg='operation'));node.args.kw_defaults.append(None)
 node.body.insert(0,ast.Expr(value=ast.Call(func=ast.Name(id='_require_task',ctx=ast.Load()),args=[ast.Name(id='operation',ctx=ast.Load()),ast.Name(id='root',ctx=ast.Load())],keywords=[])))
 need(len(node.body)==len(original.body)+1)
 return ast.fix_missing_locations(tree),{'source_reads':2,'annotation_inventory':1}

def _require_task(operation,root):
 root=Path(root);source.require_task_operation(operation,root)
 if root not in operation._task_contexts:raise ValueError('unbound_full_native_root')
def _task_read(operation,root,path):
 root=Path(root);path=Path(path);_require_task(operation,root)
 need(path.is_absolute()and path.is_relative_to(root)and path.resolve()==path)
 return operation._task_contexts[root].current(str(path.relative_to(root)))
def _task_annotation_inventory(operation,root):
 root=Path(root);_require_task(operation,root);context=operation._task_contexts[root];annotated=set()
 for relative,row in context.entries.items():
  path=Path(relative)
  if not relative.startswith('src/')or not relative.endswith('.php')or '__tests__'in path.parts:continue
  need(row['kind']=='file')
  raw=context.current(relative)
  if '->setPrivateMailProducer('in raw.decode('utf-8'):annotated.add(relative)
 return annotated

def _module(operation,root,relative):
 _require_task(operation,root);raw=operation._task_contexts[root].current(relative)
 need(relative in PINS and hashlib.sha256(raw).hexdigest()==PINS[relative])
 module=types.ModuleType('Task_CURRENT_'+Path(relative).stem);module.__file__=str(root/relative)
 exec(compile(raw,module.__file__,'exec'),module.__dict__);return module

def build_in_operation(operation,root):
 root=Path(root);_require_task(operation,root);context=operation._task_contexts[root]
 completion=_module(operation,root,'scripts/governance/check_completion.py')
 measurement=_module(operation,root,'support/governance/revocation-measurement/consumer.py')
 native='scripts/governance/check_native_organization.py';notifications='scripts/governance/check_private_notifications.py'
 native_ast,native_seams=native_tree(context.current(native));notification_ast,notification_seams=notification_tree(context.current(notifications))
 common={'_require_task':_require_task,'_task_read':_task_read,'_task_annotation_inventory':_task_annotation_inventory}
 native_namespace=dict(common,__file__=str(root/native),__name__='Task_CURRENT_full_native_strict_body',_task_operation=operation,completion=completion,measurement=measurement)
 exec(compile(native_ast,str(root/native),'exec'),native_namespace)
 notification_namespace=dict(common,__file__=str(root/notifications),__name__='Task_CURRENT_private_notifications_strict_body')
 exec(compile(notification_ast,str(root/notifications),'exec'),notification_namespace)
 return {'native_validate_restore':native_namespace['_task_restore'],'native_validate':native_namespace['validate'],'native_exact_tree':native_namespace['exact_tree'],'notification_validate':notification_namespace['validate'],'native_seams':native_seams,'notification_seams':notification_seams,'native_required_checks':frozenset(native_namespace['REQUIRED_CHECKS']),'native_workers':native_namespace['WORKERS'],'original_native_strict_scope_retained':True,'genuine_native_or_SQL_qualified':False}
