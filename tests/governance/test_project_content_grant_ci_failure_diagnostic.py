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
if __name__=='__main__':unittest.main()
