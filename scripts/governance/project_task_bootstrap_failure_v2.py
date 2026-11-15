"""Failure-only safe bootstrap metadata; never a native body/schema/SQL proof.
Raw stdout/stderr remain private. No message/trace/config/key/nonce is emitted.
"""
import argparse,json,os,stat
from pathlib import Path
SELF='scripts/governance/project_task_bootstrap_failure_v2.py'
ROLES={'conduit':'native-conduit-producer-owner','oauth-access':'native-oauth-access-producer-owner',
 'oauth-code':'native-oauth-code-producer-owner','assessment-credential':'native-assessment-credential-readset'}
ERRORS=frozenset(('RuntimeException','LogicException','InvalidArgumentException','TypeError','Error','Exception',
 'ParseError','ArgumentCountError','ValueError','mysqli_sql_exception','PhutilAggregateException',
 'AphrontQueryException','AphrontObjectMissingQueryException'))
PHASES=frozenset(('preflight','full-native-source-restore','owned-real-gorge-file-service',
 'focused-native-conduit-producer-owner','focused-native-oauth-access-producer-owner',
 'focused-native-oauth-code-producer-owner','focused-native-assessment-credential-readset',
 'unexpected-native-runtime-output'))
FILES=(
 'support/governance/task-additive-schema-v2/producer-prefix.php',
 'support/governance/task-additive-schema-v2/run.php','support/governance/task-additive-schema-v2/capture.php',
 'support/governance/completion/fixture.php','support/runtime/bootstrap-cli.php','support/runtime/php-version.php',
 'support/governance/native-organization/test-native-organization-mysql.php',
 'support/governance/native-organization/test-oauth-access-owner-mysql.php',
 'support/governance/native-organization/test-oauth-code-owner-mysql.php',
 'support/governance/native-organization/test-assessment-credential-readset-mysql.php',
 'src/infrastructure/governance/consistency/PhorgeGovernanceNativeRuntimeProfile.php',
 'src/applications/people/governance/auth/PhorgeGovernanceNativeIdentitySource.php',
 'src/infrastructure/env/PhabricatorEnv.php')
MARKERS={'Task_directory_v2_unbound_before_SQL':('profile-unbound','BootstrapRefusal'),
 'Task_directory_v2_profile_refused':('profile-validation','BootstrapRefusal'),
 'Task_directory_v2_source_refused':('source-validation','BootstrapRefusal'),
 'Task_native_role_argument_refused':('role-argument','BootstrapRefusal'),
 'Task_native_outer_capture_refused':('native-outer-capture','BootstrapRefusal'),
 'Task_native_envelope_refused':('native-envelope','BootstrapRefusal')}
KEYS={'schema','passed','diagnostic_available','role','phase','error_class','source_file','source_line',
 'native_exit_code','runtime_authority','cleanup','native_body_created','schema_proof_claimed'}

def need(value):
 if value is not True:raise ValueError('Task_bootstrap_diagnostic_refused')
def pairs(rows):
 result={}
 for key,value in rows:need(key not in result);result[key]=value
 return result
def decode(raw):return json.loads(raw,object_pairs_hook=pairs,parse_constant=lambda _:need(False))
def private(path,limit=1048576):
 path=Path(path);need(path.is_absolute()and path.resolve()==path)
 before=path.lstat();need(stat.S_ISREG(before.st_mode)and before.st_uid==os.geteuid()and
  stat.S_IMODE(before.st_mode)==0o600 and before.st_nlink==1 and 0<=before.st_size<=limit)
 def stamp(s):return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  raw=bytearray()
  while len(raw)<before.st_size:
   chunk=os.read(fd,min(65536,before.st_size-len(raw)));need(bool(chunk));raw.extend(chunk)
  need(os.read(fd,1)==b''and stamp(before)==stamp(os.fstat(fd))==stamp(path.lstat()));return bytes(raw)
 finally:os.close(fd)
def _failure(raw,role):
 if type(raw)is not bytes or len(raw)>1048576:return None
 try:row=decode(raw)
 except(ValueError,TypeError,UnicodeError,RecursionError):return None
 if type(row)is dict and row.get('schema')=='Task-native-failure-v2':row=row.get('native_receipt')
 if type(row)is not dict or row.get('batch')!=ROLES[role]or row.get('passed')is not False or type(row.get('failure'))is not dict:return None
 return row['failure']
