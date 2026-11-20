"""Strict CURRENT selected SQL controller. Never enables application features.
No default v1 replacement; workflow adoption requires actual signed regression.
"""
import argparse,hashlib,json,os,secrets,sys,types
from pathlib import Path
import source_operation_snapshot as source
ROOT=Path(__file__).resolve().parents[2]
SELF='scripts/governance/run_media_task_selected_v2.py'
BASE='support/governance/media-session-docker/'
RUNTIME='scripts/governance/media_task_runtime_v2.py'
PREPARE='scripts/governance/prepare_media_task_archive_v2.py'
ARCHIVE='scripts/governance/media_task_archive_v2.py'
SQL_CLEAN="SELECT JSON_OBJECT('uuid',@@server_uuid,'databases',(SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('mysql','sys','performance_schema','information_schema')),'users',(SELECT GROUP_CONCAT(CONCAT(User,'@',Host) ORDER BY User,Host SEPARATOR ',') FROM mysql.user),'MDL',(SELECT COUNT(*) FROM performance_schema.metadata_locks WHERE OBJECT_SCHEMA NOT IN ('mysql','sys','performance_schema','information_schema')));"
def need(value):
 if value is not True:raise ValueError('media_Task_selected_v2_refused')
def check_cleanup_snapshot(raw,uuid,prior=False):
 """Pure SQL observer DATA checks; never proves that a query actually ran."""
 need(type(raw)is bytes and 0<len(raw)<=8192 and type(uuid)is str)
 def unique(pairs):
  d={}
  for k,v in pairs:need(k not in d);d[k]=v
  return d
 d=json.loads(raw,object_pairs_hook=unique)
 need(type(d)is dict and set(d)=={'uuid','databases','users','MDL'}and d['uuid']==uuid and type(d['databases'])is int and d['databases']==0 and type(d['MDL'])is int and d['MDL']==0 and type(d['users'])is str and bool(d['users']))
 if prior is not False:need(type(prior)is dict and d==prior)
 return d
def captured(operation,root,path,dependencies=None):
 source.require_task_operation(operation,root)
 need(path in {RUNTIME,PREPARE,ARCHIVE,BASE+'snapshot.py',BASE+'consume.py',BASE+'run.py'})
 raw=operation._task_contexts[root].current(path)
 module=types.ModuleType('Media_CURRENT_'+Path(path).stem);module.__file__=str(root/path)
 if dependencies is not None:
  original=b'import snapshot,consume\n';need(raw.count(original)==1)
  raw=raw.replace(original,b'',1);module.__dict__.update(dependencies)
 exec(compile(raw,module.__file__,'exec'),module.__dict__)
 return module

def helper_program(original,worker_sha):
 """Append a closed v2 dispatcher; preserve original helper and all deadlines."""
 need(type(original)is bytes and original.startswith(b'<?php\n')and hashlib.sha256(original).hexdigest()=='9c315552420b91f8c569f9f353829d23b70c9d0dbe7d163f202cf89608c667c3')
 need(type(worker_sha)is str and len(worker_sha)==64 and all(c in'0123456789abcdef'for c in worker_sha))
 prefix=b"define('MEDIA_HELPER_LIBRARY_ONLY',true);\n"+original[6:]
 dispatch=r'''
$mode=$argv[1]??null;
if($mode==='stage-current'){
 $raw=stream_get_contents(STDIN,131073);media_helper_need(is_string($raw)&&strlen($raw)>0&&strlen($raw)<=131072&&hash('sha256',$raw)==='WORKER_SHA');
 $p='/run/owned/current-worker.php';$old=umask(0077);try{$h=fopen($p,'x+b');}finally{umask($old);}media_helper_need(is_resource($h));
 try{$off=0;while($off<strlen($raw)){$n=fwrite($h,substr($raw,$off));media_helper_need(is_int($n)&&$n>0);$off+=$n;}media_helper_need(fflush($h)&&fsync($h)&&fseek($h,0)===0&&stream_get_contents($h,131073)===$raw);$a=fstat($h);clearstatcache(true,$p);$b=lstat($p);foreach(array('dev','ino','mode','uid','gid','nlink','size','mtime','ctime')as$k){media_helper_need($a[$k]===$b[$k]);}media_helper_need(($a['mode']&0177777)===0100600&&$a['nlink']===1&&$a['uid']===posix_geteuid()&&$a['gid']===posix_getegid()&&$a['size']===strlen($raw));}finally{fclose($h);}echo hash('sha256',$raw);return;
}
if($mode==='worker'){
 media_helper_need(mkdir('/run/owned',0700));$end=microtime(true)+600;while(!is_file('/run/owned/ready')){media_helper_need(microtime(true)<$end);usleep(100000);}
 $p='/run/owned/current-worker.php';$a=lstat($p);media_helper_need($a!==false&&($a['mode']&0177777)===0100600&&$a['nlink']===1&&$a['uid']===posix_geteuid()&&$a['gid']===posix_getegid()&&hash_file('sha256',$p)==='WORKER_SHA');
 media_helper_need(function_exists('pcntl_exec'));pcntl_exec('/usr/local/bin/php',array('-d','opcache.jit=disable','-d','opcache.jit_buffer_size=0','-d','opcache.enable_cli=0',$p,'--execute','/run/owned/profile.json'));throw new RuntimeException('container_current_exec_returned');
}
media_helper_main($argv);
'''.replace('WORKER_SHA',worker_sha).encode()
 return (prefix+dispatch).decode()

