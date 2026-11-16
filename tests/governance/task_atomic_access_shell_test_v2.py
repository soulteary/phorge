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
def builder_parent_DATA(name,current,parent,provided=None):
 """Closed source bytes only; caller must own the genuine CURRENT window."""
 need(type(name)is str and name in BUILDERS and type(current)is bytes and type(parent)is bytes)
 if provided is not None:need(type(provided)is bytes and provided==current)
 if name=='tests/atomic/paired-gorge.json':
  need(len(current)==149 and hashlib.sha256(current).hexdigest()=='94b47858a458a2fac8ea2d2331def3ac3e5c67edbcaf46030ec45708381140d9')
  need(len(parent)==149 and hashlib.sha256(parent).hexdigest()==BUILDERS[name])
  value=json.loads(current)
  need(type(value.get('schemaVersion'))is int and value=={'schemaVersion':1,'revision':'16318f87a017f82c28f3011b362364cf24b73ef8','release':None,'scope':'unreleased-source-only-test-pair'})
  return parent
 need(current==parent and hashlib.sha256(current).hexdigest()==BUILDERS[name]);return current

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
  assert_current_phase_contract(raw,'oauth-access')
  # Preserve the original real File-only builder and paired Gorge source;
  # these exact reviewed source bytes are DATA, never built by this helper.
  def builder_source(name,provided=None):
   need(type(name)is str and name in BUILDERS)
   return builder_parent_DATA(name,context.current(name),context.parent(name),provided)
  builder_negative_count=0
  for name in BUILDERS:
   baseline_bytes=builder_source(name);need(hashlib.sha256(baseline_bytes).hexdigest()==BUILDERS[name]);current=context.current(name)
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
  result={'workflow_current':raw,'checkpoint':revision,'source_operation_exit_verified':False,'strict_contract_positive_and_negatives':True,'real_File_only_builder_and_pair_source_preserved':True,'reviewed_current_pair_continuation':True,'negative_contract_cases':negative_count,'source_SQL_obligations':8,'total_contract_checks':18,'source_inputs':50,'orchestration_only':True,'native_or_source_SQL_HMAC_qualified':False}
 result['source_operation_exit_verified']=True
 return result

