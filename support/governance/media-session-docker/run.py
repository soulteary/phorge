"""Local fresh-resource Docker runner. Author must not execute Docker."""
import argparse,hashlib,json,os,re,resource,secrets,stat,subprocess,time
from pathlib import Path
import snapshot,consume
MAX=4*1024*1024
LOCAL_ENDPOINT='unix:///var/run/docker.sock'
def docker_environment():return {'PATH':os.defpath}

DB_WAIT="while [ ! -f /run/owned/ready ]; do sleep 0.1; done; exec /usr/local/bin/docker-entrypoint.sh mysqld --performance-schema=ON --performance-schema-instrument=wait/lock/metadata/sql/mdl=ON --bind-address=127.0.0.1"
DB_INJECT="set -eu; umask 077; set -C; cat > /run/owned/root.tmp; test $(wc -c < /run/owned/root.tmp) -eq 64; ln /run/owned/root.tmp /run/owned/root; rm /run/owned/root.tmp; p=$(cat /run/owned/root); printf '[client]\nuser=root\npassword=%s\nhost=127.0.0.1\nprotocol=TCP\n' \"$p\" > /run/owned/client.cnf; unset p; : > /run/owned/marker.tmp; ln /run/owned/marker.tmp /run/owned/ready; rm /run/owned/marker.tmp"
SQL_BASELINE="SELECT JSON_OBJECT('version',VERSION(),'uuid',@@server_uuid,'performance_schema',@@performance_schema,'mdl_enabled',(SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME='wait/lock/metadata/sql/mdl' AND ENABLED='YES'),'databases',(SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('mysql','sys','performance_schema','information_schema')));"
def child_capture_limit():
 resource.setrlimit(resource.RLIMIT_FSIZE,(MAX,MAX))
class KnownNonzero(RuntimeError):pass
EXPANDED_DROP={'CHOWN','DAC_OVERRIDE','FOWNER','FSETID','KILL','NET_BIND_SERVICE','SETFCAP','SETGID','SETPCAP','SETUID','SYS_CHROOT'}
CAP_FIELDS={'CapEff','CapPrm','CapInh','CapAmb','CapBnd','NoNewPrivs'}
CAP_READ='while IFS= read -r line; do case "$line" in CapEff:*|CapPrm:*|CapInh:*|CapAmb:*|CapBnd:*|NoNewPrivs:*) printf "%s\\n" "$line";; esac; done < /proc/1/status'
HELPER_SHA='9c315552420b91f8c569f9f353829d23b70c9d0dbe7d163f202cf89608c667c3'
def helper_source():
 p=Path(__file__).with_name('helper.php');a=os.lstat(p);need(stat.S_ISREG(a.st_mode)and a.st_nlink==1 and a.st_size<=65536)
 fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:
  raw=bytearray()
  while len(raw)<a.st_size:
   b=os.pread(fd,a.st_size-len(raw),len(raw));need(b);raw.extend(b)
  need(os.pread(fd,1,a.st_size)==b''and stamp(a)==stamp(os.fstat(fd))==stamp(os.lstat(p))and snapshot.sha(raw)==HELPER_SHA)
 finally:os.close(fd)
 need(raw.startswith(b'<?php\n'));return bytes(raw[6:]).decode()
def helper_args(mode):
 need(mode in ('seed','worker','inject','readback','namespace','caps','prerequisite'))
 return ['/usr/local/bin/php','-d','opcache.jit=disable','-d','opcache.jit_buffer_size=0','-d','opcache.enable_cli=0','-r',helper_source(),'--',mode]
def capdrop_valid(h):
 d=h.get('CapDrop');return h.get('CapAdd')in(None,[])and type(d)is list and len(d)==len(set(d))and (d==['ALL']or set(d)==EXPANDED_DROP)
def nofile_valid(h):
 rows=h.get('Ulimits')
 if type(rows)is not list or len(rows)not in(1,2):return False
 seen=set()
 for r in rows:
  if type(r)is not dict or set(r)!={'Name','Hard','Soft'}or type(r['Hard'])is not int or type(r['Soft'])is not int:return False
  if r['Name']in('nofile','RLIMIT_NOFILE'):key='nofile';value=1024
  elif r['Name']in('nproc','RLIMIT_NPROC'):key='nproc';value=1048576
  else:return False
  if key in seen or r['Hard']!=value or r['Soft']!=value:return False
  seen.add(key)
 return 'nofile'in seen
