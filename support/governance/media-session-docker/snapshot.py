"""Public Git tree plus exact reviewed test overlay; never archives a worktree."""
import hashlib,io,json,os,posixpath,re,stat,subprocess,tarfile
from pathlib import Path
BASE='68921e0ffda220630327dca954923643ba98eb6d'
OVERLAY={'support/governance/media-session-positive/README.md': 'a3cc5c39b8e327085490621a1d0dc167d27c9e9c7e96dcb435953653bdc6938a', 'support/governance/media-session-positive/cases.php': 'ec93f85e5827b281e0605556f423d0837b44c5b467eed4223fb3075fb7203708', 'support/governance/media-session-positive/diagnostic.php': '7248c628ff94caabba218ae02762842311aa204d6c7792e049f17f6499f84f55', 'support/governance/media-session-positive/profile.php': '50db5b55613f03b3297bfc780301da485ba896410237e82c1b0475b6ba79d83d', 'support/governance/media-session-positive/run.php': 'bd3504a2212eaedafae27bbde8f6ca824086f8573d0cec95871af35a3f012f81', 'support/governance/media-session-positive/source-pins.json': '5d708748e4bf47af83ae97edac0fb9f37ac89d41ce11b1d7d371fb595e24d222', 'support/governance/media-session-positive/test-diagnostic.php': '0f308b02a47f1d4c56faf71e1557036014a781ac5341eae30c8785c8a8a9865e', 'support/governance/media-session-positive/test-guards.php': 'dbe79fe6b78beed08b47cb339b2248ad45cc0fde70ecd6faa454d57f1a522e2e'}
def need(x):
 if not x:raise ValueError('source snapshot refused')
def sha(b):return hashlib.sha256(b).hexdigest()
def canonical(v):return json.dumps(v,sort_keys=True,separators=(',',':')).encode()
def validate_name(n):
 need(type(n)is str and n and not n.startswith('/')and all(c not in ('','.','..')for c in n.rstrip('/').split('/')))
 need(n.split('/')[0]!='.git'and (not n.startswith('conf/local/')or n=='conf/local/README'))
def validate_link(n,t):
 need(type(t)is str and t and not t.startswith('/'))
 dest=posixpath.normpath(posixpath.join(posixpath.dirname(n),t));need(dest!='..'and not dest.startswith('../'))
ROOT_TREE='e24988d7d69fb18cea67d5daad5a653ad827e0c2'
def git_environment():
 # No caller GIT_* selectors, global/system config or attributes are inherited.
 return {'PATH':os.defpath,'GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_SYSTEM':'/dev/null','GIT_CONFIG_GLOBAL':'/dev/null','GIT_ATTR_NOSYSTEM':'1','GIT_NO_REPLACE_OBJECTS':'1','GIT_CONFIG_COUNT':'0','GIT_NO_LAZY_FETCH':'1','GIT_TERMINAL_PROMPT':'0'}
def objects(repo,ids):
 ids=sorted(set(ids));need(ids and all(re.fullmatch('[0-9a-f]{40}',h)for h in ids))
 raw=subprocess.run(['git','--no-replace-objects','-c','protocol.allow=never','-c','core.hooksPath=/dev/null','-C',str(repo),'cat-file','--batch'],input=('\n'.join(ids)+'\n').encode(),capture_output=True,check=True,timeout=30,env=git_environment()).stdout
 need(len(raw)<=128*1024*1024);offset=0;result={}
 for expected in ids:
  end=raw.find(b'\n',offset);need(end>=0);parts=raw[offset:end].split(b' ');need(len(parts)==3)
  h,kind,size=parts;need(h.decode()==expected and kind in (b'commit',b'tree',b'blob')and re.fullmatch(b'[0-9]+',size))
  size=int(size);need(size<=32*1024*1024);offset=end+1;body=raw[offset:offset+size];need(len(body)==size and raw[offset+size:offset+size+1]==b'\n');offset+=size+1
  need(hashlib.sha1(kind+b' '+str(size).encode()+b'\0'+body).hexdigest()==expected);result[expected]=(kind.decode(),body)
 need(offset==len(raw));return result
