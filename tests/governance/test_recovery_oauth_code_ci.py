import hashlib
"""Execute actual CI phase shells with owned files and fixed process collaborators.
No database, Docker, native fixture or binary execution is performed.
"""
from pathlib import Path
import copy,json,os,re,shlex,shutil,subprocess,sys,tempfile,textwrap,unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_oauth_code_failure as projector
import check_recovery_oauth_code_producer_v1 as code_gate
import test_native_organization as base_template
ATOMIC=ROOT/'.github/workflows/atomic-feature-regressions.yml'
FOCUSED=ROOT/'.github/workflows/oauth-code-producer-focused.yml'
REVIEWED_POLICY_FIXTURES={
 'support/governance/native-organization/test-native-organization-mysql.php':{'bytes':39959,'sha256':'3ae6699231f460351bf205fb4bc62f48668cbf56386e671fa9ade8ec224f9c02'},
 'support/governance/native-execution/project-policy-negative-cases.php':{'bytes':9548,'sha256':'52bd347a06c32c3448730e802f61ec632ded251fd2c9161baf39ba893786a33d'},
}
def reviewed_policy_fixture(root,name,raw=None):
 # These are two reviewed isolated fixtures, not a general source exception.
 import project_description_admission_delta as current
 def need(value):
  if not value:raise ValueError('unreviewed native policy fixture source')
 def pin(value):return {'bytes':len(value),'sha256':hashlib.sha256(value).hexdigest()}
 need(name in REVIEWED_POLICY_FIXTURES)
 current.assert_current_recipe(root)
 path=root/name
 need(not path.is_symlink()and path.stat().st_mode&0o7777==0o644)
 actual=current.description.checked_bytes(path)
 if raw is None:raw=actual
 need(type(raw)is bytes and raw==actual and pin(raw)==REVIEWED_POLICY_FIXTURES[name])
 git=lambda revision:subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(root),'show',revision+':'+name])
 need(raw==git(current.TARGET))
 legacy=git('b93916d8a2beb4a9ab28ea1769692c0839cfc60b')
 need(legacy==git('060ac7f2b631cc5964f28cd783082dd6e2fb1565'))
 registry=json.loads(current.description.checked_bytes(root/'support/governance/source-operation/registry-v1.json'))
 need(registry['entries'][name]==pin(legacy))
 need(registry['successor_entries'][name]=={'baseline':pin(legacy),'current':pin(raw)})
 if name in current.CURRENT_METADATA:
  need(current._normalize_metadata(name,raw)==legacy)
 return legacy
def phase(which):
 text=(ATOMIC if which=='atomic'else FOCUSED).read_text()
 if which=='atomic':text=text.split('# BEGIN isolated OAuth access-token SQL8',1)[1]
 else:text=text.split('      - name: Run only actual native OAuth',1)[1]
 return textwrap.dedent(text.split('        run: |\n',1)[1].split('      - uses:',1)[0])