def caps_accept(d):
 need(type(d)is dict and set(d)==CAP_FIELDS and d['NoNewPrivs']=='1'and all(d[k]=='0000000000000000'for k in CAP_FIELDS-{'NoNewPrivs'}));return True
def actual_caps(j,c,cid,php=False):
 if php:
  raw,err=j.call(c['docker'],['exec',cid]+helper_args('caps'));need(not err);d=json.loads(raw)
 else:
  raw,err=j.call(c['docker'],['exec',cid,'/bin/sh','-c',CAP_READ]);need(not err);d={}
 if not php:
  for line in raw.decode().splitlines():
   k,sep,v=line.partition(':');need(sep and k in CAP_FIELDS and k not in d);d[k]=v.strip()
 caps_accept(d);j.write('actual-caps-'+cid[:12]+'.json',canon({'all_five_capability_sets_zero':True,'NoNewPrivs':1}));return d
def need(x):
 if not x:raise ValueError('runner refused')
def canon(v):return json.dumps(v,sort_keys=True,separators=(',',':')).encode()+b'\n'
def stamp(s):return(s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
class Journal:
 def __init__(self,path):
  self.path=Path(path);need(self.path.is_absolute()and self.path.parent.resolve()==self.path.parent);self.parentfd=os.open(self.path.parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW);self.parentid=stamp(os.fstat(self.parentfd))[:5]
  try:
   need(self.parentid==stamp(os.lstat(self.path.parent))[:5]);os.mkdir(self.path.name,0o700,dir_fd=self.parentfd);created=stamp(os.stat(self.path.name,dir_fd=self.parentfd,follow_symlinks=False));self.fd=os.open(self.path.name,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=self.parentfd);self.id=stamp(os.fstat(self.fd));need(self.id==created==stamp(os.lstat(self.path)));os.mkdir('empty-docker-config',0o700,dir_fd=self.fd);self.configdir=self.path/'empty-docker-config';self.configid=stamp(os.lstat(self.configdir))[:5];self.n=0;self.unknown=False
  except BaseException:os.close(self.parentfd);raise
 def check(self):
  if hasattr(self,'configdir'):need(stamp(os.lstat(self.configdir))[:5]==self.configid and not os.listdir(self.configdir))
  need(stamp(os.fstat(self.parentfd))[:5]==self.parentid==stamp(os.lstat(self.path.parent))[:5]and stamp(os.fstat(self.fd))[:5]==self.id[:5]and stamp(os.lstat(self.path))[:5]==self.id[:5])
 def write(self,name,b):
  self.check();need(re.fullmatch('[a-zA-Z0-9_.-]+',name));f=os.open(name,os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=self.fd)
  try:
   off=0
   while off<len(b):n=os.write(f,b[off:]);need(n>0);off+=n
   os.fsync(f);s=stamp(os.fstat(f));need(stat.S_ISREG(s[2])and stat.S_IMODE(s[2])==0o600 and s[3]==os.geteuid()and s[4]==os.getegid()and s[5]==1 and s[6]==len(b));read=bytearray()
   while len(read)<len(b):
    part=os.pread(f,min(65536,len(b)-len(read)),len(read));need(part);read.extend(part)
   need(bytes(read)==b and os.pread(f,1,len(b))==b'' and s==stamp(os.fstat(f))==stamp(os.stat(name,dir_fd=self.fd,follow_symlinks=False)))
  finally:os.close(f)
  self.check()
 def call(self,docker,args,body=None,timeout=30):
  need(not self.unknown);self.n+=1;key='%04d'%self.n;self.write(key+'.intent.json',canon({'argv':args,'pending':True,'stdin_bytes':len(body)if body else 0}))
  out=os.open(key+'.stdout',os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=self.fd);err=os.open(key+'.stderr',os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=self.fd);p=None;fed=False
  try:
   self.check();p=subprocess.Popen([docker,'--config',str(self.configdir),'--host',LOCAL_ENDPOINT]+args,env=docker_environment(),stdin=subprocess.PIPE,stdout=out,stderr=err,preexec_fn=child_capture_limit)
   self.write(key+'.started.json',canon({'pid':p.pid,'body_fed':False}))
   self.check()
   try:
    fed=True;p.communicate(body,timeout=timeout)
   except subprocess.TimeoutExpired:
    self.unknown=True;self.write(key+'.status.json',canon({'parent_terminal_known':False,'capture_complete':False,'pid':p.pid,'retry_or_cleanup_authorized':False}));raise
   result=[];stamps=[]
   for f,name in [(out,key+'.stdout'),(err,key+'.stderr')]:
    os.fsync(f);s=os.fstat(f);need(stat.S_ISREG(s.st_mode)and s.st_nlink==1 and stat.S_IMODE(s.st_mode)==0o600 and s.st_size<=MAX);a=stamp(s);b=bytearray()
    while len(b)<s.st_size:
     c=os.pread(f,min(65536,s.st_size-len(b)),len(b));need(c);b.extend(c)
    need(os.pread(f,1,s.st_size)==b'');need(a==stamp(os.fstat(f))==stamp(os.stat(name,dir_fd=self.fd,follow_symlinks=False)));result.append(bytes(b));stamps.append((f,name,a))
   for f,name,s in stamps:need(s==stamp(os.fstat(f))==stamp(os.stat(name,dir_fd=self.fd,follow_symlinks=False)))
   self.check();self.write(key+'.status.json',canon({'parent_terminal_known':True,'capture_complete':True,'exit_code':p.returncode,'stdout_sha256':snapshot.sha(result[0]),'stderr_sha256':snapshot.sha(result[1])}))
   if p.returncode!=0:raise KnownNonzero('known parent nonzero; resources retained')
   return result
  except BaseException:
   if p is not None and not fed:
    try:
     p.stdin.close();p.wait(timeout=2)
    except BaseException:self.unknown=True
    try:self.write(key+'.prefeed.json',canon({'pid':p.pid,'body_fed':False,'stdin_closed':True,'parent_terminal_known':p.poll()is not None,'retry_or_cleanup_authorized':False}))
    except BaseException:pass
   if p is not None and p.poll()is None:self.unknown=True
   raise
  finally:os.close(out);os.close(err)
 def close(self):os.close(self.fd);os.close(self.parentfd)
def config(path):
 p=Path(path);s=os.lstat(p);need(p.resolve()==p and stat.S_ISREG(s.st_mode)and stat.S_IMODE(s.st_mode)==0o600 and s.st_nlink==1 and s.st_uid==os.geteuid()and s.st_size<=8192)
 fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 try:b=os.read(fd,8193);need(stamp(s)==stamp(os.fstat(fd))==stamp(os.lstat(p))and len(b)==s.st_size)
 finally:os.close(fd)
 d=json.loads(b);need(set(d)=={'docker','mysql_image','php_image'}and d['docker']in('/usr/bin/docker','/usr/local/bin/docker','/opt/homebrew/bin/docker'))
 for k in ('mysql_image','php_image'):need(type(d[k])is str and re.fullmatch('sha256:[a-f0-9]{64}',d[k]))
 return d
def inspect(j,c,cid,role,owner,image,network):
 raw,err=j.call(c['docker'],['inspect',cid]);need(not err);rows=json.loads(raw);need(type(rows)is list and len(rows)==1);v=rows[0]
 need(v['Id']==cid and v['Image']==image and all(v['Config']['Labels'].get(k)==x for k,x in {'org.phorge.media-positive.owner':owner,'org.phorge.media-positive.role':role}.items()))
 need(v['Config']['User']==('mysql'if role=='db'else'0:0'))
 h=v['HostConfig'];need(h['NetworkMode']==network and not h['Privileged']and h['ReadonlyRootfs']is True and capdrop_valid(h)and not h.get('PortBindings')and all(x is None for x in (v['NetworkSettings'].get('Ports')or{}).values())and h['PidMode']in('',None,'private'))
 need(h['Memory']==(1024*1024*1024 if role=='db'else 512*1024*1024)and h['PidsLimit']==128 and h['NanoCpus']==2000000000 and 'no-new-privileges' in ' '.join(h['SecurityOpt']))
 need(nofile_valid(h)and set(h['Tmpfs'])=={'/run','/tmp'})
 if role=='prerequisite':need(v['Mounts']==[])
 else:
  need(len(v['Mounts'])==1);m=v['Mounts'][0];need(m['Type']=='volume'and m['Name']==('media-positive-db-'if role=='db'else'media-positive-code-')+owner and m['Destination']==('/var/lib/mysql'if role=='db'else'/source')and m['RW']is(role!='worker'))
 need(h['RestartPolicy']['Name']=='no');return v
def create(j,c,role,owner,image,network,volume):
 name='media-positive-'+role+'-'+owner;mount='/var/lib/mysql'if role=='db'else'/source';ro=''if role in('db','seed')else',readonly'
 args=['create','--pull=never','--restart','no','--name',name,'--label','org.phorge.media-positive.owner='+owner,'--label','org.phorge.media-positive.role='+role,'--network',network,'--read-only','--cap-drop','ALL','--security-opt','no-new-privileges','--cpus','2','--memory','1g'if role=='db'else'512m','--pids-limit','128','--ulimit','nofile=1024:1024','--tmpfs','/run:rw,nosuid,nodev,noexec,size=16m,mode=1777','--tmpfs','/tmp:rw,nosuid,nodev,size=64m,mode=1777',]
 if role!='prerequisite':args+=['--mount','type=volume,src='+volume+',dst='+mount+ro]
 if role=='db':args+=['--user','mysql']
 else:args+=['--user','0:0']
 if role=='prerequisite':args+=['--entrypoint','/usr/local/bin/php',image]+helper_args('prerequisite')[1:]
 elif role=='db':args+=['--env','MYSQL_ROOT_PASSWORD_FILE=/run/owned/root','--env','MYSQL_ROOT_HOST=127.0.0.1','--entrypoint','/bin/sh',image,'-c','mkdir -m 700 /run/owned; mkdir -p /run/mysqld; '+DB_WAIT]
 elif role!='prerequisite':
  args+=['--env','PHORGE_GOVERNANCE_ISOLATED_TEST=1','--entrypoint','/usr/local/bin/php',image]+helper_args(role)[1:]
 raw,err=j.call(c['docker'],args);need(not err);cid=raw.decode().strip();need(re.fullmatch('[a-f0-9]{64}',cid));inspect(j,c,cid,role,owner,image,network);j.call(c['docker'],['start',cid]);return cid
def volume(j,c,name,owner,role):
 raw,err=j.call(c['docker'],['volume','create','--label','org.phorge.media-positive.owner='+owner,'--label','org.phorge.media-positive.role='+role,name]);need(not err and raw.decode().strip()==name)
 raw,err=j.call(c['docker'],['volume','inspect',name]);v=json.loads(raw)[0];need(not err and v['Name']==name and v['Driver']=='local'and not v.get('Options')and v['Labels']=={'org.phorge.media-positive.owner':owner,'org.phorge.media-positive.role':role})
def source_readback(j,c,cid,manifest):
 raw,err=j.call(c['docker'],['exec',cid]+helper_args('readback'),timeout=90);need(not err and json.loads(raw)==manifest['entries'])
def terminal_worker_logs(j,c,worker):
 raw,err=j.call(c['docker'],['wait',worker],timeout=240)
 value=raw.strip();need(not err and re.fullmatch(b'(?:0|[1-9][0-9]{0,2})',value)and int(value)<=255);code=int(value)
 out,err=j.call(c['docker'],['logs',worker],timeout=20)
 j.write('worker-terminal-capture.json',canon({'worker_exit_code':code,'stdout_sha256':snapshot.sha(out),'stderr_sha256':snapshot.sha(err),'known_terminal':True}))
 return code,out,err
def execute(config_path,output,cleanup=False):
 c=config(config_path);j=Journal(output);resources={};owner=secrets.token_hex(16);password=secrets.token_hex(32);accepted=False
 try:
  j.write('run-intent.json',canon({'schema':'media-positive-docker-run-v1','owner':owner,'images':{'mysql':c['mysql_image'],'php':c['php_image']},'cleanup_requested':cleanup,'runtime_authority':'explicit-local-synthetic-only'}))
  # Private recovery material is never copied into the public source volume.
  j.write('synthetic-mysql-root.private',password.encode())
  for role in ('mysql','php'):
   raw,err=j.call(c['docker'],['image','inspect',c[role+'_image']]);v=json.loads(raw);need(not err and len(v)==1 and v[0]['Id']==c[role+'_image'])
  prerequisite=create(j,c,'prerequisite',owner,c['php_image'],'none',None);resources['prerequisite']=prerequisite;j.write('resources-prerequisite.json',canon(resources))
  raw,err=j.call(c['docker'],['wait',prerequisite],timeout=30);need(not err and raw.strip()==b'0')
  raw,err=j.call(c['docker'],['logs',prerequisite]);need(not err);pr=json.loads(raw);need(set(pr)=={'version','mysqli','mbstring','jit','buffer','cli','caps','pcntl_exec','posix_identity','fsync'}and pr['version']=='8.5.12'and pr['mysqli']is True and pr['mbstring']is True and pr['jit']=='disable'and pr['buffer']=='0'and pr['cli']=='0'and pr['pcntl_exec']is True and pr['posix_identity']is True and pr['fsync']is True);caps_accept(pr['caps'])
  pv=inspect(j,c,prerequisite,'prerequisite',owner,c['php_image'],'none');need(pv['State']['Running']is False and pv['State']['ExitCode']==0 and pv['State']['OOMKilled']is False);j.write('actual-image-prerequisite.json',canon(pr))
  archive,manifest=snapshot.build(Path(__file__).resolve().parents[3],j.path)
  j.check();source_volume='media-positive-code-'+owner;db_volume='media-positive-db-'+owner
  for role,name in [('source',source_volume),('database',db_volume)]:volume(j,c,name,owner,role)
  resources.update(source_volume=source_volume,database_volume=db_volume)
  seed=create(j,c,'seed',owner,c['php_image'],'none',source_volume);resources['seed']=seed;j.write('resources-seed.json',canon(resources))
  actual_caps(j,c,seed,True)
  j.call(c['docker'],['cp','-',seed+':/source'],archive.read_bytes(),timeout=90);source_readback(j,c,seed,manifest)
  db=create(j,c,'db',owner,c['mysql_image'],'none',db_volume);resources['db']=db;j.write('resources-db.json',canon(resources))
  actual_caps(j,c,db)
  # Payload is private stdin only; argv/env contain no secret values.
  j.call(c['docker'],['exec','-i',db,'/bin/sh','-c',DB_INJECT],password.encode())
  baseline=None;deadline=time.monotonic()+120
  while time.monotonic()<deadline:
   try:
    raw,err=j.call(c['docker'],['exec',db,'mysql','--defaults-extra-file=/run/owned/client.cnf','--batch','--skip-column-names','-e',SQL_BASELINE],timeout=10)
    if not err:
     baseline=json.loads(raw);break
   except KnownNonzero:
    # Only a known-terminal nonzero readiness SELECT can be polled. All
    # unknown processes/captures stop the entire run, never restart the DB.
    if j.unknown:raise
   time.sleep(.5)
  need(type(baseline)is dict and baseline['version']=='8.4.13'and type(baseline['uuid'])is str and re.fullmatch('[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}',baseline['uuid'])and baseline['performance_schema']==1 and baseline['mdl_enabled']==1 and baseline['databases']==0)
  j.write('fresh-database-baseline.json',canon(baseline))
  worker=create(j,c,'worker',owner,c['php_image'],'container:'+db,source_volume);resources['worker']=worker;j.write('resources-worker.json',canon(resources))
  actual_caps(j,c,worker,True)
  source_readback(j,c,worker,manifest)
  probe="echo json_encode(['version'=>PHP_VERSION,'mysqli'=>extension_loaded('mysqli'),'mbstring'=>extension_loaded('mbstring'),'jit'=>ini_get('opcache.jit'),'buffer'=>ini_get('opcache.jit_buffer_size'),'cli'=>ini_get('opcache.enable_cli')]);"
  raw,err=j.call(c['docker'],['exec',worker,'/usr/local/bin/php','-d','opcache.jit=disable','-d','opcache.jit_buffer_size=0','-d','opcache.enable_cli=0','-r',probe]);need(not err and json.loads(raw)=={'version':'8.5.12','mysqli':True,'mbstring':True,'jit':'disable','buffer':'0','cli':'0'})
  # Inspect shared isolated namespace and exact RO code mount before supplying
  # the only execution-ready marker/profile. The daemon sees no host bind.
  w=inspect(j,c,worker,'worker',owner,c['php_image'],'container:'+db);d=inspect(j,c,db,'db',owner,c['mysql_image'],'none')
  need(w['State']['Running']is True and d['State']['Running']is True and not w['State']['OOMKilled']and not d['State']['OOMKilled'])
  need(len(w['Mounts'])==1 and w['Mounts'][0]['Name']==source_volume and w['Mounts'][0]['Destination']=='/source'and w['Mounts'][0]['RW']is False)
  dbns,e=j.call(c['docker'],['exec',db,'/bin/sh','-c','readlink /proc/1/ns/net; readlink /proc/1/ns/pid']);need(not e)
  worker_ns,e=j.call(c['docker'],['exec',worker]+helper_args('namespace'));need(not e)
  dn=dbns.decode().splitlines();wn=json.loads(worker_ns);need(len(dn)==2 and type(wn)is list and len(wn)==2 and all(re.fullmatch(r'(net|pid):\[[0-9]+\]',x)for x in dn+wn)and dn[0]==wn[0]and dn[1]!=wn[1])
  j.write('actual-namespace-bindings.json',canon({'shared_isolated_network_namespace':True,'separate_PID_namespaces':True,'db':dn,'worker':wn}))
  payload=canon({'acknowledge_synthetic_DDL':True,'host':'127.0.0.1','password':password,'port':3306,'user':'root'})
  j.write('synthetic-fixture-profile.private.json',payload)
  j.call(c['docker'],['exec','-i',worker]+helper_args('inject'),payload)
  worker_exit,raw,err=terminal_worker_logs(j,c,worker);need(worker_exit==0 and not err);result=consume.accept(raw)
  terminal=inspect(j,c,worker,'worker',owner,c['php_image'],'container:'+db);need(terminal['State']['Running']is False and terminal['State']['ExitCode']==0 and terminal['State']['OOMKilled']is False)
  accepted=True;j.write('accepted.json',canon(dict(result,source_archive_sha256=manifest['archive_sha256'],source_inventory_sha256=snapshot.sha(snapshot.canonical(manifest)),php_image=c['php_image'],mysql_image=c['mysql_image'],worker_exit=0)))
  if cleanup:
   # Explicit success-only disposal: requalify exact owned IDs/volumes first.
   source_readback(j,c,seed,manifest)
   for role,cid in [('prerequisite',prerequisite),('seed',seed),('worker',worker),('db',db)]:inspect(j,c,cid,role,owner,c['mysql_image']if role=='db'else c['php_image'],'container:'+db if role=='worker'else'none')
   for cid in (seed,db):j.call(c['docker'],['stop','--time','5',cid],timeout=15)
   for cid in (worker,seed,db,prerequisite):j.call(c['docker'],['rm',cid])
   for name,role in [(source_volume,'source'),(db_volume,'database')]:
    raw,err=j.call(c['docker'],['volume','inspect',name]);v=json.loads(raw)[0];need(not err and v['Name']==name and v['Labels']=={'org.phorge.media-positive.owner':owner,'org.phorge.media-positive.role':role});j.call(c['docker'],['volume','rm',name])
  j.write('terminal.json',canon({'accepted':True,'resources_retained':not cleanup,'unknown':False,'no_browser_TLS_or_fault_claim':True}));return True
 except BaseException:
  j.write('terminal.json',canon({'accepted':False,'resources_retained':True,'unknown':j.unknown,'retry_or_cleanup_authorized':False}));return False
 finally:j.close()
def main():
 p=argparse.ArgumentParser();p.add_argument('--execute',action='store_true');p.add_argument('--config',required=True);p.add_argument('--output',required=True);p.add_argument('--cleanup-on-success',action='store_true');a=p.parse_args()
 if not a.execute:raise SystemExit('explicit --execute required')
 ok=execute(a.config,a.output,a.cleanup_on_success);print(json.dumps({'selected_fixture_accepted':ok,'browser_TLS_or_fault_proof':False}));return 0 if ok else 1
if __name__=='__main__':raise SystemExit(main())
