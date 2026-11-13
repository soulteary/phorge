"""Typed, closed Task test-harness source sidecar; never runtime qualification."""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess

PARENT_FILES=frozenset({
 'tests/governance/private_mail_ci/task_source.py',
 'tests/governance/private_mail_ci/runtime.py',
 'tests/governance/private_mail_ci/run_pending.py',
 'tests/governance/private_mail_ci/pure_inventory.py',
 'tests/governance/private_mail_ci/prepare_source.py',
 'tests/governance/private_mail_ci/make_config.py',
 'tests/governance/run.php',
})

HISTORY_SOURCE_FILES=frozenset({
 'src/applications/notification/storage/PhabricatorFeedStoryNotification.php',
 'src/applications/notification/query/PhabricatorNotificationQuery.php',
 'src/applications/notification/controller/PhabricatorNotificationIndividualController.php',
 'src/applications/feed/storage/PhabricatorFeedStoryData.php',
 'src/applications/feed/story/PhabricatorFeedStory.php',
 'src/applications/transactions/feed/PhabricatorApplicationTransactionFeedStory.php',
 'src/applications/maniphest/query/ManiphestTaskQuery.php',
})

def need(value):
 if value is not True:raise ValueError('task_typed_source_refused')

def git(root,*args):
 env={k:os.environ[k]for k in ('HOME','TMPDIR','LANG')if k in os.environ}
 env.update({'PATH':'/usr/bin:/bin','GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null','GIT_NO_REPLACE_OBJECTS':'1'})
 return subprocess.check_output(['/usr/bin/git','--no-replace-objects','-c','core.fsmonitor=false','-C',str(root),*args],env=env,stderr=subprocess.PIPE)

def read_source(root,name):
 need(type(name)is str and not Path(name).is_absolute()and '..'not in Path(name).parts)
 path=root/name;need(path.resolve()==path)
 parents=[(parent,parent.lstat())for parent in path.parents]
 need(all(stat.S_ISDIR(info.st_mode)and not stat.S_ISLNK(info.st_mode)for parent,info in parents))
 before=path.lstat();need(stat.S_ISREG(before.st_mode)and stat.S_IMODE(before.st_mode)==0o644 and before.st_nlink==1 and before.st_uid==os.getuid()and 0<before.st_size<=2*1024*1024)
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  stamp=lambda x:(x.st_dev,x.st_ino,x.st_uid,x.st_gid,x.st_mode,x.st_nlink,x.st_size,x.st_mtime_ns,x.st_ctime_ns)
  need(stamp(before)==stamp(os.fstat(fd)));raw=os.read(fd,before.st_size+1)
  need(len(raw)==before.st_size and stamp(before)==stamp(os.fstat(fd))==stamp(path.lstat()))
  for parent,was in parents:
   now=parent.lstat()
   need((was.st_dev,was.st_ino,was.st_uid,was.st_gid,was.st_mode)==(now.st_dev,now.st_ino,now.st_uid,now.st_gid,now.st_mode))
  return raw
 finally:os.close(fd)

