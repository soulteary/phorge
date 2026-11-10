"""Strict isolated borrowed real-store Session compatibility, never a production provider."""
import recovery_oauth_code_coordinator_delta as code_coord
import argparse,hashlib,json,os,re,stat
import recovery_worker_terminal_delta as worker_terminal
from pathlib import Path
import check_completion as completion
import check_native_organization as native
import check_recovery_partial_composition as shared
ROOT=Path(__file__).resolve().parents[2]
SOURCE_INPUTS={'support/governance/native-organization/native-cases.php': 'b87c5eac435b11ccb158e9922dd0a3c0a561cc8c2ad38a0ab68e6ef2891eaedc', 'src/__phutil_library_map__.php': '397bc1b1f86ecd09bae57c2be333ab11c79c3915eee1fff8f9f4eaf3271bc4d3', 'support/runtime/src/__phutil_library_map__.php': '17f893fa28fa0ec3e5858e522376de40f425d88afbb0028e2140f51619cbdd27', 'support/runtime/manifest.json': '5f3168f23959a240d4323f9fa882237a1e41fa164807665ea6268ece8b70ba95', 'src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php': '5d2bf5ef3cd86da509428de2a63af35f1ca11a7de8b3665bd80f061c7c6f42c4', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreProfile.php': '663938f8481b9d59998a82c6c648a4628d7db8a25bbfd48cf966f933e28b21cb', 'src/applications/auth/storage/PhabricatorAuthSession.php': '8e9efa7fa53676ec28e13d8b3e02a99096894ebf05f53fdd6366d9199d212a56', 'src/applications/auth/engine/PhabricatorAuthSessionEngine.php': '81372aff5539c88d79d3a0f91af409b75fed91432eb137f65ae0621d3efb97ef', 'support/governance/completion/fixture.php': '8c69fdbc292b6b42f7764c0258e1edb671190d97f9977cf6aee57eac9e5ae09b', 'support/governance/completion/pipeline-gorge.php': 'ff27390d7a7e5b5c14555758e8b8172fd401bb0dcf51221fe32251b11308fcda', 'support/governance/recovery/session-lease-focused.php': '35987c1398aa7fc134c8cb7864cd4619722f644a95a65164faf7b0e972a5d090', 'support/governance/recovery/session-lease-diagnostic.php': 'c9fe7c4d3160be80eee2a869d37db8e1654cbf99fe7698b0aca27f117d60cbe4', 'support/governance/recovery/session-lease-observer.php': '1c321303ee1618025d25bc4702fc7218573a85921df7dc9907d9d3618d226e72', 'support/governance/recovery/session-lease-cases.php': '126b9dbe10f93b4f41f6207800aca3ab25f0a3fac4b7538f2d8bd86e37f109b3', 'support/governance/recovery/task-owner-source-cases.php': '56ccc81c0b5dcb664484862f93bc8e14df4dcd9a10db27de3f20469a2355d7a1', 'support/governance/recovery/account-cases.php': '18692fa80a4f52dae9009b4103499949644499365e582cb689752989a9d126a7', 'support/governance/native-organization/native-worker.php': '14860aa459cdf4834d108a20f776a33b67740fad3da1fe6129da36b50202baf7', 'support/governance/native-organization/workers.php': '11df3cffa66837d56ffaf720a65846ed33e4aede6213893ffb5db817436f169d', 'support/governance/native-organization/test-native-organization-mysql.php': '12f3da8ac1ff4f0eda73200a28247add24066378c9295cb10f23339d6bf589c7', 'support/governance/recovery/project-source-focused.php': '8528452412088a02c8f87d4bc06473f9f2af49daf7d72f189b674e82eabfabf4', 'src/applications/files/builtin/PhabricatorFilesComposeAvatarBuiltinFile.php': 'ba6828a206f57ad53a70659a59d8375e239e85f8b2d837904c20a1b1a41b86f0', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php': '04e75340a1959594da97fc9047abbd98d9f61e72d1dd16b02ded098e86c94aa5', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreSchemaSpec.php': 'e5a596d8e7c2b2ca148ecdcdb2a4ae7908b90e4262a538eb55afab26c1937fbf', 'src/applications/people/storage/PhabricatorUser.php': '1b402225b9ecd675cbe752f7b2d1de9bd05807583bcbc15c1ee58bce1e6822eb', 'src/infrastructure/governance/consistency/PhorgeGovernanceNativeRuntimeProfile.php': '7f9e7d98ff96577653186f458b288f6a56a9f38968e0e2a20c3bdef005941824', 'support/governance/recovery/borrowed-session-focused.php': 'edf99247ab931cbd8baa84c0226d5721ae50e66a26d2b153b0fd3cdffefaa988', 'support/governance/recovery/borrowed-session-cases.php': 'a8791d8508b399775dd2244cc9c8f0db07a56a4bb916cf52a1cbce972c89bd31', 'support/governance/recovery/borrowed-session-observer.php': '89adc88560855248c128625e5acb2ca12ae30e222d4ee01d2dcd283f82a34383', 'support/governance/recovery/borrowed-session-diagnostic.php': 'fa92eac7fdef7bfec9285af28f8923edd64ca10d2f4637f1abada460f300cc3d', 'support/governance/recovery/borrowed-session-producer.php': 'c2c3259df33273d92de3b23d3631ce031de1046f043b495e9651e8e1a1a8e970'}
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

def SQL_obligations(root=ROOT):
 code_coord.assert_cohort(root,read_source_checked)
 captured={}
 for relative,pin in SOURCE_INPUTS.items():
  raw=worker_terminal.reduce_if_selected(relative,code_coord.source_view(relative,read_source_checked(Path(root)/relative)))
  if hashlib.sha256(raw).hexdigest()!=pin:raise ValueError('reviewed borrowed source changed')
  captured[relative]=raw
 text=captured['support/governance/recovery/borrowed-session-cases.php'].decode('utf-8')
 prefixes=re.findall(r"'(borrowed-session-[A-Za-z0-9-]+-)'",text)
 prefixes={p for p in prefixes if p.endswith(('owner-','resumes-','binding-'))}
 if len(prefixes)!=3:raise ValueError('borrowed source obligations changed')
 return {p+ending for p in prefixes for ending in ('commit','rollback')}
def obligations(root=ROOT):return shared.BOOTSTRAP|shared.PREPARATION|shared.CLEANUP|SQL_obligations(root)
def observer_cleanup():return {'owned_observer_removed':True,'owned_observer_SQL_sessions_absent':True,'native_writer_grants_unchanged':True,'cleanup_proved':True}
def summary(revision):
 return {'profile':'isolated-borrowed-real-store-session-owner-v2','borrowed_only_native_producer':True,'child_preflight_committed_before_owner':True,'child_preflight_no_record_locks_observed':True,'actor_revalidated_before_child_commit':True,'old_covering_positive_query_used':False,'executed_case_obligations':sorted(SQL_obligations()),
  'real_store_owner_and_native_session_INSERT':True,'participant_owns_transaction':False,
  'production_observer_factory_available':False,'business_submit_review_integration_proved':False,
  'durable_review_compatible':False,'Task_File_RR_combined':False,'all_sources_complete':False,
  'restoration_authorized':False,'execution_blocked':True,'source_revision':revision,
  'default_native_User_writer':True,'forced_connection_used':False,
  'both_native_identity_epochs_prewarmed':True,'observer_cleanup':observer_cleanup()}
def exact(a,b):completion.exact(a,b,'Borrowed Session evidence refused')
def validate(data,root=ROOT,*,source_revision,file_digest):
 if type(source_revision)is not str or not re.fullmatch('[a-f0-9]{40}',source_revision):raise ValueError('source revision required')
 expected=obligations(root)
 completion.fields(data,native.FIELDS,'Borrowed Session evidence shape refused')
 for k,v in {'batch':'native-borrowed-session-owner','scope':'owned-native-borrowed-session-owner-no-permit','passed':True,'php_version':'8.5.12','check_count':len(expected),'failure':None,'cleanup_failures':[],
  'optional_source_reader_adapter_exercised':True,'isolated_optional_features_enabled':True,'successful_owned_file_service_startups':1,'terminal_failure_cleanup_rollbacks':0,
  'production_deployment':False,'production_executor':False,'production_optional_features_enabled':False,'native_permission_axes_changed':False}.items():exact(data[k],v)
 checks=data['checks']
 if type(checks)is not list or any(type(x)is not str for x in checks)or len(checks)!=len(expected)or len(set(checks))!=len(expected)or set(checks)!=expected:raise ValueError('Borrowed Session obligations refused')
 service=data['file_service_cleanup'];completion.fields(service,{'kind','binary_sha256','process_stopped','private_storage_removed'},'File lifecycle evidence refused')
 if type(file_digest)is not str or not re.fullmatch('[a-f0-9]{64}',file_digest):raise ValueError('independent binary pin required')
 exact(service,{'kind':'file','binary_sha256':file_digest,'process_stopped':True,'private_storage_removed':True})
 exact(data['native'],summary(source_revision))
 exact(data['worker_cleanup'],{'private_directories_removed':2,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':2})
 exact(data['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
 native.validate_restore(data['source_restore'],root);exact(data['remaining_blocked'],native.BLOCKERS)
 if type(data['captured_native_output_bytes'])is not int or not 0<=data['captured_native_output_bytes']<=65536 or not completion.digest(data['captured_native_output_sha256']):raise ValueError('captured output refused')
 return {'passed':True,'scope':'isolated-borrowed-real-store-owner-no-production-provider','source_revision':source_revision,'checks':len(expected),'SQL_obligations':len(SQL_obligations(root)),
  'production_observer_factory_available':False,'business_submit_review_integration_proved':False,'all_sources_complete':False,'restoration_authorized':False,'execution_blocked':True}
def main():
 p=argparse.ArgumentParser();p.add_argument('--receipt',type=Path,required=True);p.add_argument('--source-revision',required=True);p.add_argument('--file-binary',type=Path,required=True);p.add_argument('--file-binary-sha256',required=True);a=p.parse_args()
 try:
  if not re.fullmatch('[a-f0-9]{64}',a.file_binary_sha256)or shared.read_checked(a.file_binary,binary=True)!=a.file_binary_sha256:raise ValueError('independent binary mismatch')
  def nonfinite(v):raise ValueError('nonfinite')
  data=json.loads(shared.read_checked(a.receipt),object_pairs_hook=completion.strict_object,parse_constant=nonfinite)
  result=validate(data,source_revision=a.source_revision,file_digest=a.file_binary_sha256)
 except (ValueError,OSError,TypeError,KeyError,UnicodeError):raise SystemExit('Borrowed Session receipt refused')from None
 print(json.dumps(result,sort_keys=True))
if __name__=='__main__':main()
