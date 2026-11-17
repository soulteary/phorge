#!/usr/bin/env python3
"""Explicit N11 CURRENT source-only/runtime entry; original runtime gates remain mandatory."""
import argparse,json,os,stat,sys,types
from pathlib import Path
import n11_task_source_current_v2 as adapter
ROOT=Path(__file__).resolve().parents[2]
def write_exclusive(path,value):
 path=Path(path);adapter.need(path.is_absolute()and path.parent.resolve(strict=True)==path.parent and not path.is_relative_to(ROOT))
 raw=(json.dumps(value,sort_keys=True,separators=(',',':'))+'\n').encode();adapter.need(0<len(raw)<=65536)
 parent=path.parent.lstat();adapter.need(stat.S_ISDIR(parent.st_mode)and parent.st_uid==os.geteuid()and stat.S_IMODE(parent.st_mode)==0o700)
 identity=lambda st:(st.st_dev,st.st_ino,st.st_uid,st.st_gid,st.st_mode)
 stamp=lambda st:identity(st)+(st.st_nlink,st.st_size,st.st_mtime_ns,st.st_ctime_ns)
 dfd=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW);fd=None
 try:
  adapter.need(identity(parent)==identity(os.fstat(dfd)))
  fd=os.open(path.name,os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=dfd);os.fchmod(fd,0o600);position=0
  while position<len(raw):count=os.write(fd,raw[position:]);adapter.need(count>0);position+=count
  os.fsync(fd);written=os.fstat(fd);adapter.need(stat.S_ISREG(written.st_mode)and written.st_nlink==1 and written.st_uid==os.geteuid()and stat.S_IMODE(written.st_mode)==0o600 and written.st_size==len(raw))
  adapter.need(os.pread(fd,len(raw)+1,0)==raw and stamp(written)==stamp(os.fstat(fd))==stamp(os.stat(path.name,dir_fd=dfd,follow_symlinks=False)))
  adapter.need(identity(parent)==identity(os.fstat(dfd))==identity(path.parent.lstat()));os.fsync(dfd)
 finally:
  errors=[]
  for handle in(fd,dfd):
   if handle is not None:
    try:os.close(handle)
    except OSError as error:errors.append(error)
  if errors:
   primary=sys.exc_info()[1]
   raise BaseExceptionGroup('N11 private output close refused',([primary]if primary is not None else[])+errors)
def source_only(root,revision,config,manifest,dependencies):
 binding=adapter.bind(Path(root),revision,config,manifest,dependencies)
 with binding['admitted_source'](config)as operation:
  context=adapter._guard(operation,Path(root),revision)
  result={'schema':'N11-CURRENT-adapter-source-only-v2','source_revision':revision,'current_source_leaf_count':len(context.entries),'new_dependency_count':len(adapter.DEPENDENCIES),'original_prepared_context_and_FD_functions_unchanged':True,'original_parent_run_and_finalize_functions_unchanged':True,'actual_source_exit_verified':False,'SQL_executed':False,'browser_executed':False,'run_or_finalize_dispatch_executed':False,'runtime_qualified':False}
 result['actual_source_exit_verified']=True;return result

def canonical_config(value):
 adapter.need(type(value)is dict);return json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False)

def output_role(path,root):
 path=Path(path);root=Path(root)
 adapter.need(path.is_absolute()and path.parent.resolve(strict=True)==path.parent and not path.is_relative_to(root)and not path.exists()and not path.is_symlink())
 parent=path.parent.lstat();adapter.need(stat.S_ISDIR(parent.st_mode)and parent.st_uid==os.geteuid()and stat.S_IMODE(parent.st_mode)==0o700)
 return path

def closed_runtime_argv(config_path,manifest_path,worker_path,output,*,case=None,browser_hold=False,browser_ready_output=None,browser_seconds=None,finalize_browser=None,owner_file=None):
 adapter.need(type(browser_hold)is bool and (browser_seconds is None or type(browser_seconds)is int))
 # This is only closed argument DATA. Actual required/choices/type/options and
 # browser bounds still execute in the unchanged parent parser/business core.
 argv=['--task-source-manifest',str(manifest_path),'--config',str(config_path),'--worker-manifest',str(worker_path),'--output',str(output)]
 for key,value in (('--case',case),('--browser-ready-output',browser_ready_output),('--browser-seconds',browser_seconds),('--finalize-browser',finalize_browser),('--owner-file',owner_file)):
  if value is not None:argv.extend((key,str(value)))
 if browser_hold:argv.append('--browser-hold')
 return tuple(argv)

