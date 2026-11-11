"""Exact T3 historical source interpretation for tests only; never runtime import."""
import hashlib,subprocess,types,sys,json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_description_admission_delta as current
REVISION='15d4f76d9c1c12256f82e53435be038422e205a5'
PINS={'recovery_oauth_authorization_source_delta.py': 'b62008a60fe8020396debeeeabcb6df4fbc2fc223772783a54f248a9809a9694', 'recovery_partial_current_schema_delta.py': 'e042d7a7483fcb719b6cb404b79092c23eebe639d90c748393885a9c151a6f95', 'recovery_oauth_code_ci_delta.py': 'e34581d1a898b08c4e0c36e1bbcc27e7c9e2577c261f4728cb318e3c2681da83', 'recovery_oauth_code_source_delta.py': '30f8a7c4c9ddad0c161ab07218939a2bb299013c14be519c2ede199e9ac97114', 'recovery_oauth_code_coordinator_delta.py': 'e0df9d8edcd95c20646b3dc3cb38d74f3b8ec8e8cba3b273ddd2877d8f53c480', 'recovery_oauth_client_lifecycle_delta.py': '98fcb29f115150faf8cb4d2ada3913ac4e6b01497ac142e8bb885ab4f9d51829', 'recovery_oauth_client_lifecycle_media_delta.py': '25612ec3fb7f68ff41e5a7b99221fb2d033e67d309e38e4a0507eff811f202fd', 'recovery_oauth_client_lifecycle_media_warning_delta.py': '0c0d50f97a06eb8ad6380fd259059185a15b45fd13eae175e4a1054ea16fc1c8', 'recovery_oauth_client_lifecycle_media_provider_delta.py': '88c691bed50ea97d4309b9a0556dbf5e28be5a5922dc0fba49f4f27732ed320c'}
PINS_SHA256='9856794ef7b502f8f83d7d75eab407c04dd47fa5411c3dc6550aec6dcefdd765'
def checked_modules():
 if hashlib.sha256(json.dumps(PINS,sort_keys=True,separators=(',',':')).encode()).hexdigest()!=PINS_SHA256:raise ValueError('historical module pins changed')
 result={}
 for name in ('recovery_oauth_client_lifecycle_delta.py','recovery_oauth_client_lifecycle_media_delta.py','recovery_oauth_client_lifecycle_media_warning_delta.py','recovery_oauth_client_lifecycle_media_provider_delta.py'):
  actual=current.description.checked_bytes(ROOT/'scripts/governance'/name)
  if hashlib.sha256(actual).hexdigest()!=PINS[name]:raise ValueError('current transitive source changed')
 for name,pin in PINS.items():
  raw=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show',REVISION+':scripts/governance/'+name])
  if hashlib.sha256(raw).hexdigest()!=pin:raise ValueError('unknown exact historical module')
  m=types.ModuleType('test_only_'+name[:-3]);m.__file__=str(ROOT/'scripts/governance'/name);exec(compile(raw,m.__file__,'exec'),m.__dict__);result[name]=m
 provider=result['recovery_oauth_client_lifecycle_media_provider_delta.py']
 warning=result['recovery_oauth_client_lifecycle_media_warning_delta.py'];warning.provider_delta=provider
 media=result['recovery_oauth_client_lifecycle_media_delta.py'];media.warning_delta=warning
 lifecycle=result['recovery_oauth_client_lifecycle_delta.py'];lifecycle.media_delta=media
 authorization=result['recovery_oauth_authorization_source_delta.py'];authorization.lifecycle_delta=lifecycle
 partial=result['recovery_partial_current_schema_delta.py'];partial.authorization_delta=authorization
 code=result['recovery_oauth_code_source_delta.py'];code.authorization_delta=authorization
 ci=result['recovery_oauth_code_ci_delta.py'];ci.authorization_delta=authorization
 coordinator=result['recovery_oauth_code_coordinator_delta.py'];coordinator.ci_delta=ci;coordinator.authorization_delta=authorization;coordinator.partial_delta=partial;coordinator.code_delta=code
 return coordinator
