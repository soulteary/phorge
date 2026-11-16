"""Pinned CURRENT Workbench checker; reversible read/restore seams, no SQL issuer."""
import ast,copy,hashlib,types
CHECKER='scripts/governance/check_organization_approval_workbench.py'
CHECKER_SHA='c899d76c4c7876af224adb730b3f7062c70aad4d314af8e40e176332cab67f37'
SOURCE='support/governance/organization/approval-workbench-cases.php'
SOURCE_SHA='db166e6faa96d016d9a7e88676ef004090bc7140850b9258a70f89cf6d2f37e6'
IMPORTS=('import check_completion as completion','import check_native_organization as native','from check_recovery_file_source import read_receipt')
def need(value):
 if value is not True:raise ValueError('Workbench_CURRENT_strict_body_refused')
def model(raw,restore,completion,native,filename,source_read):
 need(type(raw)is bytes and hashlib.sha256(raw).hexdigest()==CHECKER_SHA and callable(restore)and callable(source_read))
 need(type(native)is types.SimpleNamespace and set(vars(native))=={'FIELDS','BLOCKERS'})
 tree=ast.parse(raw);removed=[];body=[]
 for item in tree.body:
  if ast.unparse(item)in IMPORTS:removed.append(ast.unparse(item))
  else:body.append(item)
 need(tuple(removed)==IMPORTS);tree.body=body
 validators=[n for n in body if isinstance(n,ast.FunctionDef)and n.name=='validate'];need(len(validators)==1)
 validator=validators[0];original=copy.deepcopy(validator)
 calls=[n for n in ast.walk(validator)if isinstance(n,ast.Call)and ast.unparse(n.func)=='native.validate_restore']
 need(len(calls)==1 and ast.unparse(calls[0])=="native.validate_restore(data['source_restore'], root)")
 calls[0].func=ast.Name(id='_task_restore',ctx=ast.Load());calls[0].keywords.append(ast.keyword(arg='operation',value=ast.Name(id='operation',ctx=ast.Load())))
 validator.args.kwonlyargs.append(ast.arg(arg='operation'));validator.args.kw_defaults.append(None)
 obligations=[n for n in body if isinstance(n,ast.FunctionDef)and n.name=='obligations'];need(len(obligations)==1)
 obligation=obligations[0];original_obligation=copy.deepcopy(obligation)
 reads=[n for n in ast.walk(obligation)if isinstance(n,ast.Call)and isinstance(n.func,ast.Attribute)and n.func.attr=='read_text']
 need(len(reads)==1 and ast.unparse(reads[0])=="(Path(root) / '"+SOURCE+"').read_text()")
 read=reads[0];receiver=read.func.value;read.func=ast.Name(id='_task_source_read',ctx=ast.Load());read.args=[ast.Name(id='operation',ctx=ast.Load()),ast.Name(id='root',ctx=ast.Load()),receiver]
 inverse=copy.deepcopy(obligation);seams=[n for n in ast.walk(inverse)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='_task_source_read'];need(len(seams)==1)
 seam=seams[0];seam.func=ast.Attribute(value=seam.args[2],attr='read_text',ctx=ast.Load());seam.args=[]
 need(ast.dump(inverse,include_attributes=False)==ast.dump(original_obligation,include_attributes=False))
 obligation.args.kwonlyargs.append(ast.arg(arg='operation'));obligation.args.kw_defaults.append(None)
 callers=[n for n in ast.walk(validator)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='obligations'];need(len(callers)==1 and ast.unparse(callers[0])=='obligations(root)')
 callers[0].keywords.append(ast.keyword(arg='operation',value=ast.Name(id='operation',ctx=ast.Load())))
 inverse=copy.deepcopy(validator);need(inverse.args.kwonlyargs[-1].arg=='operation'and inverse.args.kw_defaults[-1]is None);inverse.args.kwonlyargs.pop();inverse.args.kw_defaults.pop()
 restored=[n for n in ast.walk(inverse)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='_task_restore'];need(len(restored)==1)
 call=restored[0];need(len(call.keywords)==1 and call.keywords[0].arg=='operation'and ast.unparse(call.keywords[0].value)=='operation');call.func=ast.Attribute(value=ast.Name(id='native',ctx=ast.Load()),attr='validate_restore',ctx=ast.Load());call.keywords=[]
 forwarded=[n for n in ast.walk(inverse)if isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='obligations'];need(len(forwarded)==1 and len(forwarded[0].keywords)==1 and forwarded[0].keywords[0].arg=='operation'and ast.unparse(forwarded[0].keywords[0].value)=='operation');forwarded[0].keywords=[]
 need(ast.dump(inverse,include_attributes=False)==ast.dump(original,include_attributes=False))
 namespace={'__name__':'Task_CURRENT_workbench','__file__':filename,'_task_restore':restore,'_task_source_read':source_read,'native':native,'completion':completion}
 exec(compile(ast.fix_missing_locations(tree),filename,'exec'),namespace)
 need(len(namespace['BOOTSTRAP'])==6 and len(namespace['CLEANUP'])==3)
 return {'validate':namespace['validate'],'obligations':namespace['obligations']}