def typed_manifest(root,revision,paths):
 root=Path(root);need(root.is_absolute()and root.resolve()==root and not root.is_symlink()and re.fullmatch('[a-f0-9]{40}',revision)is not None)
 need(git(root,'rev-parse','HEAD').decode().strip()==revision)
 need(git(root,'status','--porcelain','--untracked-files=all')==b'')
 need(type(paths)is frozenset and PARENT_FILES<=paths)
 tree=git(root,'rev-parse',revision+'^{tree}').decode().strip();rows={}
 for name in sorted(paths):
  rawrow=git(root,'ls-tree','-z',revision,'--',name);need(rawrow.count(b'\0')==1)
  header,path=rawrow[:-1].split(b'\t');mode,kind,blob=header.split();need(path.decode()==name and mode==b'100644'and kind==b'blob')
  raw=git(root,'cat-file','blob',blob.decode());need(raw==read_source(root,name))
  rows[name]={'git_mode':'100644','git_blob':blob.decode(),'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
 need(git(root,'status','--porcelain','--untracked-files=all')==b'')
 return {'schema':'owned-task-source-v1','source_revision':revision,'source_tree':tree,'files':rows,'runtime_qualified':False}

def validate_manifest(value,config,paths):
 need(type(value)is dict and set(value)=={'schema','source_revision','source_tree','files','runtime_qualified'})
 need(type(value['runtime_qualified'])is bool and value['runtime_qualified']is False)
 need(value['schema']=='owned-task-source-v1'and type(value['files'])is dict)
 need(type(value['source_revision'])is str and re.fullmatch('[a-f0-9]{40}',value['source_revision'])is not None)
 need(type(value['source_tree'])is str and re.fullmatch('[a-f0-9]{40}',value['source_tree'])is not None)
 for name,row in value['files'].items():
  need(type(name)is str and type(row)is dict and set(row)=={'git_mode','git_blob','bytes','sha256'})
  need(row['git_mode']=='100644'and type(row['bytes'])is int and 0<row['bytes']<=2*1024*1024)
  need(type(row['git_blob'])is str and re.fullmatch('[a-f0-9]{40}',row['git_blob'])is not None)
  need(type(row['sha256'])is str and re.fullmatch('[a-f0-9]{64}',row['sha256'])is not None)
 actual=typed_manifest(Path(config['source_root']),config['source_revision'],paths)
 need(value==actual)
 for name,row in actual['files'].items():need(config['source_sha256'].get(name)==row['sha256'])
 return actual

def load_manifest(path):
 path=Path(path);need(path.is_absolute()and path.resolve()==path)
 parents=[(parent,parent.lstat())for parent in path.parents]
 need(all(stat.S_ISDIR(info.st_mode)and not stat.S_ISLNK(info.st_mode)for parent,info in parents))
 before=path.lstat();need(stat.S_ISREG(before.st_mode)and stat.S_IMODE(before.st_mode)==0o600 and before.st_nlink==1 and before.st_uid==os.getuid()and 0<before.st_size<=65536)
 def pairs(items):
  out={}
  for k,v in items:
   need(k not in out);out[k]=v
  return out
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  key=lambda x:(x.st_dev,x.st_ino,x.st_mode,x.st_uid,x.st_gid,x.st_nlink,x.st_size,x.st_mtime_ns,x.st_ctime_ns)
  need(key(before)==key(os.fstat(fd)))
  raw=os.read(fd,before.st_size+1);after=os.fstat(fd)
  need(len(raw)==before.st_size and key(before)==key(after)==key(path.lstat()))
  for parent,was in parents:
   now=parent.lstat()
   need((was.st_dev,was.st_ino,was.st_uid,was.st_gid,was.st_mode)==(now.st_dev,now.st_ino,now.st_uid,now.st_gid,now.st_mode))
  return json.loads(raw,object_pairs_hook=pairs)
 finally:os.close(fd)

def validate_parent_modules(config,browser=False):
 """Physical parent graph identity; unrelated imported roots cannot drive a run."""
 import sys
 need(type(browser)is bool)
 root=Path(config['source_root'])
 modules={'task_source':PARENT_FILES,'runtime':None,'run_pending':None,'pure_inventory':None,'run_task':None}
 if browser:modules['task_browser']=None
 for name in modules:
  module=sys.modules.get(name)
  if module is None and name=='run_task':module=sys.modules.get('__main__')
  need(module is not None)
  path=Path(getattr(module,'__file__',''))
  need(path.is_absolute()and path.resolve()==root/'tests/governance/private_mail_ci'/f'{name}.py')
  need(hashlib.sha256(read_source(root,str(path.relative_to(root)))).hexdigest()==config['source_sha256'].get(str(path.relative_to(root))))
 return True


def pure_source_files(root):
 """Exact current native unit classes selected by run.php, not arbitrary globs."""
 root=Path(root);raw=read_source(root,'tests/governance/run.php').decode()
 need(raw.count('$classes = array(')==1)
 block=raw.split('$classes = array(',1)[1].split(');',1)[0]
 classes=re.findall(r"'(Phorge[A-Za-z0-9_]+TestCase)'",block)
 need(bool(classes)and len(classes)==len(set(classes)))
 need(not re.sub(r"'(Phorge[A-Za-z0-9_]+TestCase)'",'',block).replace(',','').strip())
 paths=set()
 for name in classes:
  found=list((root/'src').rglob(name+'.php'));need(len(found)==1)
  relative=str(found[0].relative_to(root));read_source(root,relative);paths.add(relative)
 return frozenset(paths)

# All source gate inputs are source roles; generated receipts remain outside Git.
GATE_FILES=frozenset({
 'support/governance/project-content-grants-ci/ci.py',
 'scripts/governance/check_directory_transports_v1.py',
 'scripts/governance/source_operation_snapshot.py',
 'scripts/governance/project_description_admission_delta.py',
 'support/governance/source-operation/registry-v1.json',
 'tests/governance/native-directory-transports-source-v1.json',
 'support/governance/media-session-ci/freshness.py',
 'support/governance/media-session-docker/snapshot.py',
})

def declared_files(root,task,browser):
 """Exact union, not a count or wildcard authorizer."""
 from runtime import SOURCE_FILES
 need(type(task)is frozenset and type(browser)is frozenset)
 return task|browser|SOURCE_FILES|GATE_FILES|pure_source_files(Path(root))

def operation_module(config):
 import importlib,sys
 root=Path(config['source_root'])
 need(root.is_absolute()and root.resolve()==root and root==Path(__file__).resolve().parents[3])
 folder=str(root/'scripts/governance')
 if folder not in sys.path:sys.path.insert(0,folder)
 module=importlib.import_module('source_operation_snapshot')
 need(Path(module.__file__).resolve()==root/'scripts/governance/source_operation_snapshot.py')
 return module

def _native_and_source_cli_gates(config):
 """Real current native/source gates. No shell, fake receipt or skip switch."""
 import importlib.util,sys
 root=Path(config['source_root']);need(root==Path(__file__).resolve().parents[3])
 need(root.is_absolute()and root.resolve()==root)
 revision=config['source_revision'];need(type(revision)is str and re.fullmatch('[a-f0-9]{40}',revision)is not None)
 try:
  ci_path=root/'support/governance/project-content-grants-ci/ci.py'
  read_source(root,str(ci_path.relative_to(root)))
  env={k:os.environ[k]for k in ('HOME','TMPDIR','LANG')if k in os.environ}
  env.update({'PATH':'/usr/bin:/bin','PYTHONDONTWRITEBYTECODE':'1','GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null','GIT_NO_REPLACE_OBJECTS':'1'})
  env.update({'GIT_CONFIG_COUNT':'2','GIT_CONFIG_KEY_0':'core.fsmonitor','GIT_CONFIG_VALUE_0':'false','GIT_CONFIG_KEY_1':'core.hooksPath','GIT_CONFIG_VALUE_1':'/dev/null'})
  program="import importlib.util,json,sys;from pathlib import Path;r=Path(sys.argv[1]);s=importlib.util.spec_from_file_location('owned_task_real_current_source',r/'support/governance/project-content-grants-ci/ci.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m);v=m.current_source(r,sys.argv[2]);print(json.dumps({'source_revision':v,'native_source_checked':True}))"
  native=subprocess.run([sys.executable,'-B','-c',program,str(root),revision],cwd=root,env=env,capture_output=True)
  need(native.returncode==0)
  value=json.loads(native.stdout)
  need(type(value)is dict and set(value)=={'source_revision','native_source_checked'}and value['source_revision']==revision and value['native_source_checked']is True)

  full=subprocess.run([sys.executable,'-B',str(root/'scripts/governance/check_directory_transports_v1.py'),'--source-only','--source-revision',revision],cwd=root,env=env,capture_output=True,timeout=10)
  need(full.returncode==0)
  value=json.loads(full.stdout)
  need(type(value)is dict and set(value)=={'schema','source_revision','selected_inprocess_matrix_accepted','runtime_authority'}and value['schema']=='native-directory-transports-source-only-v1'and value['source_revision']==revision and value['selected_inprocess_matrix_accepted']is False and value['runtime_authority']is False)
 except Exception:
  raise ValueError('task_current_source_admission_refused')from None

from contextlib import contextmanager


def prepared_context(config,operation):
 """Real issued prepared ledger plus freshly authenticated private source modules.

 This is only an adapter for the independent all-byte protected-source witness;
 it never substitutes a ledger or a pin for that witness's complete byte checks.
 No snapshot/freshness module is accepted from the shared module cache.
 """
 import builtins,sys,types
 from types import MappingProxyType
 root=Path(config['source_root']);revision=config['source_revision']
 need(root.is_absolute()and root.resolve()==root and root==Path(__file__).resolve().parents[3])
 need(type(revision)is str and re.fullmatch('[a-f0-9]{40}',revision)is not None)
 need(git(root,'rev-parse','HEAD').decode().strip()==revision)
 old_path=list(sys.path);missing=object();old_snapshot=sys.modules.get('snapshot',missing)
 try:
  module=operation_module(config)
  need(Path(module.__file__).resolve()==root/'scripts/governance/source_operation_snapshot.py')
  module.require_operation(operation,root)
  need(type(operation)is module.SourceOperation)
  h_path='scripts/governance/source_operation_snapshot.py'
  snapshot_path='support/governance/media-session-docker/snapshot.py'
  freshness_path='support/governance/media-session-ci/freshness.py'
  def authenticated(name):
   need(name in (h_path,snapshot_path,freshness_path))
   raw=read_source(root,name)
   need(hashlib.sha256(raw).hexdigest()==config['source_sha256'].get(name))
   row=git(root,'ls-tree','-z',revision,'--',name)
   need(row.count(b'\0')==1)
   head,returned=row[:-1].split(b'\t');mode,kind,oid=head.split()
   need(returned.decode()==name and mode==b'100644'and kind==b'blob'and re.fullmatch(b'[a-f0-9]{40}',oid)is not None)
   need(git(root,'cat-file','blob',oid.decode())==raw)
   need(name in operation._inventory_expected and operation._inventory_expected[name]=='100644')
   if name in (h_path,snapshot_path):need(operation.read_source(root/name)==raw)
   else:need(name==freshness_path) # Actual tracked Git/config source role; not an H captured entry.
   return raw
  # Current H is itself source/config/Git/capture-bound; keep its class identity.
  authenticated(h_path)
  need(type(operation._prepared_ledgers)is dict and root in operation._prepared_ledgers)
  ledger=operation._prepared_ledgers[root]
  need(type(ledger)is module.PreparedLedger and ledger._owner is operation and ledger._root==root)
  layout=ledger._layout
  need(type(layout)is module.PreparedLayout and layout.profile in ('parser','installed','fresh'))
  need(type(layout.source)is MappingProxyType and type(layout.dependency)is MappingProxyType and type(layout.artifact)is MappingProxyType and type(ledger._records)is MappingProxyType)
  need(type(operation._inventory_expected)is MappingProxyType and dict(layout.source)==dict(operation._inventory_expected))
  dependency=dict(layout.dependency);artifact=dict(layout.artifact)
  need(not(set(dependency)&set(artifact)))
  need(all(type(name)is str and role=='parser-source'for name,role in dependency.items()))
  need(all(type(name)is str and role in ('distribution','symbol-cache','observed-binary','compiler-intermediate')for name,role in artifact.items()))
  roles={**dependency,**artifact};extras=frozenset(roles)
  need(bool(extras)and extras==frozenset(ledger._records)and not(extras&frozenset(layout.source)))
  for name,record in ledger._records.items():
   need(type(record)is tuple and len(record)==4 and record[0]==roles[name]and type(record[1])is int and record[1]>0 and type(record[2])is str and re.fullmatch('[a-f0-9]{64}',record[2])is not None and type(record[3])is tuple and len(record[3])==9 and all(type(value)is int for value in record[3]))
  ledger.verify_exit(operation)
  snapshot_raw=authenticated(snapshot_path);freshness_raw=authenticated(freshness_path)
  snapshot=types.ModuleType('owned_task_prepared_snapshot');snapshot.__file__=str(root/snapshot_path)
  exec(compile(snapshot_raw,snapshot.__file__,'exec'),snapshot.__dict__)
  freshness=types.ModuleType('owned_task_prepared_freshness');freshness.__file__=str(root/freshness_path)
  original_import=builtins.__import__
  def private_import(name,globals=None,locals=None,fromlist=(),level=0):
   if name=='snapshot'and level==0:return snapshot
   return original_import(name,globals,locals,fromlist,level)
  private_builtins=dict(vars(builtins));private_builtins['__import__']=private_import
  freshness.__dict__['__builtins__']=private_builtins
  exec(compile(freshness_raw,freshness.__file__,'exec'),freshness.__dict__)
  need(freshness.snapshot is snapshot)
  need(Path(snapshot.__file__)==root/snapshot_path and Path(freshness.__file__)==root/freshness_path)
  # Re-read physical/Git/config/issued-capture roles after executing originals.
  need(authenticated(snapshot_path)==snapshot_raw and authenticated(freshness_path)==freshness_raw)
  module.require_operation(operation,root);ledger.verify_exit(operation)
  need(type(operation)is module.SourceOperation and operation._prepared_ledgers[root]is ledger and ledger._layout is layout)
  return freshness,snapshot,ledger,extras
 finally:
  sys.path[:]=old_path
  # We never insert/replace any shared snapshot entry. The private import
  # hook routes only this original freshness module's exact snapshot import.
  need(sys.modules.get('snapshot',missing)is old_snapshot)


def _source_stamp(info):
 return (info.st_dev,info.st_ino,info.st_uid,info.st_gid,info.st_mode,info.st_nlink,info.st_size,info.st_mtime_ns,info.st_ctime_ns)


def _protected_leaf(root,name,expected):
 """Bounded real-FD observation only; never an admission capability."""
 need(type(name)is str and not Path(name).is_absolute()and '..'not in Path(name).parts)
 path=root/name;parents=[]
 for parent in path.parents:
  info=parent.lstat();need(stat.S_ISDIR(info.st_mode)and not stat.S_ISLNK(info.st_mode))
  parents.append((str(parent),info.st_dev,info.st_ino,info.st_uid,info.st_gid,info.st_mode))
 before=path.lstat();kind,value,mode=expected
 if kind=='symlink':
  need(stat.S_ISLNK(before.st_mode)and stat.S_IMODE(before.st_mode)==mode and os.readlink(path)==value)
  need(_source_stamp(before)==_source_stamp(path.lstat()))
  digest=hashlib.sha256(value.encode()).hexdigest()
 else:
  need(kind=='file'and type(value)is bytes and stat.S_ISREG(before.st_mode))
  need(stat.S_IMODE(before.st_mode)==mode and before.st_nlink==1 and before.st_size==len(value)and len(value)<=32*1024*1024)
  fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
  try:
   need(_source_stamp(before)==_source_stamp(os.fstat(fd)))
   position=0;digest_object=hashlib.sha256()
   while position<len(value):
    raw=os.pread(fd,min(65536,len(value)-position),position)
    need(bool(raw)and raw==value[position:position+len(raw)])
    digest_object.update(raw);position+=len(raw)
   need(os.pread(fd,1,position)==b'')
   need(_source_stamp(before)==_source_stamp(os.fstat(fd))==_source_stamp(path.lstat()))
   digest=digest_object.hexdigest()
  finally:os.close(fd)
 for parent,dev,ino,uid,gid,parent_mode in parents:
  now=Path(parent).lstat()
  need((dev,ino,uid,gid,parent_mode)==(now.st_dev,now.st_ino,now.st_uid,now.st_gid,now.st_mode))
 return (_source_stamp(before),digest,tuple(parents))


def _protected_capture(root,revision,snapshot,freshness,expected,extras):
 need(git(root,'rev-parse','HEAD').decode().strip()==revision)
 need({name:row for name,row in freshness.head_entries(root,revision).items()if freshness.protected(name)}==expected)
 seen=freshness.actual(root)
 protected_extras={name for name in extras if freshness.protected(name)}
 need(not(set(expected)&protected_extras)and set(seen)==set(expected)|protected_extras)
 records={}
 for name,row in expected.items():
  kind,value,mode=row
  need(seen[name][0]==kind and seen[name][2]==mode)
  records[name]=_protected_leaf(root,name,row)
 # Every overlay is independently checked even if excluded from protected BASE.
 need(len(snapshot.OVERLAY)==8)
 for name,pin in snapshot.OVERLAY.items():
  raw=read_source(root,name);need(hashlib.sha256(raw).hexdigest()==pin)
  records[name]=_protected_leaf(root,name,('file',raw,0o644))
 for name in ('support/governance/media-session-docker/snapshot.py','support/governance/media-session-ci/freshness.py'):
  raw=read_source(root,name)
  records[name]=_protected_leaf(root,name,('file',raw,0o644))
 return records


@contextmanager
def _protected_source_witness(config,module,operation):
 """Prepared extras never waive original protected BASE bytes or eight overlays."""
 root=Path(config['source_root']);revision=config['source_revision']
 module.require_operation(operation,root)
 freshness,snapshot,ledger,extras=prepared_context(config,operation)
 base,proof=snapshot.base_entries(root)
 expected={name:row for name,row in base.items()if freshness.protected(name)}
 captured=_protected_capture(root,revision,snapshot,freshness,expected,extras)
 try:
  yield
 finally:
  module.require_operation(operation,root)
  actual_freshness,actual_snapshot,actual_ledger,actual_extras=prepared_context(config,operation)
  need(actual_ledger is ledger and actual_extras==extras)
  need(actual_freshness.__file__==freshness.__file__ and actual_snapshot.__file__==snapshot.__file__)
  snapshot,freshness=actual_snapshot,actual_freshness
  need(_protected_capture(root,revision,snapshot,freshness,expected,extras)==captured)
  ledger.verify_exit(operation)
  module.require_operation(operation,root)


@contextmanager
def admitted_source(config):
 """Only genuine issued operation and full protected bytes span measurement."""
 _native_and_source_cli_gates(config)
 module=operation_module(config)
 with module.open_source_operation(Path(config['source_root']))as operation:
  module.require_operation(operation,Path(config['source_root']))
  with _protected_source_witness(config,module,operation):
   yield operation
 # No successful result is returned until real source/FD/prepared exit verified.
 _native_and_source_cli_gates(config)
