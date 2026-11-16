"""Closed CURRENT FileParent/Session/Borrowed strict bodies; no SQL authority."""
import ast,copy,hashlib
from pathlib import Path
import source_operation_snapshot as source
PROFILES={
 'file-parent-policy':('check_recovery_file_parent_policy.py','239b9614af1288b053d369d5a4e62fb127a955a79f66053f281e34e9b1aad786',12),
 'session-producer-lease':('check_recovery_session_lease.py','4ebb8b5c0257bdaf6480ce8ce1e11ede39a5e6e38a6d1bbba97cbba69b72b814',19),
 'session-borrowed-owner':('check_recovery_borrowed_session.py','f6252dec35141e12ab64a66dea67176fb9d9ff4cd83e3ea1a18e522f43f77843',30)}
def need(value):
 if value is not True:raise ValueError('paired_session_CURRENT_strict_body_refused')
def model(operation,root,role,captured,restore):
 root=Path(root);source.require_task_operation(operation,root);need(role in PROFILES and callable(restore))
 context=operation._task_contexts[root];filename,pin,count=PROFILES[role];relative='scripts/governance/'+filename
 raw=context.current(relative);need(hashlib.sha256(raw).hexdigest()==pin)
 original=captured(operation,root,relative);need(len(original.SOURCE_INPUTS)==count)
 names={'source_obligations','summary','validate'}if role=='file-parent-policy'else{'_validate_with_source_operation'}
 nodes=[copy.deepcopy(n)for n in ast.parse(raw).body if isinstance(n,ast.FunctionDef)and n.name in names];need({n.name for n in nodes}==names)
 before=copy.deepcopy(nodes);restores=[];imports=[]
 for node in nodes:
  for item in ast.walk(node):
   if isinstance(item,ast.Call)and isinstance(item.func,ast.Attribute)and isinstance(item.func.value,ast.Name)and item.func.value.id=='native'and item.func.attr=='validate_restore':restores.append(item)
   if isinstance(item,ast.Import)and ast.unparse(item)=='import project_description_admission_delta as current':imports.append(item)
 need(len(restores)==1 and ast.unparse(restores[0])=="native.validate_restore(data['source_restore'], root)")
 call=restores[0];call.func=ast.Name('_task_restore',ast.Load());call.keywords.append(ast.keyword(arg='operation',value=ast.Name('_source_operation',ast.Load())))
 if role=='file-parent-policy':
  need(len(imports)==1)
  class Admission(ast.NodeTransformer):
   def visit_Import(self,node):
    return ast.Assign([ast.Name('current',ast.Store())],ast.Name('_current_admission',ast.Load()))if node is imports[0]else node
  nodes=[Admission().visit(n)for n in nodes]
  validator=next(n for n in nodes if n.name=='validate');validator.args.kwonlyargs.append(ast.arg(arg='_source_operation'));validator.args.kw_defaults.append(None)
 else:need(not imports)
 inverse=copy.deepcopy(nodes)
 for node in inverse:
  if role=='file-parent-policy'and node.name=='validate':
   need(node.args.kwonlyargs[-1].arg=='_source_operation'and node.args.kw_defaults[-1]is None);node.args.kwonlyargs.pop();node.args.kw_defaults.pop()
  class Inverse(ast.NodeTransformer):
   def visit_Assign(self,item):
    if ast.dump(item,include_attributes=False)==ast.dump(ast.Assign([ast.Name('current',ast.Store())],ast.Name('_current_admission',ast.Load())),include_attributes=False):return ast.Import(names=[ast.alias(name='project_description_admission_delta',asname='current')])
    return self.generic_visit(item)
   def visit_Call(self,item):
    if isinstance(item.func,ast.Name)and item.func.id=='_task_restore':
     need(len(item.keywords)==1 and ast.unparse(item.keywords[0].value)=='_source_operation');item.func=ast.Attribute(ast.Name('native',ast.Load()),'validate_restore',ast.Load());item.keywords=[]
    return self.generic_visit(item)
  Inverse().visit(node)
 need(ast.dump(ast.Module(inverse,[]),include_attributes=False)==ast.dump(ast.Module(before,[]),include_attributes=False))
 namespace=dict(original.__dict__);namespace['_task_restore']=restore
 def guard():source.require_task_operation(operation,root)
 if role=='file-parent-policy':
  def read(path):
   guard();path=Path(path);need(path.is_absolute()and path.is_relative_to(root));name=str(path.relative_to(root));need(name in original.SOURCE_INPUTS)
   result=context.current(name)if name==original.NATIVE_DRIVER_PATH else context.parent(name);guard();return result
  def prior(base,body):
   guard();need(Path(base)==root and type(body)is bytes and body==context.current(original.NATIVE_DRIVER_PATH))
   parent=context.parent(original.NATIVE_DRIVER_PATH);admission=operation._modules['project_description_admission_delta'];result=original._native_driver_prior(admission._normalize_metadata(original.NATIVE_DRIVER_PATH,parent));guard();return result
  namespace.update(read_source_checked=read,admitted_native_driver_source=prior,_current_admission=operation._modules['project_description_admission_delta'])
 exec(compile(ast.fix_missing_locations(ast.Module(nodes,[])),str(root/relative),'exec'),namespace)
 validate=namespace['validate'if role=='file-parent-policy'else'_validate_with_source_operation']
 def accept(data,*,source_revision,file_digest):
  guard();result=validate(data,root,source_revision=source_revision,file_digest=file_digest,_source_operation=operation);guard();return result
 return {'validate':accept,'source_inputs':count,'checks':16,'SQL_obligations':6}
