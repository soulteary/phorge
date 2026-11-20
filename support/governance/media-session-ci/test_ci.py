import hashlib,json,os,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parent))
import freshness,prepare,project
CURRENT_DRIVER = 'python3 -B scripts/governance/run_media_task_selected_v2.py --execute --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --config "$RUNNER_TEMP/media-positive-images/config.private.json" --output "$RUNNER_TEMP/media-positive-run" > "$RUNNER_TEMP/media-current-driver.private.json"'
CURRENT_PROJECTOR = 'python3 -B scripts/governance/project_media_task_selected_v2.py --input "$RUNNER_TEMP/media-current-driver.private.json" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" > media-positive-safe/selected-safe.json'
CURRENT_HEAD = 'python3 -B scripts/governance/check_task_ci_head_v1.py --root "$GITHUB_WORKSPACE" --event-file "$GITHUB_EVENT_PATH" --event-name "$GITHUB_EVENT_NAME" --expected-head "$EXPECTED_SOURCE_CHECKPOINT" --github-sha "$GITHUB_SHA" --trust-home "$trust_home"'
CURRENT_PUBLIC = 'python3 -B scripts/governance/check_task_ci_head_v1.py --prepare-public-trust --public-key "$GITHUB_WORKSPACE/support/governance/source-operation/ci-source-verifier.asc" --trust-home "$trust_home"'
CURRENT_INTRO = '''name: Native media session selected SQL
on:
  workflow_call:
    inputs:
      expected_head:
        required: true
        type: string
  workflow_dispatch:
    inputs:
      expected_head:
        description: Exact signed source commit for this manual full qualification
        required: true
        type: string

'''
CURRENT_BIND = '''      - name: Require exact full-tier caller checkpoint
        env:
          FOCUSED_EXPECTED_HEAD: ${{ inputs.expected_head }}
        run: |
          python3 -B scripts/ci/focused_workflows.py bind-head --expected-head "$FOCUSED_EXPECTED_HEAD"
'''
CURRENT_PROOF_FLAG = ' --external-proof-tag "source-proof/$EXPECTED_SOURCE_CHECKPOINT"'
CURRENT_PROOF_ENV = '          echo "PHORGE_CI_MERGE_SOURCE_PROOF=source-proof/$EXPECTED_SOURCE_CHECKPOINT" >> "$GITHUB_ENV"\n'
def require_current_workflow(s):
 def need(value):
  if value is not True:raise ValueError('media_current_workflow_contract_refused')
 # CURRENT wiring must be exact before inverse comparison. The immutable old
 # PR workflow is DATA only; it is neither executed nor accepted as CURRENT.
 need(type(s)is str and s.startswith(CURRENT_INTRO)and s.count(CURRENT_INTRO)==1)
 need(s.count(CURRENT_BIND)==1 and s.count('        run: |')==6)
 need(s.count(CURRENT_PROOF_FLAG)==s.count(CURRENT_PROOF_ENV)==1)
 need(s.index(CURRENT_BIND)<s.index(CURRENT_PUBLIC)<s.index(CURRENT_HEAD+CURRENT_PROOF_FLAG)<s.index(CURRENT_PROOF_ENV)<s.index(CURRENT_DRIVER)<s.index(CURRENT_PROJECTOR))
 root=Path(__file__).resolve().parents[3]
 golden=subprocess.check_output(['git','--no-replace-objects','-C',str(root),'show',
  '7b23c47e329f32e4fe083d0efa23fd82d6eefe6f:.github/workflows/native-media-session-positive-sql.yml'],timeout=10)
 need(hashlib.sha256(golden).hexdigest()=='df876a1606950fc0960bc0a136cd0ee4673f1acd6d15b6c1803a007a7761c519')
 old=golden.decode();old_intro=old.split('concurrency:',1)[0]
 normalized=s.replace(CURRENT_INTRO,old_intro,1).replace(CURRENT_BIND,'',1)
 normalized=normalized.replace(CURRENT_PROOF_FLAG,'',1).replace(CURRENT_PROOF_ENV,'',1)
 need(normalized==old)
 s=normalized
 # Retain every original runtime/cleanup/upload assertion on the exact inverse.
 need(s.count('        run: |')==5 and s.count('timeout-minutes: 30')==1)
 for command in (CURRENT_PUBLIC,CURRENT_HEAD,CURRENT_DRIVER,CURRENT_PROJECTOR):
  need([x.strip()for x in s.splitlines()if command.split(' ',3)[2]in x and ('--prepare-public-trust'in x if command==CURRENT_PUBLIC else '--root'in x if command==CURRENT_HEAD else True)]==[command])
 need(s.index(CURRENT_PUBLIC)<s.index(CURRENT_HEAD)<s.index(CURRENT_DRIVER)<s.index(CURRENT_PROJECTOR))
 need('EXPECTED_SOURCE_CHECKPOINT: ${{ github.event.pull_request.head.sha || github.sha }}'in s)
 need('ref: ${{ github.event.pull_request.head.sha || github.sha }}'in s)
 need('fetch-depth: 0'in s and 'persist-credentials: false'in s and 'contents: read'in s)
 need('          export HOME="$RUNNER_TEMP/media-current-source-verifier"'in s)
 need('        id: selected'in s and 'SELECTED_DRIVER_OUTCOME: ${{ steps.selected.outcome }}'in s)
 need('if test "$SELECTED_DRIVER_OUTCOME" = success; then'in s)
 need(s.count('\n        if:')==2 and s.count('          set -euo pipefail')==5)
 selected=s.split('        id: selected\n',1)[1].split('      - name:',1)[0]
 expected_selected='        run: |\n          set -euo pipefail\n          umask 077\n          export HOME="$RUNNER_TEMP/media-current-source-verifier"\n          '+CURRENT_DRIVER+'\n'
 need(selected==expected_selected)
 need(s.count('if: always()')==2 and 'continue-on-error'not in s and '|| true'not in s and '; true'not in s)
 need(s.count(' pull ')==2 and prepare.PHP in s and prepare.MYSQL in s)
 pulls=[x.strip()for x in s.splitlines()if ' pull 'in x]
 expected=['env -i PATH=/usr/bin:/bin /usr/bin/docker --config "$RUNNER_TEMP/media-positive-pull-config" --host unix:///var/run/docker.sock pull '+image for image in (prepare.PHP,prepare.MYSQL)]
 need(pulls==expected)
 need('services:'not in s and '--publish'not in s and 'path: $'not in s)
 need(s.count('path: media-positive-safe/selected-safe.json')==1 and 'if-no-files-found: error'in s)
 need('"cleanup_verified":False'in s and '"source_operation_exit_verified":False'in s and '"selected_SQL_accepted":False'in s)
 need('freshness.py'not in s and 'media-session-docker/run.py'not in s)
 return True
