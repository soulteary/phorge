#!/usr/bin/env python3
"""Actual native process is enclosed by one genuine current source window."""
import argparse,os,subprocess,sys,types
from pathlib import Path
import source_operation_snapshot as source

def need(ok):
 if ok is not True:raise ValueError('Task_directory_native_window_refused')
def run(root,checkpoint,directory,stdout_path,stderr_path,php,role="directory"):
 root=Path(root);directory=Path(directory)
 need(root.resolve(strict=True)==root and directory.parent.resolve(strict=True)==directory.parent and not directory.exists())
 output=[];code=None
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint)as operation:
  source.require_task_operation(operation,root);context=operation._task_contexts[root]
  rel='scripts/governance/prepare_task_directory_run_v2.py';module=types.ModuleType('Task_CURRENT_run_profile_builder');module.__file__=str(root/rel)
  exec(compile(context.current(rel),module.__file__,'exec'),module.__dict__)
  captures,template=module.capture(operation,root,role)
  module.publish(root,checkpoint,directory,captures,template,role)
  roles=__import__("json").loads(captures["support/governance/task-additive-schema-v2/producer-roles-v2.json"]);argument=roles["roles"][role]["argument"]
  if role=='full-native':need(argument is None);arguments=[]
  else:need(type(argument)is str and argument.startswith('--'));arguments=[argument]
  env=dict(os.environ);env['PHORGE_TASK_DIRECTORY_RUN_PROFILE']=str(directory/'run-profile.private.json')
  # Exclusive files and bounded source window; no acceptance result before exit.
  files=[]
  try:
   for path in (stdout_path,stderr_path):
    path=Path(path);need(path.parent.resolve(strict=True)==path.parent)
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600);files.append(os.fdopen(fd,'wb'))
   result=subprocess.run([php,'-d','opcache.enable_cli=0','-d','opcache.jit=disable','-d','opcache.jit_buffer_size=0',str(directory/'producer-bound.php'),*arguments],cwd=root,env=env,stdout=files[0],stderr=files[1],timeout=1800)
   code=result.returncode
  finally:
   sink_errors=[]
   for stream in files:
    try:stream.flush();os.fsync(stream.fileno())
    except OSError as error:sink_errors.append(error)
    try:stream.close()
    except OSError as error:sink_errors.append(error)
   # A diagnostic/log sink failure can never turn a known native failure into
   # success or replace its actual nonzero exit status. Success still fails shut.
   if sink_errors and code==0:raise sink_errors[0]
  if type(code)is int and code!=0 and role in{'conduit','oauth-access','oauth-code','assessment-credential'}:
   try:
    relative='scripts/governance/project_task_bootstrap_failure_v2.py'
    diagnostic=types.ModuleType('Task_CURRENT_failure_only_bootstrap_projection');diagnostic.__file__=str(root/relative)
    exec(compile(context.current(relative),diagnostic.__file__,'exec'),diagnostic.__dict__)
    diagnostic.capture_in_operation(operation,root,role,code,directory,stdout_path,stderr_path)
   except Exception:
    # Failure-only best effort. No raw exception/log printing, fake native body,
    # source/schema proof, retry or acceptance; source exit remains mandatory.
    pass
 return code
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--root',required=True);p.add_argument('--expected-checkpoint',required=True);p.add_argument('--directory',required=True);p.add_argument('--stdout',required=True);p.add_argument('--stderr',required=True);p.add_argument('--php',default='php');p.add_argument('--role',default='directory',choices=['directory','oauth-authorization','oauth-client-lifecycle','conduit','oauth-access','oauth-code','assessment-credential','api-credential-concurrency','full-native']);a=p.parse_args()
 try:sys.exit(run(a.root,a.expected_checkpoint,a.directory,a.stdout,a.stderr,a.php,a.role))
 except BaseException as error:
  if isinstance(error,SystemExit):raise
  print('Task_directory_native_source_window_refused',file=sys.stderr);sys.exit(1)
