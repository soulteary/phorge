import hashlib,json,os,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parent))
import freshness,prepare,project
class Controls(unittest.TestCase):
 def setUp(self):self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name).resolve()
 def tearDown(self):self.tmp.cleanup()
 def test_actual_protected_file_mutation_extra_and_docs_boundary(self):
  repo=self.root/'repo';repo.mkdir();(repo/'src').mkdir();(repo/'src/public.php').write_bytes(b'<?php // current\n');(repo/'docs').mkdir();(repo/'docs/note').write_text('old')
  def git(*args):return subprocess.run(['git','-c','commit.gpgsign=false','-c','core.hooksPath=/dev/null','-C',str(repo)]+list(args),env=freshness.snapshot.git_environment(),check=True,capture_output=True,text=True).stdout.strip()
  git('init','-q');git('config','user.name','Synthetic fixture');git('config','user.email','fixture@example.invalid');git('add','.');git('commit','-qm','fixture')
  expected={'src/public.php':('file',b'<?php // current\n',0o644)}
  with patch.object(freshness.snapshot,'base_entries',return_value=(expected,{})),patch.object(freshness.snapshot,'OVERLAY',{}):
   self.assertTrue(freshness.verify(repo)['protected_source_equal']);(repo/'docs/note').write_text('new report');self.assertTrue(freshness.verify(repo)['protected_source_equal'])
   (repo/'src/public.php').write_bytes(b'<?php // changed\n')
   with self.assertRaises(ValueError):freshness.verify(repo)
   (repo/'src/public.php').write_bytes(b'<?php // current\n');(repo/'src/extra.php').write_text('extra')
   with self.assertRaises(ValueError):freshness.verify(repo)
 def test_committed_runtime_change_cannot_hide_in_restored_worktree(self):
  repo=self.root/'repo';repo.mkdir();(repo/'src').mkdir();(repo/'src/public.php').write_bytes(b'<?php // base\n');(repo/'docs').mkdir();(repo/'docs/note').write_text('base')
  def git(*a):return subprocess.run(['git','-c','commit.gpgsign=false','-c','core.hooksPath=/dev/null','-C',str(repo)]+list(a),env=freshness.snapshot.git_environment(),check=True,capture_output=True,text=True).stdout.strip()
  git('init','-q');git('config','user.name','Synthetic fixture');git('config','user.email','fixture@example.invalid');git('add','.');git('commit','-qm','base');base=git('rev-parse','HEAD');expected=freshness.head_entries(repo,base)
  with patch.object(freshness.snapshot,'base_entries',return_value=(expected,{})),patch.object(freshness.snapshot,'OVERLAY',{}):
   (repo/'docs/note').write_text('changed doc');git('commit','-qam','docs only');self.assertTrue(freshness.verify(repo)['protected_source_equal'])
   (repo/'src/public.php').write_bytes(b'<?php // changed committed runtime\n');git('commit','-qam','runtime changed')
   (repo/'src/public.php').write_bytes(b'<?php // base\n');git('update-index','--assume-unchanged','src/public.php')
   self.assertEqual(git('status','--porcelain'),'')
   with self.assertRaisesRegex(ValueError,'committed protected'):freshness.verify(repo)
 def test_prefix_and_intermediate_parent_aliases_refused_leaf_link_allowed(self):
  repo=self.root/'repo';repo.mkdir();(repo/'src').mkdir();(repo/'src/public.php').write_bytes(b'<?php // base\n');(repo/'src/link.php').symlink_to('public.php');(repo/'support/governance').mkdir(parents=True);(repo/'support/governance/existing.php').write_bytes(b'<?php // existing\n')
  def git(*a):return subprocess.run(['git','-c','commit.gpgsign=false','-c','core.hooksPath=/dev/null','-C',str(repo)]+list(a),env=freshness.snapshot.git_environment(),check=True,capture_output=True,text=True).stdout.strip()
  git('init','-q');git('config','user.name','Synthetic fixture');git('config','user.email','fixture@example.invalid');git('add','.');git('commit','-qm','base');expected=freshness.head_entries(repo,git('rev-parse','HEAD'))
  with patch.object(freshness.snapshot,'base_entries',return_value=(expected,{})),patch.object(freshness.snapshot,'OVERLAY',{}):
   self.assertTrue(freshness.verify(repo)['protected_source_equal'])
   for name in ('src','support'):
    held=self.root/(name+'-retained');(repo/name).rename(held);(repo/name).symlink_to(held,target_is_directory=True)
    with self.assertRaisesRegex(ValueError,'protected prefix alias'):freshness.verify(repo)
    (repo/name).unlink();held.rename(repo/name)
 def test_fixed_image_binding_no_pull_or_inherited_context(self):
  calls=[]
  def fake(argv,**kw):calls.append((argv,kw));return type('R',(),{'stdout':b'sha256:'+b'a'*64+b'\n','stderr':b''})()
  with patch.object(prepare.subprocess,'run',side_effect=fake),patch.dict(os.environ,{'DOCKER_HOST':'tcp://private-canary:2375'}):d=prepare.bind(self.root/'images')
  self.assertEqual(set(d),{'docker','php_image','mysql_image'});self.assertEqual(len(calls),2)
  for argv,kw in calls:self.assertIn('unix:///var/run/docker.sock',argv);self.assertNotIn('DOCKER_HOST',kw['env']);self.assertIn('inspect',argv);self.assertNotIn('pull',argv)
  self.assertEqual((self.root/'images/config.private.json').stat().st_mode&0o7777,0o600)
 def test_diagnostic_does_not_export_path_message_or_unknown_class(self):
  d={'phase':'primary','exception_class':'SecretCanaryThrowable','source':'support/governance/media-session-positive/cases.php','line':4};safe=project.diagnostic(d)
  raw=json.dumps(safe);self.assertNotIn('cases.php',raw);self.assertNotIn('support/',raw);self.assertNotIn('SecretCanary',raw)
  for change in ({'message':'private-password-canary'},{'source':'/private/secret'},{'source':'src/../private.php'},{'phase':'PHID-USER-private'}):
   with self.assertRaises(ValueError):project.diagnostic(dict(d,**change))
 def test_no_raw_directory_upload_or_unknown_result_values(self):
  run=self.root/'run';run.mkdir();(run/'secret.private').write_text('password-canary');safe=project.project(run)
  self.assertNotIn('canary',json.dumps(safe));self.assertFalse(safe['strict_selected_accepted'])
  (run/'terminal.json').write_text(json.dumps({'accepted':False,'resources_retained':True,'unknown':True,'retry_or_cleanup_authorized':False,'secret':'canary'}))
  with self.assertRaises(ValueError):project.project(run)
 def test_duplicate_and_oversize_metadata_refused(self):
  p=self.root/'duplicate';p.write_text('{"accepted":true,"accepted":false}')
  with self.assertRaises(ValueError):project.load(p)
  p.write_bytes(b'x'*100)
  with self.assertRaises(ValueError):project.load(p,10)
 def test_strict_synthetic_success_chain_and_current_source_required(self):
  import ast,inspect
  consume=project.consume;tree=ast.parse(inspect.getsource(consume.accept));counts=next(ast.literal_eval(n.value)for n in ast.walk(tree)if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='expected'for t in n.targets))
  d={k:False for k in consume.KEYS};d.update(schema='native-media-session-positive-sql-v1',passed=True,stage='native-controller-positive-cases',diagnostic=None,cleanup_diagnostic=None,checks=[k for k,n in counts.items()for _ in range(n)],unexpected_output_bytes=0,cleanup={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'native_writer_sessions_zero':True,'native_writer_MDL_zero':True})
  root=self.root/'run';root.mkdir()
  def write(n,v):
   b=json.dumps(v,sort_keys=True,separators=(',',':')).encode();(root/n).write_bytes(b);return b
  raw=write('0001.stdout',d);empty=hashlib.sha256(b'').hexdigest();digest=hashlib.sha256(raw).hexdigest();worker='d'*64
  write('terminal.json',{'accepted':True,'resources_retained':False,'unknown':False,'no_browser_TLS_or_fault_claim':True})
  write('resources-worker.json',{'prerequisite':'a'*64,'seed':'b'*64,'db':'c'*64,'worker':worker,'source_volume':'media-positive-code-'+'a'*32,'database_volume':'media-positive-db-'+'a'*32})
  write('worker-terminal-capture.json',{'worker_exit_code':0,'stdout_sha256':digest,'stderr_sha256':empty,'known_terminal':True})
  write('0001.intent.json',{'argv':['logs',worker],'pending':True,'stdin_bytes':0});write('0001.status.json',{'parent_terminal_known':True,'capture_complete':True,'exit_code':0,'stdout_sha256':digest,'stderr_sha256':empty})
  inv={'schema':'media-positive-public-source-v1','base':freshness.snapshot.BASE,'base_object_provenance':{'root_tree':freshness.snapshot.ROOT_TREE,'git_object_hashes_verified':True},'overlay':{str(i):'a'*64 for i in range(8)},'entries':{str(i):{} for i in range(9325)},'archive_sha256':'a'*64};ib=write('public-source-inventory.json',inv)
  write('accepted.json',{'schema':'media-positive-selected-acceptance-v1','accepted':True,'normal_SQL_controller_only':True,'browser_TLS_or_fault_proof':False,'source_archive_sha256':'a'*64,'source_inventory_sha256':hashlib.sha256(ib).hexdigest(),'php_image':'sha256:'+'b'*64,'mysql_image':'sha256:'+'c'*64,'worker_exit':0})
  write('actual-image-prerequisite.json',{'version':'8.5.12','mysqli':True,'mbstring':True,'pcntl_exec':True,'posix_identity':True,'fsync':True,'jit':'disable','buffer':'0','cli':'0','caps':{k:('1'if k=='NoNewPrivs'else'0000000000000000')for k in ('CapEff','CapPrm','CapInh','CapAmb','CapBnd','NoNewPrivs')}})
  write('fresh-database-baseline.json',{'version':'8.4.13','uuid':'a'*8+'-'+'a'*4+'-'+'a'*4+'-'+'a'*4+'-'+'a'*12,'performance_schema':1,'mdl_enabled':1,'databases':0})
  fresh=self.root/'freshness';fresh.write_text(json.dumps({'schema':'media-positive-protected-source-freshness-v1','protected_source_equal':True,'base_revision':freshness.snapshot.BASE,'base_tree':freshness.snapshot.ROOT_TREE,'current_revision':'a'*40,'protected_leaf_count':1,'reviewed_overlay_count':8,'runtime_diff_allowed':False,'docs_reports_are_not_runtime_proof':True}))
  self.assertTrue(project.project(root,fresh)['strict_selected_accepted'])
  # Metadata collaborators: recompute the receipt hash to isolate the exact-count gate.
  original_entries=dict(inv['entries'])
  for count in (9140,9178,9183,9184,9185,9200,9201,9202,9236,9237,9271,9272,9273,9274,9275,9277,9278,9303):
   inv['entries']={str(i):{} for i in range(count)};ib=write('public-source-inventory.json',inv)
   accepted=json.loads((root/'accepted.json').read_bytes());accepted['source_inventory_sha256']=hashlib.sha256(ib).hexdigest();write('accepted.json',accepted)
   with self.assertRaisesRegex(ValueError,'source inventory binding'):project.project(root,fresh)
  inv['entries']=original_entries;ib=write('public-source-inventory.json',inv)
  accepted['source_inventory_sha256']=hashlib.sha256(ib).hexdigest();write('accepted.json',accepted)
  self.assertTrue(project.project(root,fresh)['strict_selected_accepted'])
  inv['entries']['0']={'changed':True};write('public-source-inventory.json',inv)
  with self.assertRaisesRegex(ValueError,'source inventory binding'):project.project(root,fresh)
  inv['entries']=original_entries;write('public-source-inventory.json',inv)
  with self.assertRaises(ValueError):project.project(root)
  d['cleanup']['ownedSchemasRemoved']=54;write('0001.stdout',d)
  self.assertFalse(project.project(root,fresh)['strict_selected_accepted'])
 def test_projector_refuses_links_special_and_mode(self):
  p=self.root/'data';p.write_bytes(b'{}');p.chmod(0o600);link=self.root/'link';link.symlink_to(p)
  with self.assertRaises(OSError):project.load(link)
  os.link(p,self.root/'hardlink')
  with self.assertRaises(ValueError):project.load(p)
  (self.root/'hardlink').unlink();p.chmod(0o666)
  with self.assertRaises(ValueError):project.load(p)
  fifo=self.root/'fifo';os.mkfifo(fifo,0o600)
  with self.assertRaises(ValueError):project.load(fifo)
 def test_actual_workflow_shell_blocks_parse_without_execution(self):
  root=Path(__file__).resolve().parents[3];lines=(root/'.github/workflows/native-media-session-positive-sql.yml').read_text().splitlines();blocks=[]
  for i,line in enumerate(lines):
   if line=='        run: |':
    body=[]
    for following in lines[i+1:]:
     if not following.startswith('          '):break
     body.append(following[10:])
    blocks.append('\n'.join(body)+'\n')
  self.assertEqual(len(blocks),4)
  for body in blocks:
   p=subprocess.run(['bash','-n'],input=body,capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr)
 def test_workflow_fixed_indexes_permissions_and_safe_artifact(self):
  root=Path(__file__).resolve().parents[3];s=(root/'.github/workflows/native-media-session-positive-sql.yml').read_text()
  self.assertIn('fetch-depth: 0',s);self.assertIn('persist-credentials: false',s);self.assertIn('contents: read',s)
  self.assertEqual(s.count(' pull '),2);self.assertIn(prepare.PHP,s);self.assertIn(prepare.MYSQL,s);self.assertIn('--cleanup-on-success',s)
  self.assertNotIn('services:',s);self.assertNotIn('--publish',s);self.assertEqual(s.count('path: media-positive-safe/selected-safe.json'),1)
  self.assertNotIn('path: $',s);self.assertIn("python3 -B support/governance/media-session-ci/freshness.py",s)
if __name__=='__main__':unittest.main()
