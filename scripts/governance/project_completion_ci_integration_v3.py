"""Closed artifact projection only; never a substitute for the actual driver."""
import argparse,json,re,os,stat
from pathlib import Path
def need(value):
 if value is not True:raise ValueError('Completion_CI_safe_projection_refused')
def project(data,checkpoint):
 need(type(checkpoint)is str and re.fullmatch('[a-f0-9]{40}',checkpoint)is not None and checkpoint!='0'*40)
 need(type(data)is dict and data.get('schema')=='Completion-Task-strict-consumer-v3')
 for key in ('completion_DATA_validated','completion_v3_accepted','producer_window_proven','consumer_source_exit_verified','driver_executed_actual_producer','SQL_executed_by_driver'):need(data.get(key)is True)
 for key in ('raw_current_receipt_modified','SQL_executed_by_checker','deployment_proved','activation_authorized','full_CI_qualified'):need(data.get(key)is False)
 need(data.get('source_window_kind')=='one-genuine-owner-direct-process-and-validation')
 delta=data.get('Task_additive_schema');need(type(delta)is dict and delta.get('source_revision')==checkpoint and delta.get('native_profile_mode')=='completion-v3')
 old=data.get('unchanged_completion_consumer');need(type(old)is dict and old.get('schema_valid')is True and old.get('can_execute')is False and old.get('global_completion')is False)
 # Never copy nested payloads, paths, messages, profiles, environment or raw logs.
 return {'schema':'Completion-v3-CI-integration-safe-v1','source_revision':checkpoint,'actual_same_owner_integration_passed':True,'original_strict_consumer_accepted':True,'actual_SQL_driver_completed':True,'source_exit_verified':True,'raw_current_receipt_modified':False,'global_completion':False,'can_execute':False,'deployment_proved':False,'activation_authorized':False,'full_CI_qualified':False,'artifact_is_qualification_authority':False}
def private(path):
 path=Path(path);fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  before=os.fstat(fd);need(stat.S_ISREG(before.st_mode)and before.st_uid==os.getuid()and before.st_nlink==1 and stat.S_IMODE(before.st_mode)==0o600 and 0<before.st_size<=1048576)
  raw=bytearray()
  while len(raw)<before.st_size:
   chunk=os.read(fd,before.st_size-len(raw));need(bool(chunk));raw.extend(chunk)
  after=os.fstat(fd)
  def stamp(info):return(info.st_dev,info.st_ino,info.st_mode,info.st_nlink,info.st_uid,info.st_gid,info.st_size,info.st_mtime_ns,info.st_ctime_ns)
  need(stamp(before)==stamp(after))
  return bytes(raw)
 finally:os.close(fd)
def main():
 p=argparse.ArgumentParser();p.add_argument('--result',required=True);p.add_argument('--expected-checkpoint',required=True);a=p.parse_args()
 try:row=project(json.loads(private(a.result)),a.expected_checkpoint)
 except Exception:return 1
 print(json.dumps(row,sort_keys=True));return 0
if __name__=='__main__':raise SystemExit(main())
