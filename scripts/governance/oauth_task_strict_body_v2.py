"""Pure CURRENT-body adapter; no source admission, SQL, callback reader or legacy fallback.
The owning consumer must supply captured CURRENT bytes in a genuine scope. Only
explicit source-obligation/restore seams change; all old receipt assertions stay.
"""
import ast,copy,hashlib,re,types,os,stat
from pathlib import Path
PINS={
 'check_completion':'39956a43c26607ac5ebb761ec25f686b2314326511a082f80efe4bf3a2258649',
 'check_native_organization':'c2c614ab497e6590c13455bd1b495f9f9230c62acd066884b738d460023be300',
 'check_recovery_partial_composition':'76930e99b57cb3a4488817fa88865f9b8a17df9c2cf8c501367f8779abb3e0ca',
 'check_recovery_file_parent_policy':'239b9614af1288b053d369d5a4e62fb127a955a79f66053f281e34e9b1aad786',
 'check_recovery_oauth_authorization_producer_v1':'e3e271c4343815bcbf0a263de9b75428561721e8e42fc1f522a8dd9907d69c5f',
 'check_recovery_oauth_client_lifecycle':'a5f6e98e1bea0ae6365bd412d8cf3f1cc5313901969cdcf72d616d2957cd4e25',
 'qualify_oauth_authorization_mysql84':'d24bbde68146aa9972ac7a901048551e7eda56493162dc47294034c6e236303f',
 'qualify_oauth_client_lifecycle_mysql84':'bccd536fbb401e601380893e73582e255657e476922bf91a0cdf51aa16f772f5',
}
ROLES={'oauth-authorization':('check_recovery_oauth_authorization_producer_v1','qualify_oauth_authorization_mysql84'),
       'oauth-client-lifecycle':('check_recovery_oauth_client_lifecycle','qualify_oauth_client_lifecycle_mysql84')}
def need(v):
 if v is not True:raise ValueError('Task_OAuth_strict_body_v2_refused')
def trees(raws):
 need(type(raws)is dict and set(raws)==set(PINS));out={}
 for name,pin in PINS.items():
  need(type(raws[name])is bytes and hashlib.sha256(raws[name]).hexdigest()==pin);out[name]=ast.parse(raws[name])
 return out
def literal(tree,name):
 nodes=[n for n in tree.body if isinstance(n,ast.Assign)and len(n.targets)==1 and isinstance(n.targets[0],ast.Name)and n.targets[0].id==name]
 need(len(nodes)==1);value=nodes[0].value
 if isinstance(value,ast.Call)and isinstance(value.func,ast.Attribute)and value.func.attr=='splitlines'and isinstance(value.func.value,ast.Constant)and type(value.func.value.value)is str and not value.args and not value.keywords:
  return value.func.value.value.splitlines()
 return ast.literal_eval(value)
def functions(tree,names):
 selected=[copy.deepcopy(n)for n in tree.body if isinstance(n,ast.FunctionDef)and n.name in names]
 need({n.name for n in selected}==set(names)and len(selected)==len(names));return selected
def compile_functions(nodes,namespace):
 exec(compile(ast.fix_missing_locations(ast.Module(body=nodes,type_ignores=[])),'captured_CURRENT_pure_body','exec'),namespace)
def _captured(operation,root,path):
 operation.require(root);path=Path(path);need(path.is_relative_to(root))
 return operation._task_contexts[Path(root)].current(str(path.relative_to(root)))
class RestoreReads(ast.NodeTransformer):
 def __init__(self):self.count=0
 def visit_Call(self,node):
  node=self.generic_visit(node)
  if isinstance(node.func,ast.Attribute)and node.func.attr=='read_bytes':
   need(not node.args and not node.keywords);self.count+=1
   return ast.Call(func=ast.Name(id='_captured',ctx=ast.Load()),args=[ast.Name(id='operation',ctx=ast.Load()),ast.Name(id='root',ctx=ast.Load()),node.func.value],keywords=[])
  return node
