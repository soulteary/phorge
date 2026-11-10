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
  raw=current.description.reconstruct_historical(source,ROOT).get(path)
  if raw is None:raise ValueError('new native leaf has no old source')
 elif path=='docs/governance/batch-2-callers.json':raw=_historical_batch_scan_source(path,raw)
 elif path not in original_test_view.METADATA_PATHS:raw=current._normalize_metadata(path,raw)
 if raw is None:raise ValueError('new metadata leaf has no old source')
 return coordinator.source_view(path,raw)

def historical_authorization_source(path,raw):
 current.assert_current_recipe(ROOT);coordinator=checked_modules();module=coordinator.authorization_delta
 if type(path)is not str or path not in module.SOURCE_INPUTS or type(raw)is not bytes or raw!=(ROOT/path).read_bytes():raise ValueError('unknown actual authorization test source')
 import n05_legacy_audit_test_view as original
 if path in original.METADATA_PATHS:raw=original.historical_metadata_source(path,raw)
 else:raw=current._normalize_metadata(path,raw)
 return module.reduce_current(path,raw)
def historical_lifecycle_source(path,raw):
 current.assert_current_recipe(ROOT);coordinator=checked_modules();module=coordinator.authorization_delta.lifecycle_delta
 if type(path)is not str or path not in module.SOURCE_INPUTS or type(raw)is not bytes or raw!=(ROOT/path).read_bytes():raise ValueError('unknown actual lifecycle test source')
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

def _historical_batch_scan_source(path,raw,root=ROOT):
 current.assert_current_recipe(root)
 if path!='docs/governance/batch-2-callers.json' or type(raw)is not bytes:raise ValueError('unselected historical batch scan')
 target=Path(root)/path
 if target.stat().st_mode&0o7777!=0o644 or raw!=current.description.checked_bytes(target):raise ValueError('unknown actual batch scan')
 revision='577ae2eb82d854753b88f2985a154e409272068a'
 if current.TARGET!=revision:raise ValueError('unreviewed native batch scan target')
 mode=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'ls-tree',revision,'--',path]).split(b' ',1)[0]
 actual=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show',revision+':'+path])
 if mode!=b'100644' or raw!=actual:raise ValueError('unknown native batch scan')
 prior=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show',REVISION+':'+path])
 module=checked_modules()
 if hashlib.sha256(module.authorization_delta.reduce_if_selected(path,prior)).hexdigest()!=module.SOURCE_INPUTS[path]:raise ValueError('unknown historical batch scan')
 return prior
