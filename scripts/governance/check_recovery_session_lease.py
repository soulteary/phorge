"""Strict selected native Session INSERT interval evidence, never a restore permit."""
import recovery_oauth_code_coordinator_delta as code_coord
import argparse,hashlib,json,os,re,stat,sys
import recovery_worker_terminal_delta as worker_terminal
import source_operation_snapshot as source_operation
from pathlib import Path
import check_completion as completion
import check_native_organization as native
import check_recovery_partial_composition as shared
ROOT=Path(__file__).resolve().parents[2]
_CODE_RECIPE_ROOT=Path(__file__).resolve(strict=True).parents[2]
SOURCE_INPUTS={'support/governance/native-organization/native-cases.php': 'b87c5eac435b11ccb158e9922dd0a3c0a561cc8c2ad38a0ab68e6ef2891eaedc', 'src/__phutil_library_map__.php': '397bc1b1f86ecd09bae57c2be333ab11c79c3915eee1fff8f9f4eaf3271bc4d3', 'support/runtime/src/__phutil_library_map__.php': '17f893fa28fa0ec3e5858e522376de40f425d88afbb0028e2140f51619cbdd27', 'support/runtime/manifest.json': '5f3168f23959a240d4323f9fa882237a1e41fa164807665ea6268ece8b70ba95', 'src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php': '5d2bf5ef3cd86da509428de2a63af35f1ca11a7de8b3665bd80f061c7c6f42c4', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreProfile.php': '663938f8481b9d59998a82c6c648a4628d7db8a25bbfd48cf966f933e28b21cb', 'src/applications/auth/storage/PhabricatorAuthSession.php': '8e9efa7fa53676ec28e13d8b3e02a99096894ebf05f53fdd6366d9199d212a56', 'src/applications/auth/engine/PhabricatorAuthSessionEngine.php': '81372aff5539c88d79d3a0f91af409b75fed91432eb137f65ae0621d3efb97ef', 'support/governance/completion/fixture.php': '8c69fdbc292b6b42f7764c0258e1edb671190d97f9977cf6aee57eac9e5ae09b', 'support/governance/completion/pipeline-gorge.php': 'ff27390d7a7e5b5c14555758e8b8172fd401bb0dcf51221fe32251b11308fcda', 'support/governance/recovery/session-lease-focused.php': '35987c1398aa7fc134c8cb7864cd4619722f644a95a65164faf7b0e972a5d090', 'support/governance/recovery/session-lease-diagnostic.php': 'c9fe7c4d3160be80eee2a869d37db8e1654cbf99fe7698b0aca27f117d60cbe4', 'support/governance/recovery/session-lease-observer.php': '1c321303ee1618025d25bc4702fc7218573a85921df7dc9907d9d3618d226e72', 'support/governance/recovery/session-lease-cases.php': '126b9dbe10f93b4f41f6207800aca3ab25f0a3fac4b7538f2d8bd86e37f109b3', 'support/governance/recovery/task-owner-source-cases.php': '56ccc81c0b5dcb664484862f93bc8e14df4dcd9a10db27de3f20469a2355d7a1', 'support/governance/recovery/account-cases.php': '18692fa80a4f52dae9009b4103499949644499365e582cb689752989a9d126a7', 'support/governance/native-organization/native-worker.php': '14860aa459cdf4834d108a20f776a33b67740fad3da1fe6129da36b50202baf7', 'support/governance/native-organization/workers.php': '11df3cffa66837d56ffaf720a65846ed33e4aede6213893ffb5db817436f169d', 'support/governance/native-organization/test-native-organization-mysql.php': '12f3da8ac1ff4f0eda73200a28247add24066378c9295cb10f23339d6bf589c7'}
def read_source_checked(path):
 path=Path(path);parents=[(p,os.lstat(p))for p in path.parents]
 if any(not stat.S_ISDIR(v.st_mode)or stat.S_ISLNK(v.st_mode)for _,v in parents):raise ValueError('source parent alias refused')
 stamp=lambda x:(x.st_dev,x.st_ino,x.st_uid,x.st_gid,x.st_mode,x.st_nlink,x.st_size,x.st_mtime_ns,x.st_ctime_ns)
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  old=os.fstat(fd)
  if not stat.S_ISREG(old.st_mode)or old.st_uid!=os.getuid()or old.st_nlink!=1 or old.st_mode&0o022 or not 0<old.st_size<=1048576:raise ValueError('bounded owned source required')
  body=bytearray();remaining=old.st_size
  while remaining:
   chunk=os.read(fd,min(65536,remaining))
   if not chunk:raise ValueError('source short read')
   body.extend(chunk);remaining-=len(chunk)
  if stamp(old)!=stamp(os.fstat(fd))or stamp(old)!=stamp(os.lstat(path)):raise ValueError('source changed during capture')
  for p,was in parents:
   now=os.lstat(p)
   if (was.st_dev,was.st_ino,was.st_uid,was.st_gid,was.st_mode)!=(now.st_dev,now.st_ino,now.st_uid,now.st_gid,now.st_mode):raise ValueError('source parent changed')
  return bytes(body)
 finally:os.close(fd)

def SQL_obligations(root=ROOT,*,_source_operation=None):
 if _source_operation is None and Path(root)==_CODE_RECIPE_ROOT:
  with source_operation.open_source_operation(root)as operation:
   result=SQL_obligations(root,_source_operation=operation)
  return result
 if _source_operation is None:
  coordinator=code_coord;reader=read_source_checked;terminal=worker_terminal
 else:
  operation=source_operation.require_operation(_source_operation,root)
  coordinator=operation.coordinator;reader=operation.read_producer;terminal=operation.worker_terminal
 coordinator.assert_cohort(root,reader)
 captured={}
 for relative,pin in SOURCE_INPUTS.items():
  raw=terminal.reduce_if_selected(relative,coordinator.source_view(relative,reader(Path(root)/relative)))
  if hashlib.sha256(raw).hexdigest()!=pin:raise ValueError('reviewed Session lease source changed')
  captured[relative]=raw
 text=captured['support/governance/recovery/session-lease-cases.php'].decode('utf-8')
 if "foreach(array('commit','rollback')as$ending)" not in text:raise ValueError('lease terminal cases changed')
 prefixes=re.findall(r"'(session-lease-[a-zA-Z0-9-]+-)'\.\$ending\);",text)
 if len(prefixes)!=3 or len(set(prefixes))!=3:raise ValueError('lease source cases changed')
 return {prefix+ending for prefix in prefixes for ending in ('commit','rollback')}
def obligations(root=ROOT,*,_source_operation=None):return shared.BOOTSTRAP|shared.PREPARATION|shared.CLEANUP|SQL_obligations(root,_source_operation=_source_operation)
def observer_cleanup():return {'owned_observer_removed':True,'owned_observer_SQL_sessions_absent':True,'native_writer_grants_unchanged':True,'cleanup_proved':True}
def summary(revision,root=ROOT,*,_source_operation=None):
 return {'profile':'native-AuthSession-selected-producer-focused-v1','source_revision':revision,'executed_case_obligations':sorted(SQL_obligations(root,_source_operation=_source_operation)),
  'actual_User_writer_RC_and_independent_observer':True,'actual_native_SessionEngine_workers':2,'exact_PRIMARY_wait_and_terminal_events_observed':True,
  'worker_SQL_sessions_absent':True,'observer_public_close_returned':True,'observer_cleanup':observer_cleanup(),
  'all_session_producers_closed':False,'fault_matrix_proved':False,'grant_DDL_lease_proved':False,'Task_File_RR_combined':False,
  'durable_review_compatible':False,'all_sources_complete':False,'restoration_authorized':False,'execution_blocked':True}
def exact(a,b):completion.exact(a,b,'Session lease evidence refused')
def validate(data,root=ROOT,*,source_revision,file_digest):
 if Path(root)==_CODE_RECIPE_ROOT:
  with source_operation.open_source_operation(root)as operation:
   result=_validate_with_source_operation(data,root,source_revision=source_revision,file_digest=file_digest,_source_operation=operation)
  return result
 return _validate_with_source_operation(data,root,source_revision=source_revision,file_digest=file_digest)
def _validate_with_source_operation(data,root=ROOT,*,source_revision,file_digest,_source_operation=None):
 if type(source_revision)is not str or not re.fullmatch('[a-f0-9]{40}',source_revision):raise ValueError('source revision required')
 expected=obligations(root,_source_operation=_source_operation)
 completion.fields(data,native.FIELDS,'Session lease evidence shape refused')
 for k,v in {'batch':'native-session-producer-lease','scope':'owned-native-session-producer-lease-no-permit','passed':True,'php_version':'8.5.12','check_count':len(expected),'failure':None,'cleanup_failures':[],
  'optional_source_reader_adapter_exercised':True,'isolated_optional_features_enabled':True,'successful_owned_file_service_startups':1,'terminal_failure_cleanup_rollbacks':0,
  'production_deployment':False,'production_executor':False,'production_optional_features_enabled':False,'native_permission_axes_changed':False}.items():exact(data[k],v)
 checks=data['checks']
 if type(checks)is not list or any(type(x)is not str for x in checks)or len(checks)!=len(expected)or len(set(checks))!=len(expected)or set(checks)!=expected:raise ValueError('Session lease obligations refused')
 service=data['file_service_cleanup'];completion.fields(service,{'kind','binary_sha256','process_stopped','private_storage_removed'},'File lifecycle evidence refused')
 if type(file_digest)is not str or not re.fullmatch('[a-f0-9]{64}',file_digest):raise ValueError('independent binary pin required')
 exact(service,{'kind':'file','binary_sha256':file_digest,'process_stopped':True,'private_storage_removed':True})
 exact(data['native'],summary(source_revision,root,_source_operation=_source_operation))
 exact(data['worker_cleanup'],{'private_directories_removed':2,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':2})
 exact(data['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
 native.validate_restore(data['source_restore'],root);exact(data['remaining_blocked'],native.BLOCKERS)
 if type(data['captured_native_output_bytes'])is not int or not 0<=data['captured_native_output_bytes']<=65536 or not completion.digest(data['captured_native_output_sha256']):raise ValueError('captured output refused')
 return {'passed':True,'scope':'selected-native-Session-INSERT-no-permit','source_revision':source_revision,'checks':len(expected),'SQL_obligations':len(SQL_obligations(root,_source_operation=_source_operation)),
  'fault_matrix_proved':False,'all_sources_complete':False,'restoration_authorized':False,'execution_blocked':True}
def _prepared_layout_diagnostic(frame):
 """Bounded source inventory metadata only, never raw names or other locals."""
 fallback={'diagnostic':'session_layout_metadata_v1','available':False}
 if frame.f_code is not source_operation._prepared_layout.__code__:return fallback
 try:
  local=frame.f_locals;found=local.get('found');expected=local.get('source_expected')
  def mapping(value):
   if type(value)not in (dict,source_operation.MappingProxyType)or len(value)>16384:raise ValueError('metadata refused')
   for name,mode in value.items():
    if type(name)is not str or not 0<len(name)<=4096 or type(mode)is not str or mode not in ('100644','100755','120000','invalid'):raise ValueError('metadata refused')
   return value
  found=mapping(found);expected=mapping(expected)
  missing=set(expected)-set(found);modes={name for name in expected if name in found and expected[name]!=found[name]}
  extra=set(found)-set(expected)
  policy=source_operation._prepared_policy();catalog=policy['catalog'];archives=policy['archives']
  compiled=set(source_operation._PREPARED_FRESH_XHP);installed_path=source_operation._PREPARED_INSTALLED_XHP
  allowed=set(catalog)|set(archives)|set(source_operation._PREPARED_CACHE_PATHS)|compiled|{installed_path}
  parser=extra&set(catalog);fresh=extra&compiled;installed=installed_path in extra
  observed={installed_path,*(name for name in compiled if name.endswith('/xhpast'))}
  mode_bad={name for name in extra&allowed if found[name]not in (('100644','100755')if name in observed else ('100644',))}
  unknown=extra-allowed
  def hashes(names):return [hashlib.sha256(name.encode('utf-8')).hexdigest()for name in sorted(names)[:64]]
  suffix={kind:0 for kind in ('pyc','json','log','other')}
  for name in unknown:
   kind=next((kind for kind in ('pyc','json','log')if name.endswith('.'+kind)),'other');suffix[kind]+=1
  return {'diagnostic':'session_layout_metadata_v1','available':True,'found_count':len(found),'expected_count':len(expected),'extra_count':len(extra),'missing_count':len(missing),'source_mode_mismatch_count':len(modes),'unknown_extra_count':len(unknown),'missing_name_sha256':hashes(missing),'source_mode_mismatch_name_sha256':hashes(modes),'unknown_extra_name_sha256':hashes(unknown),'unknown_suffix_counts':suffix,'parser_present_count':len(parser),'parser_expected_count':len(catalog),'fresh_compiler_present_count':len(fresh),'fresh_compiler_expected_count':len(compiled),'installed_binary_present':installed,'archives_present_count':len(extra&set(archives)),'artifact_role_mode_mismatch_count':len(mode_bad)}
 except (ValueError,TypeError,KeyError,UnicodeError):return fallback
def _refusal_stage(error):
 """Fixed code-identity diagnostics only; never inspect exception text or data."""
 stages={
  source_operation.SourceOperation.__enter__.__code__:'source_operation_enter',
  source_operation.SourceOperation._build_graph.__code__:'source_operation_graph',
  source_operation._prepared_layout.__code__:'prepared_layout',
  source_operation._validate_prepared_cache.__code__:'prepared_cache',
  source_operation.admission.assert_current_recipe.__code__:'source_admission',
  _validate_with_source_operation.__code__:'receipt',
  native.validate_restore.__code__:'restore',
  exact.__code__:'native_exact',
 }
 stage='unclassified';tb=error.__traceback__
 while tb is not None:
  code=tb.tb_frame.f_code
  if code is source_operation._prepared_layout.__code__:
   print(json.dumps(_prepared_layout_diagnostic(tb.tb_frame),sort_keys=True),file=sys.stderr)
  if code is source_operation.SourceOperation.__exit__.__code__:
   return 'source_operation_exit'
  if code in stages:stage=stages[code]
  tb=tb.tb_next
 return stage
def main():
 p=argparse.ArgumentParser();p.add_argument('--receipt',type=Path,required=True);p.add_argument('--source-revision',required=True);p.add_argument('--file-binary',type=Path,required=True);p.add_argument('--file-binary-sha256',required=True);a=p.parse_args()
 try:
  if not re.fullmatch('[a-f0-9]{64}',a.file_binary_sha256)or shared.read_checked(a.file_binary,binary=True)!=a.file_binary_sha256:raise ValueError('independent binary mismatch')
  def nonfinite(v):raise ValueError('nonfinite')
  data=json.loads(shared.read_checked(a.receipt),object_pairs_hook=completion.strict_object,parse_constant=nonfinite)
  result=validate(data,source_revision=a.source_revision,file_digest=a.file_binary_sha256)
 except (ValueError,OSError,TypeError,KeyError,UnicodeError)as error:
  print(json.dumps({'diagnostic':'session_lease_refusal_v1','stage':_refusal_stage(error)},sort_keys=True),file=sys.stderr)
  raise SystemExit('Session lease receipt refused')from None
 print(json.dumps(result,sort_keys=True))
if __name__=='__main__':main()
