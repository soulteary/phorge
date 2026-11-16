"""Explicit CURRENT live-owner archive component. Not a runtime runner/grant.
No old Git code, issuer substitution, Docker, SQL, receipt acceptance or cleanup.
"""
import hashlib,io,json,os,secrets,stat,sys,tarfile
from pathlib import Path
import source_operation_snapshot as source
SELF='scripts/governance/media_task_archive_v2.py'
MAX=268435456

def need(value):
 if value is not True:raise ValueError('media_Task_archive_v2_refused')
def sha(raw):return hashlib.sha256(raw).hexdigest()
def canonical(value):return json.dumps(value,sort_keys=True,separators=(',',':')).encode()
def name(value):
 need(type(value)is str and value and not value.startswith('/')and '\\'not in value and '\x00'not in value)
 need(len(value.encode('utf-8'))<=4096);parts=value.split('/');need(all(p not in('','.','..')for p in parts)and parts[0]!='.git')
def link(relative,target):
 need(type(target)is str and target and not target.startswith('/')and '\\'not in target and '\x00'not in target)
 need(len(target.encode('utf-8'))<=4096);parts=relative.split('/')[:-1]
 for item in target.split('/'):
  if item=='..':need(bool(parts));parts.pop()
  elif item not in('','.'):parts.append(item)
 need(bool(parts))
def validate_data(rows):
 """Pure archive DATA validation; no caller-provided rows confer source proof."""
 need(type(rows)is dict and 1<=len(rows)<=12000);inventory={};total=0
 for relative,row in sorted(rows.items()):
  name(relative);need(type(row)is tuple and len(row)==3);kind,value,mode=row
  if kind=='file':
   need(type(value)is bytes and type(mode)is int and mode in(0o644,0o755)and len(value)<=16777216);total+=len(value)
   inventory[relative]={'kind':'file','mode':mode,'bytes':len(value),'sha256':sha(value)}
  else:
   need(kind=='symlink'and mode==0o777);link(relative,value);total+=len(value.encode())
   inventory[relative]={'kind':'symlink','target':value}
  need(total<=MAX)
 for relative in rows:
  parents=relative.split('/')[:-1]
  for i in range(1,len(parents)+1):need('/'.join(parents[:i])not in rows)
 # Inspect each raw component BEFORE any following .. can erase it.
 directories={''}
 for relative in rows:
  parts=relative.split('/')
  for i in range(1,len(parts)):directories.add('/'.join(parts[:i]))
 for relative,(kind,target,mode)in rows.items():
  if kind!='symlink':continue
  parts=relative.split('/')[:-1];components=target.split('/')
  for index,item in enumerate(components):
   if item in('','.'):continue
   if item=='..':need(bool(parts));parts.pop();continue
   parts.append(item);current='/'.join(parts)
   need(current not in rows or rows[current][0]!='symlink')
   if index<len(components)-1:need(current in directories)
  final='/'.join(parts);need(final in rows and rows[final][0]=='file')
 return inventory

def stamp(s):
 return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
def tar_to(handle,rows):
 with tarfile.open(fileobj=handle,mode='w|',format=tarfile.PAX_FORMAT)as tar:
  for relative,(kind,value,mode)in sorted(rows.items()):
   entry=tarfile.TarInfo(relative);entry.mode=mode;entry.uid=entry.gid=0;entry.mtime=0
   if kind=='file':entry.size=len(value);tar.addfile(entry,io.BytesIO(value))
   else:entry.type=tarfile.SYMTYPE;entry.linkname=value;tar.addfile(entry)
class DigestSink:
 def __init__(self):self.hash=hashlib.sha256();self.size=0
 def write(self,raw):
  need(self.size+len(raw)<=MAX+12000*4096+10240);self.hash.update(raw);self.size+=len(raw);return len(raw)
 def flush(self):pass

