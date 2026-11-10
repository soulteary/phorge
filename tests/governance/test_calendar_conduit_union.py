"""Exact main/Calendar union controls. Native PHP selector/cleanup, no SQL."""
import hashlib,json,subprocess,sys,unittest
from pathlib import Path
from recovery_oauth_code_test_cohort import complete_source_cohort
ROOT=Path(__file__).resolve().parents[2]
MAIN='fc0369af9d84c4a5f23a7080528e70f1ce84c4e7'
WIRE='9710b9edd56b9acf99b7c79b8c7d84669f7f2559'
DRIVER='support/governance/native-organization/test-native-organization-mysql.php'
MEDIA_WORKFLOW='.github/workflows/native-media-session-positive-sql.yml'
DESCRIPTION_WORKFLOW='.github/workflows/project-description-focused.yml'
WORKFLOW_BASELINE='46a1b044950584006cba754bc9841031d452eb76'
MEDIA_WORKFLOW_SHA256='d0b00933b908aa5e8da92ca3a1c6c154d7a082adaea9883a762324e4e8a3d786'
def git(*args):return subprocess.check_output(['git','-C',str(ROOT),*args])
def validate_workflow_union(changed,historical,read):
 import project_content_grant_ci_source_delta as n05_ci
 import project_description_admission_delta as n05_current
 n05_current.assert_current_recipe(ROOT)
 # This is an exact new current workflow, not a historical ignore rule.
 actual=n05_current.description.checked_bytes(ROOT/DESCRIPTION_WORKFLOW)
 if (ROOT/DESCRIPTION_WORKFLOW).stat().st_mode&0o7777!=0o644 or read(DESCRIPTION_WORKFLOW)!=actual or git('ls-tree',WORKFLOW_BASELINE,'--',DESCRIPTION_WORKFLOW)!=b'':
  raise ValueError('unreviewed description workflow')
 import recovery_oauth_code_ci_delta as code_ci
 import recovery_oauth_authorization_ci_delta as authorization_ci
 import recovery_oauth_client_lifecycle_ci_delta as lifecycle_ci
 if set(changed)!=set(historical)|{'.github/workflows/native-directory-transports-v1.yml',MEDIA_WORKFLOW,code_ci.WORKFLOW,authorization_ci.WORKFLOW,lifecycle_ci.WORKFLOW,n05_ci.WORKFLOW,DESCRIPTION_WORKFLOW}:
  raise ValueError('unreviewed workflow union')
 if hashlib.sha256(read(MEDIA_WORKFLOW)).hexdigest()!=MEDIA_WORKFLOW_SHA256:
  raise ValueError('reviewed media workflow changed')
 code_ci.assert_workflow(code_ci.WORKFLOW,read(code_ci.WORKFLOW))
 authorization_ci.assert_workflow(authorization_ci.WORKFLOW,read(authorization_ci.WORKFLOW))
 lifecycle_ci.assert_workflow(lifecycle_ci.WORKFLOW,read(lifecycle_ci.WORKFLOW))
 current_n05=read(n05_ci.WORKFLOW)
 if current_n05!=n05_current.description.checked_bytes(ROOT/n05_ci.WORKFLOW):
  raise ValueError('unreviewed current content-grants workflow')
 n05_current.assert_workflow(n05_ci.WORKFLOW,current_n05)
