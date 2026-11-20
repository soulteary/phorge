"""Dedicated strict Completion source/schema consumer; no SQL or activation."""
import argparse,copy,hashlib,json,re,types,os,subprocess,stat
from pathlib import Path
import source_operation_snapshot as source
ROOT=Path(__file__).resolve().parents[2]
BASE='support/governance/completion-task-v3/'
COMPLETION_SHA='fa9f6c1e360a95822651f3b4af0277cc243e8edac78ad78f1cf924c713c46b1c'
IMAGE='mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'
class CompletionProcessExit(Exception):
 def __init__(self,returncode,sink_errors=()):
  if type(returncode)is not int or returncode==0 or not -127<=returncode<=255:raise ValueError('Completion_bad_process_status')
  self.returncode=returncode;self.sink_errors=tuple(sink_errors)
  super().__init__('Completion_actual_process_nonzero')
 def shell_status(self):return self.returncode if self.returncode>0 else 128-self.returncode

def need(ok):
 if ok is not True:raise ValueError('Completion_Task_consumer_refused')
def captured(operation,root,relative):
 source.require_task_operation(operation,root);raw=operation._task_contexts[root].current(relative)
 m=types.ModuleType('Completion_CURRENT_'+Path(relative).stem);m.__file__=str(root/relative);exec(compile(raw,m.__file__,'exec'),m.__dict__);return m

def runtime_pair(before,after,checkpoint,profile):
 fields={'schema','phase','source_revision','version','server_uuid_sha256','observed_service_image'}
 for phase,row in(('before',before),('after',after)):
  need(type(row)is dict and set(row)==fields and row['schema']=='native-full-mysql-runtime-v1'and row['phase']==phase and row['source_revision']==checkpoint and row['version']=='8.4.13'and row['observed_service_image']==IMAGE)
  need(type(row['server_uuid_sha256'])is str and re.fullmatch('[a-f0-9]{64}',row['server_uuid_sha256'])is not None)
 need(before['server_uuid_sha256']==after['server_uuid_sha256']==hashlib.sha256(profile['server_uuid'].encode()).hexdigest())
 return {'same_primary_UUID':True,'actual_before_after_version':'8.4.13','observed_service_image':IMAGE}

def project_completion_capture_cleanup(projected,proof):
 # DATA projection only: source/process authority remains with run_and_accept.
 need(type(projected)is dict and type(proof)is dict and proof.get('native_profile_mode')=='completion-v3')
 cleanup=projected.get('cleanup')
 need(type(cleanup)is dict and set(cleanup)=={'ownedSchemasRemoved','ownedRuntimeUsersRemoved','readerRemoved','ownedAuxiliarySetupConnectionsClosed'})
 need(type(cleanup['ownedAuxiliarySetupConnectionsClosed'])is int and cleanup['ownedAuxiliarySetupConnectionsClosed']==2)
 result=copy.deepcopy(projected);delta=copy.deepcopy(proof)
 del result['cleanup']['ownedAuxiliarySetupConnectionsClosed']
 need(type(delta.get('exact_projection'))is dict and 'capture_auxiliary_setup_connections'not in delta['exact_projection'])
 delta['exact_projection']['capture_auxiliary_setup_connections']=[2,0]
 return result,delta

