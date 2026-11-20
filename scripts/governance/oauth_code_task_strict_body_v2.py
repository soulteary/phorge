"""Pinned OAuth-code CURRENT bodies with explicit typed inputs; no SQL/authority."""
import ast,copy,hashlib,re,types,os,stat
from pathlib import Path
PINS={'check_completion': 'b412555b95a1f450540474807c044e0c20dd3305b3e17e5635c9812122e08296', 'check_native_organization': 'c2c614ab497e6590c13455bd1b495f9f9230c62acd066884b738d460023be300', 'check_recovery_partial_composition': '1a06ef08a91bc152ffee0d8810210a1e0536223bda683a4a4dbb2028fdf1660f', 'check_recovery_oauth_code_producer_v1': 'db7169a52d07ed0f81496d3a1ed5f682f5da078a856366fb60bd057d69a6cac1'}
IMAGE='mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'
def need(v):
 if v is not True:raise ValueError('Task_OAuthCode_body_refused')
class SQLReaderSeam(ast.NodeTransformer):
 def __init__(self):self.count=0
 def visit_Name(self,node):
  if isinstance(node.ctx,ast.Load)and node.id=='read_source_checked':self.count+=1;return ast.copy_location(ast.Name(id='reader',ctx=ast.Load()),node)
  return node

def model(raws,common):
 need(type(raws)is dict and set(raws)==set(PINS));parsed={}
 for name,pin in PINS.items():
  raw=raws[name];need(type(raw)is bytes)
  if name=='check_native_organization':common.native_pair_parent_DATA(raw,pin)
  else:need(hashlib.sha256(raw).hexdigest()==pin)
  parsed[name]=ast.parse(raw)
 completion_ns={'re':re};common.compile_functions(common.functions(parsed['check_completion'],{'require','_equal','exact','fields','digest','strict_object'}),completion_ns)
 completion=types.SimpleNamespace(**{n:completion_ns[n]for n in('exact','fields','digest','strict_object')});completion.PATCHES=common.literal(parsed['check_completion'],'PATCHES')
 native_ns={};common.compile_functions(common.functions(parsed['check_native_organization'],{'exact_tree'}),native_ns)
 native=types.SimpleNamespace(FIELDS=common.literal(parsed['check_native_organization'],'FIELDS'),BLOCKERS=common.literal(parsed['check_native_organization'],'BLOCKERS'))
 restore=common.functions(parsed['check_native_organization'],{'validate_restore'})[0];restore.name='_strict_restore';restore.args.args.append(ast.arg(arg='operation'));rr=common.RestoreReads();restore=rr.visit(restore);need(rr.count==2)
 ns={'hashlib':hashlib,'completion':completion,'exact_tree':native_ns['exact_tree'],'_captured':common._captured};common.compile_functions([restore],ns)
 shared=types.SimpleNamespace(**{n:common.literal(parsed['check_recovery_partial_composition'],n)for n in('BOOTSTRAP','PREPARATION','CLEANUP')})
 tree=parsed['check_recovery_oauth_code_producer_v1'];source_inputs=common.literal(tree,'SOURCE_INPUTS')
 sql=common.functions(tree,{'SQL_obligations'})[0];sql.name='_sql_current_body';sql.args.defaults=[];sql_seam=SQLReaderSeam();sql=sql_seam.visit(sql);need(sql_seam.count==2)
 for name in('reader','code_delta','authorization_delta','calendar_delta'):sql.args.kwonlyargs.append(ast.arg(arg=name));sql.args.kw_defaults.append(None)
 sql_ns={'SOURCE_INPUTS':source_inputs,'Path':Path,'hashlib':hashlib,'re':re};common.compile_functions([sql],sql_ns)
 nodes=common.functions(tree,{'observer_cleanup','summary','exact','validate'});seams={}
 for i,node in enumerate(nodes):
  if node.name not in('validate','summary'):continue
  original=copy.deepcopy(node);seam=common.BodySeams();node=seam.visit(node);seams[node.name]=seam.counts
  node.args.kwonlyargs.append(ast.arg(arg='sql_obligations'));node.args.kw_defaults.append(None)
  if node.name=='validate':
   node.args.defaults=[]
   for name in('expected_obligations','operation'):node.args.kwonlyargs.append(ast.arg(arg=name));node.args.kw_defaults.append(None)
  need(len(original.body)==len(node.body)and [x.value for x in ast.walk(original)if isinstance(x,ast.Constant)]==[x.value for x in ast.walk(node)if isinstance(x,ast.Constant)])
  nodes[i]=node
 need(seams=={'summary':{'obligations':0,'SQL_obligations':1,'restore':0,'summary':0},'validate':{'obligations':1,'SQL_obligations':1,'restore':1,'summary':1}})
 body_ns={'completion':completion,'native':native,'shared':shared,'re':re,'_strict_restore':ns['_strict_restore'],'SOURCE_INPUTS':source_inputs};common.compile_functions(nodes,body_ns)
 artifact_ns={'Path':Path,'os':os,'stat':stat,'hashlib':hashlib};common.compile_functions(common.functions(parsed['check_recovery_partial_composition'],{'read_checked'}),artifact_ns)
 return {'validate':body_ns['validate'],'SQL_current_body':sql_ns['_sql_current_body'],'read_checked':artifact_ns['read_checked'],'bootstrap':shared.BOOTSTRAP|shared.PREPARATION|shared.CLEANUP,'source_inputs':source_inputs,'body_seams':seams,'SQL_reader_seams':sql_seam.count,'source_or_SQL_qualified':False}
def runtime(before,after,revision):
 fields={'schema','phase','source_revision','version','server_uuid_sha256','observed_service_image'}
 need(type(revision)is str and re.fullmatch('[a-f0-9]{40}',revision)is not None and revision!='0'*40)
 for phase,row in(('before',before),('after',after)):
  need(type(row)is dict and set(row)==fields and row['schema']=='native-oauth-code-mysql-runtime-v1'and row['phase']==phase and row['source_revision']==revision and row['version']=='8.4.13'and row['observed_service_image']==IMAGE and type(row['server_uuid_sha256'])is str and re.fullmatch('[a-f0-9]{64}',row['server_uuid_sha256'])is not None)
 need(before['server_uuid_sha256']==after['server_uuid_sha256'])
 return {'actual_MySQL84_both_observations':True,'same_primary_UUID':True,'source_revision':revision,'observed_service_image':IMAGE}