class CalendarConduitUnion(unittest.TestCase):
 def test_current_main_production_hooks_map_and_workflows_preserved(self):
  sys.path.insert(0,str(ROOT/'scripts/governance'));import recovery_assessment_source_delta as assessment
  previous='46a1b044950584006cba754bc9841031d452eb76'
  changed=set(git('diff','--name-only',previous,'--','src','resources').decode().splitlines())
  import recovery_oauth_code_source_delta as code_delta
  import recovery_oauth_authorization_source_delta as authorization_delta
  import recovery_oauth_client_lifecycle_delta as lifecycle_delta
  import project_description_admission_delta as n05_delta
  n05_delta.assert_production_cohort(ROOT)
  changed,reviewed=n05_delta.historical_inputs(ROOT,previous,changed,{path:(ROOT/path).read_bytes()for path in changed})
  self.assertEqual(changed,set(assessment.SOURCE_INPUTS)|set(code_delta.SOURCE_INPUTS)|set(authorization_delta.PRODUCTION_PATHS)|set(lifecycle_delta.PRODUCTION_PATHS))
  for path in changed:self.assertEqual(assessment.reduce_current(path,reviewed[path]),git('show',previous+':'+path))
  workflows={'.github/workflows/atomic-feature-regressions.yml','.github/workflows/oauth-access-producer-focused.yml','.github/workflows/assessment-credential-focused.yml'}
  validate_workflow_union(git('diff','--name-only',previous,'--','.github/workflows').decode().splitlines(),workflows,lambda path:(ROOT/path).read_bytes())
  self.assertEqual(git('ls-tree',previous,'--',MEDIA_WORKFLOW),b'')
  for path in workflows-{'.github/workflows/assessment-credential-focused.yml'}:
   raw=(ROOT/path).read_bytes();needle=b'check_recovery_oauth_access_producer_v3.py';self.assertEqual(raw.count(needle),1)
   self.assertEqual(raw.replace(needle,b'check_recovery_oauth_access_producer_v2.py'),git('show',previous+':'+path))
  for path in ['support/governance/recovery/conduit-producer.php','support/governance/recovery/conduit-focused.php','support/governance/recovery/conduit-cases.php','support/governance/recovery/conduit-diagnostic.php','support/governance/native-organization/native-worker.php','support/governance/native-organization/workers.php']:
   raw=(ROOT/path).read_bytes()
   if path=='support/governance/native-organization/workers.php':
    sys.path.insert(0,str(ROOT/'scripts/governance'));import recovery_worker_terminal_delta as worker_delta
    raw=worker_delta.reduce_current(path,raw)
   self.assertEqual(raw,git('show',MAIN+':'+path))
 def test_reviewed_media_workflow_union_rejects_unknown_addition_or_mutation(self):
  historical={'.github/workflows/atomic-feature-regressions.yml','.github/workflows/oauth-access-producer-focused.yml','.github/workflows/assessment-credential-focused.yml'}
  import recovery_oauth_code_ci_delta as code_ci
  import project_content_grant_ci_source_delta as n05_ci
  expected=historical|{'.github/workflows/native-directory-transports-v1.yml',MEDIA_WORKFLOW,code_ci.WORKFLOW,'.github/workflows/oauth-authorization-producer-focused.yml','.github/workflows/oauth-client-lifecycle-focused.yml',n05_ci.WORKFLOW,DESCRIPTION_WORKFLOW}
  raw=(ROOT/MEDIA_WORKFLOW).read_bytes()
  validate_workflow_union(expected,historical,lambda path:(ROOT/path).read_bytes())
  for changed in (expected|{'.github/workflows/unreviewed.yml'},expected-{MEDIA_WORKFLOW},expected-{DESCRIPTION_WORKFLOW}):
   with self.assertRaises(ValueError):validate_workflow_union(changed,historical,lambda path:(ROOT/path).read_bytes())
  with self.assertRaises(ValueError):validate_workflow_union(expected,historical,lambda path:raw+b'\n# unreviewed mutation\n')
 def test_exact_description_workflow_and_existing_hook_mutations_are_refused(self):
  import recovery_oauth_code_ci_delta as code_ci
  import project_content_grant_ci_source_delta as n05_ci
  import project_description_admission_delta as n05_current
  historical={'.github/workflows/atomic-feature-regressions.yml','.github/workflows/oauth-access-producer-focused.yml','.github/workflows/assessment-credential-focused.yml'}
  changed=git('diff','--name-only',WORKFLOW_BASELINE,'--','.github/workflows').decode().splitlines()
  for target in (DESCRIPTION_WORKFLOW,code_ci.WORKFLOW,n05_ci.WORKFLOW):
   def mutated(path):
    raw=(ROOT/path).read_bytes()
    return raw+b'\n# unknown-workflow-mutation\n'if path==target else raw
   with self.subTest(path=target),self.assertRaises(ValueError):validate_workflow_union(changed,historical,mutated)
  def old_target(path):
   return n05_current._target_bytes(path)if path==n05_ci.WORKFLOW else(ROOT/path).read_bytes()
  with self.assertRaises(ValueError):validate_workflow_union(changed,historical,old_target)
 def test_signed_Calendar_fixture_files_are_exact_and_not_reimplemented(self):
  paths=git('diff','--name-only','c496e2cf0ae25fb3e5153c6a1070eb53cf56b504',WIRE,'--','support/governance/notification').decode().splitlines()
  self.assertGreater(len(paths),10)
  for path in paths:self.assertEqual((ROOT/path).read_bytes(),git('show',WIRE+':'+path))
 def test_exact_dual_profile_inverse_and_unknown_mutation_refusal(self):
  sys.path.insert(0,str(ROOT/'scripts/governance'))
  import recovery_conduit_source_delta as delta
  import check_private_calendar_reminders as calendar
  raw=(ROOT/DRIVER).read_bytes()
  self.assertEqual(delta.reduce_calendar_wire_union(raw),git('show',MAIN+':'+DRIVER))
  self.assertEqual(delta.reduce_driver(raw),git('show','c496e2cf0ae25fb3e5153c6a1070eb53cf56b504:'+DRIVER))
  self.assertEqual(calendar.reduce_reviewed_calendar_wire_driver(raw),delta.reduce_driver(raw))
  for addition in delta.CALENDAR_WIRE_DRIVER_ADDITIONS:
   changed=raw.replace(addition,b'',1);old=delta.DRIVER_SHA256
   try:
    delta.DRIVER_SHA256=hashlib.sha256(changed).hexdigest()
    with self.assertRaises(ValueError):delta.reduce_driver(changed)
   finally:delta.DRIVER_SHA256=old
 def test_OAuth_current_and_historical_consumers_keep_manifests_and_reject_driver_tamper(self):
  import ast,tempfile
  sys.path.insert(0,str(ROOT/'scripts/governance'))
  import check_recovery_oauth_access_producer_v3 as current
  import check_recovery_oauth_access_producer as historical
  self.assertEqual((50,8,18),(len(current.SOURCE_INPUTS),len(current.SQL_obligations()),len(current.obligations())))
  import check_recovery_oauth_access_producer_v2 as historical_v2
  for module in (historical,historical_v2):
   path='scripts/governance/'+module.__name__+'.py'
   def pins(raw):return next(ast.literal_eval(x.value)for x in ast.parse(raw).body if isinstance(x,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='SOURCE_INPUTS'for t in x.targets))
   import project_description_admission_delta as n05_delta
   self.assertEqual(pins(n05_delta.reduce_if_selected(path,(ROOT/path).read_bytes())),pins(git('show','46a1b044950584006cba754bc9841031d452eb76:'+path)))
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve()
   for n in current.SOURCE_INPUTS:
    p=root/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/n).read_bytes());p.chmod(0o600)
   complete_source_cohort(root)
   self.assertEqual(current.SQL_obligations(root),current.SQL_obligations())
   p=root/DRIVER;p.write_bytes(p.read_bytes()+b'// unrelated edit')
   with self.assertRaises(ValueError):current.SQL_obligations(root)
 def test_worker_terminal_layer_composes_without_changing_Calendar_or_historical_pins(self):
  import ast
  sys.path.insert(0,str(ROOT/'scripts/governance'));import recovery_worker_terminal_delta as worker
  for name in ('check_recovery_session_lease','check_recovery_borrowed_session','check_recovery_conduit_producer'):
   gate=__import__(name);path='scripts/governance/'+name+'.py'
   def pins(raw):return next(ast.literal_eval(n.value)for n in ast.parse(raw).body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='SOURCE_INPUTS'for t in n.targets))
   import recovery_assessment_source_delta as assessment
   import project_description_admission_delta as n05_delta
   historical=pins(n05_delta.reduce_if_selected(path,(ROOT/path).read_bytes()))
   for source,pin in assessment.SOURCE_INPUTS.items():
    if source in historical:
     self.assertEqual(historical[source],pin);self.assertEqual(hashlib.sha256(assessment.reduce_current(source,(ROOT/source).read_bytes())).hexdigest(),assessment.BASE_PINS[source]);historical[source]=assessment.BASE_PINS[source]
   self.assertEqual(historical,pins(git('show','732dd79049f017becc8b05d1c3fd0852ed00ff44:'+path)))
   self.assertEqual(gate.SOURCE_INPUTS[DRIVER],hashlib.sha256((ROOT/DRIVER).read_bytes()).hexdigest())
   self.assertTrue(gate.SQL_obligations())
  self.assertEqual(worker.reduce_current(worker.PATH,(ROOT/worker.PATH).read_bytes()),git('show',MAIN+':'+worker.PATH))
 def test_OAuth_worker_source_view_accepts_only_exact_old_or_terminal_inverse(self):
  import tempfile
  sys.path.insert(0,str(ROOT/'scripts/governance'))
  import check_recovery_oauth_access_producer as old
  import check_recovery_oauth_access_producer_v3 as current
  import check_recovery_conduit_producer as conduit
  import recovery_worker_terminal_delta as worker
  actual=(ROOT/worker.PATH).read_bytes();prior=git('show',MAIN+':'+worker.PATH)
  for module in (old,current):
   self.assertEqual(module.worker_terminal.reduce_if_selected(worker.PATH,actual),prior)
   self.assertEqual(module.worker_terminal.reduce_if_selected(worker.PATH,prior),prior)
   with self.assertRaises(ValueError):module.worker_terminal.reduce_if_selected(worker.PATH,actual+b'// other change')
  self.assertEqual((len(current.SQL_obligations()),len(conduit.SQL_obligations())),(8,6))
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve()
   for name in current.SOURCE_INPUTS:
    p=root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/name).read_bytes());p.chmod(0o600)
   complete_source_cohort(root)
   self.assertEqual(current.SQL_obligations(root),current.SQL_obligations())
   (root/worker.PATH).write_bytes(prior);self.assertEqual(current.SQL_obligations(root),current.SQL_obligations())
   (root/worker.PATH).write_bytes(actual+b'// other change')
   with self.assertRaises(ValueError):current.SQL_obligations(root)
 def test_actual_PHP_both_selectors_and_independent_unknown_cleanup_guards(self):
  program=r'''$s=file_get_contents($argv[1]);$a=strpos($s,'$arguments=array_slice');$b=strpos($s,'$fixture = null;',$a);$init=substr($s,$a,$b-$a);$a=strpos($s,'$borrowed_unknown=$phase===');$b=strpos($s,'  if(!$borrowed_unknown){$_COOKIE',$a);$unknown=substr($s,$a,$b-$a);$a=strrpos($s,'  if($borrowed_unknown){');$b=strpos($s,'  if(!$borrowed_unknown){if ($control !== null)',$a);$guard=substr($s,$a,$b-$a);function governance_recovery_conduit_cleanup_ready(){return($GLOBALS['governance_recovery_conduit_cleanup_proved']??null)===true;}class UnionFixture{function cleanup(){$GLOBALS['drops']++;return[];}}$rows=[];foreach(['--calendar-reminder-wire-hold','--conduit-producer-owner']as$selector){$argv=['owned',$selector];eval($init);$fixture=new UnionFixture();$cleanup_failures=[];$checks=[];$drops=0;eval($unknown);eval($guard);$rows[]=['phase'=>$phase,'calendar'=>$calendar_wire,'calendar_cleanup'=>$GLOBALS['governance_workbench_http_cleanup_permitted'],'conduit_cleanup'=>$GLOBALS['governance_recovery_conduit_cleanup_proved'],'drops'=>$drops,'failures'=>count($cleanup_failures)];}echo json_encode($rows);'''
  r=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen','-r',program,str(ROOT/DRIVER)],capture_output=True,text=True,timeout=5)
  self.assertEqual(r.returncode,0,r.stderr);self.assertEqual(r.stderr,'');rows=json.loads(r.stdout)
  self.assertEqual(rows,[{'phase':'calendar-reminder-wire-hold','calendar':True,'calendar_cleanup':False,'conduit_cleanup':True,'drops':0,'failures':1},{'phase':'conduit-producer-owner','calendar':False,'calendar_cleanup':True,'conduit_cleanup':False,'drops':0,'failures':1}])
