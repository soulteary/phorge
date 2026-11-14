"""Root-signed actual Task source stage for the real issued SourceOperation.
No arbitrary reader callbacks, executable historical modules, DB or runtime grant.
"""
import base64,hashlib,json,os,re,stat,subprocess,zlib
from pathlib import Path
from types import MappingProxyType
ROOT_SIGNER='CD85ED7465AFF2FFA2FFB686DBCDD8CBF440F8DE'
BASE='356878fb64e33f62967e36eeff926abba285ff25'
BASELINE='support/governance/source-operation/task-parent5903.json'
BASELINE_SHA='e9b0860ac3d4e8fb835b18fdee07e60b20b373713a11ddfb2ac1d5a24f2d7ac0'
INVERSE='support/governance/source-operation/task-api-parent-inverse.json'
INVERSE_SHA='4af284a3da68786d2508826f69aeb50d85b7080ca7af81073b34e657473440ce'
TASK_NEW_PINS={'resources/sql/autopatches/20261112.task.contentgrants.sql': {'bytes': 1553, 'sha256': '632b4412c18ea61787ca6b38afc9d3c16b62f76591e9a99d624401eeac117236'}, 'scripts/governance/task_content_grant_source_delta.py': {'bytes': 13894, 'sha256': '25ff589d4e9a78127066ceee80ccf12830513cc96e445a2a9d7270f5e980f0b6'}, 'src/infrastructure/governance/content/PhorgeTaskContentApprovalBinding.php': {'bytes': 1017, 'sha256': 'fb5c45796754222fb0e0c608ae16ac7c3de29ee463ddc149025b7f8e910c0e8e'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantCommitProof.php': {'bytes': 1870, 'sha256': '47fa65e73361a8a17f1f428ffdc3fa402d9263a290a17c14b99902f381f3fc48'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantConfigOptions.php': {'bytes': 867, 'sha256': 'c55503b9deb2cd2077a9dcf7b08c1dea881de97c880c33c369643363f9d85e94'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantConfiguration.php': {'bytes': 794, 'sha256': '6bd5868f4b86d5918b000adea0af1447d641018aed6ad036b5a444d493c6b1d4'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantPlan.php': {'bytes': 3687, 'sha256': '5274f30a211ab36ea59a2c2c0897792c61b3e2551606df11f8a2e94df83ce158'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantRequest.php': {'bytes': 2619, 'sha256': 'b526bfc0665178a16e15bfbca09206c89b6f5a4f67041713aa8a5caf02b5b480'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantSchemaSpec.php': {'bytes': 1782, 'sha256': '7cac65fe600e2681eed05618fd584a02f37e27b7f532d6ca2b59244bf5045ccb'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantService.php': {'bytes': 1807, 'sha256': '45b2f14300ae6b308ee33ec088aa732e14fe9396fbf8f4bf4dd7e1fafd116eef'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantStore.php': {'bytes': 10327, 'sha256': '07a1d9533c5b0bc23bb0cd147c57706b3e0863fe47ecc647628fe965e1d8d85d'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantValue.php': {'bytes': 1845, 'sha256': '4b9fb818abd4126dbd184f2876606fef5751c916304434cdcebf6616ca180cb6'}, 'src/infrastructure/governance/content/PhorgeTaskContentGrantWriteScope.php': {'bytes': 29444, 'sha256': 'da48c7aa59cc9d1c2be7df2ac39230a6757e992ee8956b781c3e245f6a294374'}, 'src/infrastructure/governance/content/PhorgeTaskContentSource.php': {'bytes': 5155, 'sha256': 'd71672d278a8a3ac2d2a5f3e0f13ea44d49e4c7daffc6106125a0c846298509d'}, 'src/infrastructure/governance/content/README.task-durable-grants.md': {'bytes': 4184, 'sha256': '6fc6078b52c4d4115d7f96a329afd6039026913c6171140842205e09649783c2'}, 'src/infrastructure/governance/content/task-grant-migration-source.json': {'bytes': 541, 'sha256': '675eb678ae558bbaf0c2d9707bd7ffcc9753c4dd4ee650636b05576b3347d02d'}, 'src/infrastructure/governance/execution/PhorgeTaskGrantExecutionConfigSource.php': {'bytes': 10783, 'sha256': '8301a70ee12b6a2707d624806983ba24ede834f9881869fd656f772056f13f7f'}, 'support/governance/task-content-grants-qualification/README.md': {'bytes': 5886, 'sha256': '26fe1bec2fc31b8cbe81bdd6852f61fbeba697fe6643cb102793316c91fad198'}, 'support/governance/task-content-grants-qualification/native.php': {'bytes': 17537, 'sha256': 'c36d890bba1b192955b154620c3723394547146d47be8942d7ecc4640cd52e66'}, 'support/governance/task-content-grants-qualification/qualify.py': {'bytes': 8475, 'sha256': '322e4471a14f6b80d26bf461f8e81e5a470f988e07631b8db76362fa4428d384'}, 'support/governance/task-content-grants-qualification/source_gate.py': {'bytes': 6985, 'sha256': '6ceeb0d88e5cbf999e500ff8c8c3d0fb79e3539f06a8af2b3197c49025263476'}, 'tests/governance/test_task_current_source_admission.py': {'bytes': 17736, 'sha256': '2de9e287431540fcf375b9a900a152e3d8bfa9c75738cfc74bf68d3204238154'}, 'tests/governance/test_task_durable_grant_lifecycle.py': {'bytes': 10029, 'sha256': '2fd99c724f3a5d5d5032a9fcd4562edb683c78661a7c7dd27d7409209c0755d1'}, 'tests/governance/test_task_durable_grant_source.py': {'bytes': 11978, 'sha256': 'bafa91f4dbd2ad1d20b18ac08deef409880b0ecffc5089434f374846cdde7b4f'}}
V2_PATHS={'scripts/governance/check_directory_transports_task_source_v2.py','scripts/governance/check_media_freshness_task_source_v2.py','tests/governance/test_task_source_consumers_v2.py'}
MAX=268435456

def need(v):
 if v is not True:raise ValueError('task_SourceOperation_admission_refused')
def sha(raw):return hashlib.sha256(raw).hexdigest()
def stamp(s):return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
def read(path):
 a=path.lstat();need(stat.S_ISREG(a.st_mode)and a.st_uid==os.getuid()and a.st_nlink==1 and not a.st_mode&0o022 and a.st_size<=16777216)
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  raw=bytearray()
  while len(raw)<a.st_size:
   b=os.read(fd,min(65536,a.st_size-len(raw)));need(bool(b));raw.extend(b)
  need(os.read(fd,1)==b''and stamp(a)==stamp(os.fstat(fd))==stamp(path.lstat()));return bytes(raw),stamp(a)
 finally:os.close(fd)
def decode(raw):
 def pairs(items):
  d={}
  for k,v in items:need(k not in d);d[k]=v
  return d
 return json.loads(raw,object_pairs_hook=pairs,parse_constant=lambda _:need(False))
def git_tree(root,expected_checkpoint):
 need(type(expected_checkpoint)is str and re.fullmatch('[a-f0-9]{40}',expected_checkpoint)is not None)
 env={'PATH':'/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin','HOME':str(Path.home()),'LANG':'C','GIT_NO_REPLACE_OBJECTS':'1','GIT_OPTIONAL_LOCKS':'0'}
 def git(*args,**kw):return subprocess.run(['git','-C',str(root),*args],env=env,stdin=None if'input'in kw else subprocess.DEVNULL,capture_output=True,timeout=30,check=True,**kw)
 revision=git('rev-parse','HEAD').stdout.decode().strip();need(revision==expected_checkpoint)
 verified=git('verify-commit','--raw',revision)
 need(re.search(rb'^\[GNUPG:\] (?:BADSIG|ERRSIG|EXPSIG|EXPKEYSIG|REVKEYSIG|KEYREVOKED|SIGEXPIRED|KEYEXPIRED)(?: |$)',verified.stderr,re.M)is None)
 need(re.findall(rb'^\[GNUPG:\] VALIDSIG ([A-F0-9]{40}) ',verified.stderr,re.M)==[ROOT_SIGNER.encode()])
 git('merge-base','--is-ancestor',BASE,revision);git('diff','--quiet',revision,'--');git('diff','--cached','--quiet',revision,'--')
 rows=[];names=set()
 for item in git('ls-tree','-rz','--full-tree',revision).stdout.split(b'\0'):
  if not item:continue
  a,p=item.split(b'\t',1);mode,kind,oid=a.decode().split();p=p.decode();need(kind=='blob'and mode in('100644','100755','120000')and p not in names);names.add(p);rows.append((p,mode,oid))
 need(len(rows)<=12000);batch=git('cat-file','--batch',input=('\n'.join(r[2]for r in rows)+'\n').encode()).stdout;need(len(batch)<=MAX+len(rows)*128)
 entries={};raws={};offset=0
 for p,mode,oid in rows:
  end=batch.find(b'\n',offset);need(offset<=end<offset+128);h=batch[offset:end].decode().split();need(len(h)==3 and h[0]==oid and h[1]=='blob'and re.fullmatch('0|[1-9][0-9]*',h[2])is not None);n=int(h[2]);need(n<=16777216);offset=end+1;raw=batch[offset:offset+n];offset+=n;need(len(raw)==n and batch[offset:offset+1]==b'\n');offset+=1
  entries[p]={'kind':'symlink','target':raw.decode(),'sha256':sha(raw)}if mode=='120000'else{'kind':'file','mode':int(mode[-3:],8),'bytes':n,'sha256':sha(raw)};raws[p]=raw
 need(offset==len(batch));return revision,entries,raws

class TaskContext:
 def __init__(self,operation,root,entries,git_raws):
  self.operation=operation;self.root=Path(root);self.entries=entries;self.files={};self.directories={};self.current_raw={};self.closed=False
  for rel,pin in entries.items():
   path=self.root/rel
   for parent in path.parents:
    if parent not in self.directories:
     s=parent.lstat();need(stat.S_ISDIR(s.st_mode)and not parent.is_symlink());self.directories[parent]=self.directory_stamp(parent,s)
   if pin['kind']=='file':
    raw,identity=read(path);need(stat.S_IMODE(identity[2])==pin['mode']and len(raw)==pin['bytes']and sha(raw)==pin['sha256']);self.current_raw[rel]=raw
   else:
    st=path.lstat();need(stat.S_ISLNK(st.st_mode)and os.readlink(path)==pin['target']);identity=stamp(st)
   self.files[rel]=identity
  self._directory_scopes_root=self.root
  self._directory_scopes=MappingProxyType({path:path.is_relative_to(self.root)for path in self.directories})
  need(sha(self.current_raw[BASELINE])==BASELINE_SHA and sha(self.current_raw[INVERSE])==INVERSE_SHA)
  self.parent_pins=decode(self.current_raw[BASELINE])['entries'];self.api_inverse=decode(self.current_raw[INVERSE]);self.reduced={}
  need(set(entries)==set(self.parent_pins)|set(TASK_NEW_PINS)|{BASELINE,INVERSE,'scripts/governance/task_source_operation_context.py'}|V2_PATHS)
  for rel,pin in TASK_NEW_PINS.items():need(len(self.current_raw[rel])==pin['bytes']and sha(self.current_raw[rel])==pin['sha256'])
  # Compile CURRENT Task inverse, captured from this real source; never Git old code.
  path='scripts/governance/task_content_grant_source_delta.py';namespace={'__file__':str(self.root/path),'__name__':'TaskOperation_current_inverse'};exec(compile(self.current_raw[path],str(self.root/path),'exec'),namespace);self.reduce=namespace['reduce_selected']
  for rel,pin in entries.items():
   if pin['kind']!='file':continue
   raw=self.current_raw[rel]
   if rel in self.api_inverse:
    inverse=self.api_inverse[rel];decoder=zlib.decompressobj();raw=decoder.decompress(base64.b64decode(inverse['zlib_base64'],validate=True),inverse['bytes']+1);need(decoder.eof and not decoder.unused_data and not decoder.unconsumed_tail and len(raw)==inverse['bytes']and sha(raw)==inverse['sha256'])
   elif rel in self.parent_pins:raw=self.reduce(rel,raw)
   else:raw=None
   if rel in self.parent_pins:
    old=self.parent_pins[rel];need(old['kind']=='file'and raw is not None and len(raw)==old['bytes']and sha(raw)==old['sha256']);self.reduced[rel]=raw
   else:need(raw is None)
  need(set(self.parent_pins).issubset(entries))
  for rel,pin in self.parent_pins.items():
   if pin['kind']=='symlink':need(entries[rel]==pin)
 def directory_stamp(self,path,info=None):
  value=stamp(path.lstat()if info is None else info)
  # Cache pure lexical scope only. Every call still obtains/checks actual stat.
  scopes=getattr(self,'_directory_scopes',None)
  inside=scopes[path]if scopes is not None and self.root==self._directory_scopes_root and path in scopes else path.is_relative_to(self.root)
  return value if inside else value[:5]
 def require(self):
  self.operation._require_task_context(self);need(not self.closed)
 def current(self,relative):
  self.require();need(relative in self.current_raw);path=self.root/relative
  need(stamp(path.lstat())==self.files[relative])
  for p in path.parents:need(self.directory_stamp(p)==self.directories[p])
  return self.current_raw[relative]
 def parent(self,relative):
  self.current(relative);return self.reduced.get(relative)
 def exit(self):
  self.require()
  try:
   for p,identity in self.directories.items():need(self.directory_stamp(p)==identity)
   for rel,pin in self.entries.items():
    path=self.root/rel;need(stamp(path.lstat())==self.files[rel])
    if pin['kind']=='file':raw,_=read(path);need(raw==self.current_raw[rel])
    else:need(os.readlink(path)==pin['target'])
  finally:self.closed=True

def capture_current(operation,roots):
 code=roots[0];revision,entries,raws=git_tree(code,operation._task_expected_checkpoint)
 # The known-root signer authenticates exact CURRENT code and data. Producer
 # copies are checked against the identical real tree; prepared files are
 # still solely accepted by the original strict PreparedLedger policy.
 result={root:TaskContext(operation,root,entries,raws)for root in dict.fromkeys(roots)}
 return result
