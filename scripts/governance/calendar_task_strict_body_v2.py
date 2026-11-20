"""Closed CURRENT Calendar body/source views; no SQL issuer or SMTP authority."""
import ast,copy,hashlib,types
from pathlib import Path
import source_operation_snapshot as source
CHECKER='scripts/governance/check_private_calendar_reminders.py'
CHECKER_SHA='95d0540f89b798d0045070c865b7e909819e0a871d378efc95f9a277a859f4c9'
MAIL_RETIREMENT_PINS={'src/applications/metamta/storage/PhabricatorMetaMTAMail.php': ('56016d144982aa284ac0afc2fd272f65a785af26d85c502782d45fc3cfa07c28', 'f2340c51cb66a17c841f8d17f5e1ba425d4294a7abfb5d376c2130113c186212'), 'src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php': ('67112136ba242de5c8ada4b22ee07958722fcf3fb93b34dabf08bc353364a361', '22ea72b678bddb26058b8268903c4a1c8a1cc000655a63f0db0377178b4e22d9')}
def need(value):
 if value is not True:raise ValueError('Calendar_CURRENT_strict_body_refused')
def model(operation,root,captured,restore,completion,native):
 root=Path(root);source.require_task_operation(operation,root);need(callable(restore)and type(native)is types.SimpleNamespace and set(vars(native))=={'FIELDS','BLOCKERS'})
 context=operation._task_contexts[root];raw=context.current(CHECKER);need(hashlib.sha256(raw).hexdigest()==CHECKER_SHA)
 original=captured(operation,root,CHECKER);need(len(original.SOURCE_INPUTS)==12 and len(original.CASES)==21 and len(original.obligations())==32)
 # Only these reviewed mail sources changed with retirement. Authenticate both
 # ends of their DATA provenance, then require the current physical hashes.
 current_pins=dict(original.SOURCE_INPUTS)
 for relative,(parent_pin,current_pin)in MAIL_RETIREMENT_PINS.items():
  need(original.SOURCE_INPUTS.get(relative)==parent_pin)
  need(hashlib.sha256(context.parent(relative)).hexdigest()==parent_pin)
  need(hashlib.sha256(context.current(relative)).hexdigest()==current_pin)
  current_pins[relative]=current_pin
 need(set(current_pins)==set(original.SOURCE_INPUTS) and len(current_pins)==12)

 names={'_source_views','sources','wire_sources','summary','validate'};nodes=[copy.deepcopy(n)for n in ast.parse(raw).body if isinstance(n,ast.FunctionDef)and n.name in names];need({n.name for n in nodes}==names);before=copy.deepcopy(nodes)
 imports={'import check_recovery_file_parent_policy as native_driver_source':'_native_driver_source','import project_description_admission_delta as current':'_current_admission'};found=[];restores=[]
 for node in nodes:
  for item in ast.walk(node):
   if isinstance(item,ast.Import)and ast.unparse(item)in imports:found.append(ast.unparse(item))
   if isinstance(item,ast.Call)and ast.unparse(item.func)=='native.validate_restore':restores.append(item)
 need(set(found)==set(imports)and len(found)==2 and len(restores)==1 and ast.unparse(restores[0])=="native.validate_restore(data['source_restore'], Path(root))")
 class Adapt(ast.NodeTransformer):
  def visit_Import(self,node):
   text=ast.unparse(node)
   if text in imports:return ast.Assign([ast.Name(node.names[0].asname,ast.Store())],ast.Name(imports[text],ast.Load()))
   return node
 nodes=[Adapt().visit(node)for node in nodes]
 call=restores[0];call.func=ast.Name('_task_restore',ast.Load());call.keywords.append(ast.keyword(arg='operation',value=ast.Name('_source_operation',ast.Load())))
 inverse=copy.deepcopy(nodes);reversed_imports={}
 for text,name in imports.items():reversed_imports[name]=ast.parse(text).body[0]
 class Inverse(ast.NodeTransformer):
  def visit_Assign(self,item):
   if isinstance(item.value,ast.Name)and item.value.id in reversed_imports:
    expected=reversed_imports[item.value.id];need(len(item.targets)==1 and isinstance(item.targets[0],ast.Name)and item.targets[0].id==expected.names[0].asname);return expected
   return self.generic_visit(item)
  def visit_Call(self,item):
   if isinstance(item.func,ast.Name)and item.func.id=='_task_restore':
    need(len(item.keywords)==1 and item.keywords[0].arg=='operation'and ast.unparse(item.keywords[0].value)=='_source_operation');item.func=ast.Attribute(ast.Name('native',ast.Load()),'validate_restore',ast.Load());item.keywords=[]
   return self.generic_visit(item)
 inverse=[Inverse().visit(node)for node in inverse];need(ast.dump(ast.Module(inverse,[]),include_attributes=False)==ast.dump(ast.Module(before,[]),include_attributes=False))
 driver=captured(operation,root,'scripts/governance/check_recovery_file_parent_policy.py');conduit=captured(operation,root,'scripts/governance/recovery_conduit_source_delta.py')
 def guard():source.require_task_operation(operation,root)
 def read(path):
  guard();path=Path(path);need(path.is_absolute()and path.is_relative_to(root));name=str(path.relative_to(root));need(name in original.SOURCE_INPUTS);body=context.current(name);guard();return body
 def prior(base,body):
  guard();need(Path(base)==root and type(body)is bytes and body==context.current(driver.NATIVE_DRIVER_PATH));admission=operation._modules['project_description_admission_delta'];parent=context.parent(driver.NATIVE_DRIVER_PATH);result=driver._native_driver_prior(admission._normalize_metadata(driver.NATIVE_DRIVER_PATH,parent));guard();return result
 namespace=dict(original.__dict__);namespace.update(SOURCE_INPUTS=current_pins,_task_restore=restore,_source_operation=operation,completion=completion,native=native,conduit_delta=conduit,source_reader=types.SimpleNamespace(read_source_checked=read),_native_driver_source=types.SimpleNamespace(NATIVE_DRIVER_PATH=driver.NATIVE_DRIVER_PATH,admitted_native_driver_source=prior),_current_admission=operation._modules['project_description_admission_delta'])
 exec(compile(ast.fix_missing_locations(ast.Module(nodes,[])),str(root/CHECKER),'exec'),namespace)
 def accept(data,*,source_revision,file_digest,queue_digest):
  guard();result=namespace['validate'](data,root=root,source_revision=source_revision,file_digest=file_digest,queue_digest=queue_digest);guard();return result
 def verify_sources():
  guard();result=namespace['sources'](root);guard();return result
 return {'validate':accept,'verify_sources':verify_sources,'source_inputs':12,'checks':32,'calendar_obligations':21}