def historical_atomic_source(path='.github/workflows/atomic-feature-regressions.yml',raw=None):
 # Test-only exact current Atomic -> actual20b view; runtime readers stay current.
 import project_description_admission_delta as current
 current.assert_current_recipe(ROOT)
 producer='scripts/governance/check_recovery_oauth_code_producer_v1.py'
 if path!='.github/workflows/atomic-feature-regressions.yml' or path not in current.CURRENT_METADATA:
  raise ValueError('unreviewed Atomic historical source role')
 if Path(code_gate.__file__).resolve()!=ROOT/producer:raise ValueError('unreviewed current producer source role')
 if (ROOT/producer).stat().st_mode&0o7777!=0o644:raise ValueError('unknown current producer mode')
 producer_raw=current.description.checked_bytes(ROOT/producer)
 registry_path='support/governance/source-operation/registry-v1.json';registry_raw=current.description.checked_bytes(ROOT/registry_path);registry_pin=current.CURRENT_METADATA[registry_path]
 if len(registry_raw)!=registry_pin['bytes'] or hashlib.sha256(registry_raw).hexdigest()!=registry_pin['sha256']:raise ValueError('unknown current source registry')
 registry=json.loads(registry_raw);pin=registry['successor_entries'].get(producer,{}).get('current',registry['entries'].get(producer))
 if type(pin)is not dict:raise ValueError('unreviewed current producer source role')
 old_producer=subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(ROOT),'show','20b138b4cd9a2cf61522613b4a8e5e21eca7341b:'+producer])
 if producer_raw!=old_producer:raise ValueError('unknown baseline producer bytes')
 if len(producer_raw)!=pin['bytes'] or hashlib.sha256(producer_raw).hexdigest()!=pin['sha256']:raise ValueError('unknown current producer bytes')
 if (ROOT/path).stat().st_mode&0o7777!=0o644:raise ValueError('unknown current Atomic source mode')
 actual=current.description.checked_bytes(ROOT/path)
 if raw is None:raw=actual
 if type(raw)is not bytes or raw!=actual:raise ValueError('unknown current Atomic source bytes')
 prior=current._normalize_metadata(path,raw)
 expected=subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(ROOT),'show','20b138b4cd9a2cf61522613b4a8e5e21eca7341b:'+path])
 if prior!=expected:raise ValueError('unknown Atomic historical stage')
 return prior.decode('utf-8')
