"""Strict fresh-fixture partial composition evidence. No passing receipt shipped."""
import argparse, json, re, os, stat, hashlib
from pathlib import Path
import check_completion as completion
import check_native_organization as native
ROOT=Path(__file__).resolve().parents[2]
BOOTSTRAP={'full-current-source-quickstart-and-patch-ledger-restored',
 'completion-reader-real-select-only-grants-and-lineage-proved',
 'completion-writer-forbidden-privilege-denied','completion-writer-cannot-modify-ownership-marker',
 'completion-reader-forbidden-privilege-denied','completion-reader-cannot-modify-ownership-marker'}
SQL={'recovery-partial-real-project-row-change-invalidates-durable-preview', 'recovery-partial-real-space-row-change-invalidates-durable-preview', 'recovery-restore-submit-ledger-replay-no-second-audit', 'recovery-restore-owned-setup-proves-real-audit-trigger-installed', 'recovery-restore-direct-locked-account-web-api-readers-preserve-caller-transaction', 'recovery-partial-real-config-source-change-invalidates-durable-preview', 'recovery-restore-live-independent-admin-preview-exposes-blockers-not-credentials-or-permit', 'recovery-partial-real-policy-row-addition-invalidates-durable-review', 'recovery-restore-missing-external-incarnation-refuses-without-domain-write', 'recovery-restore-retained-database-review-refused-after-external-incarnation-change', 'recovery-partial-restored-config-remains-blocked', 'recovery-restore-history-rechecks-actual-logged-out-session', 'recovery-restore-native-submission-persists-blocked-review', 'recovery-restore-requester-cannot-self-review', 'recovery-partial-restored-policy-rows-remain-blocked', 'recovery-restore-independent-review-is-not-a-permit', 'recovery-partial-real-policy-budget-fails-closed-without-review-write', 'recovery-restore-history-fixed-metadata-without-binding-or-reason', 'recovery-restore-real-review-audit-fault-rolls-ticket-operation-and-audit-back', 'recovery-restore-real-review-cannot-bypass-incomplete-families', 'recovery-restore-ordinary-current-session-cannot-submit'}
PREPARATION={'completion-real-gorge-file-ready-loopback'}
CLEANUP={'owned-native-write-guard-disposed','owned-writer-environment-and-cache-restored',
 'all-owned-full-native-schemas-and-principals-cleaned'}
def exact(actual,expected):completion.exact(actual,expected,'partial composition evidence refused')
def summary(revision):
 return {'profile':'native-recovery-partial-composition-file-v3-assessment-readset','source_revision':revision,'assessment_schema':4,
  'actual_current_writer_adapters':['application-config','policy','project-relationships','spaces'],
  'Task_File_owned_RR_combined':False,'complete':False,'all_sources_complete':False,
  'restoration_authorized':False,'production_activation':False}

