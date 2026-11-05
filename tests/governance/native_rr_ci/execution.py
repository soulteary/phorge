"""Fixed disposable MySQL/RR21 execution and source/receipt checks; no arbitrary shell."""
import hashlib,io,os,posixpath,re,subprocess,tarfile,importlib.util,json,sys
from pathlib import Path
from provenance import decode,digest
MYSQL_IMAGE='mysql:8.4.13'
MYSQL_PASSWORD='native-rr-ci-disposable-only'
def exact(value,wanted):
 if type(value)is not type(wanted):raise ValueError('typed evidence mismatch')
 if type(wanted)is dict:
  if set(value)!=set(wanted):raise ValueError('evidence fields mismatch')
  for k,v in wanted.items():exact(value[k],v)
 elif type(wanted)is list:
  if len(value)!=len(wanted):raise ValueError('evidence length mismatch')
  for a,b in zip(value,wanted):exact(a,b)
 elif value!=wanted:raise ValueError('evidence value mismatch')
def service_identity(rows,container):
 if type(container)is not str or not re.fullmatch('[a-f0-9]{64}',container)or type(rows)is not list or len(rows)!=1:raise ValueError('actual isolated service identity required')
 row=rows[0]
 if type(row)is not dict or any(type(row.get(k))is not dict for k in ('Config','State','HostConfig')):raise ValueError('actual service shape refused')
 if row.get('Id')!=container or row.get('Config',{}).get('Image')!=MYSQL_IMAGE or row.get('State',{}).get('Running')is not True:raise ValueError('actual service not running expected image')
 host=row.get('HostConfig',{})
 if host.get('Privileged')is not False or host.get('PidMode')!=''or host.get('IpcMode')!='private'or host.get('Binds')not in (None,[]):raise ValueError('service isolation profile refused')
 if type(host.get('NetworkMode'))is not str or host['NetworkMode']in ('','host','none'):raise ValueError('isolated service network required')
 exact(host.get('PortBindings'),{'3306/tcp':[{'HostIp':'127.0.0.1','HostPort':'3306'}]})
 if type(row.get('Image'))is not str or not re.fullmatch('sha256:[a-f0-9]{64}',row['Image']):raise ValueError('actual image digest required')
 return {'image':MYSQL_IMAGE,'actual_image_id':row['Image'],'loopback_only':True,'port':3306,'running':True}
def extract_current_archive(archive,root):
 # A narrow Git-archive data extractor: no device/hardlink/sparse/chown paths,
 # no parent aliases, and only direct internal regular-file symlinks.
 with tarfile.open(fileobj=io.BytesIO(archive))as tar:
  rows=tar.getmembers();members={m.name:m for m in rows}
  if len(rows)>20000 or len(rows)!=len(members)or any(type(m.size)is not int or m.size<0 for m in rows)or sum(m.size for m in rows)>268435456:raise ValueError('bounded unique source archive required')
  for m in rows:
   name=m.name
   if not name or posixpath.isabs(name)or posixpath.normpath(name)!=name or '..'in name.split('/')or not(m.isdir()or m.isfile()or m.issym())or m.issparse():raise ValueError('source archive path/type refused')
   parent=posixpath.dirname(name)
   while parent:
    if parent in members and not members[parent].isdir():raise ValueError('source archive parent alias refused')
    parent=posixpath.dirname(parent)
   if m.issym():
    target=posixpath.normpath(posixpath.join(posixpath.dirname(name),m.linkname))
    if posixpath.isabs(m.linkname)or target not in members or not members[target].isfile():raise ValueError('source archive alias refused')
  for m in rows:
   target=root/m.name;target.parent.mkdir(parents=True,exist_ok=True)
   if m.isdir():target.mkdir(exist_ok=True)
   elif m.isfile():
    fd=os.open(target,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'wb')as output:
     raw=tar.extractfile(m).read()
     if len(raw)!=m.size:raise ValueError('source archive bytes incomplete')
     output.write(raw);os.fchmod(output.fileno(),0o755 if m.mode&0o111 else 0o644)
  for m in rows:
   if m.issym():os.symlink(m.linkname,root/m.name)
