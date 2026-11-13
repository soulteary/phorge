"""Root-only standard migration and durable native dual-admin qualification."""
import argparse,hashlib,json,os,re,stat,subprocess
from pathlib import Path
ROOT_PROFILE_SHA=None
ROOT_REVIEW_SHA=None
HERE=Path(__file__).resolve().parent
TARGET='8afc70658f303fbcdd0168def66ef3b2cfaff0ab'
TARGET_TREE='06f6c88f0b81b89463fc9d08eb55df5a7aa047f7'
FREEZE_PIN=None # Root rebinds reviewed source cohort before any execution.
def need(v):
 if not v:raise ValueError('N05_qualification_refused_retain_no_retry')
def sha(b):return hashlib.sha256(b).hexdigest()
def canon(d):return json.dumps(d,sort_keys=True,separators=(',',':')).encode()+b'\n'
def decode(b):
 def unique(pairs):
  d={}
  for k,v in pairs:need(k not in d);d[k]=v
  return d
 return json.loads(b,object_pairs_hook=unique)
def private(path):
 p=Path(path);need(p.is_absolute()and p.resolve()==p);a=p.lstat();need(stat.S_ISREG(a.st_mode)and stat.S_IMODE(a.st_mode)==0o600 and a.st_uid==os.geteuid()and a.st_nlink==1 and 0<a.st_size<=65536)
 fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW)
 try:
  b=os.read(fd,65537);stamp=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
  need(len(b)==a.st_size and stamp(a)==stamp(os.fstat(fd))==stamp(p.lstat()));return b
 finally:os.close(fd)
def native_source_bytes(root,relative):
 need(type(relative)is str and not Path(relative).is_absolute()and '..'not in Path(relative).parts and str(Path(relative))==relative)
 path=root/relative
 if relative=='bin/storage':
  # This committed native entry is intentionally a symlink, not a regular file.
  # Inventory pins bind its script bytes; also require the exact reviewed link.
  need(path.is_symlink()and os.readlink(path)=='../scripts/sql/manage_storage.php')
  target=root/'scripts/sql/manage_storage.php';need(target.resolve()==target and path.resolve()==target and target.is_file())
  return target.read_bytes()
 need(path.resolve()==path and path.is_file());return path.read_bytes()
def source_root_identity(root):
 need(subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(root),'rev-parse','HEAD']).decode().strip()==TARGET and subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(root),'rev-parse','HEAD^{tree}']).decode().strip()==TARGET_TREE)
 subprocess.run(['/usr/bin/git','--no-replace-objects','-C',str(root),'diff','--quiet','HEAD'],check=True)

def bind(profile,review,freeze):
 need(all(type(v)is str and re.fullmatch('[a-f0-9]{64}',v)for v in (ROOT_PROFILE_SHA,ROOT_REVIEW_SHA,FREEZE_PIN)))
 raw=private(profile);rr=private(review);need(sha(raw)==ROOT_PROFILE_SHA and sha(rr)==ROOT_REVIEW_SHA);p=decode(raw)
 need(decode(rr)=={'schema':'N05-native-SQL-qualification-review-v1','profile_sha256':sha(raw),'scope':'owned-native-standard-upgrade-and-grant-metadata-only'})
 keys={'schema','runtime_authorized','owner','source_root','source_inventory_sha256','php','php_sha256','local_configuration_sha256','namespace','server_uuid','actors_path','actors_sha256','target_phid','subjects'}
 need(set(p)==keys and p['schema']=='N05-native-SQL-qualification-v1'and p['runtime_authorized']is True)
 need(re.fullmatch('[a-f0-9]{32}',p['owner'])and p['namespace']=='n05_grants_'+p['owner'])
 need(p['php']in('/usr/local/bin/php','/usr/bin/php','/opt/homebrew/bin/php')and re.fullmatch('[a-f0-9-]{36}',p['server_uuid']))
 need(re.fullmatch('PHID-PROJ-[A-Za-z0-9]{20}',p['target_phid'])and set(p['subjects'])=={'user','business-team'})
 need(re.fullmatch('PHID-USER-[A-Za-z0-9]{20}',p['subjects']['user'])and re.fullmatch('[A-Za-z0-9._-]{1,64}',p['subjects']['business-team']))
 fraw=Path(freeze).read_bytes();need(sha(fraw)==FREEZE_PIN);f=decode(fraw)
 need(f['source_directory']==str(HERE)and f['schema']=='N05-CI-bound-reviewed-source-v2'and f['kind']=='qualification'and f['native_git_revision']==TARGET and f['native_git_tree']==TARGET_TREE and sha(canon(f['native_source_inventory']))==p['source_inventory_sha256'])
 root=Path(p['source_root']);need(root.is_absolute()and root.resolve()==root)
 source_root_identity(root)
 for n,pin in f['native_source_inventory'].items():
  raw=native_source_bytes(root,n);need(len(raw)==pin['bytes']and sha(raw)==pin['sha256'])
 for n,pin in f['source_sha256'].items():need(sha((HERE/n).read_bytes())==pin['sha256'])
 need(sha(Path(p['php']).read_bytes())==p['php_sha256'])
 need(sha(private(root/'conf/local/local.json'))==p['local_configuration_sha256'])
 need(sha(private(p['actors_path']))==p['actors_sha256']);return p
