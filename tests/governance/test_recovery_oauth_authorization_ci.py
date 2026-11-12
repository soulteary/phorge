import hashlib
"""Execute actual CI phase shells with owned files and fixed process collaborators.
No database, Docker, native fixture or binary execution is performed.
"""
from pathlib import Path
import copy,json,os,re,shlex,shutil,subprocess,sys,tempfile,textwrap,unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_oauth_authorization_failure as projector
import check_recovery_oauth_authorization_producer_v1 as code_gate
import test_recovery_oauth_authorization_sql as sql_controls
import test_native_organization as base_template
ATOMIC=ROOT/'.github/workflows/atomic-feature-regressions.yml'
FOCUSED=ROOT/'.github/workflows/oauth-authorization-producer-focused.yml'
def phase(which):
 text=(ATOMIC if which=='atomic'else FOCUSED).read_text()
 if which=='atomic':text=text.split('# BEGIN isolated OAuth access-token SQL8',1)[1]
 else:text=text.split('      - name: Run only actual native OAuth',1)[1]
 return textwrap.dedent(text.split('        run: |\n',1)[1].split('      - uses:',1)[0])
def historical_atomic_source(path='.github/workflows/atomic-feature-regressions.yml',raw=None):
 # Test-only exact current Atomic -> actual20b view; runtime readers stay current.
 import project_description_admission_delta as current
 current.assert_current_recipe(ROOT)
 producer='scripts/governance/check_recovery_oauth_authorization_producer_v1.py'
 if path!='.github/workflows/atomic-feature-regressions.yml' or path not in current.CURRENT_METADATA or producer not in current.CURRENT_METADATA:
  raise ValueError('unreviewed Atomic historical source role')
 if Path(code_gate.__file__).resolve()!=ROOT/producer:raise ValueError('unreviewed current producer source role')
 if (ROOT/producer).stat().st_mode&0o7777!=0o644:raise ValueError('unknown current producer mode')
 producer_raw=current.description.checked_bytes(ROOT/producer);pin=current.CURRENT_METADATA[producer]
 if len(producer_raw)!=pin['bytes'] or hashlib.sha256(producer_raw).hexdigest()!=pin['sha256']:raise ValueError('unknown current producer bytes')
 if (ROOT/path).stat().st_mode&0o7777!=0o644:raise ValueError('unknown current Atomic source mode')
 actual=current.description.checked_bytes(ROOT/path)
 if raw is None:raw=actual
 if type(raw)is not bytes or raw!=actual:raise ValueError('unknown current Atomic source bytes')
 prior=current._normalize_metadata(path,raw)
 expected=subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(ROOT),'show','20b138b4cd9a2cf61522613b4a8e5e21eca7341b:'+path])
 if prior!=expected:raise ValueError('unknown Atomic historical stage')
 return prior.decode('utf-8')