def historical_coordinator_source(path,raw):
 current.assert_current_recipe(ROOT)
 coordinator=checked_modules()
 if type(path)is not str or path not in set(coordinator.SOURCE_INPUTS)|set(coordinator.code_delta.SOURCE_INPUTS):raise ValueError('unselected historical source')
 if type(raw)is not bytes or raw!=(ROOT/path).read_bytes():raise ValueError('unknown current source view')
 import n05_legacy_audit_test_view as original_test_view
 if path in original_test_view.METADATA_PATHS:raw=original_test_view.historical_metadata_source(path,raw)
 source=current.description.current_snapshot(ROOT)
 if path in source:
  if path==current.description.NATIVE_EDITOR_BASELINE['path']:raw=current.description.reconstruct_native_editor(source)
  elif path==current.description.NATIVE_STATE_BASELINE['path']:raw=current.description.reconstruct_native_state(source)
  else:raw=current.description.reconstruct_historical(source,ROOT).get(path)
  if raw is None:raise ValueError('new native leaf has no old source')
 elif path=='docs/governance/batch-2-callers.json':raw=_historical_batch_scan_source(path,raw)
 elif path not in original_test_view.METADATA_PATHS:raw=current._normalize_metadata(path,raw)
 if raw is None:raise ValueError('new metadata leaf has no old source')
 return coordinator.source_view(path,raw)

def historical_authorization_source(path,raw):
 current.assert_current_recipe(ROOT);coordinator=checked_modules();module=coordinator.authorization_delta
 if type(path)is not str or path not in module.SOURCE_INPUTS or type(raw)is not bytes or raw!=(ROOT/path).read_bytes():raise ValueError('unknown actual authorization test source')
 import n05_legacy_audit_test_view as original
 if path in _DOCUMENT_PATHS:raw=_historical_document_source(path,raw)
 elif path in original.METADATA_PATHS:raw=original.historical_metadata_source(path,raw)
 else:raw=current._normalize_metadata(path,raw)
 if path in _DOCUMENT_PATHS and hashlib.sha256(module.lifecycle_delta.reduce_if_selected(path,raw)).hexdigest()!=module.SOURCE_INPUTS[path]:raise ValueError('unknown authorization document stage')
 return module.reduce_current(path,raw)
def historical_lifecycle_source(path,raw):
 current.assert_current_recipe(ROOT);coordinator=checked_modules();module=coordinator.authorization_delta.lifecycle_delta
 if type(path)is not str or path not in module.SOURCE_INPUTS or type(raw)is not bytes or raw!=(ROOT/path).read_bytes():raise ValueError('unknown actual lifecycle test source')
 if path==current.description.NATIVE_EDITOR_BASELINE['path']:
  raw=current.native_c4_reader(ROOT,Path.read_bytes)(ROOT/path)
 elif path in _DOCUMENT_PATHS:
  raw=_historical_document_source(path,raw)
  if hashlib.sha256(raw).hexdigest()!=module.SOURCE_INPUTS[path]:raise ValueError('unknown lifecycle document stage')
 elif path in _LIFECYCLE_REVIEWED_ED66:
  raw=_historical_reviewed_lifecycle_source(path,raw)
 else:
  from test_recovery_oauth_client_lifecycle_coordination import current_descriptor_test_view
  raw=current_descriptor_test_view(path,raw)
  if path not in {'tests/governance/native-directory-transports-source-v1.json','tests/governance/test_recovery_oauth_code_ci.py'}:raw=current._normalize_metadata(path,raw)
 return module.reduce_current(path,raw)

def historical_R3_media_recipe_source(path,raw):
 current.assert_current_recipe(ROOT)
 old=current.legacy.media_source_delta()
 if type(path)is not str or path not in old.SOURCE_INPUTS or type(raw)is not bytes:raise ValueError('unknown prior media source')
 actual=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','432bd0166880b4a6ab4820cfebcd4482cf89bb27:'+path])
 if raw!=actual or raw!=old.CURRENT_BYTES[path]:raise ValueError('unknown signed R3 media predecessor')
 return checked_modules().partial_delta.reduce_if_selected(path,old.reduce_current(path,raw))