def base_entries(repo):
 kind,commit=objects(repo,[BASE])[BASE];need(kind=='commit'and commit.startswith(('tree '+ROOT_TREE+'\n').encode()))
 pending=[('',ROOT_TREE)];leaves={};seen=set();tree_count=0
 for depth in range(65):
  if not pending:break
  need(depth<64);batch=objects(repo,[h for _,h in pending]);next_level=[]
  for prefix,h in pending:
   kind,body=batch[h];need(kind=='tree');tree_count+=1;need(tree_count<=4096);i=0;names=set()
   while i<len(body):
    space=body.find(b' ',i);zero=body.find(b'\0',space+1);need(space>i and zero>space);mode=body[i:space];name=body[space+1:zero].decode('utf-8');oid=body[zero+1:zero+21];need(len(oid)==20);i=zero+21
    need('/'not in name and name not in names);names.add(name);n=prefix+name;validate_name(n);h=oid.hex()
    if mode==b'40000':next_level.append((n+'/',h))
    else:
     need(mode in (b'100644',b'100755',b'120000')and n not in leaves);leaves[n]=(mode,h);need(len(leaves)<=12000)
  pending=next_level
 need(not pending);blobs=objects(repo,[h for _,h in leaves.values()]);entries={};total=0
 for n,(mode,h)in leaves.items():
  kind,b=blobs[h];need(kind=='blob');total+=len(b);need(total<=128*1024*1024)
  if mode==b'120000':target=b.decode('utf-8');validate_link(n,target);entries[n]=('symlink',target,0o777)
  else:entries[n]=('file',b,0o755 if mode==b'100755'else 0o644)
 return entries,{'commit':BASE,'root_tree':ROOT_TREE,'verified_tree_objects':tree_count,'verified_blob_objects':len(blobs),'git_object_hashes_verified':True,'replace_objects_disabled':True,'attributes_not_used':True}
def build(repo,output):
 repo=Path(repo);output=Path(output)
 entries,provenance=base_entries(repo)
 for n,pin in OVERLAY.items():
  validate_name(n);p=repo/n;a=os.lstat(p);need(stat.S_ISREG(a.st_mode)and a.st_nlink==1)
  fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
  try:
   b=os.read(fd,1024*1024+1);z=os.fstat(fd);need(len(b)<=1024*1024 and len(b)==a.st_size and sha(b)==pin)
   need((a.st_dev,a.st_ino,a.st_mode,a.st_size,a.st_mtime_ns,a.st_ctime_ns)==(z.st_dev,z.st_ino,z.st_mode,z.st_size,z.st_mtime_ns,z.st_ctime_ns))
   q=os.lstat(p);need((q.st_dev,q.st_ino,q.st_mode,q.st_size,q.st_mtime_ns,q.st_ctime_ns)==(z.st_dev,z.st_ino,z.st_mode,z.st_size,z.st_mtime_ns,z.st_ctime_ns))
  finally:os.close(fd)
  entries[n]=('file',b,0o644)
 inventory={n:({'kind':'file','sha256':sha(v),'size':len(v),'mode':m}if k=='file'else{'kind':'symlink','target':v})for n,(k,v,m)in sorted(entries.items())}
 archive=output/'public-source.tar'
 with archive.open('xb')as out:
  os.chmod(archive,0o600)
  with tarfile.open(fileobj=out,mode='w')as t:
   for n,(kind,v,mode)in sorted(entries.items()):
    e=tarfile.TarInfo(n);e.mode=mode;e.uid=e.gid=0;e.mtime=0
    if kind=='symlink':e.type=tarfile.SYMTYPE;e.linkname=v;t.addfile(e)
    else:e.size=len(v);t.addfile(e,io.BytesIO(v))
  out.flush();os.fsync(out.fileno())
 manifest={'schema':'media-positive-public-source-v1','base':BASE,'base_object_provenance':provenance,'overlay':OVERLAY,'entries':inventory,'archive_sha256':sha(archive.read_bytes())}
 (output/'public-source-inventory.json').write_bytes(canonical(manifest)+b'\n');os.chmod(output/'public-source-inventory.json',0o600)
 return archive,manifest
