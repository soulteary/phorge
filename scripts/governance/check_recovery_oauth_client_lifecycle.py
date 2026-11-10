"""Strict source-bound lifecycle SQL receipt consumer; JSON is not execution proof."""
import argparse,hashlib,json,re,subprocess
from pathlib import Path
import check_completion as completion
import check_native_organization as native
import check_recovery_partial_composition as shared
import check_directory_transports_v1 as directory
ROOT=Path(__file__).resolve().parents[2]
OPERATIONS=('disabled','redirect','trust','secret','authorization-delete','client-cascade')
SOURCE_INPUTS={'.github/workflows/oauth-client-lifecycle-focused.yml': '9036405ba276507a05b59e1be4cf5e2bd200d41bb0cb266daa8e93b48c3b5dce', 'scripts/governance/probe_oauth_authorization_mysql84.php': '6eb1a8e9c5eb21eac699c507d65046d6b54497eecef3223e88c3ce70483d4847', 'scripts/governance/project_oauth_client_lifecycle_failure.py': '907622aad1bbba07a9a3ddb010c568251af3681c9f31c5ba6134c47656d7d60a', 'scripts/governance/qualify_oauth_client_lifecycle_mysql84.py': 'bccd536fbb401e601380893e73582e255657e476922bf91a0cdf51aa16f772f5', 'src/applications/oauthserver/storage/PhabricatorOAuthClientAuthorization.php': '018a27a32321b9989ca04a2fd43e86290cf87d8f2e7a274444c2612677dbde05', 'src/applications/oauthserver/storage/PhabricatorOAuthServerClient.php': '2173ae0c4d5e136b1c4b7c156adf6ec0ba5378645dd7e9a2b2e59dd085987a60', 'src/applications/oauthserver/storage/PhabricatorOAuthServerDAO.php': '6e6f54eddf4dd080c8746347897577ffd2c9dcac0804139ee1644dcd97084496', 'src/applications/system/engine/PhabricatorDestructionEngine.php': '2a969d1d6c046464032ae1c02f85b320c0e5f91a6752492469837f07477c2909', 'src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php': '4e19ba38cfe64b7f2ecf50fcda0cfae564098d69f2c81794159785fa7eafd27a', 'src/infrastructure/governance/recovery/PhorgeRecoveryNativeAPIFacts.php': '4b334655feb2f1985135c354e4cd093688c486b499168110fc92f3a4b9e585a4', 'src/infrastructure/governance/recovery/PhorgeRecoveryNativeCaptureScope.php': '7a98886ec80a14e45ad38619e5fd5632f213f89117d5dfc929b7222030d17a26', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php': 'fea4c891cd959dd191ebeff15e541fae052e4ba581e83dc2b6909d56c7afd141', 'src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php': 'b6b8b6f07a848ef484da11e6c4c3cbdadbff2969b5a2364603850539500bfe85', 'support/governance/completion/fixture.php': '8c69fdbc292b6b42f7764c0258e1edb671190d97f9977cf6aee57eac9e5ae09b', 'support/governance/completion/pipeline-gorge.php': 'ff27390d7a7e5b5c14555758e8b8172fd401bb0dcf51221fe32251b11308fcda', 'support/governance/native-organization/oauth-client-lifecycle-worker.php': 'b0d08374b39fb301a4b60c52798a22cbe1871db5992c554f94c2ac95d8cb8520', 'support/governance/native-organization/oauth-client-lifecycle-workers.php': '27fc9e5bf762e0db07710ae5eb311cf8b0428c0fb8a864649e74987e374f5ad7', 'support/governance/native-organization/test-oauth-client-lifecycle-mysql.php': 'faed2c0671940de8d074cec2411075fa4e8cc52936d8e6446f184d40ae850cad', 'support/governance/recovery/borrowed-session-diagnostic.php': 'fa92eac7fdef7bfec9285af28f8923edd64ca10d2f4637f1abada460f300cc3d', 'support/governance/recovery/borrowed-session-focused.php': 'edf99247ab931cbd8baa84c0226d5721ae50e66a26d2b153b0fd3cdffefaa988', 'support/governance/recovery/borrowed-session-observer.php': '89adc88560855248c128625e5acb2ca12ae30e222d4ee01d2dcd283f82a34383', 'support/governance/recovery/oauth-client-lifecycle-cases.php': 'b632984276a8b2f0403c97a7ad5d64b0b953eda06c797d2db60a3feb48812bec', 'support/governance/recovery/oauth-client-lifecycle-diagnostic.php': 'c8be3d6832941a23266fe4bd87ea7a4befc5986ab3267032c88e8200de4f479c', 'support/governance/recovery/oauth-client-lifecycle-focused.php': '6db718dff710adfdfa6a29744582af252b2604d8897562fa888c57a9f33217ae', 'support/governance/recovery/oauth-client-lifecycle-producer.php': '85807fb24257fb11fa24013967d629c5bfeb4226600e0a4f3d1a147689a7c670', 'support/governance/recovery/oauth-client-lifecycle-query-trace.php': '4a492d7ca999f2b1ed396e7ccc8bae308e1ecb342374dd6b5b1254c713ad99db', 'support/governance/recovery/restore-review-cases.php': '60b9176c8c5e6c7f965f70e948b96615a3cdf9d69d53e48f6dfc5743e4da0828', 'support/governance/recovery/session-lease-cases.php': '126b9dbe10f93b4f41f6207800aca3ab25f0a3fac4b7538f2d8bd86e37f109b3', 'tests/atomic/build_directory_file.py': '3274f1fc434fdcaac7e16df68088d81483be78da464955c43d6c3033e3980c38', 'tests/atomic/paired-gorge.json': '9b5378122c7b10e497f8b1b340f51ef9e5b37bf25fb873500f2d45337fca465b'}
BOOTSTRAP={'full-current-source-quickstart-and-patch-ledger-restored','completion-reader-real-select-only-grants-and-lineage-proved','completion-writer-forbidden-privilege-denied','completion-reader-forbidden-privilege-denied','completion-writer-cannot-modify-ownership-marker','completion-reader-cannot-modify-ownership-marker','completion-real-gorge-file-ready-loopback'}
CLEANUP={'owned-native-write-guard-disposed','owned-writer-environment-and-cache-restored','all-owned-full-native-schemas-and-principals-cleaned'}
def SQL_obligations(root=ROOT):
 if len(SOURCE_INPUTS)<17:raise ValueError('lifecycle source profile unbound')
 for path,pin in SOURCE_INPUTS.items():
  if hashlib.sha256(native_source(Path(root)/path)).hexdigest()!=pin:raise ValueError('lifecycle source changed')
 return {f'oauth-client-lifecycle-{op}-{end}-{kind}'for op in OPERATIONS for end in ('commit','rollback')for kind in ('client-first-and-granted-record','native-writer-exact-record-wait','owner-terminal-writer-native-postcondition','next-store-api-binding-changed')}|{'oauth-client-lifecycle-durable-submit-five-sources','oauth-client-lifecycle-durable-replay-review-blocked','oauth-client-lifecycle-native-trust-save-invalidates-review','oauth-client-lifecycle-execute-requires-complete-sources'}
def native_source(path):
 # Existing bounded exact native source reader; no normalization of these bytes.
 import check_recovery_oauth_authorization_producer_v1 as source_io
 return source_io.read_source_checked(path)
def obligations(root=ROOT):return BOOTSTRAP|CLEANUP|SQL_obligations(root)
def exact(a,b):completion.exact(a,b,'lifecycle receipt refused')
def trace(events):
 if type(events)is not list or not 8<=len(events)<=256:raise ValueError('query order refused')
 pending=None;returned=False;kinds=set()
 for event in events:
  if type(event)is not dict or set(event)!={'phase','kind'}or event['kind']not in {'client','authorization','access','code'}:raise ValueError('query order refused')
  kind=event['kind'];phase=event['phase']
  if phase=='begin':
   if pending is not None or(kind!='client'and not returned):raise ValueError('query order refused')
   pending=kind
  elif phase=='end':
   if pending!=kind:raise ValueError('query order refused')
   pending=None;kinds.add(kind)
   if kind=='client':returned=True
  else:raise ValueError('query order refused')
 if pending is not None or kinds!={'client','authorization','access','code'}:raise ValueError('query order refused')
def identities(rows):
 fields={'operation','owner_end','subject_id_digest','subject_phid_digest','client_id_digest','client_phid_digest','authorization_id_digest','before_client_digest','after_client_digest','remaining_rows','owner_persistent_probe','query_order'}
 if type(rows)is not list or len(rows)!=12:raise ValueError('lifecycle identities refused')
 pairs=set();subjects={};clients=set();authorizations=set()
 for row in rows:
  if type(row)is not dict or set(row)!=fields:raise ValueError('lifecycle identities refused')
  op=row['operation'];end=row['owner_end']
  if op not in OPERATIONS or end not in ('commit','rollback')or(op,end)in pairs:raise ValueError('lifecycle identities refused')
  pairs.add((op,end))
  for key in fields-{'operation','owner_end','after_client_digest','remaining_rows','owner_persistent_probe','query_order'}:
   if type(row[key])is not str or re.fullmatch('[a-f0-9]{64}',row[key])is None:raise ValueError('lifecycle identity digest refused')
  subject=(row['subject_id_digest'],row['subject_phid_digest'])
  if end in subjects and subjects[end]!=subject:raise ValueError('lifecycle subject drift')
  subjects[end]=subject
  if row['client_id_digest']in clients or row['authorization_id_digest']in authorizations:raise ValueError('lifecycle experiment reused')
  clients.add(row['client_id_digest']);authorizations.add(row['authorization_id_digest'])
  exact(row['owner_persistent_probe'],'committed-probe'if end=='commit'else'rolled-back-original')
  counts=[0,0,0]if op=='client-cascade'else([0,1,1]if op=='authorization-delete'else[1,1,1])
  exact(row['remaining_rows'],counts)
  after=row['after_client_digest'];before=row['before_client_digest']
  if op=='client-cascade':exact(after,None)
  else:
   if type(after)is not str or re.fullmatch('[a-f0-9]{64}',after)is None or((after==before)!=(op=='authorization-delete')):raise ValueError('lifecycle persisted values refused')
  trace(row['query_order'])
 if subjects.get('commit')==subjects.get('rollback'):raise ValueError('lifecycle subjects aliased')
def validate(data,root=ROOT,*,source_revision,file_digest):
 if type(source_revision)is not str or re.fullmatch('[a-f0-9]{40}',source_revision)is None:raise ValueError('source revision refused')
 expected=obligations(root);completion.fields(data,native.FIELDS,'lifecycle receipt fields refused')
 for name,value in {'batch':'native-oauth-client-lifecycle-owner','scope':'owned-native-oauth-client-lifecycle-no-permit','passed':True,'php_version':'8.5.12','check_count':len(expected),'failure':None,'cleanup_failures':[],'terminal_failure_cleanup_rollbacks':0,'optional_source_reader_adapter_exercised':True,'isolated_optional_features_enabled':True,'successful_owned_file_service_startups':1,'production_deployment':False,'production_executor':False,'production_optional_features_enabled':False,'native_permission_axes_changed':False}.items():exact(data[name],value)
 checks=data['checks']
 if type(checks)is not list or any(type(v)is not str for v in checks)or len(checks)!=len(expected)or len(set(checks))!=len(expected)or set(checks)!=expected:raise ValueError('lifecycle obligations refused')
 if type(file_digest)is not str or re.fullmatch('[a-f0-9]{64}',file_digest)is None:raise ValueError('File binary refused')
 exact(data['file_service_cleanup'],{'kind':'file','binary_sha256':file_digest,'process_stopped':True,'private_storage_removed':True})
 exact(data['worker_cleanup'],{'private_directories_removed':12,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':12})
 exact(data['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
 facts=data['native'];keys={'profile','executed_experiments','executed_case_obligations','source_identities','native_scope_registry_unchanged','real_Store_owner_and_native_lifecycle','native_query_order_observed','exact_record_lock_wait_observed','both_owned_native_SQL_threads_observed','owned_child_exit0_proved','production_observer_factory_available','continuous_admin_lease_proved','physical_ACK_loss_proved','all_13_sources_complete','permit','restoration_authorized','execution_blocked','observer_cleanup','both_native_identity_epochs_prewarmed','source_revision','default_native_User_writer','forced_connection_used'}
 completion.fields(facts,keys,'lifecycle facts refused');identities(facts['source_identities'])
 for name,value in {'profile':'isolated-native-oauth-client-lifecycle-v1','executed_experiments':12,'native_scope_registry_unchanged':True,'real_Store_owner_and_native_lifecycle':True,'native_query_order_observed':True,'exact_record_lock_wait_observed':True,'both_owned_native_SQL_threads_observed':True,'owned_child_exit0_proved':True,'production_observer_factory_available':False,'continuous_admin_lease_proved':False,'physical_ACK_loss_proved':False,'all_13_sources_complete':False,'permit':False,'restoration_authorized':False,'execution_blocked':True,'both_native_identity_epochs_prewarmed':True,'source_revision':source_revision,'default_native_User_writer':True,'forced_connection_used':False}.items():exact(facts[name],value)
 exact(facts['observer_cleanup'],{'owned_observer_removed':True,'owned_observer_SQL_sessions_absent':True,'native_writer_grants_unchanged':True,'cleanup_proved':True})
 exact(facts['executed_case_obligations'],sorted(SQL_obligations(root)))
 native.validate_restore(data['source_restore'],root)
 exact(data['remaining_blocked'],['all-thirteen-source-families','creator-and-absence-phantoms','unreviewed-external-writers','physical-ACK-loss','production-provider-and-activation'])
 if type(data['captured_native_output_bytes'])is not int or not 0<=data['captured_native_output_bytes']<=65536 or not completion.digest(data['captured_native_output_sha256']):raise ValueError('native output refused')
 return {'passed':True,'scope':'isolated-native-oauth-client-lifecycle-no-permit','source_revision':source_revision,'SQL_obligations':52,'native_experiments':12,'checks':len(expected),'all_13_sources_complete':False,'production_observer_factory_available':False,'permit':False,'restoration_authorized':False,'physical_ACK_loss_proved':False,'continuous_admin_lease_proved':False,'runtime_authority':False}
def main():
 p=argparse.ArgumentParser();p.add_argument('--receipt',type=Path,required=True);p.add_argument('--source-revision',required=True);p.add_argument('--file-binary',type=Path,required=True);p.add_argument('--file-binary-sha256',required=True);a=p.parse_args()
 try:
  directory.source(ROOT,revision=a.source_revision)
  digest=shared.read_checked(a.file_binary,binary=True);exact(digest,a.file_binary_sha256)
  raw=shared.read_checked(a.receipt);data=json.loads(raw,object_pairs_hook=completion.strict_object,parse_constant=lambda _:(_ for _ in()).throw(ValueError('nonfinite')))
  print(json.dumps(validate(data,source_revision=a.source_revision,file_digest=digest),sort_keys=True));return 0
 except Exception:print('{"passed":false,"scope":"native-oauth-client-lifecycle-refused"}');return 1
if __name__=='__main__':raise SystemExit(main())