def collaborator(tool,args,c):
 mode=c['mode']
 def record(event):
  with open(c['events'],'a')as stream:stream.write(json.dumps({'event':event,'argv':args})+'\n')
 if tool=='git':
  need(args in(['rev-parse','HEAD'],['rev-parse','HEAD^{tree}']));record('git-head'if args[-1]=='HEAD'else'git-tree');print(c['revision']if args[-1]=='HEAD'else c['tree']);return 0
 if tool=='docker':
  need(args==['inspect','synthetic-db','--format','{{.Config.Image}}']);record('observed-image');print('mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7');return 0
 if tool=='php':
  if len(args)==3 and args[0]in('scripts/governance/probe_conduit_task_mysql84.php','scripts/governance/probe_oauth_access_task_mysql84.php'):
   role='conduit'if c['private'].endswith('/task-conduit-v2-private')else'oauth-access';probe,env,schema=ROLE_RUNTIME[role]
   need(args[0]=='scripts/governance/'+probe and args[1]in('before','after')and args[2]==c['revision']and os.environ.get(env)==IMAGE);record('runtime-'+args[1]);print(json.dumps({'schema':schema}));return 0
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
  if args[:1]==['-c']:
   need(args==['-c','import json,sys; print(json.load(open(sys.argv[1]))["profile_sha256"])',c['private']+'/capture/binding.safe.json']);need(json.loads(Path(args[2]).read_bytes())=={'profile_sha256':'a'*64});print('a'*64);return 0
  if len(args)>1 and args[1]=='scripts/governance/check_task_ci_head_v1.py':
   record('public-trust'if '--prepare-public-trust'in args else 'event-head');return 0
  if len(args)>1 and args[1]=='scripts/governance/run_task_directory_native_v2.py':
   need(args==['-B','scripts/governance/run_task_directory_native_v2.py','--root',c['root'],'--expected-checkpoint',c['revision'],'--role','oauth-access','--directory',c['private'],'--stdout',c['out']+'/integration.json','--stderr',c['stderr']])
   import io,contextlib
   out=io.StringIO();err=io.StringIO()
   with contextlib.redirect_stdout(out),contextlib.redirect_stderr(err):status=collaborator('php',['support/governance/native-organization/test-oauth-access-owner-mysql.php','--oauth-access-producer-owner'],c)
   private=Path(c['private']);(private/'capture').mkdir(parents=True);(private/'capture/binding.safe.json').write_text(json.dumps({'profile_sha256':'a'*64}))
   Path(c['stderr']).write_text(err.getvalue());Path(c['out']+'/integration.json').write_text(out.getvalue())
   if out.getvalue():Path(c['private']+'/capture-native-body.private.json').write_text(out.getvalue())
   return status
  if len(args)>1 and args[1]=='scripts/governance/check_oauth_access_task_schema_receipt_v2.py':
   need(args==['-B','scripts/governance/check_oauth_access_task_schema_receipt_v2.py','--root',c['root'],'--expected-checkpoint',c['revision'],'--envelope',c['out']+'/integration.json','--profile',c['private']+'/capture/schema-profile.private.json','--profile-sha','a'*64,'--runtime-before',c['out']+'/mysql-before.json','--runtime-after',c['out']+'/mysql-after.json','--file-binary',c['binary'],'--file-sha',c['file_sha']])
   record('new-typed-consumer-orchestration-only');need(json.loads(Path(c['out']+'/integration.json').read_text())=={'scope':'offline-Atomic-Access-shell-input-only','native_receipt_accepted':False,'SQL_HMAC_qualified':False})
   if mode=='consumer-failure':return 19
   print(json.dumps({'scope':'offline-Atomic-Access-publication-only','native_receipt_accepted':False,'source_SQL_or_HMAC_qualified':False}));return 0
  consumer=['-B','scripts/governance/check_recovery_oauth_access_producer_v3.py','--receipt',c['out']+'/integration.json','--source-revision',c['revision'],'--file-binary',c['binary'],'--file-binary-sha256',c['file_sha']]
  projector=['-B','scripts/governance/project_oauth_access_failure.py','--receipt',c['private']+'/capture-native-body.private.json','--stderr',c['stderr']]
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
 need(type(workflow_current)is bytes and workflow_current==(root/WORKFLOW).read_bytes());assert_current_phase_contract(workflow_current,'oauth-access');body=phase(workflow_current)
 with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as td:
  t=Path(td);tools=t/'tools';tools.mkdir();data=t/'runtime';data.mkdir();out=data/'governance-oauth-access-producer-owner';failure=data/'governance-oauth-access-producer-failure';binary=data/'gorge-file-storage';binary.write_bytes(BINARY_DATA);binary.chmod(0o700)
  maps=t/'maps';maps.write_text(''.join(hashlib.sha256((root/n).read_bytes()).hexdigest()+'  '+str(root/n)+'\n'for n in ['src/__phutil_library_map__.php','support/runtime/src/__phutil_library_map__.php','support/runtime/manifest.json']))
  for name in ('governance-batch-1/native.json','governance-native-organization/accepted.json'):
   p=data/name;p.parent.mkdir(exist_ok=True);p.write_text('{"offline_contract_only":true,"native_receipt_accepted":false}')
  if mode=='foreign-failure':failure.mkdir();(failure/'foreign').write_text('preserve')
  marker=t/'github-output'
  if mode=='marker-failure':marker.mkdir()
  diag=(root/'support/governance/recovery/oauth-access-diagnostic.php').read_bytes();need(diag.startswith(b'<?php\n'));projection=diag[6:].decode()+'\necho json_encode(governance_recovery_oauth_access_failure_projection(json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR)));'
  c={'root':str(root),'private':str(data/'task-oauth-access-v2-private'),'mode':mode,'revision':revision,'tree':'f'*40,'out':str(out),'failure':str(failure),'binary':str(binary),'file_sha':hashlib.sha256(BINARY_DATA).hexdigest(),'stderr':str(data/'oauth-access-native.stderr.private'),'native_called':str(t/'native-called'),'events':str(t/'events'),'projection_code':projection,'real_python':sys.executable,'real_php':shutil.which('php'),'real_cat':shutil.which('cat')}
  config=t/'config.json';config.write_text(json.dumps(c));config.chmod(0o600)
  code='import json,sys;sys.path.insert(0,'+repr(str(Path(__file__).resolve().parent))+');from task_atomic_access_shell_test_v2 import collaborator;raise SystemExit(collaborator(sys.argv[1],sys.argv[3:],json.load(open(sys.argv[2]))))'
  for tool in('git','php','python3','cat','docker'):
   p=tools/tool;p.write_text('#!'+sys.executable+'\nimport sys\nsys.argv=[sys.argv[0],'+repr(tool)+','+repr(str(config))+']+sys.argv[1:]\nexec('+repr(code)+')\n');p.chmod(0o700)
  for tool in('sha256sum','cut','sed','cp','mv','mkdir'):need(shutil.which(tool)is not None);(tools/tool).symlink_to(shutil.which(tool))
  env={**os.environ,'PYTHONDONTWRITEBYTECODE':'1','PATH':str(tools),'RUNNER_TEMP':str(data),'GITHUB_WORKSPACE':str(root),'GITHUB_SHA':revision,'EXPECTED_SOURCE_CHECKPOINT':revision,'MYSQL_CONTAINER':'synthetic-db','GITHUB_EVENT_PATH':str(t/'event.json'),'GITHUB_EVENT_NAME':'pull_request','GITHUB_OUTPUT':str(marker),'GORGE_TEST_MYSQL_PASSWORD':'synthetic-disposable-only'}
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