def source_manifest(archive):
 result={};aliases={}
 with tarfile.open(fileobj=io.BytesIO(archive))as tar:
  members={m.name:m for m in tar.getmembers()}
  if len(members)!=len(tar.getmembers()):raise ValueError('duplicate archive member')
  for name,m in members.items():
   if not(name.endswith(('.php','.sql'))or name.startswith('resources/governance/publicsuffix/')):continue
   target=m
   if m.issym():
    resolved=posixpath.normpath(posixpath.join(posixpath.dirname(name),m.linkname))
    if posixpath.isabs(m.linkname)or resolved=='..'or resolved.startswith('../')or resolved not in members or not members[resolved].isfile():raise ValueError('signed alias is not direct internal regular file')
    aliases[name]=m.linkname;target=members[resolved]
   if target.isfile():result[name]=digest(tar.extractfile(target).read())
 return result,aliases
def whole_runtime(root,expected,aliases):
 actual={}
 for p in root.rglob('*'):
  name=str(p.relative_to(root))
  if not(name.endswith(('.php','.sql'))or name.startswith('resources/governance/publicsuffix/'))or p.is_dir():continue
  if p.is_symlink()and(name not in aliases or os.readlink(p)!=aliases[name]):raise ValueError('unknown runtime source alias')
  if not p.is_file():raise ValueError('unknown runtime source object')
  actual[name]=digest(p.read_bytes())
 exact(actual,expected)
 for path,target in aliases.items():
  p=root/path
  if not p.is_symlink()or os.readlink(p)!=target:raise ValueError('actual signed alias changed')
 return {'passed':True,'expected_files':len(expected),'actual_files':len(actual),'missing':[],'extra':[],'changed':[],'signed_aliases_verified':len(aliases),'actual_sha256':actual,'signed_alias_targets':aliases}
def pure_inventory(root):
 text=(root/'tests/governance/run.php').read_text();block=text.split('$classes = array(',1)[1].split(');',1)[0];classes=re.findall(r"'(Phorge[A-Za-z0-9_]+TestCase)'",block);ids=set()
 if len(classes)!=len(set(classes))or len(classes)!=46:raise ValueError('current pure classes refused')
 for cls in classes:
  paths=list((root/'src').rglob(cls+'.php'))
  if len(paths)!=1:raise ValueError('pure class source ambiguous')
  methods=re.findall(r'public\s+function\s+(test[A-Za-z0-9_]+)\s*\(',paths[0].read_text())
  if len(methods)!=len(set(methods)):raise ValueError('pure method duplicate')
  ids.update(cls+'::'+method for method in methods)
 if len(ids)!=271:raise ValueError('current pure IDs refused')
 return ids
def validate_pure(data,ids):
 if data.get('passed')is not True or data.get('runtime')!='8.5.12'or type(data.get('expected_methods'))is not int or data['expected_methods']!=271:raise ValueError('actual pure outcome refused')
 results=data.get('results')
 if type(results)is not list or len(results)!=271 or len({r['id']for r in results})!=271 or {r['id']for r in results}!=ids or any(r.get('result')!='pass'for r in results):raise ValueError('actual pure IDs refused')
 return {'passed':True,'methods':271,'classes':46,'new_executions':1}
def cleanup_proved(receipt,revision):
 try:
  exact(receipt['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True})
  exact(receipt['worker_cleanup'],{'private_directories_removed':0,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':0})
  exact(receipt['cleanup_failures'],[])
  native=receipt['native']
  exact(native['profile'],'isolated-same-native-User-writer-RR-MDL-v1');exact(native['source_revision'],revision)
  exact(native['local_children'],2)
  child={'owned_processes_stopped':True,'owned_SQL_sessions_ended':True,'private_directory_removed':True,'cleanup_proved':True,'pidfd_remaining':0}
  exact(native['local_cleanup'],[child,child])
  exact(native['final_owned_cleanup'],{'cleanup_proved':True,'local_children_registered':2,'failures':[],'revoker_closed':True,'principal_cleanup':{'observer_removed':True,'owned_observer_sessions_absent':True,'cleanup_proved':True}})
 except (KeyError,ValueError,TypeError):return False
 return True

def strict_accept(root,receipt,revision,receipt_path):
 scripts=root/'scripts/governance';sys.path.insert(0,str(scripts));spec=importlib.util.spec_from_file_location('actual_portable_rr21_gate',scripts/'check_native_user_rr_mdl.py');gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
 expected=gate.validate(receipt,root=root,source_revision=revision)
 cli=subprocess.run([sys.executable,'-B',str(scripts/'check_native_user_rr_mdl.py'),'--receipt',str(receipt_path),'--source-revision',revision],capture_output=True)
 if cli.returncode!=0 or cli.stderr!=b''or decode(cli.stdout)!=expected:raise ValueError('actual CLI/inprocess strict acceptance mismatch')
 return expected,cli.stdout
