"""Fixed CURRENT API credential strict body; only its restore interface is adapted."""
import ast,copy,hashlib
PIN='cc09eb17d675d458c57f1116d97228a92834fd227101086ec56ab4c392b4ea0c'
IMPORTS=("spec = importlib.util.spec_from_file_location('native_credential_restore_gate', root / 'scripts/governance/check_native_organization.py')",'native = importlib.util.module_from_spec(spec)','spec.loader.exec_module(native)')
def need(v):
 if v is not True:raise ValueError('api_credential_CURRENT_strict_body_refused')
def model(raw,restore):
 need(type(raw)is bytes and hashlib.sha256(raw).hexdigest()==PIN and callable(restore))
 tree=ast.parse(raw);functions=[n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='validate'];need(len(functions)==1)
 node=functions[0];original=copy.deepcopy(node);removed=[];body=[]
 for item in node.body:
  text=ast.unparse(item)
  if text in IMPORTS:removed.append(text)
  else:body.append(item)
 need(tuple(removed)==IMPORTS)
 calls=[n for item in body for n in ast.walk(item)if isinstance(n,ast.Call)and isinstance(n.func,ast.Attribute)and isinstance(n.func.value,ast.Name)and n.func.value.id=='native'and n.func.attr=='validate_restore']
 need(len(calls)==1 and ast.unparse(calls[0])=="native.validate_restore(data['source_restore'], root)")
 calls[0].func=ast.Name(id='_task_restore',ctx=ast.Load());calls[0].keywords.append(ast.keyword(arg='operation',value=ast.Name(id='operation',ctx=ast.Load())))
 node.args.kwonlyargs.append(ast.arg(arg='operation'));node.args.kw_defaults.append(None);node.body=body
 # Original checks, types, constants and branch bodies remain verbatim except
 # the one restore call; the three removed statements only imported that gate.
 need(len(node.body)==len(original.body)-3)
 expected=[n.value for item in original.body if ast.unparse(item)not in IMPORTS for n in ast.walk(item)if isinstance(n,ast.Constant)]
 need([n.value for item in node.body for n in ast.walk(item)if isinstance(n,ast.Constant)]==expected)
 namespace={'__name__':'Task_CURRENT_API_credential','__file__':'scripts/governance/check_api_credential_concurrency.py','_task_restore':restore}
 exec(compile(ast.fix_missing_locations(tree),namespace['__file__'],'exec'),namespace)
 need(len(namespace['required_checks']())==46)
 return namespace['validate']
