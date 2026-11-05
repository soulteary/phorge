"""Strict bounded File source fixture; never a policy or lease."""
import argparse, json, re, os, stat
from pathlib import Path
import check_completion as completion
import check_native_organization as native
ROOT=Path(__file__).resolve().parents[2]
BOOTSTRAP={'full-current-source-quickstart-and-patch-ledger-restored',
 'completion-reader-real-select-only-grants-and-lineage-proved',
 'completion-writer-forbidden-privilege-denied','completion-writer-cannot-modify-ownership-marker',
 'completion-reader-forbidden-privilege-denied','completion-reader-cannot-modify-ownership-marker'}
SQL={'file-source-real-raw-attachment-delete-completed-after-rollback', 'file-source-real-author-rooted-same-native-User-RR-facts-partial', 'file-source-real-raw-attachment-insert-waits-rollback', 'file-source-real-raw-transform-insert-completed-after-rollback', 'file-source-real-raw-attachment-delete-waits-rollback', 'file-source-real-raw-attachment-insert-waits-commit', 'file-source-real-raw-transform-insert-completed-after-commit', 'file-source-native-File-DAO-relation-delete-rollback-independent-observation', 'file-source-real-raw-transform-insert-waits-rollback', 'file-source-real-raw-attachment-delete-waits-commit', 'file-source-real-raw-attachment-insert-completed-after-rollback', 'file-source-native-reference-is-not-parent-policy-grant', 'file-source-real-raw-transform-delete-waits-rollback', 'file-source-real-raw-author-update-waits-commit', 'file-source-native-Gorge-file-ordinary-author-persisted', 'file-source-native-relation-DAO-original-VIEW-not-transform-generation', 'file-source-real-raw-author-update-completed-after-commit', 'file-source-real-raw-attachment-insert-completed-after-commit', 'file-source-native-FileQuery-author-allow-ordinary-other-deny-existing-row', 'file-source-real-raw-author-update-waits-rollback', 'file-source-real-raw-transform-delete-waits-commit', 'file-source-native-attach-ordinary-parent-VIEW-allows-existing-file', 'file-source-real-raw-transform-delete-completed-after-commit', 'file-source-real-raw-transform-delete-completed-after-rollback', 'file-source-native-reference-does-not-downgrade-attach', 'file-source-native-detach-removes-parent-and-derived-VIEW-with-originals-existing', 'file-source-real-raw-transform-insert-waits-commit', 'file-source-real-raw-author-update-completed-after-rollback', 'file-source-real-raw-attachment-delete-completed-after-commit'}
PREPARATION={'completion-real-gorge-file-ready-loopback'}
CLEANUP={'owned-native-write-guard-disposed','owned-writer-environment-and-cache-restored',
 'all-owned-full-native-schemas-and-principals-cleaned'}
def exact(actual,expected):completion.exact(actual,expected,'File source evidence refused')
def summary(revision):
 result={'profile': 'fixed-author-rooted-File-Task-attachment-DAO-topology-under-owned-RR-v1', 'source_revision': None, 'native_File_and_Task_ordinary_author_producers': True, 'actual_ordinary_FileQuery_allow_and_deny': True, 'native_reference_attach_detach_distinguished': True, 'native_File_relation_DAO_rollback_observed': True, 'raw_author_attachment_transform_waits_and_reaps_observed': True, 'observer_closed_and_absent': True, 'native_image_transform_generator_proved': False, 'native_Editor_cross_application_atomicity_proved': False, 'all_File_visibility_and_secret_capabilities_covered': False, 'minimum_production_grants_proved': False, 'DDL_and_fault_matrix_proved': False, 'complete': False, 'all_sources_complete': False, 'restoration_authorized': False}
 result['source_revision']=revision;return result
def source_obligations(root):
 text=(Path(root)/'support/governance/recovery/file-source-cases.php').read_text()
 fixed=set(re.findall(r"'(file-source-[a-zA-Z0-9-]+)'\);",text))
 if len(fixed)!=9:raise ValueError('File native check set drift')
 dynamic=set()
 operations=('author-update','attachment-insert','attachment-delete','transform-insert','transform-delete')
 if "array('author-update','attachment-insert','attachment-delete','transform-insert','transform-delete')" not in text:raise ValueError('File raw operations drift')
 for operation in operations:
  for terminal in ('commit','rollback'):
   for phase in ('waits','completed-after'):
    if "'file-source-real-raw-'.$operation.'-"+phase+"-'.$terminal" not in text:raise ValueError('File raw obligation drift')
    dynamic.add('file-source-real-raw-'+operation+'-'+phase+'-'+terminal)
 if fixed|dynamic!=SQL or len(SQL)!=29:raise ValueError('File obligations drift')
 return fixed|dynamic

