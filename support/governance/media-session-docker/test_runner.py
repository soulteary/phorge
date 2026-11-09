import io,json,os,subprocess,sys,tarfile,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parent))
import run,snapshot,consume
class Child:
 def __init__(self,argv,stdin,stdout,stderr,**kwargs):
  self.argv=argv;self.out=stdout;self.err=stderr;self.pid=1234;self.returncode=None
  self.stdin=io.BytesIO();self.body=None
 def communicate(self,body,timeout):self.body=body;os.write(self.out,b'{"fixture":true}\n');self.returncode=0
 def poll(self):return self.returncode
 def wait(self,timeout):self.returncode=0;return 0
class Tests(unittest.TestCase):
 def setUp(self):self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name).resolve()
 def tearDown(self):self.tmp.cleanup()
 def test_journal_actualFS_and_no_reentry(self):
  j=run.Journal(self.root/'journal')
  try:
   with patch.object(run.subprocess,'Popen',Child):out,err=j.call('/usr/bin/docker',['image','inspect','sha256:'+'a'*64])
   self.assertEqual(json.loads(out),{'fixture':True});self.assertFalse(err)
   d=json.loads((j.path/'0001.status.json').read_bytes());self.assertTrue(d['parent_terminal_known']);self.assertTrue(d['capture_complete'])
   self.assertEqual((j.path/'0001.stdout').stat().st_mode&0o7777,0o600)
   with self.assertRaises(FileExistsError):run.Journal(j.path)
  finally:j.close()
 def test_timeout_unknown_no_second_dispatch(self):
  class Timeout(Child):
   def communicate(self,body,timeout):os.write(self.out,b'partial');raise subprocess.TimeoutExpired('fixed',timeout)
  j=run.Journal(self.root/'journal')
  try:
   with patch.object(run.subprocess,'Popen',Timeout)as spawn:
    with self.assertRaises(subprocess.TimeoutExpired):j.call('/usr/bin/docker',['wait','a'*64])
    self.assertTrue(j.unknown)
    with self.assertRaises(ValueError):j.call('/usr/bin/docker',['rm','a'*64])
   d=json.loads((j.path/'0001.status.json').read_bytes());self.assertFalse(d['parent_terminal_known']);self.assertFalse(d['capture_complete']);self.assertEqual((j.path/'0001.stdout').read_bytes(),b'partial')
  finally:j.close()
 def test_prefeed_failure_closes_without_body(self):
  j=run.Journal(self.root/'journal');children=[];original=j.write
  def spawn(*args,**kw):c=Child(*args,**kw);children.append(c);return c
  def write(n,b):
   if n.endswith('.started.json'):raise OSError('synthetic journal failure')
   return original(n,b)
  try:
   with patch.object(run.subprocess,'Popen',spawn),patch.object(j,'write',write):
    with self.assertRaises(OSError):j.call('/usr/bin/docker',['exec','-i','a'*64,'fixed'],b'secret-canary')
   self.assertIsNone(children[0].body);self.assertTrue(children[0].stdin.closed)
   self.assertNotIn(b'secret-canary',b''.join(p.read_bytes()for p in j.path.iterdir()if p.is_file()))
  finally:j.close()
 def test_pending_failure_zero_children(self):
  j=run.Journal(self.root/'journal')
  try:
   with patch.object(j,'write',side_effect=OSError('fixture')),patch.object(run.subprocess,'Popen')as spawn:
    with self.assertRaises(OSError):j.call('/usr/bin/docker',['start','a'*64])
    spawn.assert_not_called()
  finally:j.close()
 def test_short_pread_exact_loop(self):
  original=os.pread;j=run.Journal(self.root/'journal')
  try:
   with patch.object(run.subprocess,'Popen',Child),patch.object(run.os,'pread',side_effect=lambda f,n,o:original(f,min(n,2),o)):self.assertIn(b'fixture',j.call('/usr/bin/docker',['inspect','a'*64])[0])
  finally:j.close()
 def test_log_final_barrier(self):
  original=os.stat;j=run.Journal(self.root/'journal');changed=False
  def mutate(path,*a,**kw):
   nonlocal changed
   if path=='0001.stderr'and not changed:
    changed=True;(j.path/'0001.stdout').write_bytes(b'{"fixture":null}\n')
   return original(path,*a,**kw)
  try:
   with patch.object(run.subprocess,'Popen',Child),patch.object(run.os,'stat',side_effect=mutate):
    with self.assertRaises(ValueError):j.call('/usr/bin/docker',['inspect','a'*64])
  finally:j.close()
 def test_fixed_argv_limits_and_no_secret(self):
  class J:
   def __init__(self):self.calls=[]
   def call(self,d,a,body=None,timeout=30):
    self.calls.append(a)
    return (b'a'*64+b'\n',b'')if a[0]=='create'else(b'',b'')
  j=J();c={'docker':'/usr/bin/docker'}
  with patch.object(run,'inspect',return_value={}):run.create(j,c,'db','b'*32,'sha256:'+'c'*64,'none','owned-db')
  args=j.calls[0];self.assertIn('--pull=never',args);self.assertIn('mysql',args);self.assertIn('ALL',args);self.assertNotIn('--publish',args);self.assertNotIn('--privileged',args);self.assertIn('MYSQL_ROOT_HOST=127.0.0.1',args)
  self.assertIn('MYSQL_ROOT_PASSWORD_FILE=/run/owned/root',args);self.assertNotIn('MYSQL_ROOT_PASSWORD=',str(args))
 def test_config_refuses_mutable_image(self):
  p=self.root/'config';p.write_text(json.dumps({'docker':'/usr/bin/docker','mysql_image':'mysql:8','php_image':'sha256:'+'a'*64}));p.chmod(0o600)
  with self.assertRaises(ValueError):run.config(p)
 def test_snapshot_only_git_and_overlay(self):
  raw=io.BytesIO()
  with tarfile.open(fileobj=raw,mode='w')as t:
   e=tarfile.TarInfo('public.txt');e.size=4;e.mode=0o644;t.addfile(e,io.BytesIO(b'base'))
  repo=self.root/'repo';repo.mkdir();(repo/'.git').mkdir();(repo/'private-secret').write_bytes(b'canary');(repo/'overlay.php').write_bytes(b'overlay');out=self.root/'out';out.mkdir()
  with patch.object(snapshot,'OVERLAY',{'overlay.php':snapshot.sha(b'overlay')}),patch.object(snapshot,'base_entries',return_value=({'public.txt':('file',b'base',0o644)},{'test_only':True})):
   archive,m=snapshot.build(repo,out)
  self.assertEqual(set(m['entries']),{'public.txt','overlay.php'});self.assertNotIn(b'canary',archive.read_bytes())
 def test_actual_Git_replace_and_attributes_cannot_change_base(self):
  repo=self.root/'git';repo.mkdir()
  def git(*a):return subprocess.run(['git','-c','commit.gpgsign=false','-c','core.hooksPath=/dev/null','-C',str(repo)]+list(a),check=True,capture_output=True,text=True,env=snapshot.git_environment()).stdout.strip()
  git('init','-q');git('config','user.name','Synthetic fixture');git('config','user.email','fixture@example.invalid')
  (repo/'public.txt').write_bytes(b'original $Format:%H$\n');(repo/'.gitattributes').write_text('public.txt export-subst\n')
  git('add','.');git('commit','-qm','original');base=git('rev-parse','HEAD');tree=git('rev-parse','HEAD^{tree}')
  (repo/'public.txt').write_bytes(b'replacement');git('commit','-qam','replacement');replacement=git('rev-parse','HEAD');git('replace',base,replacement)
  (repo/'.git/info/attributes').write_text('public.txt export-ignore\n')
  bad=self.root/'global-config';bad.write_text('[core]\n attributesFile = /unrelated/private-canary\n')
  with patch.object(snapshot,'BASE',base),patch.object(snapshot,'ROOT_TREE',tree),patch.dict(os.environ,{'GIT_CONFIG_GLOBAL':str(bad),'GIT_DIR':'/not-a-repository','GIT_REPLACE_REF_BASE':'refs/replace/'}):
   entries,proof=snapshot.base_entries(repo)
  self.assertEqual(entries['public.txt'][1],b'original $Format:%H$\n');self.assertTrue(proof['git_object_hashes_verified']);self.assertEqual(proof['root_tree'],tree)
 def test_object_corruption_refused(self):
  with patch.object(snapshot.subprocess,'run',return_value=type('R',(),{'stdout':b'a'*40+b' blob 3\nBAD\n'})()):
   with self.assertRaises(ValueError):snapshot.objects(self.root,['a'*40])
 def test_Docker_selectors_are_not_inherited(self):
  children=[]
  class Observe(Child):
   def __init__(self,*a,**kw):children.append((a[0],kw['env']));super().__init__(*a,**kw)
  j=run.Journal(self.root/'journal')
  try:
   with patch.dict(os.environ,{'DOCKER_HOST':'tcp://private-canary:2375','DOCKER_CONTEXT':'remote','DOCKER_TLS_VERIFY':'1','DOCKER_CONFIG':'/private-canary'}),patch.object(run.subprocess,'Popen',Observe):j.call('/usr/bin/docker',['image','inspect','sha256:'+'a'*64])
   args,env=children[0];self.assertEqual(args[1:5],['--config',str(j.configdir),'--host','unix:///var/run/docker.sock']);self.assertFalse(any(k.startswith('DOCKER_')for k in env));self.assertFalse(list(j.configdir.iterdir()));self.assertEqual(j.configdir.stat().st_mode&0o7777,0o700)
  finally:j.close()
 def test_actual_engine_aliases_require_zero_runtime_caps(self):
  owner='b'*32;cid='a'*64;image='sha256:'+'c'*64
  h={'NetworkMode':'none','Privileged':False,'ReadonlyRootfs':True,'CapDrop':sorted(run.EXPANDED_DROP),'CapAdd':None,'PortBindings':{},'PidMode':'private','Memory':512*1024*1024,'PidsLimit':128,'NanoCpus':2000000000,'SecurityOpt':['no-new-privileges'],'Ulimits':[{'Name':'RLIMIT_NOFILE','Hard':1024,'Soft':1024}],'Tmpfs':{'/run':'fixed','/tmp':'fixed'},'RestartPolicy':{'Name':'no'}}
  v={'Id':cid,'Image':image,'Config':{'Labels':{'org.phorge.media-positive.owner':owner,'org.phorge.media-positive.role':'seed'},'User':'0:0'},'HostConfig':h,'NetworkSettings':{'Ports':{}},'Mounts':[{'Type':'volume','Name':'media-positive-code-'+owner,'Destination':'/source','RW':True}]}
  class J:
   def call(self,*a,**k):return json.dumps([v]).encode(),b''
  self.assertEqual(run.inspect(J(),{'docker':'fixed'},cid,'seed',owner,image,'none'),v)
  clean={k:('1'if k=='NoNewPrivs'else'0000000000000000')for k in run.CAP_FIELDS};self.assertTrue(run.caps_accept(clean))
  bad=dict(clean,NoNewPrivs='0')
  with self.assertRaises(ValueError):run.caps_accept(bad)
  for key in run.CAP_FIELDS-{'NoNewPrivs'}:
   d={k:('1'if k=='NoNewPrivs'else'0000000000000000')for k in run.CAP_FIELDS};d[key]='0000000000000001'
   with self.assertRaises(ValueError):run.caps_accept(d)
  h['CapAdd']=['CHOWN']
  with self.assertRaises(ValueError):run.inspect(J(),{'docker':'fixed'},cid,'seed',owner,image,'none')
  h['CapAdd']=None;h['CapDrop']=sorted(run.EXPANDED_DROP-{'KILL'})
  with self.assertRaises(ValueError):run.inspect(J(),{'docker':'fixed'},cid,'seed',owner,image,'none')
 def test_nofile_only_known_aliases(self):
  for name in ('nofile','RLIMIT_NOFILE'):self.assertTrue(run.nofile_valid({'Ulimits':[{'Name':name,'Hard':1024,'Soft':1024}]}))
  for name in ('unknown','RLIMIT_NPROC'):self.assertFalse(run.nofile_valid({'Ulimits':[{'Name':name,'Hard':1024,'Soft':1024}]}))
  self.assertFalse(run.nofile_valid({'Ulimits':[{'Name':'nofile','Hard':True,'Soft':1024}]}))
 def test_postexit_known_NPROC_extra_only(self):
  base={'Name':'RLIMIT_NOFILE','Hard':1024,'Soft':1024};extra={'Name':'RLIMIT_NPROC','Hard':1048576,'Soft':1048576}
  self.assertTrue(run.nofile_valid({'Ulimits':[base]}));self.assertTrue(run.nofile_valid({'Ulimits':[base,extra]}));self.assertTrue(run.nofile_valid({'Ulimits':[extra,base]}))
  invalid=[[],[extra],[base,base],[base,dict(base,Name='nofile')],[base,extra,extra],[base,dict(extra,Hard=1048575)],[base,dict(extra,Soft=True)],[base,dict(extra,Name='RLIMIT_UNKNOWN')],[dict(base,Soft=2048),extra],[base,dict(extra,unexpected=True)]]
  for rows in invalid:
   with self.subTest(rows=rows):self.assertFalse(run.nofile_valid({'Ulimits':rows}))
 def test_helper_fixed_modes_and_no_Python_dependency(self):
  source=run.helper_source();self.assertIn('pcntl_exec',source);self.assertNotIn('python',source.lower())
  self.assertEqual(run.helper_args('inject')[-2:],['--','inject'])
  with self.assertRaises(ValueError):run.helper_args('/arbitrary/command')
 def test_tar_special_paths_links(self):
  for n in ('../escape','/absolute','.git/config','conf/local/local.json','a//b'):
   with self.assertRaises(ValueError):snapshot.validate_name(n)
  for t in ('/etc/passwd','../../escape'):
   with self.assertRaises(ValueError):snapshot.validate_link('dir/link',t)
 def test_known_worker_nonzero_still_captures_logs(self):
  class J:
   def __init__(self,wait):self.wait=wait;self.calls=[];self.records={}
   def call(self,d,a,**kw):self.calls.append(a);return (self.wait,b'')if a[0]=='wait'else(b'{"diagnostic":"private-canary"}',b'')
   def write(self,n,b):self.records[n]=b
  j=J(b'1\n');code,out,err=run.terminal_worker_logs(j,{'docker':'fixed'},'a'*64)
  self.assertEqual(code,1);self.assertEqual([a[0]for a in j.calls],['wait','logs']);self.assertIn(b'private-canary',out);self.assertNotIn(b'private-canary',j.records['worker-terminal-capture.json'])
  for value in (b'',b'unknown',b'-1',b'01',b'256',b'0\n1'):
   j=J(value)
   with self.assertRaises(ValueError):run.terminal_worker_logs(j,{'docker':'fixed'},'a'*64)
   self.assertEqual([a[0]for a in j.calls],['wait'])
 def test_strict_receipt_rejects_passed_echo(self):
  for d in ({'passed':True},{'schema':'native-media-session-positive-sql-v1','passed':True}):
   with self.assertRaises(ValueError):consume.accept(json.dumps(d))
 def test_exact_receipt_positive_and_negative(self):
  counts={'native-session-issued-and-loaded':4,'actual-native-route':32,'controller-response-status':32,'actual-response-cookie':8,'actual-redeem':4,'actual-ready':4,'actual-native-binding':4,'actual-used-ledger':4,'same-browser-account-generation':1,'old-account-generation-denied':1,'native-logout-invalidates-media':1,'current-disabled-account-denied':1,'current-session-expiry-denied':1}
  d={k:False for k in consume.KEYS};d.update(schema='native-media-session-positive-sql-v1',passed=True,stage='native-controller-positive-cases',diagnostic=None,cleanup_diagnostic=None,checks=[k for k,n in counts.items()for _ in range(n)],unexpected_output_bytes=0,cleanup={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'native_writer_sessions_zero':True,'native_writer_MDL_zero':True})
  self.assertTrue(consume.accept(json.dumps(d))['accepted'])
  d['checks'].pop()
  with self.assertRaises(ValueError):consume.accept(json.dumps(d))
if __name__=='__main__':unittest.main()