class OAuthCI(unittest.TestCase):
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
 def test_existing_workflows_and_real_File_builder_are_unchanged(self):
  for name in ['.github/workflows/atomic-feature-regressions.yml','.github/workflows/oauth-access-producer-focused.yml','.github/workflows/conduit-producer-focused.yml','tests/atomic/build_directory_file.py','tests/atomic/build_gorge.py','tests/atomic/paired-gorge.json']:
   actual=historical_atomic_source().encode() if name=='.github/workflows/atomic-feature-regressions.yml' else (ROOT/name).read_bytes()
   self.assertEqual(actual,subprocess.check_output(['git','-C',str(ROOT),'show','060ac7f2b631cc5964f28cd783082dd6e2fb1565:'+name]))
 def test_actual_all_new_Bash_blocks_syntax_and_owned_artifact_conditions(self):
  for p in [FOCUSED]:
   s=p.read_text();blocks=re.findall(r'        run: \|\n((?:(?:          .*|)\n)*)',s)
   for block in blocks:
    r=subprocess.run(['bash','-n'],input=textwrap.dedent(block),capture_output=True,text=True);self.assertEqual(r.returncode,0,r.stderr)
  self.assertIn('if: success()',FOCUSED.read_text());self.assertNotIn('if: always()',FOCUSED.read_text());self.assertIn("failure() && steps.oauth-code-owner.outputs.diagnostic_owned == 'true'",FOCUSED.read_text())
 def test_path_scoped_readonly_job_exact_File_builder_and_real_phase(self):
  import yaml
  wf=yaml.safe_load(FOCUSED.read_text());events=wf.get('on',wf.get(True));self.assertEqual(set(events),{'pull_request','workflow_dispatch'});self.assertEqual(wf['permissions'],{'contents':'read'})
  paths=events['pull_request']['paths']
  for name in ['.github/workflows/oauth-code-producer-focused.yml','src/applications/auth/**','src/applications/conduit/**','src/applications/oauthserver/**','tests/atomic/build_directory_file.py']:self.assertIn(name,paths)
  job=wf['jobs']['oauth-code-owner'];self.assertEqual(job['services']['mysql']['image'],'mysql:8.0');text=FOCUSED.read_text();self.assertIn("module['build_file'](",text);self.assertNotIn("module['build'](",text);self.assertIn('tests/atomic/build_directory_file.py',text);self.assertIn('--oauth-code-producer-owner',text);self.assertIn('check_recovery_oauth_code_producer_v1.py',text);self.assertIn('project_oauth_code_failure.py',text);self.assertNotIn('secrets.',text);self.assertNotIn('workflow_run:',text);self.assertNotIn('docker push',text)
 def test_exact_named_workflow_and_unrelated_bytes_refuse(self):
  import recovery_oauth_code_ci_delta as ci
  raw=FOCUSED.read_bytes();ci.assert_workflow(ci.WORKFLOW,raw)
  for path,data in [(ci.WORKFLOW,raw+b'\n# unknown edit'),('.github/workflows/unreviewed.yml',raw)]:
   with self.assertRaises(ValueError):ci.assert_workflow(path,data)
  for path in ci.SOURCE_INPUTS:
   raw=(ROOT/path).read_bytes();self.assertEqual(__import__('hashlib').sha256(ci.reduce_if_selected(path,raw)).hexdigest(),ci.BASE_PINS[path])
   with self.assertRaises(ValueError):ci.reduce_if_selected(path,raw+b'#unknown')
 def test_current_committed_N01_source_revision_and_app_payload_are_real(self):
  import check_directory_transports_v1 as gate
  revision=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD']).decode().strip()
  dirty=subprocess.run(['git','-C',str(ROOT),'diff','--quiet','HEAD','--','src','resources',gate.MANIFEST,*sorted(gate.SOURCE_FILES)]).returncode!=0
  if dirty:
   with self.assertRaises((ValueError,subprocess.CalledProcessError)):gate.source(ROOT,revision=revision)
  else:self.assertEqual(gate.source(ROOT,revision=revision)['schema'],'native-directory-transports-source-v1')
  manifest=json.loads((ROOT/gate.MANIFEST).read_bytes())
  for name,pin in manifest['pins'].items():
   raw=(ROOT/name).read_bytes();self.assertEqual(len(raw),pin['bytes']);self.assertEqual(hashlib.sha256(raw).hexdigest(),pin['sha256'])
  changed=subprocess.check_output(['git','-C',str(ROOT),'diff','--name-only','060ac7f2b631cc5964f28cd783082dd6e2fb1565','--','src','resources','support/governance']).decode().splitlines()
  # Current additions are a closed literal set, each exact source reviewed.
  # Existing count/partial changes still independently invert to signed71.
  import recovery_partial_current_schema_delta as partial
  import recovery_oauth_authorization_source_delta as authorization_delta
  import recovery_oauth_client_lifecycle_delta as lifecycle_delta
  approved={'support/governance/media-session-ci/project.py', 'support/governance/media-session-ci/test_ci.py', 'support/governance/media-session-docker/snapshot.py', 'support/governance/recovery/partial-composition-focused.php', 'support/governance/recovery/restore-review-cases.php', 'support/governance/media-session-docker/README.md'}
  authorization_runtime={'src/applications/oauthserver/storage/PhabricatorOAuthClientAuthorization.php': '68ba2b0145c25e53206f24b565260d7d3e93b2b232f7e9382f4b720bedc4a4c3', 'src/infrastructure/governance/recovery/PhorgeRecoveryConduitProducerFence.php': 'cd1b0ad05eafa48e79f50cde12ec982dd59f5374f63f10a7c36e52da30ab5a3c', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php': '05aa84847028b1b67259bade9375d914f409921d9bed514296546e48e0e2559f', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreRequest.php': '007182650c08344038520978d00256bf6e734bad741d9c5d094f9238418b2e03', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php': 'bfcb50198a53b6a4757dcb5997c4958bb45e667ff3567fef10ac94682f5a69a8', 'src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php': '356bf32dbc89499e40f5fe7e90d8f9d63e63e041398ca1830ccb7ca363bc4bc9', 'support/governance/native-organization/oauth-authorization-worker.php': '49ec8d298d46b0df0aae255e9fbd8a77d78a3188ab59e472c328a0027ea17b97', 'support/governance/native-organization/oauth-authorization-workers.php': 'eec44271b5b7a0e8ff164eea301c0a7da2ca760e30d82a28a10df4ab8bbda737', 'support/governance/native-organization/test-oauth-authorization-owner-mysql.php': '32265fb84af9aa6d9669fcba01bb0434401265f5f424d1d708cccc313291cc46', 'support/governance/recovery/assessment-credential-cases.php': '29bcbe84e7bc30bcecd395d635b059f1061677045652de1ec8117fd74ef1b72f', 'support/governance/recovery/oauth-authorization-cases.php': '72224fcb69389313b7979d379d411fc06d32175322f662f350f8481aaacfd992', 'support/governance/recovery/oauth-authorization-diagnostic.php': '0cfb73c1551c0745ed8f680a0737ea1948a3d7d68c672bdbb41c12d18b6ddf7b', 'support/governance/recovery/oauth-authorization-focused.php': '64bf17a51532e8c5e8421aa0c5e5d94bcec637819645e33123bcfddaa84e2d75', 'support/governance/recovery/oauth-authorization-producer.php': 'f523b78b9f9682b9d751aab96d7a4fbc9aa2a6595ec10933902416fbc83d90fe', 'support/governance/recovery/tests/assessment-oauth-authorization-owner-host.php': 'f66e1c0b385859bb908bfa58f3494d9773a7622a68b11cd2ccd8bec89656fc0a'}
  import project_description_admission_delta as n05_delta
  n05_delta.assert_production_cohort(ROOT)
  changed,_=n05_delta.historical_inputs(ROOT,'060ac7f2b631cc5964f28cd783082dd6e2fb1565',changed,{name:(ROOT/name).read_bytes()for name in changed})
  lifecycle_paths={p for p in lifecycle_delta.SOURCE_INPUTS if p.startswith(('src/','resources/','support/governance/'))}|set(lifecycle_delta.SOURCE_ADDITIONS)
  for name in REVIEWED_POLICY_FIXTURES:reviewed_policy_fixture(ROOT,name)
  self.assertEqual(set(changed),approved|set(authorization_runtime)|lifecycle_paths|set(REVIEWED_POLICY_FIXTURES))
  lifecycle_reader=n05_delta.native_c4_reader(ROOT,Path.read_bytes)
  for name in lifecycle_paths:
   raw=(ROOT/name).read_bytes()
   self.assertEqual(raw,subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','HEAD:'+name]))
   if name in lifecycle_delta.SOURCE_ADDITIONS:self.assertEqual(hashlib.sha256(raw).hexdigest(),lifecycle_delta.SOURCE_ADDITIONS[name])
   else:self.assertEqual(lifecycle_delta.reduce_current(name,lifecycle_reader(ROOT/name)),subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','4c2245300dc1d78d042b95190dd537eb6b1f9861:'+name]))
  for name,pin in authorization_runtime.items():
   raw=(ROOT/name).read_bytes();self.assertEqual(hashlib.sha256(lifecycle_delta.reduce_if_selected(name,raw)).hexdigest(),pin)
   self.assertEqual(raw,subprocess.check_output(['git','-C',str(ROOT),'show','HEAD:'+name]))
   if name in authorization_delta.SOURCE_INPUTS:
    self.assertEqual(authorization_delta.reduce_current(name,raw),subprocess.check_output(['git','-C',str(ROOT),'show','0436e865b8431b9f064fe7b8a92dcad5e6623997:'+name]))
  for name in approved:
   fixed=subprocess.check_output(['git','-C',str(ROOT),'show','71c1338ea01116f6118f4ac3bd7aea024ed762f9:'+name])
   self.assertEqual(partial.reduce_if_selected(name,(ROOT/name).read_bytes()),fixed)
   committed=subprocess.check_output(['git','-C',str(ROOT),'show','HEAD:'+name])
   if committed!=(ROOT/name).read_bytes():
    self.assertEqual(revision,'d95b3bd2253afb6fdce615f4041920bdce5df625')
    self.assertEqual(subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'rev-parse','HEAD^{tree}']).decode().strip(),'4af5ae154be5b9fd01565762edbb712450c41c34')
    from n05_r3_historical_test_view import historical_D95_media_recipe_source
    old=historical_D95_media_recipe_source(name,committed)
   else:old=partial.reduce_if_selected(name,committed)
   self.assertEqual(old,fixed)
 def test_reviewed_policy_fixture_source_set_refuses_unknown_bytes_and_roles(self):
  for name in REVIEWED_POLICY_FIXTURES:
   raw=(ROOT/name).read_bytes()
   self.assertEqual(reviewed_policy_fixture(ROOT,name),subprocess.check_output(['git','-C',str(ROOT),'show','060ac7f2b631cc5964f28cd783082dd6e2fb1565:'+name]))
   for unknown in (raw+b'\n',b'',bytearray(raw)):
    with self.assertRaises(ValueError):reviewed_policy_fixture(ROOT,name,unknown)
  for name in ('support/governance/native-execution/unknown.php','../project-policy-negative-cases.php'):
   with self.assertRaises(ValueError):reviewed_policy_fixture(ROOT,name,b'')
 def test_yaml_dependency_is_explicit_isolated_and_precedes_controls(self):
  import yaml
  steps=yaml.safe_load(FOCUSED.read_text())['jobs']['oauth-code-owner']['steps']
  def check(items):
   dependency=next(i for i,v in enumerate(items)if v.get('name')=='Provision isolated offline-control Python dependencies')
   controls=next(i for i,v in enumerate(items)if v.get('name')=='Offline focused phase, source and failure controls')
   self.assertLess(dependency,controls)
   body=items[dependency]['run']
   for required in ['python3 -m venv', '"$RUNNER_TEMP/oauth-code-control-python/bin/python" -m pip', "--only-binary=:all: 'PyYAML==6.0.3'", 'import yaml; assert yaml.__version__ == "6.0.3"', '>> "$GITHUB_PATH"']:self.assertIn(required,body)
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
  code_pattern='tests/governance/test_recovery_oauth_code_*.py';self.assertIn(code_pattern,paths)
  for name in ['test_recovery_oauth_code_source.py','test_recovery_oauth_code_ci.py','test_recovery_oauth_code_threads.py','test_recovery_oauth_code_result.py']:
   change='tests/governance/'+name;self.assertTrue((ROOT/change).is_file());self.assertTrue(schedules([change]));self.assertFalse(schedules([change],[p for p in paths if p!=code_pattern]))
  self.assertTrue(schedules(['tests/governance/test_recovery_oauth_access_source.py']))
  self.assertTrue(schedules(['src/infrastructure/governance/recovery/PhorgeRecoveryConduitProducerFence.php']))
  self.assertFalse(schedules(['docs/unrelated-guide.md']));self.assertFalse(schedules(['tests/governance/test_unrelated_ui.py']))
 def execute(self,which,mode):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   t=Path(tmp);bin=t/'tools';bin.mkdir();data=t/'runtime';data.mkdir();output=data/('governance-oauth-code-producer-owner'if which=='atomic'else'governance-oauth-code-focused');failure=data/('governance-oauth-code-producer-failure'if which=='atomic'else'oauth-code-focused-failure')
   if which=='focused':output.mkdir()
   if mode=='foreign-failure':failure.mkdir();(failure/'foreign').write_text('preserve')
   file=data/'gorge-file-storage'if which=='atomic'else data/'oauth-code-focused-binary/gorge-file-storage';file.parent.mkdir(exist_ok=True);file.write_bytes(b'File DATA only, never executed');file.chmod(0o700)
   for n in ['governance-batch-1/native.json','governance-native-organization/accepted.json']:(data/n).parent.mkdir(exist_ok=True);(data/n).write_text('{}')
   maps=t/'maps';maps.write_text(''.join(__import__('hashlib').sha256((ROOT/n).read_bytes()).hexdigest()+'  '+str(ROOT/n)+'\n'for n in ['src/__phutil_library_map__.php','support/runtime/src/__phutil_library_map__.php','support/runtime/manifest.json']))
   if which=='focused':shutil.copyfile(maps,output/'verified-maps.sha256')
   revision=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD']).decode().strip();receipt=base_template.template();labels=sorted(code_gate.obligations());receipt.update(batch='native-oauth-code-producer-owner',scope='owned-native-oauth-code-producer-owner-no-permit',checks=labels,check_count=len(labels),native=code_gate.summary(revision),file_service_cleanup={'kind':'file','binary_sha256':__import__('hashlib').sha256(file.read_bytes()).hexdigest(),'process_stopped':True,'private_storage_removed':True},successful_owned_file_service_startups=1,worker_cleanup={'private_directories_removed':2,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':2})
   failed={'batch':'native-oauth-code-producer-owner','passed':False,'failure':{'stage':'focused-native-oauth-code-producer-owner','error_type':'RuntimeException','source_file':'oauth-code-producer.php','source_line':17,'message':'PRIVATE_TOKEN_PASSWORD_SQL','token':'PRIVATE_TOKEN_PASSWORD_SQL'}}
   raw=t/'native.json';raw.write_text(json.dumps(receipt if mode=='success'else failed))
   if mode=='stderr-only':raw.write_text('')
   early=json.dumps({'scope':'native-conduit-producer-failure-v1','passed':False,'stage':'focused-native-oauth-code-producer-owner','error_type':'RuntimeException','source_file':'oauth-code-cases.php','source_line':29,'password':'PRIVATE_TOKEN_PASSWORD_SQL'})
   php=shutil.which('php');py=shutil.which('python3')
   wrappers={'php':'if [[ " $* " == *" support/governance/native-organization/test-oauth-code-owner-mysql.php "* ]]; then cat '+shlex.quote(str(raw))+"; printf '%s\\n' "+shlex.quote(early if mode=='stderr-only'else'PRIVATE_TOKEN_PASSWORD_SQL')+" >&2; exit "+('0'if mode=='success'else'37')+'; fi\nexec '+shlex.quote(php)+' "$@"','python3':('if [[ "$2" == "scripts/governance/project_oauth_code_failure.py" ]]; then exit 41; fi\n'if mode=='projector-failure'else'')+'exec '+shlex.quote(py)+' "$@"'}
   if mode=='cat-failure':wrappers['cat']='exit 52'
   for n,body in wrappers.items():p=bin/n;p.write_text('#!/bin/bash\n'+body+'\n');p.chmod(0o700)
   marker=t/'github-output'
   if mode=='marker-failure':marker.mkdir()
   env={**os.environ,'PATH':str(bin)+':'+os.environ['PATH'],'RUNNER_TEMP':str(data),'GITHUB_SHA':revision,'GITHUB_OUTPUT':str(marker),'GORGE_TEST_MYSQL_PASSWORD':'synthetic-disposable-only'}
   body=phase(which).replace('/tmp/atomic-maps.sha256',str(maps));r=subprocess.run(['bash','-euo','pipefail','-c',body],cwd=ROOT,env=env,capture_output=True,text=True,timeout=15)
   accepted=(output/'accepted.json').exists();pending=(output/'accepted.pending.json').exists();safe=failure/'diagnostic.json';owned=marker.is_file()and'diagnostic_owned=true'in marker.read_text();result={'rc':r.returncode,'accepted':accepted,'pending':pending,'owned':owned,'safe':json.loads(safe.read_bytes())if safe.exists()else None,'stderr':r.stderr,'foreign':(failure/'foreign').read_text()if(failure/'foreign').exists()else None};return result
 def test_actual_phase_success_strict_consumer_and_pending_atomic_publication(self):
  for which in ['focused']:
   d=self.execute(which,'success');self.assertEqual(d['rc'],0,d['stderr']);self.assertTrue(d['accepted']);self.assertFalse(d['pending']or d['owned'])
 def test_actual_failure_projector_marker_cat_and_foreign_directory_preserve_native37(self):
  for which in ['focused']:
   for mode in ['native-failure','projector-failure','marker-failure','cat-failure','foreign-failure','stderr-only']:
    d=self.execute(which,mode);self.assertEqual(d['rc'],37,(which,mode,d));self.assertFalse(d['accepted']);self.assertNotIn('PRIVATE_TOKEN_PASSWORD_SQL',d['stderr'])
    if mode in ['projector-failure','marker-failure','foreign-failure']:self.assertFalse(d['owned'])
    if mode=='foreign-failure':self.assertEqual(d['foreign'],'preserve')
    if mode in ['native-failure','stderr-only']:self.assertEqual(d['safe']['scope'],'native-oauth-code-producer-failure-v1');self.assertTrue(d['owned'])