# Each insertion conserves all old runtime checks and known-terminal handling.
SEAMS=(
 ("  archive,manifest=snapshot.build(Path(__file__).resolve().parents[3],j.path)","  archive,manifest=current_archive(j)"),
 ("  j.write('fresh-database-baseline.json',canon(baseline))","  j.write('fresh-database-baseline.json',canon(baseline))\n  cleanup_baseline=current_SQL_cleanup(j,c,db,baseline,False)"),
 ("  j.call(c['docker'],['exec','-i',worker]+helper_args('inject'),payload)","  current_stage_worker(j,c,worker)\n  j.call(c['docker'],['exec','-i',worker]+helper_args('inject'),payload)"),
 ("result=consume.accept(raw)","result=current_native_accept(raw)"),
 ("  accepted=True;j.write('accepted.json'","  current_SQL_cleanup(j,c,db,baseline,cleanup_baseline)\n  current_archive_check()\n  accepted=True;j.write('accepted.json'"),
 ("  j.write('terminal.json',canon({'accepted':True","  current_archive_check()\n  j.write('terminal.json',canon({'accepted':True"),
 )
def adapted_execute(raw):
 need(type(raw)is bytes and hashlib.sha256(raw).hexdigest()=='7ecb21b2beb42ff6469be467ad9924278b3d717661040d6cded817d4350643ec');text=raw.decode();start=text.index('def execute(');end=text.index('\ndef main():',start);original=text[start:end];body=original
 for old,new in SEAMS:need(body.count(old)==1);body=body.replace(old,new,1)
 restored=body
 for old,new in reversed(SEAMS):need(restored.count(new)==1);restored=restored.replace(new,old,1)
 need(restored==original);return body