def historical_F4_media_recipe_source(path,raw):
 current.assert_current_recipe(ROOT)
 old=current.legacy.media_source_delta()
 if type(path)is not str or path not in old.SOURCE_INPUTS or type(raw)is not bytes:raise ValueError('unknown F4 media source')
 actual=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','0496112f7d6706bb3d854117b7fd94a759978987:'+path])
 if raw!=actual:raise ValueError('unknown signed F4 media predecessor')
 prior=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','432bd0166880b4a6ab4820cfebcd4482cf89bb27:'+path])
 return historical_R3_media_recipe_source(path,prior)

def historical_D95_media_recipe_source(path,raw):
 current.assert_current_recipe(ROOT)
 old=current.legacy.media_source_delta()
 if type(path)is not str or path not in old.SOURCE_INPUTS or type(raw)is not bytes:raise ValueError('unknown D95 media source')
 actual=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','d95b3bd2253afb6fdce615f4041920bdce5df625:'+path])
 if raw!=actual:raise ValueError('unknown signed D95 media predecessor')
 prior=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','0496112f7d6706bb3d854117b7fd94a759978987:'+path])
 return historical_F4_media_recipe_source(path,prior)

_BATCH_PATH='docs/governance/batch-2-callers.json'
_BATCH_LEGACY={'revision':'577ae2eb82d854753b88f2985a154e409272068a','tree':'f4d5435523b65587f54465b9c5712ad0b0d8f8f8','blob':'5046e2d1bdc8bd8baae9ab1b52c8b896fe9174ae','bytes':246053,'sha256':'4e0d826ef985254278ac050c0aabffd9b838dc132b4819eed17992d084e73d61'}
_BATCH_MAINLINE={'revision':'8d7fac3491716a523648750ca496079562733033','tree':'ef05de55501db24fbd9936d6253291080e922e70','blob':'b1b1bc20845a9ee7d7d35be75ff73a799ccf691c','bytes':246053,'sha256':'e20d362953ab6035072e0456a93829fee1a9623bf5f8959b61ac3203da54e46c'}

# Additional exact reviewed document stage; the prior two stages remain immutable.
_BATCH_REVIEWED_ED66={'revision':'ed66a10e3a2a034bcb9ac52e120c946b20ed2f29','tree':'2a3f916d09e44fd7e132e146b5a846fbcd1b5282','blob':'a15c785e1abf90c820ea907271b67619b309ae69','bytes':246053,'sha256':'eeac1147d313aa3949735003074bf2f4d136850aadacb0656d2663736580dc89'}

def _batch_git_bytes(repo,revision,tree):
 import re
 if type(revision)is not str or re.fullmatch('[a-f0-9]{40}',revision)is None or type(tree)is not str or re.fullmatch('[a-f0-9]{40}',tree)is None:raise ValueError('unreviewed native batch scan target')
 def git(*args):return subprocess.check_output(['git','--no-replace-objects','-C',str(repo),*args],stderr=subprocess.DEVNULL)
 commit=git('cat-file','commit',revision)
 if hashlib.sha1(b'commit '+str(len(commit)).encode()+b'\0'+commit).hexdigest()!=revision or not commit.startswith(('tree '+tree+'\n').encode()):raise ValueError('unreviewed native batch scan commit/tree')
 line=git('ls-tree',revision,'--',_BATCH_PATH).split(b'\t')
 if len(line)!=2 or line[1].rstrip(b'\n').decode()!=_BATCH_PATH:raise ValueError('unknown native batch scan path')
 record=line[0].split()
 if len(record)!=3 or record[:2]!=[b'100644',b'blob']or re.fullmatch(b'[a-f0-9]{40}',record[2])is None:raise ValueError('unknown native batch scan mode/blob')
 raw=git('cat-file','blob',record[2].decode())
 if hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()!=record[2].decode():raise ValueError('unknown native batch scan blob bytes')
 return raw,record[2].decode()

def _batch_stage_bytes(repo,stage):
 raw,oid=_batch_git_bytes(repo,stage['revision'],stage['tree'])
 if oid!=stage['blob']or len(raw)!=stage['bytes']or hashlib.sha256(raw).hexdigest()!=stage['sha256']:raise ValueError('unknown fixed batch scan stage')
 return raw

