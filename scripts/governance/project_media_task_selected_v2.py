"""Sanitize driver output DATA only. Never admits source or runtime authority.
The workflow must independently require the actual strict driver's zero exit.
"""
import argparse,json,os,re,stat
from pathlib import Path
KEYS={'schema','selected_SQL_accepted','checkpoint','archive_sha256','worker_program_sha256','binding_sha256','independent_SQL_cleanup_verified','owned_Docker_resources_disposed','source_operation_exit_verified','browser_TLS_or_file_authorization_proved','production_changed'}
def need(value):
 if value is not True:raise ValueError('media_Task_projection_v2_refused')
def unique(pairs):
 d={}
 for k,v in pairs:need(k not in d);d[k]=v
 return d
def project(raw,checkpoint):
 need(type(raw)is bytes and 0<len(raw)<=8192 and type(checkpoint)is str and re.fullmatch('[a-f0-9]{40}',checkpoint)is not None)
 d=json.loads(raw,object_pairs_hook=unique);need(type(d)is dict and set(d)==KEYS and d['schema']=='media-Task-current-selected-runtime-v2'and d['checkpoint']==checkpoint)
 for field in ('selected_SQL_accepted','independent_SQL_cleanup_verified','owned_Docker_resources_disposed','source_operation_exit_verified'):need(d[field]is True)
 for field in ('browser_TLS_or_file_authorization_proved','production_changed'):need(d[field]is False)
 for field in ('archive_sha256','worker_program_sha256','binding_sha256'):need(type(d[field])is str and re.fullmatch('[a-f0-9]{64}',d[field])is not None)
 return {'schema':'media-Task-current-selected-driver-summary-v2','driver_reported_current_selected_SQL_pass':True,'checkpoint':checkpoint,'archive_sha256':d['archive_sha256'],'worker_program_sha256':d['worker_program_sha256'],'binding_sha256':d['binding_sha256'],'projector_independently_verifies_process_or_source_authority':False,'report_is_future_runtime_authorization':False,'browser_TLS_or_file_authorization_proved':False,'production_changed':False}
def read_private(path):
 path=Path(path);need(path.is_absolute()and path.resolve()==path);before=path.lstat();need(stat.S_ISREG(before.st_mode)and stat.S_IMODE(before.st_mode)==0o600 and before.st_nlink==1 and before.st_uid==os.geteuid()and 0<before.st_size<=8192)
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  def stamp(s):return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
  need(stamp(os.fstat(fd))==stamp(before));raw=os.pread(fd,8193,0);need(len(raw)==before.st_size and stamp(os.fstat(fd))==stamp(before)==stamp(path.lstat()));return raw
 finally:os.close(fd)
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--input',required=True);p.add_argument('--expected-checkpoint',required=True);a=p.parse_args();print(json.dumps(project(read_private(a.input),a.expected_checkpoint),sort_keys=True))