def readback_fd(fd,rows):
 """Independent held read FD, no extraction; exact canonical tar EOF bound."""
 inventory=validate_data(rows);before=os.fstat(fd)
 need(stat.S_ISREG(before.st_mode)and 0<before.st_size<=MAX+12000*4096+10240)
 actual=hashlib.sha256();offset=0
 while offset<before.st_size:
  raw=os.pread(fd,min(65536,before.st_size-offset),offset);need(bool(raw));actual.update(raw);offset+=len(raw)
 need(os.pread(fd,1,offset)==b'')
 expected=DigestSink();tar_to(expected,rows)
 need(expected.size==before.st_size and expected.hash.digest()==actual.digest())
 seen=set()
 with os.fdopen(os.dup(fd),'rb')as handle:
  handle.seek(0)
  with tarfile.open(fileobj=handle,mode='r:')as tar:
   for member in tar:
    relative=member.name;need(relative in rows and relative not in seen);seen.add(relative)
    kind,value,mode=rows[relative]
    need(member.mode==mode and member.uid==member.gid==0 and member.mtime==0 and member.uname==member.gname=='')
    if kind=='file':
     need(member.isfile()and member.size==len(value)and member.linkname=='')
     reader=tar.extractfile(member);need(reader is not None);digest=hashlib.sha256();size=0
     with reader:
      while True:
       chunk=reader.read(65536)
       if not chunk:break
       size+=len(chunk);need(size<=len(value));digest.update(chunk)
     need(size==len(value)and digest.hexdigest()==sha(value))
    else:need(member.issym()and member.linkname==value and member.size==0)
 need(seen==set(rows)and stamp(before)==stamp(os.fstat(fd)))
 return {'archive_sha256':actual.hexdigest(),'archive_bytes':offset,'entries':inventory}

