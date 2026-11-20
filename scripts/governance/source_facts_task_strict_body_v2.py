"""Pinned CURRENT Config, Project and Space facts; explicit read and restore seams."""
import ast,copy,hashlib,types
PROFILES={
 'application-config-facts':('check_recovery_application_config.py','3c97b93587e097accce34885d97f10ad625c244f3bb3015a7758add5677c329c',19,10),
 'project-source-facts':('check_recovery_project_source.py','923cb283a799964709a1c25b21b65bb48f5baa1920b9ee118b2b51d2629d7133',11,2),
 'spaces-source-facts':('check_recovery_spaces_source.py','9497624bf8d28e36b21d3ffb65a1b817fc45e06d998f2cc6572d93341cf6ec63',20,11)}
SPACE_PATH='support/governance/recovery/spaces-source-focused.php'
SPACE_SHA='d90d84b8e81c832d297dcfb6ca118de8983bdf94642c83c30c93f4e791b1ad03'
IMPORTS=('import check_completion as completion','import check_native_organization as native')
def need(v):
 if v is not True:raise ValueError('Config_CURRENT_strict_body_refused')
def model(raw,restore,completion,native,filename,role,space_read=None):
 need(role in PROFILES);path,pin,count,sql_count=PROFILES[role]
 need(type(raw)is bytes and hashlib.sha256(raw).hexdigest()==pin and callable(restore))
 need(type(native)is types.SimpleNamespace and set(vars(native))=={'FIELDS','BLOCKERS'})
 tree=ast.parse(raw);removed=[];body=[]
 for item in tree.body:
  if ast.unparse(item)in IMPORTS:removed.append(ast.unparse(item))
  else:body.append(item)
 need(tuple(removed)==IMPORTS);tree.body=body
 functions=[n for n in body if isinstance(n,ast.FunctionDef)and n.name=='validate'];need(len(functions)==1)
 node=functions[0];original=copy.deepcopy(node)
 calls=[n for n in ast.walk(node)if isinstance(n,ast.Call)and isinstance(n.func,ast.Attribute)and isinstance(n.func.value,ast.Name)and n.func.value.id=='native'and n.func.attr=='validate_restore']
 need(len(calls)==1 and ast.unparse(calls[0])=="native.validate_restore(data['source_restore'], root)")
 calls[0].func=ast.Name(id='_task_restore',ctx=ast.Load());calls[0].keywords.append(ast.keyword(arg='operation',value=ast.Name(id='operation',ctx=ast.Load())))
 node.args.kwonlyargs.append(ast.arg(arg='operation'));node.args.kw_defaults.append(None)
 need(len(node.body)==len(original.body))
 need([n.value for n in ast.walk(node)if isinstance(n,ast.Constant)]==[n.value for n in ast.walk(original)if isinstance(n,ast.Constant)])
 if role=='spaces-source-facts':
  need(callable(space_read));functions=[n for n in body if isinstance(n,ast.FunctionDef)and n.name=='source_obligations'];need(len(functions)==1)
  obligation=functions[0];original_obligation=copy.deepcopy(obligation);reads=[n for n in ast.walk(obligation)if isinstance(n,ast.Call)and isinstance(n.func,ast.Attribute)and n.func.attr=='read_text']
  need(len(reads)==1 and ast.unparse(reads[0])=="(Path(root) / 'support/governance/recovery/spaces-source-focused.php').read_text()")
  receiver=reads[0].func.value;reads[0].func=ast.Name(id='_task_space_read',ctx=ast.Load());reads[0].args=[ast.Name(id='operation',ctx=ast.Load()),ast.Name(id='root',ctx=ast.Load()),receiver]
  inverse=copy.deepcopy(obligation);seams=[n for n in ast.walk(inverse)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='_task_space_read'];need(len(seams)==1)
  restored=seams[0];restored.func=ast.Attribute(value=restored.args[2],attr='read_text',ctx=ast.Load());restored.args=[]
  need(ast.dump(inverse,include_attributes=False)==ast.dump(original_obligation,include_attributes=False))
  obligation.args.kwonlyargs.append(ast.arg(arg='operation'));obligation.args.kw_defaults.append(None)
  callers=[n for n in ast.walk(node)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='source_obligations'];need(len(callers)==1 and ast.unparse(callers[0])=='source_obligations(root)')
  callers[0].keywords.append(ast.keyword(arg='operation',value=ast.Name(id='operation',ctx=ast.Load())))
 else:need(space_read is None)
 inverse=copy.deepcopy(node);need(inverse.args.kwonlyargs[-1].arg=='operation'and inverse.args.kw_defaults[-1]is None)
 inverse.args.kwonlyargs.pop();inverse.args.kw_defaults.pop()
 restored=[n for n in ast.walk(inverse)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='_task_restore'];need(len(restored)==1)
 call=restored[0];need(len(call.keywords)==1 and call.keywords[0].arg=='operation'and ast.unparse(call.keywords[0].value)=='operation')
 call.func=ast.Attribute(value=ast.Name(id='native',ctx=ast.Load()),attr='validate_restore',ctx=ast.Load());call.keywords=[]
 if role=='spaces-source-facts':
  forwarded=[n for n in ast.walk(inverse)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='source_obligations'];need(len(forwarded)==1 and len(forwarded[0].keywords)==1 and forwarded[0].keywords[0].arg=='operation'and ast.unparse(forwarded[0].keywords[0].value)=='operation');forwarded[0].keywords=[]
 need(ast.dump(inverse,include_attributes=False)==ast.dump(original,include_attributes=False))
 namespace={'__name__':'Task_CURRENT_Config_facts','__file__':filename,'_task_restore':restore,'_task_space_read':space_read,'native':native,'completion':completion}
 exec(compile(ast.fix_missing_locations(tree),filename,'exec'),namespace)
 need(len(namespace['SQL'])==sql_count and len(namespace['BOOTSTRAP']|namespace['SQL']|namespace['CLEANUP'])==count)
 return namespace['validate']