CURRENT_COMMANDS={'conduit': ('python3 -B scripts/governance/check_task_ci_head_v1.py --prepare-public-trust --public-key "$GITHUB_WORKSPACE/support/governance/source-operation/ci-source-verifier.asc" --trust-home "$trust_home"', 'python3 -B scripts/governance/check_task_ci_head_v1.py --root "$GITHUB_WORKSPACE" --event-file "$GITHUB_EVENT_PATH" --event-name "$GITHUB_EVENT_NAME" --expected-head "$EXPECTED_SOURCE_CHECKPOINT" --github-sha "$GITHUB_SHA" --trust-home "$trust_home"', 'python3 -B scripts/governance/run_task_directory_native_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --role conduit --directory "$RUNNER_TEMP/task-conduit-v2-private" --stdout "$RUNNER_TEMP/governance-conduit-producer-owner/integration.json" --stderr "$RUNNER_TEMP/conduit-native.stderr.private" || {', 'python3 -B scripts/governance/check_conduit_task_schema_receipt_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --envelope "$RUNNER_TEMP/governance-conduit-producer-owner/integration.json" --profile "$RUNNER_TEMP/task-conduit-v2-private/capture/schema-profile.private.json" --profile-sha "$(python3 -c \'import json,sys; print(json.load(open(sys.argv[1]))["profile_sha256"])\' "$RUNNER_TEMP/task-conduit-v2-private/capture/binding.safe.json")" --runtime-before "$RUNNER_TEMP/governance-conduit-producer-owner/mysql-before.json" --runtime-after "$RUNNER_TEMP/governance-conduit-producer-owner/mysql-after.json" --file-binary "$RUNNER_TEMP/gorge-file-storage" --file-sha "$(sha256sum "$RUNNER_TEMP/gorge-file-storage" | cut -d \' \' -f1)" > "$RUNNER_TEMP/governance-conduit-producer-owner/accepted.pending.json"'), 'oauth-access': ('python3 -B scripts/governance/check_task_ci_head_v1.py --prepare-public-trust --public-key "$GITHUB_WORKSPACE/support/governance/source-operation/ci-source-verifier.asc" --trust-home "$trust_home"', 'python3 -B scripts/governance/check_task_ci_head_v1.py --root "$GITHUB_WORKSPACE" --event-file "$GITHUB_EVENT_PATH" --event-name "$GITHUB_EVENT_NAME" --expected-head "$EXPECTED_SOURCE_CHECKPOINT" --github-sha "$GITHUB_SHA" --trust-home "$trust_home"', 'python3 -B scripts/governance/run_task_directory_native_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --role oauth-access --directory "$RUNNER_TEMP/task-oauth-access-v2-private" --stdout "$RUNNER_TEMP/governance-oauth-access-producer-owner/integration.json" --stderr "$RUNNER_TEMP/oauth-access-native.stderr.private" || {', 'python3 -B scripts/governance/check_oauth_access_task_schema_receipt_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --envelope "$RUNNER_TEMP/governance-oauth-access-producer-owner/integration.json" --profile "$RUNNER_TEMP/task-oauth-access-v2-private/capture/schema-profile.private.json" --profile-sha "$(python3 -c \'import json,sys; print(json.load(open(sys.argv[1]))["profile_sha256"])\' "$RUNNER_TEMP/task-oauth-access-v2-private/capture/binding.safe.json")" --runtime-before "$RUNNER_TEMP/governance-oauth-access-producer-owner/mysql-before.json" --runtime-after "$RUNNER_TEMP/governance-oauth-access-producer-owner/mysql-after.json" --file-binary "$RUNNER_TEMP/gorge-file-storage" --file-sha "$(sha256sum "$RUNNER_TEMP/gorge-file-storage" | cut -d \' \' -f1)" > "$RUNNER_TEMP/governance-oauth-access-producer-owner/accepted.pending.json"')}
CURRENT_FAILURE_HANDLERS={'conduit': '            native_status=$?\n            if mkdir "$RUNNER_TEMP/governance-conduit-producer-failure"; then\n              if python3 -B scripts/governance/project_conduit_failure.py --receipt "$RUNNER_TEMP/task-conduit-v2-private/capture-native-body.private.json" > "$RUNNER_TEMP/governance-conduit-producer-failure/diagnostic.pending.json"; then\n                if mv "$RUNNER_TEMP/governance-conduit-producer-failure/diagnostic.pending.json" "$RUNNER_TEMP/governance-conduit-producer-failure/diagnostic.json"; then\n                  if cat "$RUNNER_TEMP/governance-conduit-producer-failure/diagnostic.json" >&2; then :; fi\n                  if [ -n "${GITHUB_OUTPUT-}" ] && echo "diagnostic_owned=true" >> "${GITHUB_OUTPUT-}"; then :; fi\n                fi\n              fi\n            fi\n            exit "$native_status"\n          }\n', 'oauth-access': '            native_status=$?\n            if mkdir "$RUNNER_TEMP/governance-oauth-access-producer-failure"; then\n              if python3 -B scripts/governance/project_oauth_access_failure.py --receipt "$RUNNER_TEMP/task-oauth-access-v2-private/capture-native-body.private.json" --stderr "$RUNNER_TEMP/oauth-access-native.stderr.private" > "$RUNNER_TEMP/governance-oauth-access-producer-failure/diagnostic.pending.json"; then\n                if mv "$RUNNER_TEMP/governance-oauth-access-producer-failure/diagnostic.pending.json" "$RUNNER_TEMP/governance-oauth-access-producer-failure/diagnostic.json"; then\n                  if cat "$RUNNER_TEMP/governance-oauth-access-producer-failure/diagnostic.json" >&2; then :; fi\n                  if [ -n "${GITHUB_OUTPUT-}" ] && echo "diagnostic_owned=true" >> "${GITHUB_OUTPUT-}"; then :; fi\n                fi\n              fi\n            fi\n            exit "$native_status"\n          }\n'}
def assert_current_phase_contract(raw,role):
 need(role in CURRENT_COMMANDS and type(raw)is bytes)
 text=raw.decode();selected=[x for x in text.split('      - name: ')if '--role '+role+' --directory'in x];need(len(selected)==1);step=selected[0].split('      - uses:',1)[0]
 need('continue-on-error'not in step and CURRENT_FAILURE_HANDLERS[role]in step)
 actual=tuple(x.strip()for x in step.splitlines()if 'python3 -B scripts/governance/'in x and not 'project_'in x)
 need(actual==CURRENT_COMMANDS[role])
 producer=next(x for x in actual if 'run_task_directory_native_v2.py'in x);consumer=next(x for x in actual if 'schema_receipt_v2.py'in x)
 need(step.index(producer)<step.index(consumer)and step.count("mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7")==2)
 need('--native-body'not in consumer and '--role'not in consumer)
 probe,env,schema=ROLE_RUNTIME[role]
 need(step.count('php scripts/governance/'+probe+' ')==2 and step.count(env+'="$mysql_image"')==2)
 need('probe_full_native_task_mysql84.php'not in step and 'FULL_NATIVE_MYSQL_OBSERVED_IMAGE'not in step)
 for phase_name in('before','after'):need(env+'="$mysql_image" php scripts/governance/'+probe+' '+phase_name+' "$EXPECTED_SOURCE_CHECKPOINT"'in step)
 need('accepted.pending.json' in consumer and 'mv "$RUNNER_TEMP/' in step)
 return True

