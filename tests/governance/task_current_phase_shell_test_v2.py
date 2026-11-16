"""Current workflow shell orchestration controls. No native acceptance or SQL.
Collaborators accept exact argv/env only. Success publication contains explicit
nonacceptance DATA; this helper never asserts native/HMAC/source qualification.
"""
import hashlib,json,os,shutil,subprocess,sys,tempfile,textwrap
from pathlib import Path
IMAGE='mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'
ROLES={
 'oauth-authorization':('oauth-authorization-producer-focused.yml','probe_oauth_authorization_mysql84.php','check_oauth_task_schema_receipt_v2.py','project_oauth_authorization_failure.py','native-oauth-authorization-producer-owner'),
 'oauth-client-lifecycle':('oauth-client-lifecycle-focused.yml','probe_oauth_authorization_mysql84.php','check_oauth_task_schema_receipt_v2.py','project_oauth_client_lifecycle_failure.py','native-oauth-client-lifecycle-owner'),
 'oauth-access':('oauth-access-producer-focused.yml','probe_oauth_access_task_mysql84.php','check_oauth_access_task_schema_receipt_v2.py','project_oauth_access_failure.py','native-oauth-access-producer-owner'),
 'oauth-code':('oauth-code-producer-focused.yml','probe_oauth_code_task_mysql84.php','check_oauth_code_task_schema_receipt_v2.py','project_oauth_code_failure.py','native-oauth-code-producer-owner')}
MODES={'success','native-failure','projector-failure','marker-failure','cat-failure','foreign-failure','stderr-only','version-before-failure','version-after-failure','image-failure','consumer-failure'}
def need(v):
 if v is not True:raise ValueError('offline_current_phase_contract_refused')
def phase(raw):
 need(type(raw)is bytes);text=raw.decode();start=text.index('      - name: Run only actual native OAuth')
 return textwrap.dedent(text[start:].split('        run: |\n',1)[1].split('      - uses:',1)[0])
def expected(c,kind):
 root=c['root'];rev=c['revision'];run=c['run'];out=c['out'];stem=c['role'];binary=c['binary'];spec=ROLES[stem]
 if kind=='runner':return ['-B','scripts/governance/run_task_directory_native_v2.py','--root',root,'--expected-checkpoint',rev,'--role',stem,'--directory',run,'--stdout',out+'/integration.json','--stderr',c['stderr']]
 if kind=='consumer':
  args=['-B','scripts/governance/'+spec[2],'--root',root,'--expected-checkpoint',rev]
  if stem in('oauth-authorization','oauth-client-lifecycle'):args+=['--role',stem]
  return args+['--runtime-before',out+'/mysql-before.json','--runtime-after',out+'/mysql-after.json','--envelope',out+'/integration.json','--profile',run+'/capture/schema-profile.private.json','--profile-sha','c'*64,'--file-binary',binary,'--file-sha',c['file_sha']]
 if kind=='projector':return ['-B','scripts/governance/'+spec[3],'--receipt',run+'/capture-native-body.private.json','--stderr',c['stderr']]
 raise ValueError('unknown collaborator kind')
