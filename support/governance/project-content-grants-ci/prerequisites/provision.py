"""Root-only native prerequisites. Imports and host controls never execute SQL."""
import argparse,hashlib,json,os,re,stat,subprocess
from pathlib import Path
DESCRIPTION_FIXTURE_SHA='754546ac5223a5631fcec55d354293564e0dc8e89a8b0ff616bbd55551fe1955'
ROOT_PROFILE_SHA=None
ROOT_REVIEW_SHA=None
ROOT_SOURCE_FREEZE_SHA=None
HERE=Path(__file__).resolve().parent
BASE='4c2245300dc1d78d042b95190dd537eb6b1f9861'
BASE_TREE='69180e43fba7476257cc534c1934d544995a4443'
TARGET='ca30fee773b622be4b586b722a3ca6bdbdf27277'
TARGET_TREE='8f325df09f2118499c84c72091967a8f43c78956'
def need(v):
 if not v:raise ValueError('N05_prerequisites_refused_retain_no_retry')
def sha(b):return hashlib.sha256(b).hexdigest()
def canon(d):return json.dumps(d,sort_keys=True,separators=(',',':')).encode()+b'\n'
def decode(b):
 def unique(items):
  d={}
  for k,v in items:need(k not in d);d[k]=v
  return d
 return json.loads(b,object_pairs_hook=unique)
def private(path):
 p=Path(path);need(p.is_absolute()and p.resolve()==p);a=p.lstat();need(stat.S_ISREG(a.st_mode)and stat.S_IMODE(a.st_mode)==0o600 and a.st_nlink==1 and a.st_uid==os.geteuid()and 0<a.st_size<=65536)
 fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW)
 try:
  raw=os.read(fd,65537);stamp=lambda s:(s.st_dev,s.st_ino,s.st_uid,s.st_gid,s.st_mode,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
  need(len(raw)==a.st_size and stamp(a)==stamp(os.fstat(fd))==stamp(p.lstat()));return raw
 finally:os.close(fd)
def new_private(path,raw):
 p=Path(path);need(p.is_absolute()and p.parent.resolve()==p.parent and not p.exists());fd=os.open(p,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
 with os.fdopen(fd,'wb')as f:f.write(raw);f.flush();os.fsync(f.fileno())
 need(private(p)==raw)
class Journal:
 def __init__(self,path):
  self.path=Path(path);need(self.path.is_absolute()and self.path.parent.resolve()==self.path.parent);os.mkdir(self.path,0o700);self.n=0
 def write(self,name,raw):need(re.fullmatch('[A-Za-z0-9_.-]+',name));new_private(self.path/name,raw)
 def run(self,argv,cwd,timeout=60):
  self.n+=1;n=f'{self.n:02d}';self.write(n+'-intent.json',canon({'argv':argv,'signals_retry_cleanup':False}))
  out=self.path/(n+'.stdout.private');err=self.path/(n+'.stderr.private')
  with open(out,'xb')as fo,open(err,'xb')as fe:
   os.chmod(out,0o600);os.chmod(err,0o600);child=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=fo,stderr=fe,cwd=cwd,env={'PATH':str(Path(argv[0]).parent),'HOME':str(self.path),'LANG':'C.UTF-8'},umask=0o077)
   self.write(n+'-started.json',canon({'pid':child.pid}))
   try:code=child.wait(timeout=timeout)
   except subprocess.TimeoutExpired:self.write(n+'-unknown.json',canon({'known_terminal':False,'signals_retry_cleanup':False}));raise
  self.write(n+'-terminal.json',canon({'known_terminal':True,'exit_code':code}));need(code==0 and out.stat().st_size<=1048576 and err.stat().st_size<=1048576);return out.read_bytes()
def git(root,*args):return subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(root),*args],stderr=subprocess.DEVNULL)
def source_root(path,commit,tree):
 p=Path(path);need(p.is_absolute()and p.resolve()==p and p.stat().st_uid==os.geteuid()and not p.stat().st_mode&0o022 and git(p,'rev-parse','HEAD').decode().strip()==commit and git(p,'rev-parse','HEAD^{tree}').decode().strip()==tree)
 need(git(p,'diff','--name-only','HEAD')==b''and git(p,'ls-files','--others','--exclude-standard','--','src','resources','support/runtime','bin','scripts/init')==b'');need(not(p/'conf/local/local.json').exists());return p