def project_data(role,exit_code,stdout,stderr,known_files):
 need(type(role)is str and role in ROLES and type(exit_code)is int and -128<=exit_code<=255 and exit_code!=0)
 need(type(known_files)is dict and set(known_files)<=set(FILES)and all(type(v)is int and 0<v<=1000000 for v in known_files.values()))
 out={'schema':'Task-native-bootstrap-failure-v2','passed':False,'diagnostic_available':True,
  'role':role,'phase':'bootstrap-no-native-body','error_class':'unavailable','source_file':None,
  'source_line':None,'native_exit_code':exit_code,'runtime_authority':False,'cleanup':'unknown',
  'native_body_created':False,'schema_proof_claimed':False}
 failure=_failure(stdout,role)
 # Only complete known JSON protocol lines; never parse PHP exception messages.
 if failure is None and type(stderr)is bytes and len(stderr)<=65536:
  for line in stderr.splitlines()[:128]:
   if len(line)>16384:continue
   try:row=decode(line)
   except(ValueError,TypeError,UnicodeError,RecursionError):continue
   expected='focused-'+ROLES[role]
   if type(row)is dict and row.get('scope')=='native-conduit-producer-failure-v1'and row.get('passed')is False and row.get('stage')in('preflight',expected):
    failure=row;break
 if type(failure)is dict:
  phase=failure.get('stage')
  if type(phase)is str and phase in PHASES:out['phase']=phase
  error=failure.get('error_type')
  if type(error)is str and error in ERRORS:out['error_class']=error
  relative=failure.get('source_file');selected=[]
  if type(relative)is str:
   selected=[name for name in known_files if name==relative or(relative==Path(name).name)]
  line=failure.get('source_line')
  if len(selected)==1 and type(line)is int and 0<line<=known_files[selected[0]]:
   out['source_file']=selected[0];out['source_line']=line
 elif type(stderr)is bytes and len(stderr)<=65536:
  markers=[MARKERS[line.decode('ascii')]for line in stderr.splitlines()[:128]if len(line)<=128 and line.isascii()and line.decode('ascii')in MARKERS]
  if len(markers)==1:out['phase'],out['error_class']=markers[0]
 return out

def validate_safe(value,role,exit_code):
 need(type(value)is dict and set(value)==KEYS and value['schema']=='Task-native-bootstrap-failure-v2')
 need(role in ROLES and type(exit_code)is int and 1<=exit_code<=255)
 need(value['role']==role and type(value['native_exit_code'])is int and -128<=value['native_exit_code']<=255 and
  value['native_exit_code']!=0 and value['native_exit_code']%256==exit_code)
 need(value['passed']is False and value['diagnostic_available']is True and value['runtime_authority']is False and
  value['cleanup']=='unknown'and value['native_body_created']is False and value['schema_proof_claimed']is False)
 need(value['phase']in PHASES|{v[0]for v in MARKERS.values()}|{'bootstrap-no-native-body'}and value['error_class']in ERRORS|{'unavailable','BootstrapRefusal'})
 file,line=value['source_file'],value['source_line']
 need((file is None and line is None)or(type(file)is str and file in FILES and type(line)is int and 0<line<=1000000))
 return value

def capture_in_operation(operation,root,role,exit_code,directory,stdout_path,stderr_path):
 import source_operation_snapshot as source
 root=Path(root);directory=Path(directory);source.require_task_operation(operation,root)
 need(operation.code_root==root and operation.producer_root==root and source.ROOT==root and role in ROLES)
 context=operation._task_contexts[root];need(context.current(SELF)==Path(__file__).read_bytes())
 body=directory/'capture-native-body.private.json'
 if body.exists()or body.is_symlink():return False
 known={name:context.current(name).count(b'\n')+1 for name in FILES if name in context.entries}
 # Missing/oversized/unsafe private logs yield unknown metadata, never a raw fallback.
 try:stdout=private(stdout_path)
 except(ValueError,OSError):stdout=b''
 try:stderr=private(stderr_path,65536)
 except(ValueError,OSError):stderr=b''
 value=project_data(role,exit_code,stdout,stderr,known)
 need(directory.is_absolute()and directory.resolve()==directory and directory!=root and not directory.is_relative_to(root))
 before=directory.lstat();need(stat.S_ISDIR(before.st_mode)and before.st_uid==os.geteuid()and stat.S_IMODE(before.st_mode)==0o700)
 dfd=os.open(directory,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW);fd=None;errors=[]
 def identity(st):return(st.st_dev,st.st_ino,st.st_mode,st.st_uid,st.st_gid)
 try:
  need(identity(before)==identity(os.fstat(dfd))==identity(directory.lstat()))
  fd=os.open('bootstrap-failure.safe.json',os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=dfd)
  os.fchmod(fd,0o600);owned=os.fstat(fd)
  need(stat.S_ISREG(owned.st_mode)and owned.st_uid==os.geteuid()and stat.S_IMODE(owned.st_mode)==0o600 and owned.st_nlink==1)
  raw=(json.dumps(value,sort_keys=True,separators=(',',':'))+'\n').encode();need(len(raw)<=16384);offset=0
  while offset<len(raw):count=os.write(fd,raw[offset:]);need(count>0);offset+=count
  os.fsync(fd);os.fsync(dfd);need(os.pread(fd,len(raw)+1,0)==raw)
  need(identity(before)==identity(os.fstat(dfd))==identity(directory.lstat()))
 finally:
  for handle in(fd,dfd):
   if handle is not None:
    try:os.close(handle)
    except OSError as error:errors.append(error)
  if errors:raise errors[0]
 source.require_task_operation(operation,root);return True