class BodySeams(ast.NodeTransformer):
 def __init__(self):self.counts={'obligations':0,'SQL_obligations':0,'restore':0,'summary':0}
 def visit_Call(self,node):
  node=self.generic_visit(node)
  if isinstance(node.func,ast.Name)and node.func.id in('obligations','SQL_obligations'):
   name=node.func.id;self.counts[name]+=1
   return ast.Name(id='expected_obligations'if name=='obligations'else'sql_obligations',ctx=ast.Load())
  if isinstance(node.func,ast.Attribute)and isinstance(node.func.value,ast.Name)and node.func.value.id=='native'and node.func.attr=='validate_restore':
   self.counts['restore']+=1;node.func=ast.Name(id='_strict_restore',ctx=ast.Load());node.args.append(ast.Name(id='operation',ctx=ast.Load()))
  if isinstance(node.func,ast.Name)and node.func.id=='summary':
   self.counts['summary']+=1;node.keywords.append(ast.keyword(arg='sql_obligations',value=ast.Name(id='sql_obligations',ctx=ast.Load())))
  return node
def model(raws,role):
 need(role in ROLES);parsed=trees(raws)
 completion_ns={'re':re};compile_functions(functions(parsed['check_completion'],{'require','_equal','exact','fields','digest','strict_object'}),completion_ns)
 completion=types.SimpleNamespace(**{n:completion_ns[n]for n in('exact','fields','digest','strict_object')});completion.PATCHES=literal(parsed['check_completion'],'PATCHES')
 native_ns={};compile_functions(functions(parsed['check_native_organization'],{'exact_tree'}),native_ns)
 native=types.SimpleNamespace(FIELDS=literal(parsed['check_native_organization'],'FIELDS'),BLOCKERS=literal(parsed['check_native_organization'],'BLOCKERS'))
 restore=functions(parsed['check_native_organization'],{'validate_restore'})[0];restore.name='_strict_restore';restore.args.args.append(ast.arg(arg='operation'));rr=RestoreReads();restore=rr.visit(restore);need(rr.count==2)
 restore_ns={'hashlib':hashlib,'completion':completion,'exact_tree':native_ns['exact_tree'],'_captured':_captured};compile_functions([restore],restore_ns)
 shared=types.SimpleNamespace(**{n:literal(parsed['check_recovery_partial_composition'],n)for n in('BOOTSTRAP','PREPARATION','CLEANUP')})
 artifact_ns={'Path':Path,'os':os,'stat':stat,'hashlib':hashlib}
 compile_functions(functions(parsed['check_recovery_partial_composition'],{'read_checked'}),artifact_ns)
 checker_name,runtime_name=ROLES[role];tree=parsed[checker_name]
 names={'observer_cleanup','source_identities','summary','exact','validate'}if role=='oauth-authorization'else{'exact','trace','identities','validate'}
 nodes=functions(tree,names);seams={}
 for i,node in enumerate(nodes):
  if node.name not in('validate','summary'):continue
  change=BodySeams();node=change.visit(node);seams[node.name]=change.counts
  node.args.kwonlyargs.append(ast.arg(arg='sql_obligations'));node.args.kw_defaults.append(None)
  if node.name=='validate':
   # Explicit root supplied by genuine consumer; do not invent a legacy ROOT.
   node.args.defaults=[]
   for name in('expected_obligations','operation'):node.args.kwonlyargs.append(ast.arg(arg=name));node.args.kw_defaults.append(None)
  nodes[i]=node
 expected={'obligations':1,'SQL_obligations':1,'restore':1,'summary':1 if role=='oauth-authorization'else 0};need(seams['validate']==expected)
 if role=='oauth-authorization':need(seams['summary']=={'obligations':0,'SQL_obligations':1,'restore':0,'summary':0})
 ns={'completion':completion,'native':native,'shared':shared,'re':re,'_strict_restore':restore_ns['_strict_restore'],'SOURCE_INPUTS':literal(tree,'SOURCE_INPUTS')}
 if role=='oauth-client-lifecycle':ns['OPERATIONS']=literal(tree,'OPERATIONS')
 compile_functions(nodes,ns)
 runtime_ns={'completion':completion,'re':re,'FIELDS':literal(parsed[runtime_name],'FIELDS'),'IMAGE':literal(parsed[runtime_name],'IMAGE')}
 compile_functions(functions(parsed[runtime_name],{'runtime'}),runtime_ns)
 return {'validate':ns['validate'],'runtime':runtime_ns['runtime'],'read_checked':artifact_ns['read_checked'],'source_inputs':literal(tree,'SOURCE_INPUTS'),'operations':ns.get('OPERATIONS'),
         'bootstrap':shared.BOOTSTRAP|shared.PREPARATION|shared.CLEANUP if role=='oauth-authorization'else literal(tree,'BOOTSTRAP')|literal(tree,'CLEANUP'),
         'body_seams':seams,'completion':completion,'parsed':parsed,'checker_name':checker_name,'source_admission_proved':False}
