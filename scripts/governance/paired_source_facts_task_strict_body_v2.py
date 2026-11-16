"""Pinned CURRENT Partial, Task and File facts; original read and restore seams."""
import ast,copy,hashlib,types
PROFILES={
 'recovery-partial-composition':('check_recovery_partial_composition.py','76930e99b57cb3a4488817fa88865f9b8a17df9c2cf8c501367f8779abb3e0ca',31,21,21,None,None),
 'task-owner-source-facts':('check_recovery_task_owner_source.py','ec15053fa6f27cb5c358b5c54cb4ae254618da805b118ab53abf618df939ee25',30,19,21,'support/governance/recovery/task-owner-source-cases.php','56ccc81c0b5dcb664484862f93bc8e14df4dcd9a10db27de3f20469a2355d7a1'),
 'file-source-facts':('check_recovery_file_source.py','9f7d9782dba24f9f14b4bfd7be9a6ebf39c567f4e6d197e705bfd76a8d08e375',39,29,30,'support/governance/recovery/file-source-cases.php','d106bc50447c9a6a62c1d115840d7272ee305d98b56d42f2fef0fd39558b2c10')}
IMPORTS=('import check_completion as completion','import check_native_organization as native')
def need(v):
 if v is not True:raise ValueError('paired_source_CURRENT_strict_body_refused')
def model(raw,restore,completion,native,filename,role,source_read=None):
 need(role in PROFILES);path,pin,count,sql_count,result_sql,source_path,source_sha=PROFILES[role]
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
 if source_path is not None:
  need(callable(source_read));functions=[n for n in body if isinstance(n,ast.FunctionDef)and n.name=='source_obligations'];need(len(functions)==1)
  obligation=functions[0];original_obligation=copy.deepcopy(obligation);reads=[n for n in ast.walk(obligation)if isinstance(n,ast.Call)and isinstance(n.func,ast.Attribute)and n.func.attr=='read_text']
  need(len(reads)==1 and ast.unparse(reads[0])=="(Path(root) / '"+source_path+"').read_text()")
  receiver=reads[0].func.value;reads[0].func=ast.Name(id='_task_source_read',ctx=ast.Load());reads[0].args=[ast.Name(id='operation',ctx=ast.Load()),ast.Name(id='root',ctx=ast.Load()),receiver]
  inverse=copy.deepcopy(obligation);seams=[n for n in ast.walk(inverse)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='_task_source_read'];need(len(seams)==1)
  restored=seams[0];restored.func=ast.Attribute(value=restored.args[2],attr='read_text',ctx=ast.Load());restored.args=[]
  need(ast.dump(inverse,include_attributes=False)==ast.dump(original_obligation,include_attributes=False))
  obligation.args.kwonlyargs.append(ast.arg(arg='operation'));obligation.args.kw_defaults.append(None)
  callers=[n for n in ast.walk(node)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='source_obligations'];need(len(callers)==1 and ast.unparse(callers[0])=='source_obligations(root)')
  callers[0].keywords.append(ast.keyword(arg='operation',value=ast.Name(id='operation',ctx=ast.Load())))
 else:need(source_read is None)
 inverse=copy.deepcopy(node);need(inverse.args.kwonlyargs[-1].arg=='operation'and inverse.args.kw_defaults[-1]is None)
 inverse.args.kwonlyargs.pop();inverse.args.kw_defaults.pop()
 restored=[n for n in ast.walk(inverse)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='_task_restore'];need(len(restored)==1)
 call=restored[0];need(len(call.keywords)==1 and call.keywords[0].arg=='operation'and ast.unparse(call.keywords[0].value)=='operation')
 call.func=ast.Attribute(value=ast.Name(id='native',ctx=ast.Load()),attr='validate_restore',ctx=ast.Load());call.keywords=[]
 if source_path is not None:
  forwarded=[n for n in ast.walk(inverse)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='source_obligations'];need(len(forwarded)==1 and len(forwarded[0].keywords)==1 and forwarded[0].keywords[0].arg=='operation'and ast.unparse(forwarded[0].keywords[0].value)=='operation');forwarded[0].keywords=[]
 need(ast.dump(inverse,include_attributes=False)==ast.dump(original,include_attributes=False))
 namespace={'__name__':'Task_CURRENT_paired_source_facts','__file__':filename,'_task_restore':restore,'_task_source_read':source_read,'native':native,'completion':completion}
 exec(compile(ast.fix_missing_locations(tree),filename,'exec'),namespace)
 need(len(namespace['SQL'])==sql_count and len(namespace['BOOTSTRAP']|namespace['PREPARATION']|namespace['SQL']|namespace['CLEANUP'])==count)
 return {'validate':namespace['validate'],'read_checked':namespace.get('read_checked')}