def publish_data(output,rows,checkpoint):
 """Filesystem DATA publication only, not source/runtime authority.
 Failure deliberately retains exact owned partial/final files; no unlink/rmtree.
 Both final names use no-overwrite link publication. A partial pair is failure.
 """
 validate_data(rows);bounded=DigestSink();tar_to(bounded,rows);need(type(checkpoint)is str and len(checkpoint)==40 and all(c in '0123456789abcdef'for c in checkpoint))
 out=Path(output);need(out.is_absolute()and out.resolve()==out)
 before=out.lstat();need(stat.S_ISDIR(before.st_mode)and stat.S_IMODE(before.st_mode)==0o700 and before.st_uid==os.geteuid())
 dfd=os.open(out,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW);fds=[]
 def directory():need(stamp(os.fstat(dfd))[:5]==stamp(before)[:5]and stamp(out.lstat())[:5]==stamp(before)[:5])
 def create(relative):
  directory();fd=os.open(relative,os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=dfd);fds.append(fd);os.fchmod(fd,0o600);need(stat.S_IMODE(os.fstat(fd).st_mode)==0o600 and os.fstat(fd).st_uid==os.geteuid()and os.fstat(fd).st_nlink==1);return fd
 def publish(temp,final,fd):
  directory();need(stamp(os.stat(temp,dir_fd=dfd,follow_symlinks=False))==stamp(os.fstat(fd)))
  os.link(temp,final,src_dir_fd=dfd,dst_dir_fd=dfd,follow_symlinks=False);os.fsync(dfd)
  need(os.stat(final,dir_fd=dfd,follow_symlinks=False).st_ino==os.fstat(fd).st_ino)
 try:
  directory();need(not os.listdir(dfd));nonce=secrets.token_hex(16)
  temporary='archive-'+nonce+'.pending';afd=create(temporary)
  with os.fdopen(os.dup(afd),'wb')as handle:tar_to(handle,rows);handle.flush();os.fsync(handle.fileno())
  # Open a distinct file description under the held directory, never a pathname-only read.
  rfd=os.open(temporary,os.O_RDONLY|os.O_NOFOLLOW,dir_fd=dfd);fds.append(rfd)
  need(stamp(os.fstat(afd))==stamp(os.fstat(rfd)))
  proof=readback_fd(rfd,rows);need(stamp(os.fstat(afd))==stamp(os.fstat(rfd)))
  manifest={'schema':'media-Task-live-source-archive-v2','actual_checkpoint':checkpoint,**proof,'leaf_count':len(rows),'source_operation_exit_verified':False,'runtime_authority':False,'runtime_SQL_qualified':False,'cleanup_proved':False}
  mtemp='inventory-'+nonce+'.pending';mfd=create(mtemp);raw=canonical(manifest)+b'\n';offset=0
  while offset<len(raw):written=os.write(mfd,raw[offset:]);need(written>0);offset+=written
  os.fsync(mfd);need(os.pread(mfd,len(raw)+1,0)==raw)
  publish(temporary,'current-source.tar',afd);publish(mtemp,'current-source-inventory.private.json',mfd)
  directory();need(readback_fd(rfd,rows)==proof)
  need(set(os.listdir(dfd))=={temporary,mtemp,'current-source.tar','current-source-inventory.private.json'})
  for final,temp,held in [('current-source.tar',temporary,afd),('current-source-inventory.private.json',mtemp,mfd)]:
   current=os.fstat(held);need(stat.S_IMODE(current.st_mode)==0o600 and current.st_uid==os.geteuid()and current.st_nlink==2)
   need(stamp(os.stat(final,dir_fd=dfd,follow_symlinks=False))==stamp(current)==stamp(os.stat(temp,dir_fd=dfd,follow_symlinks=False)))
  ifd=os.open('current-source-inventory.private.json',os.O_RDONLY|os.O_NOFOLLOW,dir_fd=dfd);fds.append(ifd)
  need(stamp(os.fstat(ifd))==stamp(os.fstat(mfd))and os.pread(ifd,len(raw)+1,0)==raw)
  # Final boundary AFTER all independent readback: named files still equal held FDs.
  directory();need(set(os.listdir(dfd))=={temporary,mtemp,'current-source.tar','current-source-inventory.private.json'})
  for final,temp,held in [('current-source.tar',temporary,afd),('current-source-inventory.private.json',mtemp,mfd)]:
   current=os.fstat(held);need(stat.S_IMODE(current.st_mode)==0o600 and current.st_uid==os.geteuid()and current.st_nlink==2)
   need(stamp(os.stat(final,dir_fd=dfd,follow_symlinks=False))==stamp(current)==stamp(os.stat(temp,dir_fd=dfd,follow_symlinks=False)))
  need(stamp(os.fstat(ifd))==stamp(os.fstat(mfd)))
  return out/'current-source.tar',manifest
 finally:
  # Attempt every descriptor close. Propagate close failures, never mark cleanup.
  errors=[]
  for fd in fds+[dfd]:
   try:os.close(fd)
   except OSError as error:errors.append(error)
  if errors:
   primary=sys.exc_info()[1]
   raise BaseExceptionGroup('archive_primary_and_close_failures',([primary]if primary is not None else[])+errors)

def build_current(operation,root,output):
 root=Path(root);out=Path(output)
 source.require_operation(operation,root);source.require_task_operation(operation,root)
 need(operation.code_root==root and operation.producer_root==root and source.ROOT==root)
 context=operation._task_contexts[root];need(context.operation is operation)
 need(context.current(SELF)==Path(__file__).read_bytes())
 need(out.is_absolute()and out.resolve()==out and out!=root and not out.is_relative_to(root)and not root.is_relative_to(out))
 rows={}
 for relative,pin in context.entries.items():
  if pin['kind']=='file':rows[relative]=('file',context.current(relative),pin['mode'])
  else:
   need(pin['kind']=='symlink');path=root/relative
   need(source.task_admission.stamp(path.lstat())==context.files[relative]and os.readlink(path)==pin['target'])
   rows[relative]=('symlink',pin['target'],0o777)
 validate_data(rows);source.require_task_operation(operation,root)
 result=publish_data(out,rows,operation._task_expected_checkpoint)
 for relative,pin in context.entries.items():
  if pin['kind']=='file':need(context.current(relative)==rows[relative][1])
  else:need(os.readlink(root/relative)==rows[relative][1]and source.task_admission.stamp((root/relative).lstat())==context.files[relative])
 source.require_task_operation(operation,root)
 return result