class SafeOAuthProjection(unittest.TestCase):
 def test_real_pinned_PHP_projection_drops_credentials_query_and_unknown_input(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   p=Path(tmp)/'receipt';p.touch(mode=0o600)
   p.write_text(json.dumps({'batch':'native-oauth-code-producer-owner','passed':False,'failure':{'error_type':'RuntimeException','source_file':'oauth-code-cases.php','source_line':19,'message':'PRIVATE SQL TOKEN','password':'PRIVATE SQL TOKEN'}}));v=projector.project(p);self.assertTrue(v['diagnostic_available']);self.assertNotIn('PRIVATE',json.dumps(v));self.assertEqual(v['scope'],'native-oauth-code-producer-failure-v1')
   for raw in ['{"passed":true}', '{"passed":false,"passed":false}', 'PRIVATE INVALID']:
    p.write_text(raw);self.assertFalse(projector.project(p)['diagnostic_available'])
 def test_actual_private_stderr_only_fixed_early_protocol_is_reprojected(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   receipt=Path(tmp)/'receipt';receipt.touch(mode=0o600);err=Path(tmp)/'native.stderr.private';err.touch(mode=0o600)
   early={'scope':'native-conduit-producer-failure-v1','passed':False,'stage':'focused-native-oauth-code-producer-owner','error_type':'RuntimeException','source_file':'oauth-code-cases.php','source_line':31,'password':'PRIVATE SQL TOKEN'}
   err.write_text('PRIVATE raw fatal text\n'+json.dumps(early)+'\n');v=projector.project(receipt,err);self.assertTrue(v['diagnostic_available']);self.assertEqual(v['source_line'],31);self.assertNotIn('PRIVATE',json.dumps(v))
   receipt.write_text(json.dumps({'passed':True}));self.assertFalse(projector.project(receipt,err)['diagnostic_available']);receipt.write_text('')
   for raw in ['PRIVATE unknown',json.dumps(dict(early,stage='focused-native-conduit-producer-owner')),'X'*65537]:
    err.write_text(raw);self.assertFalse(projector.project(receipt,err)['diagnostic_available'])
 def test_changed_PHP_or_bad_projected_scope_never_returns_untrusted_bytes(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   p=Path(tmp)/'receipt';p.touch(mode=0o600);p.write_text(json.dumps({'batch':'native-oauth-code-producer-owner','passed':False,'failure':{}}))
   with patch.object(projector.gate,'read_source_checked',return_value=b'<?php\necho "PRIVATE";'),patch.object(projector.subprocess,'run',side_effect=AssertionError('untrusted PHP')):self.assertFalse(projector.project(p)['diagnostic_available'])
   with patch.object(projector.subprocess,'run',return_value=__import__('types').SimpleNamespace(returncode=0,stderr='',stdout='{"scope":"PRIVATE"}')):self.assertFalse(projector.project(p)['diagnostic_available'])
if __name__=='__main__':unittest.main()