class OAuthAuthorizationCI(unittest.TestCase):
 def test_current_Atomic_history_refuses_unknown_names_bytes_and_producer_role(self):
  from unittest.mock import patch
  path='.github/workflows/atomic-feature-regressions.yml';raw=(ROOT/path).read_bytes()
  old=subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(ROOT),'show','20b138b4cd9a2cf61522613b4a8e5e21eca7341b:'+path])
  self.assertEqual(historical_atomic_source().encode(),old)
  for name,body in [('.github/workflows/unreviewed.yml',raw),(path,raw+b'\n# unknown'),(path,raw.decode())]:
   with self.subTest(name=name),self.assertRaises(ValueError):historical_atomic_source(name,body)
  if raw!=old:
   with self.assertRaises(ValueError):historical_atomic_source(path,old)
  with patch.object(code_gate,'__file__',str(ROOT/'scripts/governance/unreviewed.py')):
   with self.assertRaises(ValueError):historical_atomic_source()
 def test_exact_new_workflow_unknown_bytes_and_names_refuse(self):
  import recovery_oauth_authorization_ci_delta as ci
  raw=FOCUSED.read_bytes();ci.assert_workflow(ci.WORKFLOW,raw)
  for path,body in [(ci.WORKFLOW,raw+b'\n# unauthorized'),('.github/workflows/unreviewed.yml',raw)]:
   with self.assertRaises(ValueError):ci.assert_workflow(path,body)
  self.assertEqual((len(code_gate.SOURCE_INPUTS),len(code_gate.SQL_obligations()),len(code_gate.obligations())),(56,20,30))

 def test_existing_workflows_and_real_File_builder_are_unchanged(self):
  for name in ['.github/workflows/oauth-code-producer-focused.yml','.github/workflows/atomic-feature-regressions.yml','.github/workflows/oauth-access-producer-focused.yml','.github/workflows/conduit-producer-focused.yml','tests/atomic/build_directory_file.py','tests/atomic/build_gorge.py','tests/atomic/paired-gorge.json']:
   actual=historical_atomic_source().encode() if name=='.github/workflows/atomic-feature-regressions.yml' else (ROOT/name).read_bytes()
   self.assertEqual(actual,subprocess.check_output(['git','-C',str(ROOT),'show','d3b4d5f12c7290bf3d6303c4447ce61168926018:'+name]))
 def test_actual_all_new_Bash_blocks_syntax_and_owned_artifact_conditions(self):
  for p in [FOCUSED]:
   s=p.read_text();blocks=re.findall(r'        run: \|\n((?:(?:          .*|)\n)*)',s)
   for block in blocks:
    r=subprocess.run(['bash','-n'],input=textwrap.dedent(block),capture_output=True,text=True);self.assertEqual(r.returncode,0,r.stderr)
  self.assertIn('if: success()',FOCUSED.read_text());self.assertNotIn('if: always()',FOCUSED.read_text());self.assertIn("failure() && steps.oauth-authorization-owner.outputs.diagnostic_owned == 'true'",FOCUSED.read_text())
 def test_path_scoped_readonly_job_exact_File_builder_and_real_phase(self):
  import yaml
  wf=yaml.safe_load(FOCUSED.read_text());events=wf.get('on',wf.get(True));self.assertEqual(set(events),{'pull_request','workflow_dispatch'});self.assertEqual(wf['permissions'],{'contents':'read'})
  paths=events['pull_request']['paths']
  for name in ['.github/workflows/oauth-authorization-producer-focused.yml','src/applications/auth/**','src/applications/conduit/**','src/applications/oauthserver/**','tests/atomic/build_directory_file.py']:self.assertIn(name,paths)
  job=wf['jobs']['oauth-authorization-owner'];self.assertEqual(job['services']['mysql']['image'],'mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7');text=FOCUSED.read_text();self.assertIn("module['build_file'](",text);self.assertNotIn("module['build'](",text);self.assertIn('tests/atomic/build_directory_file.py',text);self.assertIn('--oauth-authorization-producer-owner',text);self.assertIn('qualify_oauth_authorization_mysql84.py',text);self.assertIn('project_oauth_authorization_failure.py',text);self.assertNotIn('secrets.',text);self.assertNotIn('workflow_run:',text);self.assertNotIn('docker push',text);self.assertIn('--source-only --source-revision \"$GITHUB_SHA\"',text);self.assertLess(text.index('--source-only --source-revision'),text.index('php scripts/runtime/build.php all'))
 def test_yaml_dependency_is_explicit_isolated_and_precedes_controls(self):
  import yaml
  steps=yaml.safe_load(FOCUSED.read_text())['jobs']['oauth-authorization-owner']['steps']
  def check(items):
   dependency=next(i for i,v in enumerate(items)if v.get('name')=='Provision isolated offline-control Python dependencies')
   controls=next(i for i,v in enumerate(items)if v.get('name')=='Offline focused phase, source and failure controls')
   self.assertLess(dependency,controls)
   body=items[dependency]['run']
   for required in ['python3 -m venv', '"$RUNNER_TEMP/oauth-authorization-control-python/bin/python" -m pip', "--only-binary=:all: 'PyYAML==6.0.3'", 'import yaml; assert yaml.__version__ == "6.0.3"', '>> "$GITHUB_PATH"']:self.assertIn(required,body)
  check(steps)
  for mutate in ['remove','late','unpin']:
   altered=copy.deepcopy(steps);i=next(i for i,v in enumerate(altered)if v.get('name')=='Provision isolated offline-control Python dependencies')
   if mutate=='remove':altered.pop(i)
   elif mutate=='late':altered.append(altered.pop(i))
   else:altered[i]['run']=altered[i]['run'].replace('PyYAML==6.0.3','PyYAML')
   with self.assertRaises((AssertionError,StopIteration)):check(altered)
 def test_code_only_PR_changes_schedule_job_and_unrelated_docs_do_not(self):
  import fnmatch,yaml
  wf=yaml.safe_load(FOCUSED.read_text());events=wf.get('on',wf.get(True));paths=events['pull_request']['paths']
  def schedules(files,patterns=paths):return any(fnmatch.fnmatchcase(name,pattern)for name in files for pattern in patterns)
  code_pattern='tests/governance/test_recovery_oauth_authorization_*.py';self.assertIn(code_pattern,paths)
  for name in ['test_recovery_oauth_authorization_source.py','test_recovery_oauth_authorization_ci.py','test_recovery_oauth_authorization_sql.py','test_recovery_oauth_authorization_coordination.py']:
   change='tests/governance/'+name;self.assertTrue((ROOT/change).is_file());self.assertTrue(schedules([change]));self.assertFalse(schedules([change],[p for p in paths if p!=code_pattern]))
  self.assertTrue(schedules(['tests/governance/test_recovery_oauth_access_source.py']))
  self.assertTrue(schedules(['src/infrastructure/governance/recovery/PhorgeRecoveryConduitProducerFence.php']))
  self.assertFalse(schedules(['docs/unrelated-guide.md']));self.assertFalse(schedules(['tests/governance/test_unrelated_ui.py']))
 def execute(self,which,mode):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   t=Path(tmp);bin=t/'tools';bin.mkdir();data=t/'runtime';data.mkdir();output=data/('governance-oauth-authorization-producer-owner'if which=='atomic'else'governance-oauth-authorization-focused');failure=data/('governance-oauth-authorization-producer-failure'if which=='atomic'else'oauth-authorization-focused-failure')
   if which=='focused':output.mkdir()
   if mode=='foreign-failure':failure.mkdir();(failure/'foreign').write_text('preserve')
   file=data/'gorge-file-storage'if which=='atomic'else data/'oauth-authorization-focused-binary/gorge-file-storage';file.parent.mkdir(exist_ok=True);file.write_bytes(b'File DATA only, never executed');file.chmod(0o700)
   for n in ['governance-batch-1/native.json','governance-native-organization/accepted.json']:(data/n).parent.mkdir(exist_ok=True);(data/n).write_text('{}')
   maps=t/'maps';maps.write_text(''.join(__import__('hashlib').sha256((ROOT/n).read_bytes()).hexdigest()+'  '+str(ROOT/n)+'\n'for n in ['src/__phutil_library_map__.php','support/runtime/src/__phutil_library_map__.php','support/runtime/manifest.json']))
   if which=='focused':shutil.copyfile(maps,output/'verified-maps.sha256')
   revision=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD']).decode().strip();receipt=base_template.template();labels=sorted(code_gate.obligations());receipt.update(batch='native-oauth-authorization-producer-owner',scope='owned-native-oauth-authorization-producer-owner-no-permit',checks=labels,check_count=len(labels),native=code_gate.summary(revision,sql_controls.AuthorizationSQL().identities()),file_service_cleanup={'kind':'file','binary_sha256':__import__('hashlib').sha256(file.read_bytes()).hexdigest(),'process_stopped':True,'private_storage_removed':True},successful_owned_file_service_startups=1,worker_cleanup={'private_directories_removed':4,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':4})
   failed={'batch':'native-oauth-authorization-producer-owner','passed':False,'failure':{'stage':'focused-native-oauth-authorization-producer-owner','error_type':'RuntimeException','source_file':'oauth-authorization-producer.php','source_line':17,'message':'PRIVATE_TOKEN_PASSWORD_SQL','token':'PRIVATE_TOKEN_PASSWORD_SQL'}}
   raw=t/'native.json';raw.write_text(json.dumps(receipt if mode in ['success','version-before-failure','version-after-failure']else failed))
   if mode=='stderr-only':raw.write_text('')
   early=json.dumps({'scope':'native-conduit-producer-failure-v1','passed':False,'stage':'focused-native-oauth-authorization-producer-owner','error_type':'RuntimeException','source_file':'oauth-authorization-cases.php','source_line':29,'password':'PRIVATE_TOKEN_PASSWORD_SQL'})
   php=shutil.which('php');py=shutil.which('python3')
   wrappers={'php':'if [[ " $* " == *" support/governance/native-organization/test-oauth-authorization-owner-mysql.php "* ]]; then cat '+shlex.quote(str(raw))+"; printf '%s\\n' "+shlex.quote(early if mode=='stderr-only'else'PRIVATE_TOKEN_PASSWORD_SQL')+" >&2; exit "+('0'if mode in ['success','version-before-failure','version-after-failure']else'37')+'; fi\nexec '+shlex.quote(php)+' "$@"','python3':('if [[ "$2" == "scripts/governance/project_oauth_authorization_failure.py" ]]; then exit 41; fi\n'if mode=='projector-failure'else'')+'exec '+shlex.quote(py)+' "$@"'}
   runtime=lambda phase:json.dumps({'schema':'native-oauth-authorization-mysql-runtime-v1','phase':phase,'source_revision':revision,'version':'8.4.13','server_uuid_sha256':'d'*64,'observed_service_image':'mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'})
   wrappers['docker']='if [[ "$1" != "inspect" ]]; then exit 53; fi\nprintf "%s\\n" '+shlex.quote('mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7')
   wrappers['php']='if [[ "$1" == "scripts/governance/probe_oauth_authorization_mysql84.php" ]]; then if [[ "$2" == "before" ]]; then printf "%s\\n" '+shlex.quote(runtime('before'))+'; else printf "%s\\n" '+shlex.quote(runtime('after'))+'; fi; exit 0; fi\n'+wrappers['php']
   native_called=t/'native-called'
   wrappers['php']=wrappers['php'].replace('then cat ','then touch '+shlex.quote(str(native_called))+'; cat ',1)
   if mode in ['version-before-failure','version-after-failure']:
    which_phase='before'if mode=='version-before-failure'else'after'
    wrappers['php']='if [[ "$1" == "scripts/governance/probe_oauth_authorization_mysql84.php" && "$2" == "'+which_phase+'" ]]; then exit 38; fi\n'+wrappers['php']
   if mode=='image-failure':wrappers['docker']='printf "%s\\n" "mysql:8.0"'
   if mode=='cat-failure':wrappers['cat']='exit 52'
   for n,body in wrappers.items():p=bin/n;p.write_text('#!/bin/bash\n'+body+'\n');p.chmod(0o700)
   marker=t/'github-output'
   if mode=='marker-failure':marker.mkdir()
   env={**os.environ,'PATH':str(bin)+':'+os.environ['PATH'],'RUNNER_TEMP':str(data),'GITHUB_SHA':revision,'GITHUB_OUTPUT':str(marker),'GORGE_TEST_MYSQL_PASSWORD':'synthetic-disposable-only','MYSQL_CONTAINER':'synthetic-ci-container'}
   body=phase(which).replace('/tmp/atomic-maps.sha256',str(maps));r=subprocess.run(['bash','-euo','pipefail','-c',body],cwd=ROOT,env=env,capture_output=True,text=True,timeout=15)
   accepted=(output/'accepted.json').exists();pending=(output/'accepted.pending.json').exists();safe=failure/'diagnostic.json';owned=marker.is_file()and'diagnostic_owned=true'in marker.read_text();result={'native_called':native_called.exists(),'rc':r.returncode,'accepted':accepted,'pending':pending,'owned':owned,'safe':json.loads(safe.read_bytes())if safe.exists()else None,'stderr':r.stderr,'foreign':(failure/'foreign').read_text()if(failure/'foreign').exists()else None};return result
 def test_runtime_version_failure_stops_before_native_or_before_acceptance(self):
  for mode,called,code in [('version-before-failure',False,38),('version-after-failure',True,38),('image-failure',False,1)]:
   d=self.execute('focused',mode);self.assertEqual(d['rc'],code,d);self.assertEqual(d['native_called'],called);self.assertFalse(d['accepted']);self.assertFalse(d['owned'])
 def test_actual_phase_success_strict_consumer_and_pending_atomic_publication(self):
  for which in ['focused']:
   d=self.execute(which,'success');self.assertEqual(d['rc'],0,d['stderr']);self.assertTrue(d['accepted']);self.assertFalse(d['pending']or d['owned'])
 def test_actual_failure_projector_marker_cat_and_foreign_directory_preserve_native37(self):
  for which in ['focused']:
   for mode in ['native-failure','projector-failure','marker-failure','cat-failure','foreign-failure','stderr-only']:
    d=self.execute(which,mode);self.assertEqual(d['rc'],37,(which,mode,d));self.assertFalse(d['accepted']);self.assertNotIn('PRIVATE_TOKEN_PASSWORD_SQL',d['stderr'])
    if mode in ['projector-failure','marker-failure','foreign-failure']:self.assertFalse(d['owned'])
    if mode=='foreign-failure':self.assertEqual(d['foreign'],'preserve')
    if mode in ['native-failure','stderr-only']:self.assertEqual(d['safe']['scope'],'native-oauth-authorization-producer-failure-v1');self.assertTrue(d['owned'])
