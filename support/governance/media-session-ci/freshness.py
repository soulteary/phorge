"""Fail closed if actual protected checkout differs from the pinned base graph."""
import hashlib,json,os,re,stat,subprocess,sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'media-session-docker'))
import snapshot
PREFIXES=('src','resources','support/runtime','support/governance','conf','webroot','externals')
EXCEPT=('support/governance/media-session-positive','support/governance/media-session-docker','support/governance/media-session-ci')
def protected(n):return any(n==p or n.startswith(p+'/')for p in PREFIXES)and not any(n==p or n.startswith(p+'/')for p in EXCEPT)
def head_entries(root,revision):
 kind,commit=snapshot.objects(root,[revision])[revision]
 first=commit.split(b'\n',1)[0]
 if kind!='commit'or not re.fullmatch(b'tree [a-f0-9]{40}',first):raise ValueError('current commit object')
 pending=[('',first[5:].decode())];leaves={};count=0
 for depth in range(65):
  if not pending:break
  if depth>=64:raise ValueError('current tree depth')
  objects=snapshot.objects(root,[h for _,h in pending]);next_level=[]
  for prefix,h in pending:
   kind,body=objects[h];count+=1
   if kind!='tree'or count>4096:raise ValueError('current tree object')
   i=0;names=set()
   while i<len(body):
    space=body.find(b' ',i);zero=body.find(b'\0',space+1)
    if space<=i or zero<=space or zero+21>len(body):raise ValueError('current tree format')
    mode=body[i:space];name=body[space+1:zero].decode();oid=body[zero+1:zero+21].hex();i=zero+21
    if '/'in name or name in ('','.','..')or name in names:raise ValueError('current tree name')
    names.add(name);n=prefix+name
    if mode==b'40000':next_level.append((n+'/',oid))
    else:
     if mode not in(b'100644',b'100755',b'120000')or n in leaves:raise ValueError('current tree mode')
     leaves[n]=(mode,oid)
     if len(leaves)>12000:raise ValueError('current tree bound')
  pending=next_level
 if pending:raise ValueError('current tree incomplete')
 blobs=snapshot.objects(root,[h for _,h in leaves.values()]);result={};total=0
 for n,(mode,h)in leaves.items():
  kind,raw=blobs[h];total+=len(raw)
  if kind!='blob'or total>128*1024*1024:raise ValueError('current blob closure')
  result[n]=('symlink',raw.decode(),0o777)if mode==b'120000'else('file',raw,0o755 if mode==b'100755'else 0o644)
 return result
def actual(root):
 found={}
 for prefix in PREFIXES:
  start=root/prefix
  if not start.exists()and not start.is_symlink():continue
  if start.resolve(strict=True)!=start or not start.is_dir()or start.is_symlink():raise ValueError('protected prefix alias')
  for base,dirs,files in os.walk(start,followlinks=False):
   for name in list(dirs)+files:
    path=Path(base)/name;n=path.relative_to(root).as_posix()
    if not protected(n):
     if name in dirs:dirs.remove(name)
     continue
    s=os.lstat(path)
    if stat.S_ISLNK(s.st_mode):found[n]=('symlink',os.readlink(path),0o777)
    elif stat.S_ISREG(s.st_mode):found[n]=('file',None,s.st_mode&0o777)
    elif not stat.S_ISDIR(s.st_mode):raise ValueError('protected special file')
 return found
def verify(root):
 root=Path(root)
 if not root.is_absolute()or root.resolve(strict=True)!=root:raise ValueError('source root alias')
 base,proof=snapshot.base_entries(root);expected={n:v for n,v in base.items()if protected(n)}
 head=subprocess.run(['git','--no-replace-objects','-C',str(root),'rev-parse','--verify','HEAD'],env=snapshot.git_environment(),check=True,capture_output=True,text=True,timeout=10).stdout.strip()
 current={n:v for n,v in head_entries(root,head).items()if protected(n)}
 if current!=expected:raise ValueError('committed protected source changed; rebase required')
 seen=actual(root)
 if set(seen)!=set(expected):raise ValueError('protected inventory changed; rebase required')
 for n,(kind,value,mode)in expected.items():
  if seen[n][0]!=kind:raise ValueError('protected type changed')
  if kind=='symlink':
   if seen[n][1]!=value:raise ValueError('protected link changed')
  else:
   p=root/n;a=os.lstat(p);fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
   try:
    out=bytearray()
    while len(out)<a.st_size:
     b=os.pread(fd,min(65536,a.st_size-len(out)),len(out))
     if not b:raise ValueError('short protected source')
     out.extend(b)
    z=os.fstat(fd);q=os.lstat(p)
    fields=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
    if fields(a)!=fields(z)or fields(z)!=fields(q)or os.pread(fd,1,a.st_size)!=b''or bytes(out)!=value or seen[n][2]!=mode:raise ValueError('protected source changed')
   finally:os.close(fd)
 # The actual runtime overlay must also be precisely the reviewed eight files.
 for n,pin in snapshot.OVERLAY.items():
  p=root/n;s=os.lstat(p)
  if not stat.S_ISREG(s.st_mode)or s.st_nlink!=1 or hashlib.sha256(p.read_bytes()).hexdigest()!=pin:raise ValueError('fixture overlay changed')
 return {'schema':'media-positive-protected-source-freshness-v1','protected_source_equal':True,'base_revision':snapshot.BASE,'base_tree':snapshot.ROOT_TREE,'current_revision':head,'protected_leaf_count':len(expected),'reviewed_overlay_count':len(snapshot.OVERLAY),'runtime_diff_allowed':False,'docs_reports_are_not_runtime_proof':True}
if __name__=='__main__':
 try:print(json.dumps(verify(Path(__file__).resolve().parents[3]),sort_keys=True))
 except Exception:print('{"schema":"media-positive-protected-source-freshness-v1","protected_source_equal":false,"rebase_required":true}');raise SystemExit(1)