def require_current_cleanup_boundary(raw,legacy):
 import ast
 if hashlib.sha256(legacy).hexdigest()!='8d84b9744560fba48aaa4634aa095e53e8f441a1138d39ede280326b67eb4209':raise ValueError('original_media_deadline_refused')
 tree=ast.parse(raw);outer=[n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='execute']
 if len(outer)!=1:raise ValueError('media_execute_shape_refused')
 calls=[n for n in ast.walk(outer[0])if isinstance(n,ast.Call)and ast.unparse(n.func)=='legacy.execute']
 if len(calls)!=1 or ast.unparse(calls[0])!='legacy.execute(config_path, output, True)':raise ValueError('success_cleanup_not_required')
 observers=[n for n in ast.walk(outer[0])if isinstance(n,ast.FunctionDef)and n.name=='current_SQL_cleanup']
 if len(observers)!=1:raise ValueError('cleanup_observer_missing')
 calls=[n for n in ast.walk(observers[0])if isinstance(n,ast.Call)and ast.unparse(n.func)=='j.call']
 if len(calls)!=1 or [(k.arg,ast.literal_eval(k.value))for k in calls[0].keywords]!=[('timeout',5)]:raise ValueError('cleanup_observer_deadline_refused')
 if raw.count(b'microtime(true)+600')!=1:raise ValueError('worker_marker_deadline_refused')
 return True
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
  write('actual-image-prerequisite.json',{'version':'8.5.13','mysqli':True,'mbstring':True,'pcntl_exec':True,'posix_identity':True,'fsync':True,'jit':'disable','buffer':'0','cli':'0','caps':{k:('1'if k=='NoNewPrivs'else'0000000000000000')for k in ('CapEff','CapPrm','CapInh','CapAmb','CapBnd','NoNewPrivs')}})
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
  self.assertEqual(len(blocks),6)
  for body in blocks:
   p=subprocess.run(['bash','-n'],input=body,capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr)
 def test_workflow_fixed_indexes_permissions_and_safe_artifact(self):
  root=Path(__file__).resolve().parents[3];s=(root/'.github/workflows/native-media-session-positive-sql.yml').read_text()
  self.assertTrue(require_current_workflow(s))
  raw=(root/'scripts/governance/run_media_task_selected_v2.py').read_bytes();legacy=(root/'support/governance/media-session-docker/run.py').read_bytes()
  self.assertTrue(require_current_cleanup_boundary(raw,legacy))
  for mutant in (raw.replace(b'legacy.execute(config_path,output,True)',b'legacy.execute(config_path,output,False)'),raw.replace(b'timeout=5)',b'timeout=10)'),raw.replace(b'microtime(true)+600',b'microtime(true)+900')):
   with self.assertRaises(ValueError):require_current_cleanup_boundary(mutant,legacy)
 def test_current_workflow_rejects_binding_omission_failure_masks_and_raw_upload(self):
  import re
  root=Path(__file__).resolve().parents[3];s=(root/'.github/workflows/native-media-session-positive-sql.yml').read_text();require_current_workflow(s)
  for command in (CURRENT_PUBLIC,CURRENT_HEAD,CURRENT_DRIVER,CURRENT_PROJECTOR):
   for flag in re.findall(r'(?<!\S)--[a-z][a-z-]*',command):
    mutant=s.replace(command,command.replace(flag+' ','--omitted-'+flag[2:]+' ',1),1)
    # A valueless --execute is followed by a space too, and is mandatory.
    with self.subTest(flag=flag),self.assertRaises(ValueError):require_current_workflow(mutant)
   for changed in (command+' || true',command+'; true',command.replace('$EXPECTED_SOURCE_CHECKPOINT','$GITHUB_SHA')):
    if changed==command:continue
    with self.assertRaises(ValueError):require_current_workflow(s.replace(command,changed,1))
  for old,new in [('        id: selected\n','        id: selected\n        if: false\n'),('          set -euo pipefail','          set +e'),('timeout-minutes: 30','timeout-minutes: 60'),('steps.selected.outcome','steps.preparation.outcome'),('path: media-positive-safe/selected-safe.json','path: ${{ runner.temp }}/media-positive-run'),('if: always()','if: success()'),(prepare.PHP,prepare.PHP[:-1]+'0'),('"cleanup_verified":False','"cleanup_verified":True')]:
   with self.subTest(old=old),self.assertRaises(ValueError):require_current_workflow(s.replace(old,new,1))
  for old,new in [(CURRENT_INTRO,CURRENT_INTRO.replace('  workflow_call:','  pull_request:')),
                 (CURRENT_INTRO,CURRENT_INTRO.replace('  workflow_dispatch:','  push:')),
                 ('        required: true','        required: false'),
                 ('        type: string','        type: boolean'),
                 ('  contents: read','  contents: write'),
                 (CURRENT_BIND,''),(CURRENT_BIND,CURRENT_BIND+CURRENT_BIND),
                 ('FOCUSED_EXPECTED_HEAD: ${{ inputs.expected_head }}','FOCUSED_EXPECTED_HEAD: ${{ github.sha }}'),
                 ('bind-head --expected-head "$FOCUSED_EXPECTED_HEAD"','bind-head --expected-head "$GITHUB_SHA"'),
                 ('bind-head --expected-head','bind-head --omitted-expected-head'),
                 (CURRENT_PROOF_FLAG,''),(CURRENT_PROOF_FLAG,CURRENT_PROOF_FLAG*2),
                 (CURRENT_PROOF_FLAG,CURRENT_PROOF_FLAG.replace('$EXPECTED_SOURCE_CHECKPOINT','$GITHUB_SHA')),
                 (CURRENT_PROOF_FLAG,' --external-proof-tag "source-proof/ancestor"'),
                 (CURRENT_PROOF_ENV,''),(CURRENT_PROOF_ENV,CURRENT_PROOF_ENV*2),
                 (CURRENT_PROOF_ENV,CURRENT_PROOF_ENV.replace('$EXPECTED_SOURCE_CHECKPOINT','$GITHUB_SHA'))]:
   with self.subTest(wiring=old),self.assertRaises(ValueError):require_current_workflow(s.replace(old,new,1))
  early=s.replace(CURRENT_PROOF_ENV,'',1).replace('          '+CURRENT_PUBLIC,CURRENT_PROOF_ENV+'          '+CURRENT_PUBLIC,1)
  with self.assertRaises(ValueError):require_current_workflow(early)
  late=s.replace(CURRENT_BIND,'',1).replace('      - name: Safe failure or success projection as DATA only',CURRENT_BIND+'      - name: Safe failure or success projection as DATA only',1)
  with self.assertRaises(ValueError):require_current_workflow(late)
if __name__=='__main__':unittest.main()