def conduit_collaborator(tool,args,c):
 """Exact separate Conduit orchestration collaborator, never SQL authority."""
 mode=c['mode']
 if tool=='php'and args==['support/governance/native-organization/test-native-organization-mysql.php','--conduit-producer-owner']:
  Path(c['native_called']).write_text('orchestration only')
  if mode in('success','consumer-failure'):print('{"orchestration_only":true}');return 0
  failed={'batch':'native-conduit-producer-owner','passed':False,'failure':{'stage':'focused-native-conduit-producer-owner','error_type':'RuntimeException','source_file':'conduit-producer.php','source_line':17,'message':'PRIVATE_TOKEN_PASSWORD_SQL'}}
  if mode!='stderr-only':print(json.dumps(failed))
  return 37
 if tool=='python3'and len(args)>1:
  if args[1]=='scripts/governance/run_task_directory_native_v2.py':
   need(args==['-B',args[1],'--root',c['root'],'--expected-checkpoint',c['revision'],'--role','conduit','--directory',c['private'],'--stdout',c['out']+'/integration.json','--stderr',c['stderr']])
   import io,contextlib
   out=io.StringIO()
   with contextlib.redirect_stdout(out):status=conduit_collaborator('php',['support/governance/native-organization/test-native-organization-mysql.php','--conduit-producer-owner'],c)
   private=Path(c['private']);(private/'capture').mkdir(parents=True);(private/'capture/binding.safe.json').write_text(json.dumps({'profile_sha256':'a'*64}));Path(c['out']+'/integration.json').write_text(out.getvalue());Path(c['stderr']).write_text('')
   if out.getvalue():Path(c['private']+'/capture-native-body.private.json').write_text(out.getvalue())
   return status
  if args[1]=='scripts/governance/check_conduit_task_schema_receipt_v2.py':
   need(json.loads(Path(c['out']+'/integration.json').read_text())=={'orchestration_only':True})
   need(args==['-B',args[1],'--root',c['root'],'--expected-checkpoint',c['revision'],'--envelope',c['out']+'/integration.json','--profile',c['private']+'/capture/schema-profile.private.json','--profile-sha','a'*64,'--runtime-before',c['out']+'/mysql-before.json','--runtime-after',c['out']+'/mysql-after.json','--file-binary',c['binary'],'--file-sha',c['file_sha']])
   if mode=='consumer-failure':return 19
   print(json.dumps({'scope':'offline-Atomic-Access-publication-only','native_receipt_accepted':False,'source_SQL_or_HMAC_qualified':False}));return 0
  if args[1]=='scripts/governance/project_conduit_failure.py':
   need(args==['-B',args[1],'--receipt',c['private']+'/capture-native-body.private.json'])
   if mode=='projector-failure':return 41
   os.execv(c['real_python'],[c['real_python'],*args])
 return collaborator(tool,args,c)