def bind(profile,review,freeze):
 need(all(type(x)is str and re.fullmatch('[a-f0-9]{64}',x)for x in (ROOT_PROFILE_SHA,ROOT_REVIEW_SHA,ROOT_SOURCE_FREEZE_SHA)))
 raw=private(profile);rr=private(review);fr=Path(freeze).read_bytes();need(sha(raw)==ROOT_PROFILE_SHA and sha(rr)==ROOT_REVIEW_SHA and sha(fr)==ROOT_SOURCE_FREEZE_SHA)
 p=decode(raw);need(decode(rr)=={'schema':'N05-prerequisites-Root-review-v1','profile_sha256':sha(raw),'scope':'new-owned-native-namespace-offline-bootstrap-only'})
 keys={'schema','owner','namespace','base_root','base_commit','base_tree','target_root','target_commit','target_tree','php','php_sha256','db_port','server_uuid','db_credentials_path','db_credentials_sha256','owned_db_receipt_path','owned_db_receipt_sha256','isolated_features_authorized'}
 need(set(p)==keys and p['schema']=='N05-native-prerequisites-v1'and p['isolated_features_authorized']is True and re.fullmatch('[a-f0-9]{32}',p['owner'])and p['namespace']=='n05_grants_'+p['owner'])
 need(p['base_commit']==BASE and p['target_tree']==TARGET_TREE and p['target_commit']==TARGET and p['base_tree']==BASE_TREE)
 need(p['php']in('/usr/bin/php','/usr/local/bin/php','/opt/homebrew/bin/php')and sha(Path(p['php']).read_bytes())==p['php_sha256'])
 need(type(p['db_port'])is int and 1<=p['db_port']<=65535 and re.fullmatch('[a-f0-9-]{36}',p['server_uuid']))
 f=decode(fr);need(f['source_directory']==str(HERE)and f['schema']=='N05-CI-bound-reviewed-source-v2'and f['kind']=='prerequisites'and f['native_git_revision']==BASE and f['native_git_tree']==BASE_TREE)
 for n,pin in f['source_sha256'].items():need(sha((HERE/n).read_bytes())==pin['sha256'])
 source_root(p['base_root'],p['base_commit'],p['base_tree']);source_root(p['target_root'],p['target_commit'],p['target_tree']);need(p['base_root']!=p['target_root'])
 credential=private(p['db_credentials_path']);need(sha(credential)==p['db_credentials_sha256']);d=decode(credential);need(set(d)=={'owner','user','password'}and d['owner']==p['owner']and re.fullmatch('[a-zA-Z0-9_]{1,64}',d['user'])and type(d['password'])is str and 1<=len(d['password'])<=256 and '\x00'not in d['password'])
 receipt=private(p['owned_db_receipt_path']);need(sha(receipt)==p['owned_db_receipt_sha256']);r=decode(receipt)
 need(set(r)=={'schema','owner','container_id','host_ip','host_port','server_uuid','source'}and r['schema']=='Root-owned-local-MySQL-binding-v1'and r['owner']==p['owner']and r['host_ip']=='127.0.0.1'and r['host_port']==p['db_port']and r['server_uuid']==p['server_uuid']and re.fullmatch('[a-f0-9]{64}',r['container_id'])and r['source']=='actual-Root-Docker-and-SQL-observation')
 return p,d

def names(owner):
 need(type(owner)is str and re.fullmatch('[a-f0-9]{32}',owner));return {role:'n05_'+role+'_'+owner for role in ('requester','approver','recipient')}
def configuration(p,d,target=False):
 need(d['owner']==p['owner']);c={'mysql.host':'127.0.0.1','mysql.port':p['db_port'],'mysql.user':d['user'],'mysql.pass':d['password'],'storage.default-namespace':p['namespace'],'phabricator.cache-namespace':p['namespace'],'phabricator.base-uri':'http://n05-fixture.localhost/','cluster.databases':[],'cluster.search':[],'cluster.instance':None,'load-libraries':[],'gorge.db.uri':None,'gorge.file.uri':None,'gorge.image.uri':None,'gorge.taskqueue.uri':None,'gorge.notification.outbox':False,'gorge.file.uploads':False,'gorge.file.deletion-outbox':False,'gorge.image.builtin-mode':'legacy','gorge.search.source-scan':False,'governance.private-mail.enabled':False,'governance.account-recovery.enabled':False,'governance.project-approval.enabled':target,'governance.organization.enabled':True,'notification.servers':[]}
 if target:c['governance.project-content-grants.enabled']=True
 return canon(c)
def upgrade_argv(p):return[p['php'],p['base_root']+'/bin/storage','upgrade','--force','--no-quickstart']
def people_argv(p,action,role):
 need(action in('approve','empower')and role in('requester','approver','recipient')and not(action=='empower'and role=='recipient'))
 return[p['php'],p['base_root']+'/bin/user',action,'--user',names(p['owner'])[role]]
