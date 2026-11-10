"""Offline workflow and exact source-cohort controls; never run native bootstrap."""
import hashlib,importlib.util,json,os,subprocess,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
import yaml
ROOT=Path(__file__).resolve().parents[2]
DIR=ROOT/'support/governance/project-content-grants-ci'
_spec=importlib.util.spec_from_file_location('N05_workflow_ci',DIR/'ci.py');ci=importlib.util.module_from_spec(_spec);_spec.loader.exec_module(ci)
WORKFLOW=ROOT/'.github/workflows/project-content-grants-focused.yml'
def load(name,path):
 spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
class Workflow(unittest.TestCase):
 def workflow(self):return yaml.safe_load(WORKFLOW.read_text())
 def test_event_permissions_and_exact_loopback_service(self):
  w=self.workflow();events=w.get('on',w.get(True));self.assertEqual(set(events),{'pull_request','workflow_dispatch'});self.assertEqual(w['permissions'],{'contents':'read'})
  job=w['jobs']['native-content-grants'];self.assertNotIn('container',job)
  service=job['services']['mysql'];self.assertEqual(service['image'],ci.MYSQL);self.assertEqual(service['ports'],['127.0.0.1:3306:3306']);self.assertNotIn('-p',service['options'])
  text=WORKFLOW.read_text();self.assertNotIn('secrets.',text);self.assertNotIn('pull_request_target',text);self.assertNotIn('git push',text)
  checkout=next(s for s in job['steps']if s.get('uses','').startswith('actions/checkout@'));self.assertFalse(checkout['with']['persist-credentials']);self.assertEqual(checkout['with']['fetch-depth'],0)
 def test_default_PHP_configuration_and_fresh_exact_native_worktrees(self):
  steps=self.workflow()['jobs']['native-content-grants']['steps'];setup=next(s for s in steps if s.get('uses','').startswith('shivammathur/setup-php@'))
  self.assertEqual(setup['with']['php-version'],'8.5.12');ini=setup['with']['ini-values']
  for expected in ['opcache.enable_cli=0','opcache.jit=disable','opcache.jit_buffer_size=0']:self.assertIn(expected,ini)
  text=WORKFLOW.read_text();self.assertIn('git worktree add --detach "$RUNNER_TEMP/n05-native-baseline" '+ci.BASE,text);self.assertIn('git worktree add --detach "$RUNNER_TEMP/n05-native-target" '+ci.TARGET,text)
  self.assertIn('scripts/runtime/build.php" all',text);self.assertIn('scripts/runtime/verify.php',text);self.assertIn('--source-only --source-revision "$GITHUB_SHA"',text)
  self.assertNotIn('CREATE TABLE',text);self.assertNotIn('ignore',text.lower())
 def test_shells_parse_and_single_exact_public_summary_artifact(self):
  steps=self.workflow()['jobs']['native-content-grants']['steps']
  for step in steps:
   if 'run'in step:
    r=subprocess.run(['bash','-n'],input=step['run'],capture_output=True,text=True);self.assertEqual(r.returncode,0,r.stderr)
  uploads=[s for s in steps if s.get('uses','').startswith('actions/upload-artifact@')];self.assertEqual(len(uploads),1)
  self.assertEqual(uploads[0]['if'],'success()');self.assertEqual(uploads[0]['with']['path'],'${{ runner.temp }}/n05-native-run/public/summary.json')
  run=next(s['run']for s in steps if s.get('name','').startswith('Run genuine'))
  self.assertNotIn('PASSWORD',run);self.assertNotIn('--password',run);self.assertNotIn('tee ',run);self.assertNotIn('docker ',run)
 def test_portable_exact_reviewed_helpers_and_default_OFF_imports(self):
  prereq=load('N05_moved_prerequisite',DIR/'prerequisites/provision.py');qual=load('N05_moved_qualification',ROOT/'support/governance/project-content-grants-qualification/qualify.py')
  for m in [prereq,qual]:self.assertIsNone(m.ROOT_PROFILE_SHA);self.assertIsNone(m.ROOT_REVIEW_SHA)
  self.assertIsNone(prereq.ROOT_SOURCE_FREEZE_SHA);self.assertIsNone(qual.FREEZE_PIN)
  folders={'prerequisites':DIR/'prerequisites','qualification':ROOT/'support/governance/project-content-grants-qualification'}
  for kind,folder in folders.items():
   template=json.loads((DIR/('prerequisites-template.json'if kind=='prerequisites'else'qualification-template.json')).read_bytes())
   self.assertEqual(template['source_sha256'],ci.REVIEWED_PINS[kind]);self.assertNotIn('source_directory',template)
   inv=template['native_public_source_dependencies'if kind=='prerequisites'else'native_source_inventory'];self.assertEqual(ci.sha(ci.canon(inv)),ci.REVIEWED_INVENTORIES[kind]);self.assertEqual(len(inv),36 if kind=='prerequisites'else 48)
   for name,pin in ci.REVIEWED_PINS[kind].items():raw=(folder/name).read_bytes();self.assertEqual(len(raw),pin['bytes']);self.assertEqual(ci.sha(raw),pin['sha256'])
   self.assertNotIn('/Users/',json.dumps(template))
 def test_source_cleanliness_and_native_equivalence_fail_closed(self):
  root=ROOT.resolve();revision='a'*40
  with patch.object(ci,'git',side_effect=['dirty.php']):
   with self.assertRaises(ValueError):ci.current_source(root,revision)
  with patch.object(ci,'git',side_effect=['','untracked.php']):
   with self.assertRaises(ValueError):ci.current_source(root,revision)
  with patch.object(ci,'git',side_effect=['','','b'*40]):
   with self.assertRaises(ValueError):ci.current_source(root,revision)
  with patch.object(ci,'git',side_effect=['','',revision]),patch.object(ci.subprocess,'run',side_effect=subprocess.CalledProcessError(1,'git')):
   with self.assertRaises(subprocess.CalledProcessError):ci.current_source(root,revision)
  with patch.object(ci,'git',side_effect=['','',revision]),patch.object(ci.subprocess,'run')as run:
   self.assertEqual(ci.current_source(root,revision),revision);args=run.call_args.args[0];self.assertIn(ci.TARGET,args)
   for path in ['src','resources','scripts','bin','support/runtime','support/startup','conf']:self.assertIn(path,args)
 def test_fixed_native_command_paths_do_not_expose_credentials(self):
  p=load('N05_moved_prerequisite_argv',DIR/'prerequisites/provision.py');q=load('N05_moved_qualification_argv',ROOT/'support/governance/project-content-grants-qualification/qualify.py')
  profile={'php':'/usr/bin/php','base_root':'/owned/base','source_root':'/owned/target','owner':'a'*32,'password':'CANARY_PASSWORD'}
  self.assertEqual(p.upgrade_argv(profile),['/usr/bin/php','/owned/base/bin/storage','upgrade','--force','--no-quickstart']);self.assertEqual(q.upgrade_argv(profile),['/usr/bin/php','/owned/target/bin/storage','upgrade','--force','--no-quickstart'])
  for argv in [p.upgrade_argv(profile),q.upgrade_argv(profile),p.people_argv(profile,'empower','requester')]:self.assertNotIn('CANARY_PASSWORD',' '.join(argv))