def run_conduit_phase(root,mode,*,workflow_current,revision):
 root=Path(root).resolve();need(mode in MODES and type(revision)is str and re.fullmatch('[a-f0-9]{40}',revision)is not None and revision!='0'*40)
 need(type(workflow_current)is bytes and workflow_current==(root/WORKFLOW).read_bytes());assert_current_phase_contract(workflow_current,'conduit');text=workflow_current.decode();selected=text.split('      - name: Verify fresh native Conduit producer ownership after borrowed Session owner',1)[1];body=textwrap.dedent(selected.split('        run: |\n',1)[1].split('      - uses:',1)[0])
 with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as td:
  t=Path(td);tools=t/'tools';tools.mkdir();data=t/'runtime';data.mkdir();out=data/'governance-conduit-producer-owner';failure=data/'governance-conduit-producer-failure';binary=data/'gorge-file-storage';binary.write_bytes(BINARY_DATA);binary.chmod(0o700)
  maps=t/'maps';maps.write_text(''.join(hashlib.sha256((root/n).read_bytes()).hexdigest()+'  '+str(root/n)+'\n'for n in ['src/__phutil_library_map__.php','support/runtime/src/__phutil_library_map__.php','support/runtime/manifest.json']))
  for name in ('governance-batch-1/native.json','governance-native-organization/accepted.json'):
   p=data/name;p.parent.mkdir(exist_ok=True);p.write_text('{"offline_contract_only":true,"native_receipt_accepted":false}')
  if mode=='foreign-failure':failure.mkdir();(failure/'foreign').write_text('preserve')
  marker=t/'github-output'
  if mode=='marker-failure':marker.mkdir()
  diag=(root/'support/governance/recovery/conduit-diagnostic.php').read_bytes();need(diag.startswith(b'<?php\n'));projection=diag[6:].decode()+'\necho json_encode(governance_recovery_conduit_failure_projection(json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR)));'
  c={'root':str(root),'private':str(data/'task-conduit-v2-private'),'mode':mode,'revision':revision,'tree':'f'*40,'out':str(out),'failure':str(failure),'binary':str(binary),'file_sha':hashlib.sha256(BINARY_DATA).hexdigest(),'stderr':str(data/'conduit-native.stderr.private'),'native_called':str(t/'native-called'),'events':str(t/'events'),'projection_code':projection,'real_python':sys.executable,'real_php':shutil.which('php'),'real_cat':shutil.which('cat')}
  config=t/'config.json';config.write_text(json.dumps(c));config.chmod(0o600)
  code='import json,sys;sys.path.insert(0,'+repr(str(Path(__file__).resolve().parent))+');from task_atomic_access_shell_test_v2 import conduit_collaborator as collaborator;raise SystemExit(collaborator(sys.argv[1],sys.argv[3:],json.load(open(sys.argv[2]))))'
  for tool in('git','php','python3','cat','docker'):
   p=tools/tool;p.write_text('#!'+sys.executable+'\nimport sys\nsys.argv=[sys.argv[0],'+repr(tool)+','+repr(str(config))+']+sys.argv[1:]\nexec('+repr(code)+')\n');p.chmod(0o700)
  for tool in('sha256sum','cut','sed','cp','mv','mkdir'):need(shutil.which(tool)is not None);(tools/tool).symlink_to(shutil.which(tool))
  env={**os.environ,'PYTHONDONTWRITEBYTECODE':'1','PATH':str(tools),'RUNNER_TEMP':str(data),'GITHUB_WORKSPACE':str(root),'GITHUB_SHA':revision,'EXPECTED_SOURCE_CHECKPOINT':revision,'MYSQL_CONTAINER':'synthetic-db','GITHUB_EVENT_PATH':str(t/'event.json'),'GITHUB_EVENT_NAME':'pull_request','GITHUB_OUTPUT':str(marker),'GORGE_TEST_MYSQL_PASSWORD':'synthetic-disposable-only'}
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

IMAGE='mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'
ROLE_RUNTIME={'conduit':('probe_conduit_task_mysql84.php','CONDUIT_MYSQL_OBSERVED_IMAGE','native-conduit-mysql-runtime-v1'),'oauth-access':('probe_oauth_access_task_mysql84.php','OAUTH_ACCESS_MYSQL_OBSERVED_IMAGE','native-oauth-access-mysql-runtime-v1')}