def validate_DATA_in_operation(operation,root,checkpoint,envelope_path,native_body_path,profile_path,profile_sha,run_profile_path,runtime_before,runtime_after,file_binary,file_sha,taskqueue_binary,taskqueue_sha):
 root=Path(root);need(root==ROOT and re.fullmatch('[a-f0-9]{40}',checkpoint)is not None and checkpoint!='0'*40)
 source.require_task_operation(operation,root);need(operation.code_root==root and operation.producer_root==root and source.ROOT==root)
 context=operation._task_contexts[root];need(context.current('scripts/governance/check_completion_task_receipt_v3.py')==Path(__file__).read_bytes())
 freshness=captured(operation,root,'scripts/governance/check_directory_transports_task_source_v2.py');proof=freshness.verify_in_operation(operation,root)
 validator=captured(operation,root,BASE+'validate.py')
 readset={}
 def read_private(path,empty=False):
  raw=validator.process_output(path)if empty else validator.private(path)
  key=(str(path),empty)
  if key in readset:need(readset[key]==raw)
  readset[key]=raw;return raw
 read_private(profile_path)
 raw=read_private(envelope_path);envelope=validator.decode(raw)
 projected,delta=validator.validate_envelope(envelope,profile_path,profile_sha,expected_mode='completion-v3')
 profile=validator.profile(profile_path,profile_sha);need(profile['source_root']==str(root)and profile['source_revision']==checkpoint)
 for path,pin in profile['source_pins'].items():need(hashlib.sha256(context.current(path)).hexdigest()==pin)
 run_raw=read_private(run_profile_path);run_profile=validator.decode(run_raw)
 need(type(run_profile)is dict and set(run_profile)=={'schema','mode','source_root','source_revision','run_id','hmac_key','source_pins','output','producer_template_sha256'})
 need(run_profile['schema']=='Completion-run-profile-v3'and run_profile['mode']=='completion-v3'and run_profile['source_root']==str(root)and run_profile['source_revision']==checkpoint and run_profile['run_id']==profile['run_id']and run_profile['hmac_key']==profile['hmac_key'])
 controller=captured(operation,root,'scripts/governance/run_completion_task_v3.py')
 need(type(run_profile['source_pins'])is dict and set(run_profile['source_pins'])==set(controller.INPUTS))
 inputs={rel:context.current(rel)for rel in controller.INPUTS}
 for rel,data in inputs.items():need(hashlib.sha256(data).hexdigest()==run_profile['source_pins'][rel])
 need(hashlib.sha256(controller.adapt(inputs[controller.PRODUCER],inputs[BASE+'prefix.php'])).hexdigest()==run_profile['producer_template_sha256'])
 directory=Path(run_profile['output']);need(directory.is_absolute()and directory.resolve()==directory and not directory.is_relative_to(root))
 need(Path(profile_path)==directory/'schema-profile.private.json'and Path(run_profile_path)==directory.parent/'run-profile.private.json'and Path(native_body_path)==Path(str(directory)+'-native-body.private.json'))
 native_raw=read_private(native_body_path);native=validator.decode(native_raw);need(native==envelope['native_receipt'])
 need(type(native.get('check_count'))is int and native['check_count']==109)
 database=runtime_pair(validator.decode(read_private(runtime_before)),validator.decode(read_private(runtime_after)),checkpoint,profile)
 binary_adapter=captured(operation,root,'scripts/governance/check_directory_receipt_task_v2.py');checker=binary_adapter.checker_for_operation(operation,root)
 actual_file=checker.file_binary_digest(file_binary,file_sha);actual_taskqueue=checker.file_binary_digest(taskqueue_binary,taskqueue_sha)
 need(native['file_service_cleanup']['binary_sha256']==actual_file and native['gorge_publication']['service']['binary_sha256']==actual_taskqueue)
 need(hashlib.sha256(context.current('scripts/governance/check_completion.py')).hexdigest()==COMPLETION_SHA)
 original=captured(operation,root,'scripts/governance/check_completion.py');projected,delta=project_completion_capture_cleanup(projected,delta);accepted=original.validate(projected,root)
 need(accepted['schema_valid']is True and accepted['global_completion']is False and accepted['can_execute']is False)
 need(validator.profile(profile_path,profile_sha)==profile and read_private(run_profile_path)==run_raw and read_private(envelope_path)==raw and read_private(native_body_path)==native_raw)
 for (path,empty),observed in readset.items():need((validator.process_output(path)if empty else validator.private(path))==observed)
 result={'schema':'Completion-Task-strict-consumer-v3','completion_DATA_validated':True,'completion_v3_accepted':False,'producer_window_proven':False,'unchanged_completion_consumer':accepted,'Task_additive_schema':delta,'consumer_source_v2':proof,'consumer_source_exit_verified':False,'database_runtime':database,'raw_native_sha256':hashlib.sha256(native_raw).hexdigest(),'raw_envelope_sha256':hashlib.sha256(raw).hexdigest(),'raw_current_receipt_modified':False,'SQL_executed_by_checker':False,'deployment_proved':False,'activation_authorized':False,'full_CI_qualified':False}
 return result