if __name__=='__main__':unittest.main()

class ExactCICohort(unittest.TestCase):
 def delta(self):
  import sys
  sys.path.insert(0,str(ROOT/'scripts/governance'))
  return load('N05_current_description_CI_delta',ROOT/'scripts/governance/project_description_admission_delta.py')
 def test_workflow_and_helpers_require_complete_exact_cohort(self):
  d=self.delta();self.assertEqual(d.assert_cohort(ROOT),set(d.PINS));self.assertTrue(d.assert_workflow(d.WORKFLOW,WORKFLOW.read_bytes()))
  for path,raw in [(d.WORKFLOW,WORKFLOW.read_bytes()+b'\n# unknown'),('.github/workflows/unknown.yml',WORKFLOW.read_bytes())]:
   with self.assertRaises(ValueError):d.assert_workflow(path,raw)
  with tempfile.TemporaryDirectory()as td:
   root=Path(td).resolve()
   names=set(d.PINS)|set(d.CURRENT_METADATA)|set(d.description.CURRENT_PINS)|{d.MANIFEST,d.BRIDGE,d.PREPARATION,'scripts/governance/project_content_grant_ci_source_delta.py'}
   for name in names:
    p=root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/name).read_bytes())
   path=root/'support/governance/project-content-grants-ci/prerequisites/provision.py';path.write_bytes(path.read_bytes()+b'\n# unknown')
   with self.assertRaises(ValueError):d.assert_cohort(root)
   path.unlink()
   with self.assertRaises((ValueError,OSError)):d.assert_cohort(root)
 def test_historical_admission_omits_only_exact_new_offline_files(self):
  d=self.delta();current={name:(ROOT/name).read_bytes()for name in d.PINS};current['unrelated']=b'unchanged';changed,out=d.historical_inputs(ROOT,ci.BASE,set(current),current)
  self.assertEqual(changed,{'unrelated'});self.assertEqual(out,{'unrelated':b'unchanged'})
  altered=dict(current);altered[d.WORKFLOW]+=b'\n# unknown'
  with self.assertRaises(ValueError):d.historical_inputs(ROOT,ci.BASE,set(altered),altered)