def native(j,p,profile,action,extra):
 need(action in('empty','users','tokens','structure','readback'))
 inp=j.path/f'input-{j.n:02d}.json';j.write(inp.name,canon(extra));profile_hash=sha(private(profile));need(profile_hash==ROOT_PROFILE_SHA);argv=[p['php'],str(HERE/'native.php'),p['base_root'],str(profile),action,str(inp),str(j.path),profile_hash,'native-v1']
 r=decode(j.run(argv,p['base_root']));need(r.get('execution_authorized')is False);return r

def sequence(j,p,d,profile,description_fixture=False):
 need(type(description_fixture)is bool)
 basecfg=Path(p['base_root'])/'conf/local/local.json';new_private(basecfg,configuration(p,d))
 native(j,p,profile,'empty',{})
 j.run(upgrade_argv(p),p['base_root'],timeout=900)
 users=native(j,p,profile,'users',{})
 for role in('requester','approver','recipient'):j.run(people_argv(p,'approve',role),p['base_root'])
 for role in('requester','approver'):j.run(people_argv(p,'empower',role),p['base_root'])
 tokens=native(j,p,profile,'tokens',{})
 actors=j.path/'actors.private.json';need(sha(private(actors))==tokens['actors_sha256'])
 chain={'actors_sha256':tokens['actors_sha256']};structure=native(j,p,profile,'structure',chain);ready=native(j,p,profile,'readback',chain)
 need(ready['target_phid']==structure['target_phid']and ready['real_native_admin_API_sources']==2 and ready['native_user_team_member_and_Project_readback']is True and ready['N05_grant_tables_absent']is True)
 if description_fixture:
  # Fixed early fixture only: no callback, old-object edit or late flag bypass.
  fixture=HERE.parent.parent/'project-description-ci/fixture.php';raw=fixture.read_bytes()
  need(sha(raw)==DESCRIPTION_FIXTURE_SHA and raw.count(b'const N05_DESCRIPTION_FIXTURE_PROFILE_SHA256=null;')==1)
  helper=j.path/'description-fixture-helper';helper.mkdir(mode=0o700)
  new_private(helper/'native.php',(HERE/'native.php').read_bytes())
  new_private(helper/'fixture.php',raw.replace(b'const N05_DESCRIPTION_FIXTURE_PROFILE_SHA256=null;',('const N05_DESCRIPTION_FIXTURE_PROFILE_SHA256='+repr(ROOT_PROFILE_SHA)+';').encode()))
  created=decode(j.run([p['php'],str(helper/'fixture.php'),p['base_root'],str(profile),str(j.path),ROOT_PROFILE_SHA],p['base_root'],timeout=180))
  need(created=={'native_fixture_created':True,'execution_authorized':False})
 targetcfg=Path(p['target_root'])/'conf/local/local.json';new_private(targetcfg,configuration(p,d,True))
 # Root must review, set runtime_authorized=true and bind the separate qualifier's None pins.
 template={'schema':'N05-native-SQL-qualification-v1','runtime_authorized':False,'owner':p['owner'],'source_root':p['target_root'],'source_inventory_sha256':None,'php':p['php'],'php_sha256':p['php_sha256'],'local_configuration_sha256':sha(private(targetcfg)),'namespace':p['namespace'],'server_uuid':p['server_uuid'],'actors_path':str(actors),'actors_sha256':tokens['actors_sha256'],'target_phid':ready['target_phid'],'subjects':ready['subjects']}
 j.write('qualification-profile-template.private.json',canon(template));result={'schema':'N05-native-prerequisites-ready-for-Root-review-v1','owner':p['owner'],'namespace':p['namespace'],'base_commit':p['base_commit'],'target_commit':p['target_commit'],'target_config_sha256':sha(private(targetcfg)),'actors_sha256':tokens['actors_sha256'],'native_prerequisites_observed':True,'N05_SQL_qualification_executed':False,'online_activation':False,'web_MFA_qualified':False,'execution_authorized':False};j.write('prerequisites-receipt.json',canon(result));return result

def execute(profile,review,freeze,output,*,description_fixture=False):
 need(type(description_fixture)is bool)
 p,d=bind(profile,review,freeze);return sequence(Journal(output),p,d,profile,description_fixture)
if __name__=='__main__':
 a=argparse.ArgumentParser()
 for n in('profile','review','freeze','output'):a.add_argument('--'+n,required=True,type=Path)
 x=a.parse_args()
 try:r=execute(x.profile,x.review,x.freeze,x.output)
 except Exception:print('{"passed":false,"retained":true,"signals_retry_cleanup":false}');raise SystemExit(1)
 print(json.dumps(r,sort_keys=True))