def _historical_batch_577_source(path,raw,repo=ROOT):
 if path!=_BATCH_PATH or type(raw)is not bytes or raw!=_batch_stage_bytes(repo,_BATCH_LEGACY):raise ValueError('unknown whole historical577 batch scan')
 prior=subprocess.check_output(['git','--no-replace-objects','-C',str(repo),'show',REVISION+':'+path])
 module=checked_modules()
 if hashlib.sha256(module.authorization_delta.reduce_if_selected(path,prior)).hexdigest()!=module.SOURCE_INPUTS[path]:raise ValueError('unknown historical batch scan')
 return prior

def _historical_batch_scan_source(path,raw,root=ROOT):
 import ast
 current.assert_current_recipe(root)
 if path!=_BATCH_PATH or type(raw)is not bytes:raise ValueError('unselected historical batch scan')
 target=Path(root)/path
 if target.stat().st_mode&0o7777!=0o644 or raw!=current.description.checked_bytes(target):raise ValueError('unknown actual batch scan')
 # Authenticate the target from actual already-forward-checked recipe source,
 # not a caller argument or mutable module-global target override.
 source=current.description.checked_bytes(Path(root)/current.BRIDGE);fields={}
 for node in ast.parse(source).body:
  if isinstance(node,ast.Assign)and len(node.targets)==1 and isinstance(node.targets[0],ast.Name)and node.targets[0].id in ('TARGET','TARGET_TREE'):
   if node.targets[0].id in fields:raise ValueError('duplicate native batch target')
   fields[node.targets[0].id]=ast.literal_eval(node.value)
 if fields!={'TARGET':current.TARGET,'TARGET_TREE':current.TARGET_TREE}:raise ValueError('unreviewed native batch scan target')
 actual,oid=_batch_git_bytes(ROOT,current.TARGET,current.TARGET_TREE)
 if actual!=raw:raise ValueError('unknown actual native batch scan')
 if current.TARGET==_BATCH_LEGACY['revision']:
  return _historical_batch_577_source(path,raw)
 # Only these exact reviewed whole documents can bridge to the immutable
 # 8d7 stage. Each must ALSO equal the authenticated actual native target.
 mainline=_batch_stage_bytes(ROOT,_BATCH_MAINLINE)
 if raw!=mainline and raw!=_batch_stage_bytes(ROOT,_BATCH_REVIEWED_ED66):raise ValueError('unknown whole current batch scan')
 return _historical_batch_577_source(path,_batch_stage_bytes(ROOT,_BATCH_LEGACY))

_DOCUMENT_PATHS=frozenset(('docs/governance/batch-2-callers.json','docs/governance/native-directory-callers.json'))
def _historical_document_source(path,raw,root=ROOT):
 current.assert_current_recipe(root)
 if type(path)is not str or path not in _DOCUMENT_PATHS or type(raw)is not bytes:raise ValueError('unselected historical document')
 target=Path(root)/path
 if target.stat().st_mode&0o7777!=0o644 or raw!=current.description.checked_bytes(target):raise ValueError('unknown actual historical document')
 if path=='docs/governance/batch-2-callers.json':return _historical_batch_scan_source(path,raw,root)
 import n05_legacy_audit_test_view as original
 return original.historical_metadata_source(path,raw,root)