def validate(data,root=ROOT,*,source_revision,file_digest):
 if type(source_revision)is not str or not re.fullmatch('[a-f0-9]{40}',source_revision):raise ValueError('source revision required')
 completion.fields(data,native.FIELDS,'partial composition evidence shape refused')
 for key,value in {'batch':'native-recovery-partial-composition','scope':'owned-native-partial-composition-no-permit',
  'passed':True,'php_version':'8.5.12','check_count':31,'failure':None,'cleanup_failures':[],
  'optional_source_reader_adapter_exercised':True,'isolated_optional_features_enabled':True,
  'successful_owned_file_service_startups':1,'terminal_failure_cleanup_rollbacks':0,
  'production_deployment':False,'production_executor':False,'production_optional_features_enabled':False,
  'native_permission_axes_changed':False}.items():exact(data[key],value)
 checks=data['checks']
 if type(checks)is not list or len(checks)!=31 or any(type(x)is not str for x in checks) or len(set(checks))!=31 or set(checks)!=BOOTSTRAP|PREPARATION|SQL|CLEANUP:raise ValueError('partial composition obligations refused')
 service=data['file_service_cleanup']
 completion.fields(service,{'kind','binary_sha256','process_stopped','private_storage_removed'},'paired file cleanup shape refused')
 for key,value in {'kind':'file','process_stopped':True,'private_storage_removed':True}.items():exact(service[key],value)
 if type(file_digest)is not str or not re.fullmatch('[a-f0-9]{64}',file_digest):raise ValueError('independent paired binary pin required')
 exact(service['binary_sha256'],file_digest)
 exact(data['native'],summary(source_revision))
 exact(data['worker_cleanup'],{'private_directories_removed':0,'owned_processes_stopped':0,'cleanup_failures':0,
  'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':0})
 exact(data['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
 native.validate_restore(data['source_restore'],root)
 exact(data['remaining_blocked'],native.BLOCKERS)
 if type(data['captured_native_output_bytes'])is not int or not 0<=data['captured_native_output_bytes']<=65536:raise ValueError('output budget refused')
 if not completion.digest(data['captured_native_output_sha256']):raise ValueError('output hash refused')
 return {'passed':True,'scope':'typed-partial-facts-no-permit','source_revision':source_revision,
  'checks':31,'SQL_obligations':21,'native_Env_cache_bound':False,'complete':False,
  'continuous_lease_or_ABA_proved':False,'all_sources_complete':False,'restoration_authorized':False}
def read_checked(path,*,binary=False):
 # Read actual independent binary bytes / private receipt through a bounded FD.
 path=Path(path);parents=[(p,os.lstat(p))for p in path.parents]
 for p,info in parents:
  if not stat.S_ISDIR(info.st_mode)or stat.S_ISLNK(info.st_mode):raise ValueError('artifact parent alias refused')
 def stamp(x):return(x.st_dev,x.st_ino,x.st_uid,x.st_gid,x.st_mode,x.st_nlink,x.st_size,x.st_mtime_ns,x.st_ctime_ns)
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  before=os.fstat(fd);limit=268435456 if binary else 4194304
  mode_ok=bool(before.st_mode&0o100)and not(before.st_mode&0o022)if binary else stat.S_IMODE(before.st_mode)==0o600
  if not stat.S_ISREG(before.st_mode)or before.st_uid!=os.getuid()or before.st_nlink!=1 or not mode_ok or not 0<before.st_size<=limit:raise ValueError('regular bounded owned input required')
  result=hashlib.sha256()if binary else bytearray();remaining=before.st_size
  while remaining:
   chunk=os.read(fd,min(65536,remaining))
   if not chunk:raise ValueError('input short read')
   remaining-=len(chunk)
   if binary:result.update(chunk)
   else:result.extend(chunk)
  if stamp(before)!=stamp(os.fstat(fd))or stamp(before)!=stamp(os.lstat(path)):raise ValueError('input changed during capture')
  for p,old in parents:
   now=os.lstat(p)
   if (old.st_dev,old.st_ino,old.st_uid,old.st_gid,old.st_mode)!=(now.st_dev,now.st_ino,now.st_uid,now.st_gid,now.st_mode):raise ValueError('input parent changed')
  return result.hexdigest()if binary else bytes(result)
 finally:os.close(fd)

def main():
 p=argparse.ArgumentParser();p.add_argument('--receipt',type=Path,required=True);p.add_argument('--source-revision',required=True)
 p.add_argument('--file-binary',type=Path,required=True);p.add_argument('--file-binary-sha256',required=True)
 a=p.parse_args()
 if not re.fullmatch('[a-f0-9]{64}',a.file_binary_sha256)or read_checked(a.file_binary,binary=True)!=a.file_binary_sha256:raise ValueError('independent binary bytes/pin mismatch')
 def nonfinite(value):raise ValueError('nonfinite partial composition evidence refused')
 data=json.loads(read_checked(a.receipt),object_pairs_hook=completion.strict_object,parse_constant=nonfinite)
 print(json.dumps(validate(data,source_revision=a.source_revision,file_digest=a.file_binary_sha256),sort_keys=True))
if __name__=='__main__':main()
