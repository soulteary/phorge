"""Dedicated completion producer source/process window. No acceptance authority."""
import hashlib,hmac,json,os,secrets,subprocess,types
from pathlib import Path
import source_operation_snapshot as source
BASE='support/governance/completion-task-v3/'
PRODUCER='support/governance/completion/fixture-run.php'
FRESH='scripts/governance/check_directory_transports_task_source_v2.py'
VALIDATOR=BASE+'validate.py'
INPUTS=('resources/sql/autopatches/20261112.task.contentgrants.sql','src/infrastructure/governance/content/PhorgeTaskContentGrantSchemaSpec.php','support/governance/completion/fixture.php','resources/sql/quickstart.sql',BASE+'capture.php',BASE+'run.php',BASE+'prefix.php',PRODUCER)
MARKER=b'const N05_COMPLETION_RUN_PROFILE_SHA256=null;'
PRODUCER_PIN='98637f5c1dd59f14f139bff7c242e1f7f6551b8924f7f4b3c5bacd1f634cd60f'

def need(ok):
 if ok is not True:raise ValueError('Completion_Task_v3_refused')

def adapt(raw,prefix):
 need(type(raw)is bytes and hashlib.sha256(raw).hexdigest()==PRODUCER_PIN and raw.startswith(b'<?php\n'))
 need(type(prefix)is bytes and prefix.startswith(b'<?php\n')and prefix.count(MARKER)==1)
 before=b"  if ($phase === 'all') {"
 before_new=b"  $task_binding=PhorgeCompletionRunV3::prepare($fixture,$task_run,array());\n  $task_before=PhorgeCompletionSchemaCaptureV3::capture($fixture,$task_binding['profile'],'before');\n"+before
 after=b"  if ($fixture !== null) { $summary = $fixture->getSummary(); }"
 after_new=b"  if ($task_binding !== null) {\n    try { $task_after=PhorgeCompletionSchemaCaptureV3::capture($fixture,$task_binding['profile'],'after'); }\n    catch (Throwable $error) { $cleanup_failures[]=array('stage'=>'completion-after-capture','error_type'=>get_class($error)); }\n  }\n"+after
 terminal=b'exit($passed?0:1);'
 terminal_bound=b'$completion_primary_exit=$passed?0:1;'+terminal
 need(raw.count(terminal)==1)
 body=raw[len(b'<?php\n'):].replace(terminal,terminal_bound,1)
 need(body.count(before)==1 and body.count(after)==1)
 body=body.replace(before,before_new,1).replace(after,after_new,1).replace(b'__DIR__',b'$completion_directory')
 # Native bootstrap clears buffers; install outer capture AFTER that exact line.
 marker=b'// The original producer owns its inner native-output capture and all cleanup.'
 need(prefix.count(marker)==1);head,outer=prefix.split(marker,1);outer=marker+outer
 bootstrap=b"  require_once $root.'/support/runtime/bootstrap-cli.php';"
 need(body.count(bootstrap)==1);body=body.replace(bootstrap,bootstrap+b'\n'+outer,1)
 guard=b"if(array_slice($argv??array(),1)!==array()){fwrite(STDERR,\"Completion_arguments_refused\\n\");exit(1);}\n"
 result=head+guard+body
 # Exact reverse conservation; no original check, cleanup or failure path lost.
 original=result[len(head+guard):]
 need(original.count(bootstrap+b'\n'+outer)==1)
 original=original.replace(bootstrap+b'\n'+outer,bootstrap,1).replace(b'$completion_directory',b'__DIR__')
 need(original.count(before_new)==1 and original.count(after_new)==1)
 original=original.replace(before_new,before,1).replace(after_new,after,1)
 need(original.count(terminal_bound)==1)
 original=original.replace(terminal_bound,terminal,1)
 need(b'<?php\n'+original==raw)
 return result

def put(path,raw):
 fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
 with os.fdopen(fd,'wb')as f:
  view=memoryview(raw)
  while view:
   n=f.write(view);need(type(n)is int and n>0);view=view[n:]
  f.flush();os.fsync(f.fileno())

def finish_process(primary,sink_errors,code):
 # Preserve actual process exception identity/trace and every secondary failure.
 if primary is not None:
  if sink_errors:raise BaseExceptionGroup('Completion_process_and_sink_failures',[primary,*sink_errors])
  raise primary
 if sink_errors and (code==0 or code is None):
  raise ExceptionGroup('Completion_sink_failures',sink_errors)
 return code