def probe_in_operation(operation,root,checkpoint,phase,out,err,php,environment,controller):
 source.require_task_operation(operation,root)
 relative='scripts/governance/probe_full_native_task_mysql84.php';need(operation._task_contexts[root].current(relative)==(root/relative).read_bytes())
 streams=[];primary=None;code=None;sink_errors=[]
 try:
  for path in(out,err):
   fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600);streams.append(os.fdopen(fd,'wb'))
  process=subprocess.run([php,'-d','opcache.enable_cli=0','-d','opcache.jit=disable','-d','opcache.jit_buffer_size=0',str(root/relative),phase,checkpoint],cwd=root,env=environment,stdin=subprocess.DEVNULL,stdout=streams[0],stderr=streams[1],timeout=15);code=process.returncode
 except BaseException as error:primary=error
 finally:
  for f in streams:
   try:f.flush();os.fsync(f.fileno())
   except Exception as error:sink_errors.append(error)
   try:f.close()
   except Exception as error:sink_errors.append(error)
 controller.finish_process(primary,sink_errors,code)
 if code!=0:
  error=CompletionProcessExit(code,sink_errors)
  if sink_errors:raise error from ExceptionGroup('Completion_probe_sink_failures',sink_errors)
  raise error

def run_and_accept(root,checkpoint,directory,file_binary,file_sha,taskqueue_binary,taskqueue_sha,observed_mysql_image,php='php'):
 root=Path(root);directory=Path(directory)
 need(root==ROOT and observed_mysql_image==IMAGE and directory.is_absolute()and directory.parent.resolve(strict=True)==directory.parent and not directory.exists()and not directory.is_symlink()and not directory.is_relative_to(root))
 # No receipt/result/proof supplied by caller can authorize this route.
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint)as operation:
  source.require_task_operation(operation,root);need(operation._task_expected_checkpoint==checkpoint and operation.code_root==root and operation.producer_root==root and source.ROOT==root)
  need(operation._task_contexts[root].current('scripts/governance/check_completion_task_receipt_v3.py')==Path(__file__).read_bytes())
  controller=captured(operation,root,'scripts/governance/run_completion_task_v3.py')
  validator=captured(operation,root,BASE+'validate.py')
  binary_adapter=captured(operation,root,'scripts/governance/check_directory_receipt_task_v2.py');checker=binary_adapter.checker_for_operation(operation,root)
  actual_file=checker.file_binary_digest(file_binary,file_sha);actual_queue=checker.file_binary_digest(taskqueue_binary,taskqueue_sha)
  os.mkdir(directory,0o700)
  environment=dict(os.environ);environment.update({'FULL_NATIVE_MYSQL_OBSERVED_IMAGE':observed_mysql_image,'PHORGE_GOVERNANCE_GORGE_FILE_BINARY':str(file_binary),'PHORGE_GOVERNANCE_GORGE_FILE_SHA256':actual_file,'PHORGE_GOVERNANCE_GORGE_TASKQUEUE_BINARY':str(taskqueue_binary),'PHORGE_GOVERNANCE_GORGE_TASKQUEUE_SHA256':actual_queue})
  before=directory/'mysql-before.private.json';after=directory/'mysql-after.private.json'
  probe_in_operation(operation,root,checkpoint,'before',before,directory/'mysql-before.stderr.private',php,environment,controller)
  # This direct call executes the actual captured controller. No deserialized
  # self-signed window receipt or caller-produced CompletedProcess is accepted.
  produced=controller.run_in_operation(operation,root,checkpoint,directory/'producer',directory/'native-envelope.private.json',directory/'native.stderr.private',php,environment)
  need(type(produced)is dict and set(produced)=={'process','directory','run_profile','stdout','stderr'})
  need(type(produced['process'])is subprocess.CompletedProcess and type(produced['process'].returncode)is int)
  if produced['process'].returncode!=0:raise CompletionProcessExit(produced['process'].returncode)
  probe_in_operation(operation,root,checkpoint,'after',after,directory/'mysql-after.stderr.private',php,environment,controller)
  profile_path=directory/'producer/capture/schema-profile.private.json';profile_raw=validator.private(profile_path)
  result=validate_DATA_in_operation(operation,root,checkpoint,produced['stdout'],directory/'producer/capture-native-body.private.json',profile_path,hashlib.sha256(profile_raw).hexdigest(),produced['run_profile'],before,after,file_binary,file_sha,taskqueue_binary,taskqueue_sha)
  need(result['completion_DATA_validated']is True and result['completion_v3_accepted']is False and result['producer_window_proven']is False)
 # Both the actual native process and strict validation were inside THIS owner.
 # Only its real successful exit permits these final qualification flags.
 result['completion_v3_accepted']=True;result['producer_window_proven']=True;result['consumer_source_exit_verified']=True
 result['consumer_source_v2']['source_operation_exit_verified']=True
 result['source_window_kind']='one-genuine-owner-direct-process-and-validation'
 result['driver_executed_actual_producer']=True;result['SQL_executed_by_driver']=True
 return result

