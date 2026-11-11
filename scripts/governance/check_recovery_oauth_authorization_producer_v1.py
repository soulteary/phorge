import recovery_oauth_client_lifecycle_delta as lifecycle_delta
"""Strict isolated borrowed real-store OAuth INSERT compatibility, never a production provider."""
import argparse,hashlib,json,os,re,stat
import recovery_worker_terminal_delta as worker_terminal
import recovery_conduit_source_delta as calendar_delta
from pathlib import Path
import check_completion as completion
import check_native_organization as native
import check_recovery_partial_composition as shared
ROOT=Path(__file__).resolve().parents[2]
SOURCE_INPUTS={'support/governance/native-organization/native-cases.php': 'b87c5eac435b11ccb158e9922dd0a3c0a561cc8c2ad38a0ab68e6ef2891eaedc', 'src/__phutil_library_map__.php': '397bc1b1f86ecd09bae57c2be333ab11c79c3915eee1fff8f9f4eaf3271bc4d3', 'support/runtime/src/__phutil_library_map__.php': '17f893fa28fa0ec3e5858e522376de40f425d88afbb0028e2140f51619cbdd27', 'support/runtime/manifest.json': '5f3168f23959a240d4323f9fa882237a1e41fa164807665ea6268ece8b70ba95', 'src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php': '356bf32dbc89499e40f5fe7e90d8f9d63e63e041398ca1830ccb7ca363bc4bc9', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreProfile.php': '663938f8481b9d59998a82c6c648a4628d7db8a25bbfd48cf966f933e28b21cb', 'src/applications/auth/storage/PhabricatorAuthSession.php': '8e9efa7fa53676ec28e13d8b3e02a99096894ebf05f53fdd6366d9199d212a56', 'src/applications/auth/engine/PhabricatorAuthSessionEngine.php': '81372aff5539c88d79d3a0f91af409b75fed91432eb137f65ae0621d3efb97ef', 'support/governance/completion/fixture.php': '8c69fdbc292b6b42f7764c0258e1edb671190d97f9977cf6aee57eac9e5ae09b', 'support/governance/completion/pipeline-gorge.php': 'ff27390d7a7e5b5c14555758e8b8172fd401bb0dcf51221fe32251b11308fcda', 'support/governance/recovery/session-lease-focused.php': '35987c1398aa7fc134c8cb7864cd4619722f644a95a65164faf7b0e972a5d090', 'support/governance/recovery/session-lease-diagnostic.php': 'c9fe7c4d3160be80eee2a869d37db8e1654cbf99fe7698b0aca27f117d60cbe4', 'support/governance/recovery/session-lease-observer.php': '1c321303ee1618025d25bc4702fc7218573a85921df7dc9907d9d3618d226e72', 'support/governance/recovery/session-lease-cases.php': '126b9dbe10f93b4f41f6207800aca3ab25f0a3fac4b7538f2d8bd86e37f109b3', 'support/governance/recovery/task-owner-source-cases.php': '56ccc81c0b5dcb664484862f93bc8e14df4dcd9a10db27de3f20469a2355d7a1', 'support/governance/recovery/account-cases.php': '18692fa80a4f52dae9009b4103499949644499365e582cb689752989a9d126a7', 'support/governance/native-organization/native-worker.php': '14860aa459cdf4834d108a20f776a33b67740fad3da1fe6129da36b50202baf7', 'support/governance/native-organization/workers.php': '07df892319f7eaac68bde2181df05e067190336334085620875bc05fabeefcbc', 'support/governance/native-organization/test-native-organization-mysql.php': '12f3da8ac1ff4f0eda73200a28247add24066378c9295cb10f23339d6bf589c7', 'support/governance/recovery/project-source-focused.php': '8528452412088a02c8f87d4bc06473f9f2af49daf7d72f189b674e82eabfabf4', 'src/applications/files/builtin/PhabricatorFilesComposeAvatarBuiltinFile.php': 'ba6828a206f57ad53a70659a59d8375e239e85f8b2d837904c20a1b1a41b86f0', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php': 'bfcb50198a53b6a4757dcb5997c4958bb45e667ff3567fef10ac94682f5a69a8', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreSchemaSpec.php': 'e5a596d8e7c2b2ca148ecdcdb2a4ae7908b90e4262a538eb55afab26c1937fbf', 'src/applications/people/storage/PhabricatorUser.php': '1b402225b9ecd675cbe752f7b2d1de9bd05807583bcbc15c1ee58bce1e6822eb', 'src/infrastructure/governance/consistency/PhorgeGovernanceNativeRuntimeProfile.php': '7f9e7d98ff96577653186f458b288f6a56a9f38968e0e2a20c3bdef005941824', 'support/governance/recovery/borrowed-session-focused.php': 'edf99247ab931cbd8baa84c0226d5721ae50e66a26d2b153b0fd3cdffefaa988', 'support/governance/recovery/borrowed-session-cases.php': 'a8791d8508b399775dd2244cc9c8f0db07a56a4bb916cf52a1cbce972c89bd31', 'support/governance/recovery/borrowed-session-observer.php': '89adc88560855248c128625e5acb2ca12ae30e222d4ee01d2dcd283f82a34383', 'support/governance/recovery/borrowed-session-diagnostic.php': 'fa92eac7fdef7bfec9285af28f8923edd64ca10d2f4637f1abada460f300cc3d', 'support/governance/recovery/borrowed-session-producer.php': 'c2c3259df33273d92de3b23d3631ce031de1046f043b495e9651e8e1a1a8e970', 'src/applications/conduit/storage/PhabricatorConduitToken.php': '6979f97a01b5c24d1d205a23fcb0397270c9159a98e302267cbce09fbf5593e1', 'src/infrastructure/governance/recovery/PhorgeRecoveryConduitProducerFence.php': 'cd1b0ad05eafa48e79f50cde12ec982dd59f5374f63f10a7c36e52da30ab5a3c', 'support/governance/recovery/conduit-cases.php': 'e9a70140a63cd3f82b861af8e2f125aeccb4ab558cf66e683a4a80350d0c6584', 'support/governance/recovery/conduit-producer.php': '5bb0aa011bb20157e578e347da2e6e8a5e02a1271f77924e90ea01e9ddda2b2d', 'support/governance/recovery/conduit-focused.php': '11129020ae3e8d9d7d212bcc21b3566ce9a1aafaa5162424199e893bb4a02959', 'src/infrastructure/governance/recovery/PhorgeRecoveryNativeCaptureScope.php': '3ed39e16e9c4243019404bfe3030b55aeb49666247938c24c943df182f8eb9a8', 'support/governance/recovery/conduit-diagnostic.php': '061208e77d17363d799ad37cde8540f6ac9acd6d29b097c74f6f0be3e299491d', 'src/applications/oauthserver/PhabricatorOAuthServer.php': '0ac8f3f6676a0312658972df9e1e6c509fba52bd235bb4dcbb5274d21b5cd5fc', 'src/applications/oauthserver/storage/PhabricatorOAuthServerAccessToken.php': '4abee85463c6f49856612ff0df5d1519f5d63c2ab87469587f1b472f6c6d2199', 'src/applications/oauthserver/storage/PhabricatorOAuthServerClient.php': '93b9640e49d0bf4eb657270395c45910f909001a80cf3eb1d7e4b5fb3abe012b', 'src/applications/oauthserver/storage/PhabricatorOAuthServerDAO.php': 'cde05448f450aa79c099719f6a34ab8ee75aea64682902002e725517dd1ff4c0', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php': '05aa84847028b1b67259bade9375d914f409921d9bed514296546e48e0e2559f', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreRequest.php': '007182650c08344038520978d00256bf6e734bad741d9c5d094f9238418b2e03', 'src/applications/oauthserver/storage/PhabricatorOAuthServerAuthorizationCode.php': '36068f9bd492b0c000f6a1ec1c497f1ceda668cc80ff26619a8652782e647829', 'support/governance/recovery/oauth-authorization-diagnostic.php': '0cfb73c1551c0745ed8f680a0737ea1948a3d7d68c672bdbb41c12d18b6ddf7b', 'support/governance/recovery/oauth-authorization-cases.php': '72224fcb69389313b7979d379d411fc06d32175322f662f350f8481aaacfd992', 'support/governance/recovery/oauth-authorization-producer.php': 'f523b78b9f9682b9d751aab96d7a4fbc9aa2a6595ec10933902416fbc83d90fe', 'support/governance/recovery/oauth-authorization-focused.php': '64bf17a51532e8c5e8421aa0c5e5d94bcec637819645e33123bcfddaa84e2d75', 'support/governance/native-organization/oauth-authorization-worker.php': '49ec8d298d46b0df0aae255e9fbd8a77d78a3188ab59e472c328a0027ea17b97', 'support/governance/native-organization/oauth-authorization-workers.php': 'eec44271b5b7a0e8ff164eea301c0a7da2ca760e30d82a28a10df4ab8bbda737', 'support/governance/native-organization/test-oauth-authorization-owner-mysql.php': '32265fb84af9aa6d9669fcba01bb0434401265f5f424d1d708cccc313291cc46', 'src/applications/oauthserver/storage/PhabricatorOAuthClientAuthorization.php': '68ba2b0145c25e53206f24b565260d7d3e93b2b232f7e9382f4b720bedc4a4c3', 'src/applications/oauthserver/PhabricatorOAuthServerScope.php': 'a0c0f5babfb2538070ee0aad80f6f18b409f1b439a8d730090d854cb9cf6c50a', 'src/infrastructure/governance/recovery/PhorgeRecoveryStopService.php': 'fe0e6ce000553f0e3cc55679b15a5fcdeac612060c8a9e576352c0e8de7f50b5', 'src/infrastructure/governance/recovery/PhorgeRecoveryStopRequest.php': '12779e19d17a24c550cb3863da981867cc0fe968279407624c70c1b10f8854fc', 'support/governance/recovery/restore-review-cases.php': '60b9176c8c5e6c7f965f70e948b96615a3cdf9d69d53e48f6dfc5743e4da0828'}
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

def assert_historical_source(root,current):
 # A removable map/class sentinel never authorizes bypass of current admission.
 # The only legacy source branch is the complete canonical real main baseline.
 import subprocess
 root=Path(root)
 if not root.is_absolute()or root.resolve()!=root:raise ValueError('historical native source refused')
 env={'PATH':os.defpath,'GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_SYSTEM':'/dev/null','GIT_CONFIG_GLOBAL':'/dev/null','GIT_ATTR_NOSYSTEM':'1','GIT_NO_REPLACE_OBJECTS':'1','GIT_CONFIG_COUNT':'0','GIT_NO_LAZY_FETCH':'1','GIT_TERMINAL_PROMPT':'0'}
 def git(*args):return subprocess.check_output(['git','--no-replace-objects','-c','protocol.allow=never','-c','core.filemode=true','-c','core.hooksPath=/dev/null','-C',str(root),*args],env=env,stderr=subprocess.DEVNULL)
 baseline=current.description.MAIN_BASELINE
 if git('rev-parse','--show-toplevel').decode().strip()!=str(root)or git('rev-parse','HEAD').decode().strip()!=baseline['git_revision']or git('rev-parse','HEAD^{tree}').decode().strip()!=baseline['git_tree']:raise ValueError('historical native identity refused')
 if git('status','--porcelain','--untracked-files=all'):raise ValueError('historical native source dirty')
 expected={}
 for item in git('ls-tree','-r','-z','HEAD').split(b'\0'):
  if not item:continue
  info,name=item.split(b'\t',1);mode,kind,oid=info.split();name=name.decode()
  if kind!=b'blob'or mode not in (b'100644',b'100755',b'120000'):raise ValueError('historical native mode refused')
  expected[name]=(mode,oid.decode())
 seen={}
 for directory,dirs,files in os.walk(root,followlinks=False):
  if Path(directory)==root and ' .git'.strip()in dirs:dirs.remove('.git')
  for name in list(dirs)+files:
   path=Path(directory)/name;rel=path.relative_to(root).as_posix();st=os.lstat(path)
   if rel=='.git':continue
   if not stat.S_ISDIR(st.st_mode)and rel not in expected:raise ValueError('historical unknown file refused')
   if stat.S_ISLNK(st.st_mode):
    if name in dirs:dirs.remove(name)
    raw=os.readlink(path).encode();mode=b'120000'
   elif stat.S_ISREG(st.st_mode):
    if st.st_nlink!=1 or st.st_uid!=os.getuid()or st.st_mode&0o022 or not 0<=st.st_size<=32*1024*1024:raise ValueError('historical native bytes refused')
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
     raw=bytearray()
     while len(raw)<st.st_size:
      b=os.read(fd,min(65536,st.st_size-len(raw)))
      if not b:raise ValueError('historical native short read')
      raw.extend(b)
     fields=lambda z:(z.st_dev,z.st_ino,z.st_uid,z.st_gid,z.st_mode,z.st_nlink,z.st_size,z.st_mtime_ns,z.st_ctime_ns)
     if os.read(fd,1)!=b''or fields(st)!=fields(os.fstat(fd))or fields(st)!=fields(os.lstat(path)):raise ValueError('historical native bytes changed')
     raw=bytes(raw)
    finally:os.close(fd)
    mode=b'100755'if st.st_mode&0o111 else b'100644'
   elif stat.S_ISDIR(st.st_mode):continue
   else:raise ValueError('historical native file refused')
   seen[rel]=(mode,hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest())
 if seen!=expected:raise ValueError('historical native closure refused')
 for name,pin in baseline['production_pins'].items():
  path=root/name
  if pin is None:
   if path.exists()or path.is_symlink():raise ValueError('historical native absence refused')
  else:current.description.exact(current.description.checked_bytes(path),pin)

def SQL_obligations(root=ROOT):
 # Current18 files are source-check inputs, never an actor/profile projection.
 # Full current admission precedes the exact C1 current18 -> real main14 view.
 import project_description_admission_delta as current
 root=Path(root)
 map_name='src/__phutil_library_map__.php'
 current_source=False
 if hashlib.sha256(read_source_checked(root/map_name)).hexdigest()==current.description.CURRENT_PINS[map_name]['sha256']:
  current_source=True
 for name,pin in current.description.MAIN_BASELINE['production_pins'].items():
  if pin is None and (root/name).exists():current_source=True
 prior={}
 if current_source:
  current.assert_current_recipe(root)
  snapshot=current.description.current_snapshot(root)
  prior=current.description.reconstruct_main(snapshot)
  prior[current.description.NATIVE_EDITOR_BASELINE['path']]=current.description.reconstruct_native_editor(snapshot)
 else:assert_historical_source(root,current)
 lifecycle_delta.assert_cohort(root,read_source_checked)
 captured={}
 for relative,pin in SOURCE_INPUTS.items():
  raw=read_source_checked(Path(root)/relative)
  if relative in prior:raw=prior[relative]
  raw=lifecycle_delta.reduce_if_selected(relative,raw)
  if relative=='support/governance/native-organization/test-native-organization-mysql.php' and hashlib.sha256(raw).hexdigest()!=pin:
   raw=calendar_delta.reduce_calendar_wire_union(raw)
  if hashlib.sha256(raw).hexdigest()!=pin:raise ValueError('reviewed borrowed source changed')
  captured[relative]=raw
 text=captured['support/governance/recovery/oauth-authorization-cases.php'].decode('utf-8')
 prefixes=re.findall(r"'(borrowed-oauth-authorization-[A-Za-z0-9-]+-)'",text)
 prefixes={p for p in prefixes if p.endswith(('owner-','resumes-','binding-','InnoDB-'))}
 if len(prefixes)!=4:raise ValueError('borrowed source obligations changed')
 durable=set(re.findall(r"'(oauth-authorization-[A-Za-z0-9-]+)'",text))
 if len(durable)!=4:raise ValueError('authorization durable source obligations changed')
 return {p+operation+'-'+ending for p in prefixes for operation in ('insert','update')for ending in ('commit','rollback')}|durable
def obligations(root=ROOT):return shared.BOOTSTRAP|shared.PREPARATION|shared.CLEANUP|SQL_obligations(root)
def observer_cleanup():return {'owned_observer_removed':True,'owned_observer_SQL_sessions_absent':True,'native_writer_grants_unchanged':True,'cleanup_proved':True}
def source_identities(rows):
 if type(rows)is not list or len(rows)!=4:raise ValueError('authorization source identities refused')
 expected=[(op,end)for op in ('INSERT','UPDATE')for end in ('commit','rollback')]
 fields={'operation','owner_end','subject_id_digest','subject_phid_digest','client_phid_digest','authorization_id_digest','old_modified','new_modified','owner_persistent_probe'}
 for row,pair in zip(rows,expected):
  if type(row)is not dict or set(row)!=fields or (row['operation'],row['owner_end'])!=pair:raise ValueError('authorization source identity shape refused')
  if any(type(row[k])is not str or not re.fullmatch('[a-f0-9]{64}',row[k])for k in ('subject_id_digest','subject_phid_digest','client_phid_digest','authorization_id_digest')):raise ValueError('authorization source identity digest refused')
  if type(row['new_modified'])is not int or not 1<=row['new_modified']<=4294967295 or row['owner_persistent_probe']!=('committed-probe'if pair[1]=='commit'else'rolled-back-original'):raise ValueError('authorization persistent value refused')
  if pair[0]=='INSERT':
   if row['old_modified']is not None:raise ValueError('authorization INSERT old value refused')
  elif type(row['old_modified'])is not int or not 1<=row['old_modified']<row['new_modified']:raise ValueError('authorization UPDATE old/new refused')
 return rows

def summary(revision,identities):
 return {'profile':'isolated-borrowed-real-store-oauth-authorization-owner-v1-dual-writer','oauth_authorization_only_native_producer':True,'executed_experiments':4,'source_identities':source_identities(identities),'native_scope_registry_unchanged':True,'both_owned_native_SQL_threads_observed':True,'owned_child_exit0_proved':True,'child_preflight_committed_before_owner':True,'child_preflight_no_record_locks_observed':True,'continuous_admin_lease_proved':False,'old_covering_positive_query_used':False,'executed_case_obligations':sorted(SQL_obligations()),
  'real_store_owner_and_native_oauth_authorization_INSERT_UPDATE':True,'participant_owns_transaction':False,
  'production_observer_factory_available':False,'business_submit_review_integration_proved':True,
  'durable_review_compatible':True,'Task_File_RR_combined':False,'all_sources_complete':False,
  'restoration_authorized':False,'execution_blocked':True,'source_revision':revision,
  'default_native_User_writer':True,'forced_connection_used':False,
  'both_native_identity_epochs_prewarmed':True,'observer_cleanup':observer_cleanup(),'all_13_sources_complete':False,'permit':False}
def exact(a,b):completion.exact(a,b,'Conduit producer evidence refused')
def validate(data,root=ROOT,*,source_revision,file_digest):
 if type(source_revision)is not str or not re.fullmatch('[a-f0-9]{40}',source_revision):raise ValueError('source revision required')
 expected=obligations(root)
 completion.fields(data,native.FIELDS,'Conduit producer evidence shape refused')
 for k,v in {'batch':'native-oauth-authorization-producer-owner','scope':'owned-native-oauth-authorization-producer-owner-no-permit','passed':True,'php_version':'8.5.12','check_count':len(expected),'failure':None,'cleanup_failures':[],
  'optional_source_reader_adapter_exercised':True,'isolated_optional_features_enabled':True,'successful_owned_file_service_startups':1,'terminal_failure_cleanup_rollbacks':0,
  'production_deployment':False,'production_executor':False,'production_optional_features_enabled':False,'native_permission_axes_changed':False}.items():exact(data[k],v)
 checks=data['checks']
 if type(checks)is not list or any(type(x)is not str for x in checks)or len(checks)!=len(expected)or len(set(checks))!=len(expected)or set(checks)!=expected:raise ValueError('Conduit producer obligations refused')
 service=data['file_service_cleanup'];completion.fields(service,{'kind','binary_sha256','process_stopped','private_storage_removed'},'File lifecycle evidence refused')
 if type(file_digest)is not str or not re.fullmatch('[a-f0-9]{64}',file_digest):raise ValueError('independent binary pin required')
 exact(service,{'kind':'file','binary_sha256':file_digest,'process_stopped':True,'private_storage_removed':True})
 exact(data['native'],summary(source_revision,data['native'].get('source_identities')))
 exact(data['worker_cleanup'],{'private_directories_removed':4,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':4})
 exact(data['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
 native.validate_restore(data['source_restore'],root);exact(data['remaining_blocked'],native.BLOCKERS)
 if type(data['captured_native_output_bytes'])is not int or not 0<=data['captured_native_output_bytes']<=65536 or not completion.digest(data['captured_native_output_sha256']):raise ValueError('captured output refused')
 return {'passed':True,'scope':'isolated-oauth-authorization-real-store-owner-no-production-provider','source_revision':source_revision,'checks':len(expected),'receipt_version':'native-oauth-authorization-producer-v1','source_inputs':len(SOURCE_INPUTS),'SQL_obligations':len(SQL_obligations(root)),
  'production_observer_factory_available':False,'business_submit_review_integration_proved':True,'all_sources_complete':False,'restoration_authorized':False,'execution_blocked':True}
def main():
 p=argparse.ArgumentParser();p.add_argument('--receipt',type=Path,required=True);p.add_argument('--source-revision',required=True);p.add_argument('--file-binary',type=Path,required=True);p.add_argument('--file-binary-sha256',required=True);a=p.parse_args()
 try:
  if not re.fullmatch('[a-f0-9]{64}',a.file_binary_sha256)or shared.read_checked(a.file_binary,binary=True)!=a.file_binary_sha256:raise ValueError('independent binary mismatch')
  def nonfinite(v):raise ValueError('nonfinite')
  data=json.loads(shared.read_checked(a.receipt),object_pairs_hook=completion.strict_object,parse_constant=nonfinite)
  result=validate(data,source_revision=a.source_revision,file_digest=a.file_binary_sha256)
 except (ValueError,OSError,TypeError,KeyError,UnicodeError):raise SystemExit('Conduit producer receipt refused')from None
 print(json.dumps(result,sort_keys=True))
if __name__=='__main__':main()