def collaborator(tool,args,c):
 role=c['role'];spec=ROLES[role];mode=c['mode'];event=None
 def record(name):
  with open(c['events'],'a')as stream:stream.write(json.dumps({'event':name,'argv':args})+'\n')
 if tool=='git':need(args==['rev-parse','HEAD']);record('git-head');print(c['revision']);return 0
 if tool=='docker':
  need(args==['inspect','synthetic-ci-container','--format','{{.Config.Image}}']);record('image');print('mysql:8.0'if mode=='image-failure'else IMAGE);return 0
 if tool=='php':
  if args==['-r',c['projection_code']]:record('fixed-real-projector-PHP');os.execv(c['real_php'],[c['real_php'],*args])
  need(len(args)==3 and args[0]=='scripts/governance/'+spec[1]and args[1]in('before','after')and args[2]==c['revision'])
  env='AUTHZ_MYSQL_OBSERVED_IMAGE'if role in('oauth-authorization','oauth-client-lifecycle')else('OAUTH_ACCESS_MYSQL_OBSERVED_IMAGE'if role=='oauth-access'else'OAUTH_CODE_MYSQL_OBSERVED_IMAGE');need(os.environ.get(env)==IMAGE);record('runtime-'+args[1])
  if mode=='version-'+args[1]+'-failure':return 38
  schema='native-oauth-authorization-mysql-runtime-v1'if role in('oauth-authorization','oauth-client-lifecycle')else'native-'+role+'-mysql-runtime-v1'
  print(json.dumps({'schema':schema,'phase':args[1],'source_revision':c['revision'],'version':'8.4.13','server_uuid_sha256':'d'*64,'observed_service_image':IMAGE}));return 0
 if tool=='python3':
  if args==expected(c,'runner'):
   for key,value in {'PHORGE_GOVERNANCE_ISOLATED_TEST':'1','PHORGE_GOVERNANCE_GORGE_FILE_BINARY':c['binary'],'PHORGE_GOVERNANCE_GORGE_FILE_SHA256':c['file_sha'],'PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION':c['revision'],'PHORGE_GOVERNANCE_FIXTURE_DSN':'mysql:host=127.0.0.1;port=3306;dbname=governance_fixture_bootstrap;charset=utf8mb4','PHORGE_GOVERNANCE_FIXTURE_USER':'root','PHORGE_GOVERNANCE_FIXTURE_PASSWORD':'synthetic-disposable-only'}.items():need(os.environ.get(key)==value)
   record('runner');Path(c['native_called']).write_text('orchestration collaborator only');run=Path(c['run']);run.mkdir(mode=0o700);(run/'capture').mkdir(mode=0o700)
   native={'batch':spec[4],'passed':False,'failure':{'stage':'focused-native-'+role+'-producer-owner','error_type':'RuntimeException','source_file':role+'-producer.php','source_line':17,'message':'PRIVATE_TOKEN_PASSWORD_SQL','token':'PRIVATE_TOKEN_PASSWORD_SQL'}}
   raw=''if mode=='stderr-only'else json.dumps(native);(run/'capture-native-body.private.json').write_text(raw);(run/'capture-native-body.private.json').chmod(0o600)
   protocol={'scope':'native-oauth-client-lifecycle-failure-v1'if role=='oauth-client-lifecycle'else'native-conduit-producer-failure-v1','passed':False,'stage':'focused-native-'+role+'-producer-owner','error_type':'RuntimeException','source_file':role+'-cases.php','source_line':29,'password':'PRIVATE_TOKEN_PASSWORD_SQL'}
   Path(c['stderr']).write_text(json.dumps(protocol)if mode=='stderr-only'else'PRIVATE_TOKEN_PASSWORD_SQL');Path(c['stderr']).chmod(0o600)
   success=mode in('success','version-before-failure','version-after-failure','consumer-failure')
   (run/'capture/binding.safe.json').write_text(json.dumps({'profile_sha256':'c'*64}))
   (run/'capture/schema-profile.private.json').write_text('OFFLINE DATA; not an actual profile')
   Path(c['out']+'/integration.json').write_text(json.dumps({'scope':'offline-shell-input-only','native_receipt_accepted':False,'runtime_authority':False}))
   return 0 if success else 37
  if args==expected(c,'consumer'):
   record('consumer');need(json.loads(Path(c['out']+'/integration.json').read_text())['native_receipt_accepted']is False)
   if mode=='consumer-failure':return 19
   print(json.dumps({'scope':'offline-shell-publication-only','native_receipt_accepted':False,'source_SQL_or_HMAC_qualified':False}));return 0
  if args==expected(c,'projector'):
   record('projector')
   if mode=='projector-failure':return 41
   os.execv(c['real_python'],[c['real_python'],*args])
  need(args==['-c','import json,sys; print(json.load(open(sys.argv[1]))["profile_sha256"])',c['run']+'/capture/binding.safe.json']);record('profile-pin');print(json.loads(Path(args[2]).read_text())['profile_sha256']);return 0
 if tool=='cat':
  need(args==[c['failure']+'/diagnostic.json']);record('cat')
  if mode=='cat-failure':return 52
  os.execv(c['real_cat'],[c['real_cat'],*args])
 raise ValueError('unexpected offline collaborator')
