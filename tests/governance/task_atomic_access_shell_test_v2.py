"""Exact CURRENT Atomic Access phase controls; no SQL/native/HMAC qualification.
Synthetic strict-body contracts run separately under a genuine current owner.
The shell collaborators never publish those synthetic claims as native evidence.
"""
import copy,hashlib,json,os,re,shutil,subprocess,sys,tempfile,textwrap
from pathlib import Path
BINARY_DATA=b'File DATA only; never executed'
MODES={'success','native-failure','projector-failure','marker-failure','cat-failure','foreign-failure','stderr-only','consumer-failure'}
BEGIN='      # BEGIN isolated OAuth access-token SQL8 (does not replace Conduit).'
END='      # END isolated OAuth access-token SQL8.'
WORKFLOW='.github/workflows/atomic-feature-regressions.yml'
BUILDERS={'tests/atomic/build_directory_file.py':'3274f1fc434fdcaac7e16df68088d81483be78da464955c43d6c3033e3980c38','tests/atomic/build_gorge.py':'dfe6348098659874f9cee4ce1d679df2e5639e1e1d7a458743b2e26b5449dee4','tests/atomic/paired-gorge.json':'9b5378122c7b10e497f8b1b340f51ef9e5b37bf25fb873500f2d45337fca465b'}
def need(v):
 if v is not True:raise ValueError('offline_Atomic_Access_phase_refused')
def phase(raw):
 need(type(raw)is bytes);text=raw.decode();need(text.count(BEGIN)==1 and text.count(END)==1)
 selected=text.split(BEGIN,1)[1].split(END,1)[0]
 need(selected.count('        run: |\n')==1)
 return textwrap.dedent(selected.split('        run: |\n',1)[1].split('      - uses:',1)[0])