def bind_runtime_arguments(namespace,original_parent,argv,config_path,config,root,revision,captured_worker):
 """Real parser with private argv/loader bindings; never a source issuer."""
 adapter.need(type(argv)is tuple and all(type(value)is str for value in argv))
 # Recompile the exact pinned CURRENT two-function AST using the same private
 # compilation context. A full-module compiler may optimize globals differently.
 expected=compile(adapter.parent_functions(Path(original_parent.__file__).read_bytes()),str(Path(original_parent.__file__)),'exec')
 functions={value.co_name:value for value in expected.co_consts if isinstance(value,types.CodeType)}
 adapter.need(set(functions)=={'main','run_task'}and all(namespace[name].__code__==functions[name]for name in functions))
 adapter.need(namespace['_run_task_with_source_operation']is original_parent._run_task_with_source_operation)
 captured_config=canonical_config(config);original_loader=original_parent.load_config
 worker_snapshot=canonical_config(captured_worker);original_worker=original_parent.validate_worker
 class BoundParser(argparse.ArgumentParser):
  def parse_args(self,args=None,namespace=None):
   adapter.need(args is None and namespace is None)
   return super().parse_args(args=list(argv),namespace=None)
  def error(self,message):self.exit(2,'N11 runtime invocation unavailable\n')
 def guarded_load_config(path):
  adapter.need(str(path)==str(config_path))
  current=original_loader(path) # Actual original private-file/full-config gate.
  adapter.need(canonical_config(current)==captured_config and current['source_root']==str(root)and current['source_revision']==revision)
  return current # Never return a cached/synthetic config to the original main.
 def guarded_validate_worker(value,current_config):
  checked=original_worker(value,current_config)
  adapter.need(canonical_config(checked)==worker_snapshot)
  return checked
 namespace['validate_worker']=guarded_validate_worker
 namespace['argparse']=types.SimpleNamespace(ArgumentParser=BoundParser)
 namespace['load_config']=guarded_load_config
 adapter.need(namespace['main'].__globals__ is namespace and namespace['run_task'].__globals__ is namespace)
 return namespace['main']

def runtime_dispatch(root,revision,config_path,manifest_path,dependency_path,worker_path,output,*,case=None,browser_hold=False,browser_ready_output=None,browser_seconds=None,finalize_browser=None,owner_file=None):
 root=Path(root);adapter.need(root==ROOT==adapter.ROOT);adapter.checkpoint(revision)
 task,parent=adapter.modules(root)
 config=parent.load_config(config_path) # Full original runtime config, never API-minimal config.
 adapter.need(config['source_root']==str(root)and config['source_revision']==revision)
 worker=adapter.strict_json(parent.private_file(worker_path).read_bytes());parent.validate_worker(worker,config)
 manifest=task.load_manifest(manifest_path);dependencies=task.load_manifest(dependency_path)
 browser=browser_hold or finalize_browser is not None
 task.validate_manifest(manifest,config,task.declared_files(root,parent.TASK_FILES,parent.BROWSER_FILES if browser else frozenset()))
 binding=adapter.bind(root,revision,config,manifest,dependencies)
 output=output_role(output,root)
 if browser_ready_output is not None:
  ready=output_role(browser_ready_output,root);adapter.need(ready!=output)
 argv=closed_runtime_argv(config_path,manifest_path,worker_path,output,case=case,browser_hold=browser_hold,browser_ready_output=browser_ready_output,browser_seconds=browser_seconds,finalize_browser=finalize_browser,owner_file=owner_file)
 main=bind_runtime_arguments(binding['parent'],parent,argv,config_path,config,root,revision,worker)
 result=main() # Original main opens/closes source in run/finalize, writes its own result.
 adapter.need(type(result)is int and result in(0,1));return result

RUNTIME_FLAGS=frozenset(('--worker-manifest','--case','--browser-hold','--browser-ready-output','--browser-seconds','--finalize-browser','--owner-file'))
def runtime_flags_present(argv):
 return any(value.split('=',1)[0]in RUNTIME_FLAGS for value in argv)

class SafeParser(argparse.ArgumentParser):
 def error(self,message):raise SystemExit('N11 current source invocation unavailable')
def main():
 parser=SafeParser(description=__doc__,allow_abbrev=False);parser.add_argument('--mode',choices=('source-only','runtime'),default='source-only');parser.add_argument('--root',required=True);parser.add_argument('--expected-checkpoint',required=True);parser.add_argument('--config',required=True);parser.add_argument('--task-source-manifest',required=True);parser.add_argument('--dependency-sidecar');parser.add_argument('--write-dependency-sidecar',action='store_true');parser.add_argument('--output',required=True)
 parser.add_argument('--worker-manifest');parser.add_argument('--case');parser.add_argument('--browser-hold',action='store_true');parser.add_argument('--browser-ready-output');parser.add_argument('--browser-seconds',type=int);parser.add_argument('--finalize-browser');parser.add_argument('--owner-file');args=parser.parse_args()
 root=Path(args.root);adapter.need(root==ROOT==adapter.ROOT);revision=adapter.checkpoint(args.expected_checkpoint)
 if args.mode=='runtime':
  adapter.need(not args.write_dependency_sidecar and args.dependency_sidecar is not None and args.worker_manifest is not None)
  return runtime_dispatch(root,revision,args.config,args.task_source_manifest,args.dependency_sidecar,args.worker_manifest,args.output,case=args.case,browser_hold=args.browser_hold,browser_ready_output=args.browser_ready_output,browser_seconds=args.browser_seconds,finalize_browser=args.finalize_browser,owner_file=args.owner_file)
 adapter.need(not runtime_flags_present(sys.argv[1:]))
 task,parent=adapter.modules(root);config=parent.load_config(args.config);manifest=task.load_manifest(args.task_source_manifest)
 if args.write_dependency_sidecar:
  adapter.need(args.dependency_sidecar is None);result=adapter.sidecar(root,revision,manifest)
 else:
  adapter.need(args.dependency_sidecar is not None);result=source_only(root,revision,config,manifest,task.load_manifest(args.dependency_sidecar))
 write_exclusive(args.output,result);return 0
if __name__=='__main__':
 try:raise SystemExit(main())
 except Exception:raise SystemExit('N11 current source admission unavailable')from None
