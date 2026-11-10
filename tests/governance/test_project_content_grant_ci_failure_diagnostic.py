"""Pure diagnostics controls; no native startup, database or child process."""
import importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('n05_failure_ci',ROOT/'support/governance/project-content-grants-ci/ci.py')
ci=importlib.util.module_from_spec(spec);spec.loader.exec_module(ci)
class DiagnosticTests(unittest.TestCase):
 def test_native_failure_retains_unknown_and_excludes_all_private_values(self):
  secret='CANARY-token-actor-profile-password'
  class Journal:
   n=4
   def run(self,argv,cwd,timeout=60):raise ValueError(secret)
  ci.instrument_journal(Journal,'prerequisites')
  try:Journal().run(['/usr/bin/php','/owned/native.php',secret,secret,'tokens',secret],secret)
  except ValueError as e:summary=ci.failure_summary(e)
  self.assertEqual(summary['stage'],'prerequisites-native-tokens');self.assertEqual(summary['child_ordinal'],1)
  self.assertEqual(summary['child_outcome'],'unknown');self.assertIsNone(summary['child_exit_code'])
  self.assertEqual(summary['SQL_execution_status'],'unknown');self.assertNotIn(secret,json.dumps(summary))
  self.assertFalse(summary['automatic_retry_or_cleanup']);self.assertFalse(summary['execution_authorized'])
 def test_upgrade_and_success_observed_without_reading_journal(self):
  class Journal:
   n=0
   def run(self,argv,cwd,timeout=60):return b'private-result'
  ci.instrument_journal(Journal,'qualification')
  self.assertEqual(Journal().run(['/usr/bin/php','/target/bin/storage','upgrade'],'/target'),b'private-result')
  self.assertEqual(ci.DIAGNOSTIC['stage'],'qualification-standard-upgrade')
  self.assertEqual(ci.DIAGNOSTIC['child_outcome'],'known-zero')
 def test_unknown_exception_class_and_untrusted_action_never_export(self):
  class CANARY_PRIVATE_EXCEPTION(Exception):pass
  class Journal:
   n=1
   def run(self,*a,**k):raise CANARY_PRIVATE_EXCEPTION('private')
  ci.instrument_journal(Journal,'qualification')
  try:Journal().run(['php','/native.php','root','profile','CANARY_PRIVATE_ACTION'],'root')
  except Exception as e:summary=ci.failure_summary(e)
  self.assertEqual(summary['stage'],'qualification-child');self.assertEqual(summary['error_code'],'unclassified-failure')
  self.assertNotIn('CANARY',json.dumps(summary))
 def test_new_phase_clears_old_child_observation(self):
  ci.stage('runtime-after');self.assertIsNone(ci.DIAGNOSTIC['child_ordinal']);self.assertEqual(ci.DIAGNOSTIC['child_outcome'],'not-observed')
class ActualJournalTests(unittest.TestCase):
 def load(self,relative,name):
  spec=importlib.util.spec_from_file_location(name,ROOT/relative)
  module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module
 def test_both_actual_pinned_journal_classes_without_children(self):
  import tempfile
  from unittest.mock import patch
  for relative,name,counter in [('support/governance/project-content-grants-ci/prerequisites/provision.py','actual_prerequisite','n'),('support/governance/project-content-grants-qualification/qualify.py','actual_qualifier','i')]:
   module=self.load(relative,name)
   with tempfile.TemporaryDirectory() as td:
    journal=module.Journal(Path(td).resolve()/'journal')
    with patch.object(module.Journal,'run',return_value=b'local-no-child') as original,patch.object(module.subprocess,'Popen') as child:
     ci.instrument_journal(module.Journal,'qualification' if counter=='i' else 'prerequisites')
     for ordinal in (1,2):
      self.assertEqual(journal.run(['/usr/bin/php','/native.php','root','profile','readback'],'root'),b'local-no-child')
      self.assertEqual(ci.DIAGNOSTIC['child_ordinal'],ordinal)
     self.assertEqual(getattr(journal,counter),0);self.assertEqual(original.call_count,2);child.assert_not_called()
 def test_exact_actual_native_action_vocabulary(self):
  import ast
  cases=[('support/governance/project-content-grants-ci/prerequisites/provision.py','prerequisites'),('support/governance/project-content-grants-qualification/qualify.py','qualification')]
  observed=set()
  for relative,phase in cases:
   tree=ast.parse((ROOT/relative).read_text())
   actions={n.args[3].value for n in ast.walk(tree) if isinstance(n,ast.Call) and isinstance(n.func,ast.Name) and n.func.id=='native' and len(n.args)>3 and isinstance(n.args[3],ast.Constant)}
   for action in actions:
    class Journal:
     def run(self,*args,**kwargs):return b'no-child'
    ci.instrument_journal(Journal,phase);Journal().run(['php','/native.php','root','profile',action],'root')
    self.assertEqual(ci.DIAGNOSTIC['stage'],phase+'-native-'+action);observed.add(action)
  self.assertTrue({'before-upgrade','target','submit-grant','submit-revoke','deny-self','deny-replay'}<=observed)