def run_phase(root,role,mode,*,workflow_current,revision):
 root=Path(root).resolve();need(role in ROLES and mode in MODES and type(revision)is str and len(revision)==40 and all(x in'0123456789abcdef'for x in revision)and revision!='0'*40)
 need(type(workflow_current)is bytes and workflow_current==(root/'.github/workflows'/ROLES[role][0]).read_bytes());body=phase(workflow_current)
 with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as td:
  t=Path(td);tools=t/'tools';tools.mkdir();data=t/'runtime';data.mkdir();stem=role;out=data/('governance-'+stem+'-focused');out.mkdir();failure=data/(stem+'-focused-failure');run=data/('task-'+stem+'-v2-private')
  binary=data/(stem+'-focused-binary/gorge-file-storage');binary.parent.mkdir();binary.write_bytes(b'File DATA only; never executed');binary.chmod(0o700)
  maps=['src/__phutil_library_map__.php','support/runtime/src/__phutil_library_map__.php','support/runtime/manifest.json'];(out/'verified-maps.sha256').write_text(''.join(hashlib.sha256((root/n).read_bytes()).hexdigest()+'  '+str(root/n)+'\n'for n in maps))
  if mode=='foreign-failure':failure.mkdir();(failure/'foreign').write_text('preserve')
  marker=t/'github-output'
  if mode=='marker-failure':marker.mkdir()
  diag=(root/('support/governance/recovery/'+stem+'-diagnostic.php')).read_bytes();need(diag.startswith(b'<?php\n'))
  function='governance_recovery_'+stem.replace('-','_')+'_failure_projection';projection=diag[6:].decode()+'\necho json_encode('+function+'(json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR)));'
  c={'root':str(root),'role':role,'mode':mode,'revision':revision,'out':str(out),'run':str(run),'failure':str(failure),'binary':str(binary),'file_sha':hashlib.sha256(binary.read_bytes()).hexdigest(),'stderr':str(data/(stem+'-native.stderr.private')),'native_called':str(t/'native-called'),'events':str(t/'events'),'real_python':sys.executable,'real_php':shutil.which('php'),'real_cat':shutil.which('cat'),'projection_code':projection}
  config=t/'config.json';config.write_text(json.dumps(c));config.chmod(0o600)
  code='import json,sys;sys.path.insert(0,'+repr(str(Path(__file__).resolve().parent))+');from task_current_phase_shell_test_v2 import collaborator;raise SystemExit(collaborator(sys.argv[1],sys.argv[3:],json.load(open(sys.argv[2]))))'
  for tool in('git','docker','php','python3','cat'):
   p=tools/tool;p.write_text('#!'+sys.executable+'\n'+"import sys\nexec("+repr(code)+')\n');p.chmod(0o700)
   # argv starts with wrapper name; insert immutable tool/config coordinates.
   p.write_text('#!'+sys.executable+'\nimport sys\nsys.argv=[sys.argv[0],'+repr(tool)+','+repr(str(config))+']+sys.argv[1:]\nexec('+repr(code)+')\n');p.chmod(0o700)
  for tool in('sha256sum','cut','mv','mkdir'):need(shutil.which(tool)is not None);(tools/tool).symlink_to(shutil.which(tool))
  env={**os.environ,'PATH':str(tools),'RUNNER_TEMP':str(data),'GITHUB_WORKSPACE':str(root),'GITHUB_SHA':revision,'EXPECTED_SOURCE_CHECKPOINT':revision,'GITHUB_OUTPUT':str(marker),'GORGE_TEST_MYSQL_PASSWORD':'synthetic-disposable-only','MYSQL_CONTAINER':'synthetic-ci-container'}
  r=subprocess.run(['/bin/bash','-euo','pipefail','-c',body],cwd=root,env=env,capture_output=True,text=True,timeout=15)
  safe=failure/'diagnostic.json';events=[json.loads(x)for x in Path(c['events']).read_text().splitlines()]if Path(c['events']).exists()else[]
  return {'native_called':Path(c['native_called']).exists(),'rc':r.returncode,'accepted':(out/'accepted.json').exists(),'pending':(out/'accepted.pending.json').exists(),'owned':marker.is_file()and'diagnostic_owned=true'in marker.read_text(),'safe':json.loads(safe.read_bytes())if safe.exists()else None,'stderr':r.stderr,'foreign':(failure/'foreign').read_text()if(failure/'foreign').exists()else None,'events':events,'orchestration_only':True,'native_or_source_SQL_HMAC_qualified':False}