# Exact current documents/checker admitted by the reviewed ed66 seven-source stage.
# This is a test-only old-source projection, not a scanner or candidate allowance.
_LIFECYCLE_REVIEWED_ED66={
 'docs/governance/batch-5-pipeline.json':{'blob':'1de82093880c9bdba858590ff07633bbdd39362c','bytes':5888,'sha256':'50af0dbef9f0fe89ea9c82554c8eca1473c26e81a5b3486e918cd803ec870e64'},
 'docs/governance/private-notification-producers.json':{'blob':'11c0335fff9714d4f6f88c2d93decc58a69aa5a1','bytes':16769,'sha256':'d7ec1ef3f3fac4eedcf7888c03b8cb13ebbe19fbdbe90b3c8dfcad54e8a476d4'},
 'scripts/governance/check_pipeline_boundaries.py':{'blob':'1058dd9d49f914f4882905da67369684f462f429','bytes':5754,'sha256':'057598ed4559dda66b9fdeefde89c88e006ddd999f9dce75c95daabdd1da65d2'},
}
def _reviewed_lifecycle_git_bytes(repo,revision,tree,path):
 import re
 if path not in _LIFECYCLE_REVIEWED_ED66 or type(revision)is not str or re.fullmatch('[a-f0-9]{40}',revision)is None or type(tree)is not str or re.fullmatch('[a-f0-9]{40}',tree)is None:raise ValueError('unreviewed lifecycle Git target')
 def git(*args):return subprocess.check_output(['git','--no-replace-objects','-C',str(repo),*args],stderr=subprocess.DEVNULL)
 commit=git('cat-file','commit',revision)
 if hashlib.sha1(b'commit '+str(len(commit)).encode()+b'\0'+commit).hexdigest()!=revision or not commit.startswith(('tree '+tree+'\n').encode()):raise ValueError('unknown lifecycle commit/tree')
 line=git('ls-tree',revision,'--',path).split(b'\t')
 if len(line)!=2 or line[1].rstrip(b'\n').decode()!=path:raise ValueError('unknown lifecycle Git path')
 record=line[0].split()
 if len(record)!=3 or record[:2]!=[b'100644',b'blob']or re.fullmatch(b'[a-f0-9]{40}',record[2])is None:raise ValueError('unknown lifecycle Git mode/blob')
 raw=git('cat-file','blob',record[2].decode())
 if hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()!=record[2].decode():raise ValueError('unknown lifecycle Git blob bytes')
 return raw,record[2].decode()

def _historical_reviewed_lifecycle_source(path,raw,root=ROOT):
 import ast,json
 current.assert_current_recipe(root)
 if type(path)is not str or path not in _LIFECYCLE_REVIEWED_ED66 or type(raw)is not bytes:raise ValueError('unselected reviewed lifecycle source')
 target=Path(root)/path
 if target.stat().st_mode&0o7777!=0o644 or raw!=current.description.checked_bytes(target):raise ValueError('unknown current lifecycle source')
 registry=json.loads(current.description.checked_bytes(Path(root)/'support/governance/source-operation/registry-v1.json'))
 pin=registry['successor_entries'][path]['current']if path in registry['successor_entries']else registry['entries'][path]
 if pin!={'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}:raise ValueError('unknown lifecycle source role')
 fields={}
 for node in ast.parse(current.description.checked_bytes(Path(root)/current.BRIDGE)).body:
  if isinstance(node,ast.Assign)and len(node.targets)==1 and isinstance(node.targets[0],ast.Name)and node.targets[0].id in ('TARGET','TARGET_TREE'):
   if node.targets[0].id in fields:raise ValueError('duplicate lifecycle target')
   fields[node.targets[0].id]=ast.literal_eval(node.value)
 if fields!={'TARGET':current.TARGET,'TARGET_TREE':current.TARGET_TREE}:raise ValueError('unreviewed lifecycle target')
 actual,oid=_reviewed_lifecycle_git_bytes(ROOT,current.TARGET,current.TARGET_TREE,path)
 fixed,fixed_oid=_reviewed_lifecycle_git_bytes(ROOT,_BATCH_REVIEWED_ED66['revision'],_BATCH_REVIEWED_ED66['tree'],path)
 stage=_LIFECYCLE_REVIEWED_ED66[path]
 if raw!=actual or raw!=fixed or fixed_oid!=stage['blob']or len(fixed)!=stage['bytes']or hashlib.sha256(fixed).hexdigest()!=stage['sha256']:raise ValueError('unknown whole reviewed lifecycle source')
 tree=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'rev-parse',REVISION+'^{tree}']).decode().strip()
 prior,oid=_reviewed_lifecycle_git_bytes(ROOT,REVISION,tree,path)
 expected=checked_modules().authorization_delta.lifecycle_delta.SOURCE_INPUTS[path]
 if hashlib.sha256(prior).hexdigest()!=expected:raise ValueError('unknown historical lifecycle source phase')
 return prior