class Journal:
 def __init__(self,path):
  self.path=Path(path);need(self.path.is_absolute()and self.path.parent.resolve()==self.path.parent);os.mkdir(self.path,0o700);self.i=0
 def write(self,name,raw):
  need(re.fullmatch('[A-Za-z0-9_.-]+',name));fd=os.open(self.path/name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
  try:
   with os.fdopen(fd,'wb')as f:f.write(raw);f.flush();os.fsync(f.fileno())
  except BaseException:raise
 def run(self,argv,cwd,timeout=60):
  self.i+=1;n=f'{self.i:02d}';self.write(n+'-intent.json',canon({'argv':argv,'retry_cleanup':False}))
  out=self.path/(n+'.stdout.private');err=self.path/(n+'.stderr.private')
  with open(out,'xb')as fo,open(err,'xb')as fe:
   os.chmod(out,0o600);os.chmod(err,0o600)
   child=subprocess.Popen(argv,cwd=cwd,env={'PATH':str(Path(argv[0]).parent),'HOME':str(self.path),'LANG':'C.UTF-8'},stdin=subprocess.DEVNULL,stdout=fo,stderr=fe,umask=0o077)
   self.write(n+'-started.json',canon({'pid':child.pid}))
   try:code=child.wait(timeout=timeout)
   except subprocess.TimeoutExpired:
    self.write(n+'-unknown.json',canon({'terminal_known':False,'signals_retry_cleanup':False}));raise
  self.write(n+'-terminal.json',canon({'exit_code':code,'terminal_known':True}));need(code==0 and out.stat().st_size<=1048576 and err.stat().st_size<=1048576);return out.read_bytes()
def upgrade_argv(p):return [p['php'],p['source_root']+'/bin/storage','upgrade','--force','--no-quickstart']
def native(j,p,profile,action,input=None,kind='user'):
 name=f'input-{j.i:02d}.json';j.write(name,canon(input or {}));argv=[p['php'],str(HERE/'native.php'),p['source_root'],str(profile),action,str(j.path/name),kind]
 result=decode(j.run(argv,p['source_root']));need(result.get('execution_authorized')is False);return result

def check_grant(state,p,kind,revoked=False):
 need(state['approval_state']=='applied'and state['approval_version']==2 and state['approver_present']is True and state['applied_at_present']is True)
 g=state['grant'];need(type(g)is dict and g['targetPHID']==p['target_phid']and g['operation']=='project.description'and g['subjectKind']==kind and g['subjectID']==p['subjects'][kind])
 need(g['grantID']==state['grant_id']and int(g['validUntil'])>int(g['validFrom'])and int(g['version'])==(2 if revoked else 1)and (g['revokedAt']is not None)is revoked)
 need([r['action']for r in state['audit']]==['submitted','revoked'if revoked else'granted'])
 need(len({r['actorPHID']for r in state['audit']})==2)

def sequence(j,p,profile):
 discovery=native(j,p,profile,'discover');need(discovery['native_patch_discovered']is True and discovery['native_schema_spec_registered']is True)
 native(j,p,profile,'before-upgrade');j.run(upgrade_argv(p),p['source_root'],timeout=900)
 schema=native(j,p,profile,'schema');need(schema['productionSchemaSpec']is True and schema['standard_patch_recorded']is True)
 baseline=native(j,p,profile,'target');out=[]
 for kind in ('user','business-team'):
  submitted=native(j,p,profile,'submit-grant',kind=kind);ticket={'approval_id':submitted['approval_id']}
  before=native(j,p,profile,'readback',ticket,kind);need(before['approval_state']=='submitted'and before['grant']is None and [r['action']for r in before['audit']]==['submitted'])
  need(native(j,p,profile,'deny-self',ticket,kind)['durable_state_unchanged']is True)
  applied=native(j,p,profile,'approve',ticket,kind);need(applied['grant_persisted']is True)
  state=native(j,p,profile,'readback',ticket,kind);check_grant(state,p,kind)
  need(native(j,p,profile,'deny-replay',ticket,kind)['durable_state_unchanged']is True)
  rev=native(j,p,profile,'submit-revoke',{'grant_id':applied['grant_id']},kind);rt={'approval_id':rev['approval_id']}
  need(native(j,p,profile,'deny-self',rt,kind)['durable_state_unchanged']is True)
  result=native(j,p,profile,'approve',rt,kind);need(result['grant_revoked']is True)
  final=native(j,p,profile,'readback',rt,kind);check_grant(final,p,kind,True)
  need(native(j,p,profile,'deny-replay',rt,kind)['durable_state_unchanged']is True)
  out.append({'kind':kind,'grant_sha256':sha(applied['grant_id'].encode()),'durable_grant_revoke_readback':True})
 need(native(j,p,profile,'target')==baseline)
 result={'schema':'N05-native-SQL-qualification-result-v1','executed_SQL':True,'standard_native_upgrade':True,'productionSchemaSpec':True,'cases':out,'native_Project_unchanged':True,'execution_authorized':False,'ordinary_provider_executor':'blocked'};j.write('result.json',canon(result));return result

def execute(profile,review,freeze,output):p=bind(profile,review,freeze);return sequence(Journal(output),p,profile)
if __name__=='__main__':
 a=argparse.ArgumentParser()
 for n in ('profile','review','freeze','output'):a.add_argument('--'+n,required=True,type=Path)
 x=a.parse_args()
 try:r=execute(x.profile,x.review,x.freeze,x.output)
 except Exception:print('{"passed":false,"retained":true,"retry_cleanup":false}');raise SystemExit(1)
 print(json.dumps(r,sort_keys=True))