def run_in_operation(operation,root,checkpoint,directory,stdout_path,stderr_path,php='php',environment=None):
 root=Path(root);directory=Path(directory)
 need(root.is_absolute()and root.resolve(strict=True)==root and directory.is_absolute()and directory.parent.resolve(strict=True)==directory.parent and not directory.exists()and not directory.is_symlink()and not directory.is_relative_to(root))
 code=None;process=None
 source.require_task_operation(operation,root);need(operation._task_expected_checkpoint==checkpoint);need(operation.code_root==root and operation.producer_root==root and source.ROOT==root)
 context=operation._task_contexts[root]
 def current_module(relative):
  module=types.ModuleType('Completion_CURRENT_'+Path(relative).stem);module.__file__=str(root/relative)
  exec(compile(context.current(relative),module.__file__,'exec'),module.__dict__);return module
 freshness=current_module(FRESH);freshness.verify_in_operation(operation,root)
 captured={rel:context.current(rel)for rel in INPUTS}
 # This coordinator's code is current-source bound, not an external issuer.
 own='scripts/governance/run_completion_task_v3.py';need(context.current(own)==Path(__file__).read_bytes())
 template=adapt(captured[PRODUCER],captured[BASE+'prefix.php']);need(template.count(MARKER)==1)
 os.mkdir(directory,0o700)
 profile={'schema':'Completion-run-profile-v3','mode':'completion-v3','source_root':str(root),'source_revision':checkpoint,'run_id':secrets.token_hex(16),'hmac_key':secrets.token_hex(32),'source_pins':{p:hashlib.sha256(b).hexdigest()for p,b in captured.items()},'output':str(directory/'capture'),'producer_template_sha256':hashlib.sha256(template).hexdigest()}
 raw=json.dumps(profile,sort_keys=True,separators=(',',':')).encode()+b'\n';put(directory/'run-profile.private.json',raw)
 bound=template.replace(MARKER,("const N05_COMPLETION_RUN_PROFILE_SHA256='"+hashlib.sha256(raw).hexdigest()+"';").encode(),1);put(directory/'producer-bound.php',bound)
 streams=[];sink_errors=[];primary=None
 try:
  for path in(stdout_path,stderr_path):
   path=Path(path);need(path.is_absolute()and path.parent.resolve(strict=True)==path.parent and not path.is_relative_to(root))
   fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600);streams.append(os.fdopen(fd,'wb'))
  env=dict(os.environ)if environment is None else dict(environment);env['PHORGE_COMPLETION_RUN_PROFILE']=str(directory/'run-profile.private.json')
  process=subprocess.run([php,'-d','opcache.enable_cli=0','-d','opcache.jit=disable','-d','opcache.jit_buffer_size=0','-d','log_errors=1','-d','error_log=/dev/stderr','-d','display_errors=0',str(directory/'producer-bound.php')],cwd=root,env=env,stdout=streams[0],stderr=streams[1],timeout=1800)
  code=process.returncode
 except BaseException as error:
  primary=error
 finally:
  for f in streams:
   try:f.flush();os.fsync(f.fileno())
   except Exception as e:sink_errors.append(e)
   try:f.close()
   except Exception as e:sink_errors.append(e)
  if sink_errors:
   try:put(directory/'sink-failure.safe.json',json.dumps({'schema':'Completion-sink-failure-v3','primary_exit':code,'primary_exception_present':primary is not None,'sink_error_count':len(sink_errors),'sink_complete':False,'runtime_authority':False},sort_keys=True).encode()+b'\n')
   except Exception:
    try:__import__('sys').stderr.write('Completion_secondary_sink_record_unavailable\n')
    except Exception:pass
 finish_process(primary,sink_errors,code)
 return {'process':process,'directory':directory,'run_profile':directory/'run-profile.private.json','stdout':Path(stdout_path),'stderr':Path(stderr_path)}

def run(root,checkpoint,directory,stdout_path,stderr_path,php='php'):
 root=Path(root)
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint)as operation:
  result=run_in_operation(operation,root,checkpoint,directory,stdout_path,stderr_path,php)
 return result['process'].returncode
if __name__=='__main__':
 import argparse,sys
 p=argparse.ArgumentParser()
 for name in('root','expected-checkpoint','directory','stdout','stderr'):p.add_argument('--'+name,required=True)
 p.add_argument('--php',default='php');a=p.parse_args()
 try:sys.exit(run(a.root,a.expected_checkpoint,a.directory,a.stdout,a.stderr,a.php))
 except Exception:sys.stderr.write('Completion_Task_source_or_process_refused\n');sys.exit(1)