class OriginalJournalOutcomeTests(unittest.TestCase):
 def load(self,phase):
  relative='support/governance/project-content-grants-qualification/qualify.py' if phase=='qualification' else 'support/governance/project-content-grants-ci/prerequisites/provision.py'
  spec=importlib.util.spec_from_file_location('actual_journal_'+phase,ROOT/relative);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
 def marker(self):
  return {'schema':'N05-native-failure-v1','passed':False,'phase':'submit-service','exceptions':[{'family':'runtime','component':'content-service','line':17,'reason':'content_grant_disabled','query_code':None,'trace':[]}],'execution_authorized':False,'SQL_execution_status':'unknown'}
 def fake(self,module,payload=b'',code=1,timeout=False):
  import subprocess
  class Child:
   pid=777
   calls=0
   def __init__(self,*a,**kw):
    Child.calls+=1;kw['stdout'].write(payload);kw['stderr'].write(b'CANARY-private-credential-actor-SQL');kw['stdout'].flush();kw['stderr'].flush()
   def wait(self,timeout=None):
    if timeout_flag:raise subprocess.TimeoutExpired('CANARY-private-argv',timeout)
    return code
  timeout_flag=timeout
  return Child
 def run_actual(self,phase,td,payload=b'',code=1,timeout=False,instrument=True):
  from unittest.mock import patch
  m=self.load(phase);j=m.Journal(Path(td).resolve()/'journal');child=self.fake(m,payload,code,timeout)
  if instrument:ci.instrument_journal(m.Journal,phase)
  else:
   j._test_original=m.Journal.run;j._test_token=ci.prepare_child_observation(j,phase,1)
   if j._test_token is not None:self.addCleanup(__import__('os').close,j._test_token['fd'])
  with patch.object(m.subprocess,'Popen',child):
   try:j.run(['/usr/bin/php','/owned/native.php','CANARY-root','CANARY-profile','submit-grant','CANARY-input'],'/owned',timeout=1)
   except Exception as e:error=e
   else:raise AssertionError('failure expected')
  self.assertEqual(child.calls,1);return j,error
 def test_both_original_journals_known_nonzero_without_leaking_private_files(self):
  import tempfile
  for phase in ('qualification','prerequisites'):
   with self.subTest(phase=phase),tempfile.TemporaryDirectory()as td:
    j,e=self.run_actual(phase,td,json.dumps(self.marker()).encode())
    d=ci.failure_summary(e);self.assertEqual(d['child_exit_code'],1);self.assertEqual(d['child_outcome'],'known-nonzero');self.assertEqual(d['child_ordinal'],1)
    self.assertEqual(d['native_diagnostic'],self.marker()if phase=='qualification'else None)
    self.assertNotIn('CANARY',json.dumps(d));self.assertEqual(d['SQL_execution_status'],'unknown');self.assertFalse(d['execution_authorized']);self.assertFalse(d['automatic_retry_or_cleanup'])
 def test_both_original_timeouts_remain_unknown_and_have_no_terminal(self):
  import tempfile
  for phase in ('qualification','prerequisites'):
   with self.subTest(phase=phase),tempfile.TemporaryDirectory()as td:
    j,e=self.run_actual(phase,td,json.dumps(self.marker()).encode(),timeout=True)
    d=ci.failure_summary(e);self.assertEqual(d['child_outcome'],'unknown');self.assertIsNone(d['child_exit_code']);self.assertIsNone(d['native_diagnostic']);self.assertTrue((j.path/'01-unknown.json').is_file());self.assertFalse((j.path/'01-terminal.json').exists())
 def test_original_output_limit_is_failure_even_when_exit_known_zero(self):
  import tempfile
  for phase in ('qualification','prerequisites'):
   with self.subTest(phase=phase),tempfile.TemporaryDirectory()as td:
    j,e=self.run_actual(phase,td,b'x'*(1048576+1),code=0);d=ci.failure_summary(e)
    self.assertFalse(d['passed']);self.assertEqual(d['child_outcome'],'known-zero');self.assertEqual(d['child_exit_code'],0);self.assertIsNone(d['native_diagnostic']);self.assertEqual(d['SQL_execution_status'],'unknown')
 def test_original_start_failure_has_no_terminal_and_stays_unknown(self):
  import tempfile
  from unittest.mock import patch
  for phase in ('qualification','prerequisites'):
   with self.subTest(phase=phase),tempfile.TemporaryDirectory()as td:
    m=self.load(phase);j=m.Journal(Path(td).resolve()/'journal');ci.instrument_journal(m.Journal,phase)
    with patch.object(m.subprocess,'Popen',side_effect=OSError('CANARY-private')):
     try:j.run(['/usr/bin/php','/native.php','root','profile','submit-grant'],'/owned')
     except OSError as e:d=ci.failure_summary(e)
    self.assertEqual(d['child_outcome'],'unknown');self.assertIsNone(d['child_exit_code']);self.assertFalse((j.path/'01-terminal.json').exists());self.assertNotIn('CANARY',json.dumps(d))
 def test_untrusted_or_mixed_original_records_never_create_known_outcome(self):
  import tempfile,os
  cases=('missing-terminal','missing-started','unknown-marker','bool-exit','wrong-schema','extra-key','duplicate-key','invalid-pid','terminal-mode','terminal-hardlink','terminal-symlink','wrong-counter','owner-mismatch','exit-mismatch','directory-swap')
  for case in cases:
   with self.subTest(case=case),tempfile.TemporaryDirectory()as td:
    j,e=self.run_actual('qualification',td,instrument=False);p=j.path/'01-terminal.json'
    if case=='missing-terminal':p.unlink()
    elif case=='missing-started':(j.path/'01-started.json').unlink()
    elif case=='unknown-marker':j.write('01-unknown.json',b'{"terminal_known":false,"signals_retry_cleanup":false}\n')
    elif case=='bool-exit':p.write_bytes(ci.canon({'terminal_known':True,'exit_code':True}))
    elif case=='wrong-schema':p.write_bytes(ci.canon({'known_terminal':True,'exit_code':1}))
    elif case=='extra-key':p.write_bytes(ci.canon({'terminal_known':True,'exit_code':1,'CANARY':'private'}))
    elif case=='duplicate-key':p.write_bytes(b'{"exit_code":1,"exit_code":1,"terminal_known":true}\n')
    elif case=='invalid-pid':(j.path/'01-started.json').write_bytes(ci.canon({'pid':True}))
    elif case=='terminal-mode':p.chmod(0o644)
    elif case=='terminal-hardlink':os.link(p,j.path/'linked')
    elif case=='terminal-symlink':data=p.read_bytes();p.unlink();(j.path/'alias').write_bytes(data);p.symlink_to('alias')
    elif case=='wrong-counter':j.i=2
    elif case=='exit-mismatch':p.write_bytes(ci.canon({'terminal_known':True,'exit_code':255}))
    elif case=='directory-swap':
     import shutil
     original_dir=j.path.with_name('original');j.path.rename(original_dir);j.path.mkdir(mode=0o700)
     for file in original_dir.iterdir():shutil.copyfile(file,j.path/file.name);(j.path/file.name).chmod(0o600)
    ci.stage('qualification-native-submit-grant');ci.DIAGNOSTIC.update(child_ordinal=1,child_outcome='unknown')
    from unittest.mock import patch
    if case=='owner-mismatch':
     with patch.object(ci.os,'geteuid',return_value=os.geteuid()+1):ci.observe_failed_child(j,'qualification',1,'native-submit-grant',j._test_token,e,j._test_original)
    else:ci.observe_failed_child(j,'qualification',1,'native-submit-grant',j._test_token,e,j._test_original)
    self.assertEqual(ci.DIAGNOSTIC['child_outcome'],'unknown');self.assertIsNone(ci.DIAGNOSTIC['child_exit_code']);self.assertIsNone(ci.DIAGNOSTIC['native_diagnostic'])
 def test_native_marker_is_strict_projection_and_unknown_stdout_is_not_exported(self):
  import tempfile,copy
  bad=[]
  for field,value in [('phase','CANARY-private'),('exceptions',[{'private':'CANARY'}]),('execution_authorized',True),('SQL_execution_status','complete')]:
   m=copy.deepcopy(self.marker());m[field]=value;bad.append(json.dumps(m).encode())
  bad.extend([b'CANARY-token-secret',json.dumps({**self.marker(),'CANARY':'private'}).encode(),json.dumps(self.marker()).encode()+b'CANARY'])
  for raw in bad:
   with self.subTest(raw=raw[:15]),tempfile.TemporaryDirectory()as td:
    j,e=self.run_actual('qualification',td,raw);d=ci.failure_summary(e);self.assertEqual(d['child_outcome'],'known-nonzero');self.assertIsNone(d['native_diagnostic']);self.assertNotIn('CANARY',json.dumps(d))
 def test_preexisting_original_records_and_after_prepare_insertion_never_adopt_stale_terminal(self):
  import tempfile
  from unittest.mock import patch
  for phase in ('qualification','prerequisites'):
   for when in ('before-prepare','after-prepare'):
    with self.subTest(phase=phase,when=when),tempfile.TemporaryDirectory()as td:
     m=self.load(phase);j=m.Journal(Path(td).resolve()/'journal')
     def insert():
      j.write('01-intent.json',m.canon({'argv':['CANARY-old'],'retry_cleanup':False}))
      j.write('01-started.json',m.canon({'pid':777}))
      j.write('01-terminal.json',m.canon({'terminal_known'if phase=='qualification'else'known_terminal':True,'exit_code':255}))
     if when=='before-prepare':insert()
     original_prepare=ci.prepare_child_observation
     def raced(*a):
      token=original_prepare(*a)
      if when=='after-prepare':insert()
      return token
     ci.instrument_journal(m.Journal,phase)
     with patch.object(ci,'prepare_child_observation',side_effect=raced),patch.object(m.subprocess,'Popen')as child:
      try:j.run(['/usr/bin/php','/native.php','root','profile','submit-grant'],'/owned')
      except (FileExistsError,ValueError) as e:d=ci.failure_summary(e)
     child.assert_not_called();self.assertEqual(d['child_outcome'],'unknown');self.assertIsNone(d['child_exit_code']);self.assertIsNone(d['native_diagnostic'])
 def test_fake_wait_fixed_message_with_forged_terminal_is_not_original_final_guard(self):
  import tempfile
  from unittest.mock import patch
  with tempfile.TemporaryDirectory()as td:
   m=self.load('qualification');j=m.Journal(Path(td).resolve()/'journal')
   class Child:
    pid=777
    def __init__(self,*a,**kw):pass
    def wait(self,timeout=None):
     j.write('01-terminal.json',m.canon({'terminal_known':True,'exit_code':255}))
     m.need(False)
   ci.instrument_journal(m.Journal,'qualification')
   with patch.object(m.subprocess,'Popen',Child):
    try:j.run(['/usr/bin/php','/native.php','root','profile','submit-grant'],'/owned')
    except ValueError as e:d=ci.failure_summary(e)
   self.assertEqual(d['child_outcome'],'unknown');self.assertIsNone(d['child_exit_code'])
 def test_prepared_directory_descriptor_is_closed_on_success_and_failure(self):
  import tempfile,os,errno
  from unittest.mock import patch
  for code in (0,1):
   with self.subTest(code=code),tempfile.TemporaryDirectory()as td:
    m=self.load('qualification');j=m.Journal(Path(td).resolve()/'journal');child=self.fake(m,b'public-test-result',code);tokens=[];prepare=ci.prepare_child_observation
    def captured(*a):
     token=prepare(*a);self.assertIsNotNone(token);tokens.append(token);return token
    ci.instrument_journal(m.Journal,'qualification')
    with patch.object(ci,'prepare_child_observation',side_effect=captured),patch.object(m.subprocess,'Popen',child):
     try:result=j.run(['/usr/bin/php','/native.php','root','profile','submit-grant'],'/owned')
     except ValueError:self.assertEqual(code,1)
     else:self.assertEqual(result,b'public-test-result');self.assertEqual(code,0)
    self.assertEqual(len(tokens),1)
    with self.assertRaises(OSError)as failure:os.fstat(tokens[0]['fd'])
    self.assertEqual(failure.exception.errno,errno.EBADF)
 def test_unknown_instrument_phase_is_refused_before_wrapping(self):
  class Journal:
   def run(self,*a,**k):return b'not-run'
  original=Journal.run
  with self.assertRaises(ValueError):ci.instrument_journal(Journal,'CANARY-private-phase')
  self.assertIs(Journal.run,original)
 def test_record_mutation_between_reads_is_refused(self):
  import tempfile
  from unittest.mock import patch
  with tempfile.TemporaryDirectory()as td:
   j,e=self.run_actual('qualification',td,instrument=False);original=ci.diagnostic_json;count=0
   def changed(raw):
    nonlocal count
    value=original(raw);count+=1
    if count==2:(j.path/'01-started.json').write_bytes(ci.canon({'pid':778}))
    return value
   ci.stage('qualification-native-submit-grant');ci.DIAGNOSTIC.update(child_ordinal=1,child_outcome='unknown')
   with patch.object(ci,'diagnostic_json',side_effect=changed):ci.observe_failed_child(j,'qualification',1,'native-submit-grant',j._test_token,e,j._test_original)
   self.assertEqual(ci.DIAGNOSTIC['child_outcome'],'unknown')

