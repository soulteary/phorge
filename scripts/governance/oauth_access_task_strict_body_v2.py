"""OAuthAccess CURRENT literal body adaptation; no source admission or SQL."""
import ast,copy,hashlib,re,types,os,stat
from pathlib import Path
PINS={'check_completion': 'b412555b95a1f450540474807c044e0c20dd3305b3e17e5635c9812122e08296', 'check_native_organization': 'c2c614ab497e6590c13455bd1b495f9f9230c62acd066884b738d460023be300', 'check_recovery_partial_composition': '1a06ef08a91bc152ffee0d8810210a1e0536223bda683a4a4dbb2028fdf1660f', 'check_recovery_oauth_access_producer_v3': 'c24ea6004b27f1722dd30c45cae0f34d1b243012f4db3a2f33b648e6ac173082'}
IMAGE='mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'
def need(v):
 if v is not True:raise ValueError('Task_OAuthAccess_body_refused')
class OAuthAccessRestoreSeam(ast.NodeTransformer):
 def __init__(self):self.count=0
 def visit_Call(self,node):
  node=self.generic_visit(node)
  if isinstance(node.func,ast.Attribute)and isinstance(node.func.value,ast.Name)and node.func.value.id=='native'and node.func.attr=='validate_restore':
   self.count+=1;node.func=ast.Name(id='_strict_restore',ctx=ast.Load());node.args.append(ast.Name(id='_source_operation',ctx=ast.Load()))
  return node
def model(raws,common,checker):
 need(type(raws)is dict and set(raws)==set(PINS));parsed={}
 for name,pin in PINS.items():
  raw=raws[name];need(type(raw)is bytes)
  if name=='check_native_organization':common.native_pair_parent_DATA(raw,pin)
  else:need(hashlib.sha256(raw).hexdigest()==pin)
  parsed[name]=ast.parse(raw)
 completion_ns={'re':re};common.compile_functions(common.functions(parsed['check_completion'],{'require','_equal','exact','fields','digest','strict_object'}),completion_ns)
 completion=types.SimpleNamespace(**{n:completion_ns[n]for n in('exact','fields','digest','strict_object')});completion.PATCHES=common.literal(parsed['check_completion'],'PATCHES')
 native_ns={};common.compile_functions(common.functions(parsed['check_native_organization'],{'exact_tree'}),native_ns)
 restore=common.functions(parsed['check_native_organization'],{'validate_restore'})[0];restore.name='_strict_restore';restore.args.args.append(ast.arg(arg='operation'));change=common.RestoreReads();restore=change.visit(restore);need(change.count==2)
 ns={'hashlib':hashlib,'completion':completion,'exact_tree':native_ns['exact_tree'],'_captured':common._captured};common.compile_functions([restore],ns)
 original=common.functions(parsed['check_recovery_oauth_access_producer_v3'],{'_validate_with_source_operation'})[0];modified=copy.deepcopy(original);seam=OAuthAccessRestoreSeam();modified=seam.visit(modified);need(seam.count==1)
 need(len(original.body)==len(modified.body)and [n.value for n in ast.walk(original)if isinstance(n,ast.Constant)]==[n.value for n in ast.walk(modified)if isinstance(n,ast.Constant)])
 # Original CURRENT functions/obligations/summary retain their real typed operation.
 body_ns=dict(checker.__dict__);body_ns['_strict_restore']=ns['_strict_restore'];common.compile_functions([modified],body_ns)
 artifact_ns={'Path':Path,'os':os,'stat':stat,'hashlib':hashlib};common.compile_functions(common.functions(parsed['check_recovery_partial_composition'],{'read_checked'}),artifact_ns)
 # Code identities come only from this pinned compilation; no frame names/paths/locals.
 diagnostic_codes={}
 for function,lines,start in ((body_ns['_validate_with_source_operation'],(70,71,72,75,76,77,78,79,80,81,82,83,84,85,86,87),1),(ns['_strict_restore'],(1202,1203,1204,1208,1209,1210,1211,1212,1213,1215,1216,1217,1218,1219,1220,1221,1222),17)):
  diagnostic_codes[id(function.__code__)]=(function.__code__,{line:'oauth_access_body_site_%02d'%(start+i)for i,line in enumerate(lines)})
 return {'diagnostic_codes':diagnostic_codes,'validate':body_ns['_validate_with_source_operation'],'read_checked':artifact_ns['read_checked'],'strict_restore_seam_count':1,'original_statement_and_literals_retained':True,'source_or_SQL_qualified':False}
def runtime(before,after,revision):
 fields={'schema','phase','source_revision','version','server_uuid_sha256','observed_service_image'}
 need(type(revision)is str and re.fullmatch('[a-f0-9]{40}',revision)is not None and revision!='0'*40)
 for phase,row in(('before',before),('after',after)):
  need(type(row)is dict and set(row)==fields and row['schema']=='native-oauth-access-mysql-runtime-v1'and row['phase']==phase and row['source_revision']==revision and row['version']=='8.4.13'and row['observed_service_image']==IMAGE and type(row['server_uuid_sha256'])is str and re.fullmatch('[a-f0-9]{64}',row['server_uuid_sha256'])is not None)
 need(before['server_uuid_sha256']==after['server_uuid_sha256'])
 return {'actual_MySQL84_both_observations':True,'same_primary_UUID':True,'source_revision':revision,'observed_service_image':IMAGE}