class SafeOAuthAuthorizationProjection(unittest.TestCase):
 def test_real_pinned_PHP_projection_drops_credentials_query_and_unknown_input(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   p=Path(tmp)/'receipt';p.touch(mode=0o600)
   p.write_text(json.dumps({'batch':'native-oauth-authorization-producer-owner','passed':False,'failure':{'error_type':'RuntimeException','source_file':'oauth-authorization-cases.php','source_line':19,'message':'PRIVATE SQL TOKEN','password':'PRIVATE SQL TOKEN'}}));v=projector.project(p);self.assertTrue(v['diagnostic_available']);self.assertNotIn('PRIVATE',json.dumps(v));self.assertEqual(v['scope'],'native-oauth-authorization-producer-failure-v1')
   for raw in ['{"passed":true}', '{"passed":false,"passed":false}', 'PRIVATE INVALID']:
    p.write_text(raw);self.assertFalse(projector.project(p)['diagnostic_available'])
 def test_actual_private_stderr_only_fixed_early_protocol_is_reprojected(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   receipt=Path(tmp)/'receipt';receipt.touch(mode=0o600);err=Path(tmp)/'native.stderr.private';err.touch(mode=0o600)
   early={'scope':'native-conduit-producer-failure-v1','passed':False,'stage':'focused-native-oauth-authorization-producer-owner','error_type':'RuntimeException','source_file':'oauth-authorization-cases.php','source_line':31,'password':'PRIVATE SQL TOKEN'}
   err.write_text('PRIVATE raw fatal text\n'+json.dumps(early)+'\n');v=projector.project(receipt,err);self.assertTrue(v['diagnostic_available']);self.assertEqual(v['source_line'],31);self.assertNotIn('PRIVATE',json.dumps(v))
   receipt.write_text(json.dumps({'passed':True}));self.assertFalse(projector.project(receipt,err)['diagnostic_available']);receipt.write_text('')
   for raw in ['PRIVATE unknown',json.dumps(dict(early,stage='focused-native-conduit-producer-owner')),'X'*65537]:
    err.write_text(raw);self.assertFalse(projector.project(receipt,err)['diagnostic_available'])
 def test_changed_PHP_or_bad_projected_scope_never_returns_untrusted_bytes(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   p=Path(tmp)/'receipt';p.touch(mode=0o600);p.write_text(json.dumps({'batch':'native-oauth-authorization-producer-owner','passed':False,'failure':{}}))
   with patch.object(projector.gate,'read_source_checked',return_value=b'<?php\necho "PRIVATE";'),patch.object(projector.subprocess,'run',side_effect=AssertionError('untrusted PHP')):self.assertFalse(projector.project(p)['diagnostic_available'])
   with patch.object(projector.subprocess,'run',return_value=__import__('types').SimpleNamespace(returncode=0,stderr='',stdout='{"scope":"PRIVATE"}')):self.assertFalse(projector.project(p)['diagnostic_available'])
if __name__=='__main__':unittest.main()