def source_contract(root):
 """CURRENT typed owner and real source/strict-body checks; synthetic DATA only."""
 from task_inverse_test_api_v2 import owner
 from task_ci_workflow_test_api_v2 import workflow_data_in_operation
 from check_oauth_access_task_schema_receipt_v2 import captured_module
 import test_native_organization as baseline
 root=Path(root)
 with owner(root)as operation:
  context=operation._task_contexts[root]
  proof=workflow_data_in_operation(operation,root,[WORKFLOW]);raw=proof['workflows'][WORKFLOW]['current']
  # Preserve the original real File-only builder and paired Gorge source;
  # these exact reviewed source bytes are DATA, never built by this helper.
  def builder_source(name,provided=None):
   need(type(name)is str and name in BUILDERS)
   current=context.current(name);parent=context.parent(name)
   need(type(current)is bytes and current==parent and hashlib.sha256(current).hexdigest()==BUILDERS[name])
   if provided is not None:need(type(provided)is bytes and provided==current)
   return current
  builder_negative_count=0
  for name in BUILDERS:
   current=builder_source(name)
   for provided in(current+b'\n# unknown',bytearray(current)):
    try:builder_source(name,provided)
    except ValueError:builder_negative_count+=1
    else:raise ValueError('unreviewed_File_builder_source_accepted')
  try:builder_source('tests/atomic/unreviewed_builder.py')
  except ValueError:builder_negative_count+=1
  else:raise ValueError('unreviewed_File_builder_role_accepted')
  checker=captured_module(operation,root,'scripts/governance/check_recovery_oauth_access_producer_v3.py')
  common=captured_module(operation,root,'scripts/governance/oauth_task_strict_body_v2.py')
  adapter=captured_module(operation,root,'scripts/governance/oauth_access_task_strict_body_v2.py')
  consumer=captured_module(operation,root,'scripts/governance/check_oauth_access_task_schema_receipt_v2.py')
  raws={name:context.current('scripts/governance/'+name+'.py')for name in adapter.PINS}
  model=adapter.model(raws,common,checker);sql,expected=consumer.source_obligations(operation,root,checker)
  need(len(sql)==8 and len(expected)==18 and len(checker.SOURCE_INPUTS)==50)
  revision=operation._task_expected_checkpoint;digest=hashlib.sha256(BINARY_DATA).hexdigest()
  data=baseline.template();data.update(batch='native-oauth-access-producer-owner',scope='owned-native-oauth-access-producer-owner-no-permit',checks=sorted(expected),check_count=len(expected),native=checker.summary(revision,root,_source_operation=operation),worker_cleanup={'private_directories_removed':2,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':2})
  data['file_service_cleanup']['binary_sha256']=digest
  def validate(row):return model['validate'](row,root,source_revision=revision,file_digest=digest,_source_operation=operation)
  accepted=validate(data);need(accepted['SQL_obligations']==8 and accepted['checks']==18 and accepted['source_inputs']==50 and accepted['all_sources_complete']is False and accepted['restoration_authorized']is False)
  negative_count=builder_negative_count
  for label in sorted(expected):
   bad=copy.deepcopy(data);bad['checks'].remove(label);bad['check_count']-=1
   try:validate(bad)
   except ValueError:negative_count+=1
   else:raise ValueError('missing_contract_obligation_accepted')
  for key in ('permit','restoration_authorized','all_sources_complete','continuous_admin_lease_proved'):
   bad=copy.deepcopy(data);bad['native'][key]=True
   try:validate(bad)
   except ValueError:negative_count+=1
   else:raise ValueError('synthetic_authority_or_missing_boundary_accepted')
  for mutate in (lambda b:b['native'].__setitem__('source_revision','0'*40),lambda b:b['file_service_cleanup'].__setitem__('binary_sha256','0'*64),lambda b:b['worker_cleanup'].__setitem__('private_directories_remaining',1),lambda b:b['source_restore'].__setitem__('quickstart_sha256','0'*64)):
   bad=copy.deepcopy(data);mutate(bad)
   try:validate(bad)
   except ValueError:negative_count+=1
   else:raise ValueError('synthetic_identity_cleanup_or_restore_tamper_accepted')
  poisoned=dict(raws);name=next(iter(poisoned));poisoned[name]+=b'\n# unreviewed source'
  try:adapter.model(poisoned,common,checker)
  except ValueError:negative_count+=1
  else:raise ValueError('unreviewed_captured_source_accepted')
  for path,body in ((WORKFLOW,raw+b'\n# unknown current workflow'),('.github/workflows/unreviewed.yml',raw)):
   try:workflow_data_in_operation(operation,root,[path],{path:body})
   except ValueError:negative_count+=1
   else:raise ValueError('unreviewed_workflow_role_accepted')
  result={'workflow_current':raw,'checkpoint':revision,'source_operation_exit_verified':False,'strict_contract_positive_and_negatives':True,'real_File_only_builder_and_pair_source_preserved':True,'negative_contract_cases':negative_count,'source_SQL_obligations':8,'total_contract_checks':18,'source_inputs':50,'orchestration_only':True,'native_or_source_SQL_HMAC_qualified':False}
 result['source_operation_exit_verified']=True
 return result

def collaborator(tool,args,c):
 mode=c['mode']
 def record(event):
  with open(c['events'],'a')as stream:stream.write(json.dumps({'event':event,'argv':args})+'\n')
 if tool=='git':
  need(args in(['rev-parse','HEAD'],['rev-parse','HEAD^{tree}']));record('git-head'if args[-1]=='HEAD'else'git-tree');print(c['revision']if args[-1]=='HEAD'else c['tree']);return 0
 if tool=='php':
  if args==['-r',c['projection_code']]:record('fixed-real-projector-PHP');os.execv(c['real_php'],[c['real_php'],*args])
  need(args==['support/governance/native-organization/test-oauth-access-owner-mysql.php','--oauth-access-producer-owner'])
  for key,value in {'PHORGE_GOVERNANCE_ISOLATED_TEST':'1','PHORGE_GOVERNANCE_GORGE_FILE_BINARY':c['binary'],'PHORGE_GOVERNANCE_GORGE_FILE_SHA256':c['file_sha'],'PHORGE_GOVERNANCE_FIXTURE_DSN':'mysql:host=127.0.0.1;port=3306;dbname=governance_fixture_bootstrap;charset=utf8mb4','PHORGE_GOVERNANCE_FIXTURE_USER':'root','PHORGE_GOVERNANCE_FIXTURE_PASSWORD':'synthetic-disposable-only','PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION':c['revision']}.items():need(os.environ.get(key)==value)
  record('native-PHP-collaborator');Path(c['native_called']).write_text('orchestration only')
  if mode in('success','consumer-failure'):print(json.dumps({'scope':'offline-Atomic-Access-shell-input-only','native_receipt_accepted':False,'SQL_HMAC_qualified':False}));return 0
  failed={'batch':'native-oauth-access-producer-owner','passed':False,'failure':{'stage':'focused-native-oauth-access-producer-owner','error_type':'RuntimeException','source_file':'oauth-access-producer.php','source_line':17,'message':'PRIVATE_TOKEN_PASSWORD_SQL','token':'PRIVATE_TOKEN_PASSWORD_SQL'}}
  if mode!='stderr-only':print(json.dumps(failed))
  protocol={'scope':'native-conduit-producer-failure-v1','passed':False,'stage':'focused-native-oauth-access-producer-owner','error_type':'RuntimeException','source_file':'oauth-access-cases.php','source_line':29,'password':'PRIVATE_TOKEN_PASSWORD_SQL'}
  print(json.dumps(protocol)if mode=='stderr-only'else'PRIVATE_TOKEN_PASSWORD_SQL',file=sys.stderr);return 37
 if tool=='python3':
  consumer=['-B','scripts/governance/check_recovery_oauth_access_producer_v3.py','--receipt',c['out']+'/integration.json','--source-revision',c['revision'],'--file-binary',c['binary'],'--file-binary-sha256',c['file_sha']]
  projector=['-B','scripts/governance/project_oauth_access_failure.py','--receipt',c['out']+'/integration.json','--stderr',c['stderr']]
  if args==consumer:
   record('strict-CLI-orchestration-collaborator');row=json.loads(Path(c['out']+'/integration.json').read_text());need(row=={'scope':'offline-Atomic-Access-shell-input-only','native_receipt_accepted':False,'SQL_HMAC_qualified':False})
   if mode=='consumer-failure':return 19
   print(json.dumps({'scope':'offline-Atomic-Access-publication-only','native_receipt_accepted':False,'source_SQL_or_HMAC_qualified':False}));return 0
  need(args==projector);record('real-projector')
  if mode=='projector-failure':return 41
  os.execv(c['real_python'],[c['real_python'],*args])
 if tool=='cat':
  need(args==[c['failure']+'/diagnostic.json']);record('cat')
  if mode=='cat-failure':return 52
  os.execv(c['real_cat'],[c['real_cat'],*args])
 raise ValueError('unexpected_Atomic_Access_collaborator')

def run_atomic_phase(root,mode,*,workflow_current,revision):
 root=Path(root).resolve();need(mode in MODES and type(revision)is str and re.fullmatch('[a-f0-9]{40}',revision)is not None and revision!='0'*40)
 need(type(workflow_current)is bytes and workflow_current==(root/WORKFLOW).read_bytes());body=phase(workflow_current)
 with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as td:
  t=Path(td);tools=t/'tools';tools.mkdir();data=t/'runtime';data.mkdir();out=data/'governance-oauth-access-producer-owner';failure=data/'governance-oauth-access-producer-failure';binary=data/'gorge-file-storage';binary.write_bytes(BINARY_DATA);binary.chmod(0o700)
  maps=t/'maps';maps.write_text(''.join(hashlib.sha256((root/n).read_bytes()).hexdigest()+'  '+str(root/n)+'\n'for n in ['src/__phutil_library_map__.php','support/runtime/src/__phutil_library_map__.php','support/runtime/manifest.json']))
  for name in ('governance-batch-1/native.json','governance-native-organization/accepted.json'):
   p=data/name;p.parent.mkdir(exist_ok=True);p.write_text('{"offline_contract_only":true,"native_receipt_accepted":false}')
  if mode=='foreign-failure':failure.mkdir();(failure/'foreign').write_text('preserve')
  marker=t/'github-output'
  if mode=='marker-failure':marker.mkdir()
  diag=(root/'support/governance/recovery/oauth-access-diagnostic.php').read_bytes();need(diag.startswith(b'<?php\n'));projection=diag[6:].decode()+'\necho json_encode(governance_recovery_oauth_access_failure_projection(json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR)));'
  c={'mode':mode,'revision':revision,'tree':'f'*40,'out':str(out),'failure':str(failure),'binary':str(binary),'file_sha':hashlib.sha256(BINARY_DATA).hexdigest(),'stderr':str(data/'oauth-access-native.stderr.private'),'native_called':str(t/'native-called'),'events':str(t/'events'),'projection_code':projection,'real_python':sys.executable,'real_php':shutil.which('php'),'real_cat':shutil.which('cat')}
  config=t/'config.json';config.write_text(json.dumps(c));config.chmod(0o600)
  code='import json,sys;sys.path.insert(0,'+repr(str(Path(__file__).resolve().parent))+');from task_atomic_access_shell_test_v2 import collaborator;raise SystemExit(collaborator(sys.argv[1],sys.argv[3:],json.load(open(sys.argv[2]))))'
  for tool in('git','php','python3','cat'):
   p=tools/tool;p.write_text('#!'+sys.executable+'\nimport sys\nsys.argv=[sys.argv[0],'+repr(tool)+','+repr(str(config))+']+sys.argv[1:]\nexec('+repr(code)+')\n');p.chmod(0o700)
  for tool in('sha256sum','cut','sed','cp','mv','mkdir'):need(shutil.which(tool)is not None);(tools/tool).symlink_to(shutil.which(tool))
  env={**os.environ,'PYTHONDONTWRITEBYTECODE':'1','PATH':str(tools),'RUNNER_TEMP':str(data),'GITHUB_WORKSPACE':str(root),'GITHUB_SHA':revision,'GITHUB_OUTPUT':str(marker),'GORGE_TEST_MYSQL_PASSWORD':'synthetic-disposable-only'}
  try:
   r=subprocess.run(['/bin/bash','-euo','pipefail','-c',body.replace('/tmp/atomic-maps.sha256',str(maps))],cwd=root,env=env,capture_output=True,text=True,timeout=15)
  except subprocess.TimeoutExpired as error:
   observed=[json.loads(line)for line in Path(c['events']).read_text().splitlines()]if Path(c['events']).exists()else[]
   fd,path=tempfile.mkstemp(prefix='Atomic-Access-shell-timeout-',suffix='.private.json')
   with os.fdopen(fd,'w')as stream:json.dump({'scope':'offline-Atomic-Access-timeout-diagnostic','mode':mode,'cap_seconds':15,'event_order':[row['event']for row in observed],'argv_events':observed,'native_or_source_SQL_HMAC_qualified':False},stream)
   error.add_note('private_orchestration_diagnostic='+path)
   raise
  safe=failure/'diagnostic.json';published=out/'accepted.json';events=[json.loads(line)for line in Path(c['events']).read_text().splitlines()]if Path(c['events']).exists()else[]
  if published.exists():need(json.loads(published.read_text())=={'scope':'offline-Atomic-Access-publication-only','native_receipt_accepted':False,'source_SQL_or_HMAC_qualified':False})
  return {'rc':r.returncode,'accepted':published.exists(),'pending':(out/'accepted.pending.json').exists(),'owned':marker.is_file()and'diagnostic_owned=true'in marker.read_text(),'safe':json.loads(safe.read_bytes())if safe.exists()else None,'stderr':r.stderr,'foreign':(failure/'foreign').read_text()if(failure/'foreign').exists()else None,'events':events,'native_called':Path(c['native_called']).exists(),'orchestration_only':True,'native_or_source_SQL_HMAC_qualified':False}