def failure_location(error):
 # Never export exception messages, arguments, locals, environment or SQL.
 kind=type(error).__name__
 if kind not in {'ValueError','TypeError','RuntimeError','CompletionProcessExit','FileNotFoundError','PermissionError','TimeoutExpired'}:kind='Exception'
 location={'error_type':kind,'source_file':None,'source_line':None}
 trace=error.__traceback__
 while trace is not None:
  path=Path(trace.tb_frame.f_code.co_filename)
  if path.is_absolute() and path.is_relative_to(ROOT) and path.is_file() and path.suffix=='.py':
   location['source_file']=str(path.relative_to(ROOT));location['source_line']=trace.tb_lineno
  trace=trace.tb_next
 return location

def native_failure_location(directory):
 # Diagnostic only. Read one bounded owned stderr file; never export its text.
 try:
  directory=Path(directory)
  if not directory.is_absolute()or directory.resolve(strict=True)!=directory:return None
  parent=directory.lstat()
  if not stat.S_ISDIR(parent.st_mode)or parent.st_uid!=os.getuid()or stat.S_IMODE(parent.st_mode)!=0o700:return None
  path=directory/'native.stderr.private';fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
  try:
   before=os.fstat(fd)
   if not stat.S_ISREG(before.st_mode)or before.st_uid!=os.getuid()or before.st_nlink!=1 or stat.S_IMODE(before.st_mode)!=0o600 or not 0<before.st_size<=262144:return None
   raw=os.read(fd,262145)
   stamp=lambda v:(v.st_dev,v.st_ino,v.st_mode,v.st_uid,v.st_nlink,v.st_size,v.st_mtime_ns,v.st_ctime_ns)
   if len(raw)!=before.st_size or stamp(before)!=stamp(os.fstat(fd))or stamp(before)!=stamp(path.lstat()):return None
   after=directory.lstat()
   if (parent.st_dev,parent.st_ino,parent.st_mode,parent.st_uid)!=(after.st_dev,after.st_ino,after.st_mode,after.st_uid):return None
  finally:os.close(fd)
  text=raw.decode('utf-8','replace')
  if 'Fatal error:'not in text:return None
  match=re.search(re.escape(str(ROOT)) + r'/((?:src|support)/[A-Za-z0-9_./-]+\.php)(?::| on line )(\d+)',text)
  return {'kind':'php-fatal','source_file':match[1]if match else None,'source_line':int(match[2])if match else None}
 except (OSError,ValueError):return None

if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--root',default=str(ROOT))
 for n in('expected-checkpoint','directory','file-binary','file-sha','taskqueue-binary','taskqueue-sha','observed-mysql-image'):p.add_argument('--'+n,required=True)
 p.add_argument('--php',default='php');a=p.parse_args()
 try:print(json.dumps(run_and_accept(a.root,a.expected_checkpoint,a.directory,a.file_binary,a.file_sha,a.taskqueue_binary,a.taskqueue_sha,a.observed_mysql_image,a.php),sort_keys=True))
 except Exception as error:
  status=error.shell_status()if type(error)is CompletionProcessExit else 1
  try:print(json.dumps({'completion_v3_accepted':False,'producer_window_proven':False,'consumer_source_exit_verified':False,'actual_process_returncode':error.returncode if type(error)is CompletionProcessExit else None,'secondary_sink_error_count':len(error.sink_errors)if type(error)is CompletionProcessExit else None,'SQL_executed_by_checker':False,'failure_location':failure_location(error),'native_failure_location':native_failure_location(a.directory)},sort_keys=True))
  finally:raise SystemExit(status)