class NativeProjectionTests(unittest.TestCase):
 def php(self,code):
  import subprocess
  prefix='require '+json.dumps(str(ROOT/'support/runtime/src/__phutil_library_init__.php'))+';phutil_load_library('+json.dumps(str(ROOT/'src'))+');define("N05_QUALIFICATION_LIBRARY_ONLY",true);require '+json.dumps(str(ROOT/'support/governance/project-content-grants-qualification/native.php'))+';'
  p=subprocess.run(['php','-r',prefix+code],cwd=ROOT,capture_output=True,text=True,timeout=20);self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
 def test_real_native_disabled_service_exception_projects_public_source_only(self):
  d=self.php('qphase("submit-service");try{PhorgeProjectContentGrantService::assertEnabled();throw new Exception("unexpected");}catch(Throwable$e){echo json_encode(qfailure($e,'+json.dumps(str(ROOT))+'));}')
  ci.validate_native_failure(d);self.assertEqual(d['phase'],'submit-service');self.assertEqual(d['exceptions'][0]['component'],'content-service');self.assertEqual(d['exceptions'][0]['reason'],'content_grant_disabled');self.assertFalse(d['execution_authorized']);self.assertEqual(d['SQL_execution_status'],'unknown')
 def test_unknown_message_trace_args_and_phase_are_never_exported(self):
  d=self.php('qphase("CANARY-private-phase");function privatecall($secret){throw new RuntimeException($secret);}try{privatecall("CANARY-token-password-actor-SQL");}catch(Throwable$e){$wrapped=new RuntimeException("CANARY-wrapper",0,$e);echo json_encode(qfailure($wrapped,"CANARY-root"));}')
  ci.validate_native_failure(d);self.assertEqual(d['phase'],'entry');self.assertEqual(len(d['exceptions']),2);self.assertNotIn('CANARY',json.dumps(d));self.assertTrue(all(e['reason']=='other' for e in d['exceptions']))
 def test_corrupted_diagnostic_global_is_revalidated_before_projection(self):
  d=self.php('$GLOBALS["n05_qualification_diagnostic_phase"]="CANARY-global-private";echo json_encode(qfailure(new RuntimeException("CANARY-message"),"CANARY-root"));')
  ci.validate_native_failure(d);self.assertEqual(d['phase'],'entry');self.assertNotIn('CANARY',json.dumps(d))
 def test_real_native_query_exception_projects_only_bounded_numeric_code(self):
  d=self.php('qphase("submit-service");echo json_encode(qfailure(new AphrontQueryException("CANARY-SQL-secret",1064),'+json.dumps(str(ROOT))+'));')
  ci.validate_native_failure(d);self.assertEqual(d['exceptions'][0]['family'],'query');self.assertEqual(d['exceptions'][0]['query_code'],1064);self.assertNotIn('CANARY',json.dumps(d))

if __name__=='__main__':unittest.main()