# Original Conduit projection stays first, unchanged, and is only re-published
# when its exact fixed metadata schema is available. Bootstrap never upgrades it.
ORIGINAL_KEYS={'scope','passed','diagnostic_available','stage','attempt_stage','error_type','source_file','source_line','query_code','child_available','child_status','child_identity_started','child_result_available'}
ORIGINAL_FILES=frozenset(('conduit-focused.php','conduit-producer.php','conduit-cases.php','conduit-diagnostic.php','borrowed-session-focused.php','borrowed-session-producer.php','borrowed-session-observer.php','borrowed-session-diagnostic.php','session-lease-cases.php','session-lease-observer.php','account-cases.php','task-owner-source-cases.php','project-source-focused.php','PhorgeRecoveryConduitProducerFence.php','PhorgeRecoveryRestoreService.php','PhorgeRecoverySessionProducerFence.php','PhorgeRecoveryRestoreProfile.php','PhorgeRecoveryNativeCaptureScope.php','PhorgeGovernanceNativeRuntimeProfile.php','PhorgeGovernanceNativeIdentitySource.php','PhabricatorAuthSessionEngine.php','PhabricatorAuthSession.php','PhabricatorUser.php','PhabricatorEnv.php','PhabricatorScopedEnv.php','fixture.php','pipeline-gorge.php','native-worker.php','workers.php','LiskDAO.php','AphrontDatabaseConnection.php','AphrontBaseMySQLDatabaseConnection.php','AphrontMySQLiDatabaseConnection.php'))
ORIGINAL_PHASES=frozenset(('preflight','full-native-source-restore','owned-real-gorge-file-service','focused-native-conduit-producer-owner','unexpected-native-runtime-output','other'))
ORIGINAL_ERRORS=frozenset(('RuntimeException','LogicException','TypeError','Error','Exception','mysqli_sql_exception','PhutilAggregateException','AphrontQueryException','AphrontObjectMissingQueryException','InvalidArgumentException','other'))
ORIGINAL_ATTEMPTS=frozenset(('preflight','prepare-users','prepare-extra-avatar','prepare-roles','flags','environment','default-writer','issuer-session','owner-session','prewarm','physical-cases','final-cleanup','other'))
def original_safe(value,depth=0):
 need(type(value)is dict and depth<=2 and ORIGINAL_KEYS<=set(value)<=ORIGINAL_KEYS|{'primary','child'})
 need(value['scope']=='native-conduit-producer-failure-v1'and value['passed']is False and value['diagnostic_available']is True)
 need(value['stage']in ORIGINAL_PHASES and value['attempt_stage']in ORIGINAL_ATTEMPTS and value['error_type']in ORIGINAL_ERRORS)
 need(value['source_file']is None or value['source_file']in ORIGINAL_FILES)
 need(value['source_line']is None or(type(value['source_line'])is int and 0<value['source_line']<=1000000))
 need(value['query_code']is None or(type(value['query_code'])is int and 0<=value['query_code']<=65535))
 for field in('child_available','child_identity_started','child_result_available'):need(type(value[field])is bool)
 need(value['child_status']in('other','failed','rejected','committed'))
 for field in('primary','child'):
  if field in value:original_safe(value[field],depth+1)
 return value

def choose(original,bootstrap,role,exit_code):
 need(role=='conduit'and type(exit_code)is int and 1<=exit_code<=255)
 if original is not None:
  try:return original_safe(decode(private(original,16384)))
  except(ValueError,TypeError,OSError,UnicodeError,RecursionError):pass
 return validate_safe(decode(private(bootstrap,16384)),role,exit_code)
def main():
 p=argparse.ArgumentParser();p.add_argument('--original',type=Path);p.add_argument('--bootstrap',type=Path,required=True);p.add_argument('--role',choices=sorted(ROLES),required=True);p.add_argument('--native-exit-code',type=int,required=True);a=p.parse_args()
 try:result=choose(a.original,a.bootstrap,a.role,a.native_exit_code);print(json.dumps(result,sort_keys=True));return 0
 except(ValueError,TypeError,OSError,UnicodeError,RecursionError):return 1
if __name__=='__main__':raise SystemExit(main())