def validate(data,root=ROOT,*,source_revision,file_digest):
 if type(source_revision)is not str or not re.fullmatch('[a-f0-9]{40}',source_revision):raise ValueError('source revision required')
 completion.fields(data,native.FIELDS,'File source evidence shape refused')
 for key,value in {'batch':'native-file-source-facts','scope':'owned-native-File-source-partial',
  'passed':True,'php_version':'8.5.12','check_count':39,'failure':None,'cleanup_failures':[],
  'optional_source_reader_adapter_exercised':True,'isolated_optional_features_enabled':True,
  'successful_owned_file_service_startups':1,'terminal_failure_cleanup_rollbacks':0,
  'production_deployment':False,'production_executor':False,'production_optional_features_enabled':False,
  'native_permission_axes_changed':False}.items():exact(data[key],value)
 checks=data['checks']
 if type(checks)is not list or len(checks)!=39 or any(type(x)is not str for x in checks) or len(set(checks))!=39 or set(checks)!=BOOTSTRAP|PREPARATION|source_obligations(root)|CLEANUP:raise ValueError('File source obligations refused')
 service=data['file_service_cleanup']
 completion.fields(service,{'kind','binary_sha256','process_stopped','private_storage_removed'},'File paired file cleanup shape refused')
 for key,value in {'kind':'file','process_stopped':True,'private_storage_removed':True}.items():exact(service[key],value)
 if type(file_digest)is not str or not re.fullmatch('[a-f0-9]{64}',file_digest):raise ValueError('paired binary input required')
 exact(service['binary_sha256'],file_digest)
 exact(data['native'],summary(source_revision))
 exact(data['worker_cleanup'],{'private_directories_removed':0,'owned_processes_stopped':0,'cleanup_failures':0,
  'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':0})
 exact(data['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'ownedAuxiliarySetupConnectionsClosed':10})
 native.validate_restore(data['source_restore'],root)
 exact(data['remaining_blocked'],native.BLOCKERS)
 if type(data['captured_native_output_bytes'])is not int or not 0<=data['captured_native_output_bytes']<=65536:raise ValueError('output budget refused')
 if not completion.digest(data['captured_native_output_sha256']):raise ValueError('output hash refused')
 return {'passed':True,'scope':'fixed-native-File-source-partial','source_revision':source_revision,
  'checks':39,'SQL_obligations':30,'native_policy_interpreter_proved':False,'complete':False,
  'continuous_lease_or_ABA_proved':False,'all_sources_complete':False,'restoration_authorized':False}
def read_receipt(path,limit=2097152):
 if type(limit)is not int or not 1<=limit<=4194304:raise ValueError('receipt limit refused')
 def stamp(info):return(info.st_dev,info.st_ino,info.st_mode,info.st_nlink,info.st_uid,info.st_gid,info.st_size,info.st_mtime_ns,info.st_ctime_ns)
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  before=os.fstat(fd)
  if not stat.S_ISREG(before.st_mode)or before.st_uid!=os.getuid()or stat.S_IMODE(before.st_mode)!=0o600 or before.st_nlink!=1 or not 0<before.st_size<=limit:raise ValueError('receipt file refused')
  chunks=[];size=0
  while True:
   chunk=os.read(fd,min(65536,limit+1-size))
   if not chunk:break
   size+=len(chunk)
   if size>limit:raise ValueError('receipt size refused')
   chunks.append(chunk)
  if size!=before.st_size or stamp(before)!=stamp(os.fstat(fd))or stamp(before)!=stamp(os.lstat(path)):raise ValueError('receipt identity changed')
  return b''.join(chunks).decode('utf-8')
 finally:os.close(fd)

def main():
 p=argparse.ArgumentParser();p.add_argument('--receipt',type=Path,required=True);p.add_argument('--source-revision',required=True);p.add_argument('--file-binary-sha256',required=True)
 a=p.parse_args()
 def nonfinite(value):raise ValueError('nonfinite File source evidence refused')
 try:
  data=json.loads(read_receipt(a.receipt),object_pairs_hook=completion.strict_object,parse_constant=nonfinite)
  accepted=validate(data,source_revision=a.source_revision,file_digest=a.file_binary_sha256)
 except (OSError,ValueError,TypeError,KeyError,UnicodeError):raise SystemExit('File receipt refused')from None
 print(json.dumps(accepted,sort_keys=True))
if __name__=='__main__':main()