def execute(root,checkpoint,config_path,output):
 root=Path(root);output=Path(output);held=None;result=None
 with source.open_task_source_operation(root,expected_checkpoint=checkpoint)as operation:
  ctx=operation._task_contexts[root];need(ctx.operation is operation and ctx.current(SELF)==Path(__file__).read_bytes()and operation.code_root==operation.producer_root==root==ROOT)
  runtime=captured(operation,root,RUNTIME);prepare=captured(operation,root,PREPARE);archive=captured(operation,root,ARCHIVE)
  raw_worker,binding=runtime.source_inputs(operation,root);key=secrets.token_bytes(32);binding['run_nonce']=secrets.token_hex(32)
  snapshot=captured(operation,root,BASE+'snapshot.py');consume=captured(operation,root,BASE+'consume.py')
  legacy=captured(operation,root,BASE+'run.py',{'snapshot':snapshot,'consume':consume})
  state={'archive':None,'worker':None,'worker_sha':None,'helper':None,'cleanup':False}
  def current_archive(j):
   nonlocal held
   directory=j.path/'current-source';os.mkdir(directory,0o700)
   data=prepare.prepare_in_operation(operation,root,directory);manifest=data['archive_DATA']['manifest'];rows={}
   for relative,pin in ctx.entries.items():rows[relative]=('file',ctx.current(relative),pin['mode'])if pin['kind']=='file'else('symlink',pin['target'],0o777)
   held=runtime.HeldArchive(directory,rows,manifest,archive);state['archive']=manifest
   binding['archive_sha256']=manifest['archive_sha256'];state['worker']=runtime.adapt_worker(raw_worker,binding,key);state['worker_sha']=runtime.sha(state['worker'])
   state['helper']=helper_program(ctx.current(BASE+'helper.php'),state['worker_sha'])
   # Original readback helper calls file byte length "size", not archive "bytes".
   inventory={p:dict((('size'if k=='bytes'else k),v)for k,v in row.items())for p,row in manifest['entries'].items()}
   return held,dict(manifest,entries=inventory)
  # Prerequisite and seed helpers run before source archive; they must remain old.
  def helper_args(mode):
   need(mode in ('seed','worker','inject','readback','namespace','caps','prerequisite','stage-current'))
   body=state['helper']if state['helper']is not None else ctx.current(BASE+'helper.php')[6:].decode()
   need(mode!='stage-current'or state['worker']is not None)
   return ['/usr/local/bin/php','-d','opcache.jit=disable','-d','opcache.jit_buffer_size=0','-d','opcache.enable_cli=0','-r',body,'--',mode]
  def current_stage_worker(j,c,worker):
   held.check();source.require_task_operation(operation,root)
   raw,err=j.call(c['docker'],['exec','-i',worker]+helper_args('stage-current'),state['worker']);need(not err and raw==state['worker_sha'].encode());held.check()
  def current_native_accept(raw):return runtime.accept_frame(raw,binding,key,consume.accept)
  def current_archive_check():held.check();source.require_task_operation(operation,root)
  def current_SQL_cleanup(j,c,db,baseline,prior):
   raw,err=j.call(c['docker'],['exec',db,'mysql','--defaults-extra-file=/run/owned/client.cnf','--batch','--skip-column-names','-e',SQL_CLEAN],timeout=5);need(not err);d=check_cleanup_snapshot(raw,baseline['uuid'],prior)
   if prior is not False:state['cleanup']=True
   return d
  legacy.__dict__.update(current_archive=current_archive,helper_args=helper_args,current_stage_worker=current_stage_worker,current_native_accept=current_native_accept,current_archive_check=current_archive_check,current_SQL_cleanup=current_SQL_cleanup)
  exec(compile(adapted_execute(ctx.current(BASE+'run.py')),str(root/SELF)+':execute','exec'),legacy.__dict__)
  primary=None
  try:
   ok=legacy.execute(config_path,output,True);need(ok is True and state['cleanup']is True);current_archive_check()
   result={'schema':'media-Task-current-selected-runtime-v2','selected_SQL_accepted':True,'checkpoint':checkpoint,'archive_sha256':state['archive']['archive_sha256'],'worker_program_sha256':state['worker_sha'],'binding_sha256':runtime.sha(runtime.canonical(binding)),'independent_SQL_cleanup_verified':True,'owned_Docker_resources_disposed':True,'source_operation_exit_verified':False,'browser_TLS_or_file_authorization_proved':False,'production_changed':False}
  except BaseException as e:primary=e
  close_errors=[]
  if held is not None:
   try:held.close()
   except BaseException as e:close_errors.append(e)
  if primary is not None:
   if close_errors:raise BaseExceptionGroup('media_runtime_and_archive_close_failures',[primary,*close_errors])
   raise primary
  if close_errors:raise BaseExceptionGroup('media_archive_close_failures',close_errors)
  source.require_task_operation(operation,root)
 # Only this actual owner exit may mark current source window complete.
 result['source_operation_exit_verified']=True;return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--execute',action='store_true');p.add_argument('--root',default=str(ROOT));p.add_argument('--expected-checkpoint',required=True);p.add_argument('--config',required=True);p.add_argument('--output',required=True);a=p.parse_args()
 if not a.execute:raise SystemExit('explicit --execute required')
 try:print(json.dumps(execute(a.root,a.expected_checkpoint,a.config,a.output),sort_keys=True))
 except BaseException:print('{"selected_SQL_accepted":false,"source_operation_exit_verified":false,"browser_TLS_or_file_authorization_proved":false}');raise
