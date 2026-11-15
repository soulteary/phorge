"""Assessment9 explicit Task counterparts. No default API or issuer replacement."""
import ast,os,shutil,stat,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import source_operation_snapshot as source
import check_recovery_assessment_credentials_v2 as assessment
import project_description_admission_delta as admission
import assessment_task_test_api_v2 as task
from assessment_legacy_DATA_model_v3 import legacy_DATA_labels
from recovery_oauth_code_task_test_cohort_v2 import complete_source_cohort

def canonical_temp_base():return Path(tempfile.gettempdir()).resolve(strict=True)
class AssessmentTaskTopLevelV2(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.temp=tempfile.TemporaryDirectory(dir=canonical_temp_base());cls.producer=Path(cls.temp.name)/'producer'
  shutil.copytree(ROOT,cls.producer,symlinks=True,ignore=shutil.ignore_patterns('.git','__pycache__'))
 @classmethod
 def tearDownClass(cls):cls.temp.cleanup()
 def test_default_current_labels_match_explicit_and_each_call_issues_fresh_operation(self):
  # Old implicit current default is NOT silently redirected to Task.
  with self.assertRaises(ValueError):assessment.SQL_obligations()
  issued=[];first=task.call('SQL_obligations',issued=issued);second=task.call('SQL_obligations',issued=issued)
  self.assertEqual(first,second);self.assertEqual(len(first),8)
  self.assertEqual(len(issued),2);self.assertIsNot(issued[0],issued[1]);self.assertEqual([o._state for o in issued],['closed','closed'])
  with task.opened(ROOT)as operation:self.assertEqual(first,assessment.SQL_obligations(_source_operation=operation))
 def test_distinct_fixture_and_fake_global_ROOT_keep_strict_path(self):
  # Real parent DATA constructs a legacy strict-reader fixture, not a source owner.
  with tempfile.TemporaryDirectory(dir=canonical_temp_base())as directory:
   fixture=Path(directory);self._minimal(fixture)
   with patch.object(source,'open_source_operation',side_effect=AssertionError('unexpected fixture operation')):
    with self.assertRaises(ValueError):assessment.SQL_obligations(fixture)
    projected=legacy_DATA_labels(fixture)
    self.assertEqual(len(projected['labels']),8);self.assertIs(projected['public_default_fixture_executed'],False);self.assertIs(projected['E_is_producer_or_current_source'],False)
    with patch.object(assessment,'ROOT',fixture):
     with self.assertRaises(ValueError):assessment.SQL_obligations(fixture)
     self.assertEqual(len(legacy_DATA_labels(fixture)['labels']),8)
   # Public ROOT reassignment cannot redirect either default's bound code root.
   with patch.object(assessment,'ROOT',fixture):
    with self.assertRaises(ValueError):assessment.SQL_obligations()
    self.assertEqual(len(task.call('SQL_obligations')),8)
 def test_default_nested_refuses_but_explicit_operation_still_works(self):
  with task.opened(ROOT)as operation:
   with self.assertRaises(ValueError):assessment.SQL_obligations()
   with self.assertRaises(ValueError):task.call('SQL_obligations')
   self.assertEqual(len(assessment.SQL_obligations(_source_operation=operation)),8)
 def test_code_root_entry_failure_propagates_without_public_strict_fallback(self):
  path=ROOT/'support/governance/recovery/assessment-credential-cases.php';raw=path.read_bytes()
  try:
   path.write_bytes(raw+b'\n# unreviewed')
   with patch.object(assessment.code_coord,'assert_cohort',side_effect=AssertionError('fallback'))as fallback:
    with self.assertRaises(ValueError):task.call('SQL_obligations')
    fallback.assert_not_called()
  finally:path.write_bytes(raw)
 def test_exit_source_tamper_refuses_before_labels_return(self):
  # Same actual __exit__ fault boundary, not an issuer or reader replacement.
  original=source.SourceOperation.__exit__;path=ROOT/'support/governance/recovery/assessment-credential-cases.php';raw=path.read_bytes()
  def corrupt_before_exit(operation,*args):
   path.write_bytes(raw+b'\n# changed at exit')
   try:return original(operation,*args)
   finally:path.write_bytes(raw)
  with patch.object(source.SourceOperation,'__exit__',corrupt_before_exit):
   with self.assertRaises(ValueError):task.call('SQL_obligations')
 def test_summary_and_obligations_keep_exact_values_without_nested_operation(self):
  with self.assertRaises(ValueError):assessment.SQL_obligations()
  labels=task.call('SQL_obligations');self.assertEqual(task.call('obligations'),assessment.shared.BOOTSTRAP|assessment.shared.PREPARATION|assessment.shared.CLEANUP|labels)
  plain=task.call('summary',revision='a'*40)
  with task.opened(ROOT)as operation:explicit=assessment.summary('a'*40,_source_operation=operation)
  self.assertEqual(plain,explicit)
 def test_real_independent_clone_derives_its_own_code_recipe_root(self):
  with tempfile.TemporaryDirectory(dir=canonical_temp_base())as directory:
   clone=Path(directory)/'clone'
   # Real clean repository + objects, never a DATA fixture presented as current source.
   subprocess.run(['/usr/bin/git','--no-replace-objects','clone','--no-hardlinks','--no-checkout',str(ROOT),str(clone)],check=True,capture_output=True,timeout=120)
   subprocess.run(['/usr/bin/git','--no-replace-objects','-C',str(clone),'checkout','--detach',task.checkpoint()],check=True,capture_output=True,timeout=120)
   script='import sys,os;from pathlib import Path;root=Path(sys.argv[1]);sys.path.insert(0,str(root/"scripts/governance"));import check_recovery_assessment_credentials_v2 as gate;import source_operation_snapshot as source;assert gate._CODE_RECIPE_ROOT==root;op=source.open_task_source_operation(root,expected_checkpoint=os.environ["PHORGE_TASK_TEST_CHECKPOINT"]);\nwith op as owner: assert len(gate.SQL_obligations(_source_operation=owner))==8\nprint("clone_current_pass")'
   result=subprocess.run([sys.executable,'-B','-c',script,str(clone)],capture_output=True,text=True,timeout=10)
   self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(result.stdout,'clone_current_pass\n')
 def test_missing_cohort_preserves_real_modes_and_never_repairs_existing_inputs(self):
  with tempfile.TemporaryDirectory(dir=canonical_temp_base())as directory:
   fixture=Path(directory);complete_source_cohort(fixture,task.checkpoint());state=fixture/admission.description.NATIVE_STATE_BASELINE['path']
   self.assertEqual(stat.S_IMODE(state.stat().st_mode),0o644);admission.assert_current_recipe(fixture)
   original=state.read_bytes();state.chmod(0o600);complete_source_cohort(fixture,task.checkpoint());self.assertEqual(stat.S_IMODE(state.stat().st_mode),0o600)
   with self.assertRaises(ValueError):admission.assert_current_recipe(fixture)
   state.chmod(0o644);state.write_bytes(original+b'unknown');complete_source_cohort(fixture,task.checkpoint());self.assertEqual(state.read_bytes(),original+b'unknown')
   with self.assertRaises(ValueError):admission.assert_current_recipe(fixture)
   state.unlink();destination=fixture/'uncreated-symlink-target';state.symlink_to(destination);complete_source_cohort(fixture,task.checkpoint())
   self.assertTrue(state.is_symlink());self.assertFalse(destination.exists())
   with self.assertRaises((ValueError,OSError)):admission.assert_current_recipe(fixture)
 def _minimal(self,fixture):
  values=task.parent_inputs(set(assessment.SOURCE_INPUTS)|set(assessment.ADAPTER_SOURCE_INPUTS))
  for name,raw in values.items():
   target=fixture/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(raw);target.chmod(0o600)
  complete_source_cohort(fixture,task.checkpoint())
 def test_minimal_fixture_preserves_original_strict_reader_and_corruption_refusal(self):
  with tempfile.TemporaryDirectory(dir=canonical_temp_base())as directory:
   fixture=Path(directory);self._minimal(fixture)
   with patch.object(source,'open_source_operation',side_effect=AssertionError('unexpected minimal fixture operation')):
    with self.assertRaises(ValueError):assessment.SQL_obligations(fixture)
    projected=legacy_DATA_labels(fixture)
    self.assertEqual(len(projected['labels']),8);self.assertIs(projected['public_default_fixture_executed'],False);self.assertIs(projected['runtime_authority'],False)
    target=fixture/'support/governance/recovery/assessment-credential-cases.php';target.write_bytes(target.read_bytes()+b'\n# unknown')
    error=None
    try:legacy_DATA_labels(fixture)
    except ValueError as caught:error=caught
    self.assertIsNotNone(error)
    # Prove the actual current validator rejected actual mutated E bytes,
    # rather than a broad source/parent-row preflight equality guard.
    names=[];trace=error.__traceback__
    while trace is not None:
     if trace.tb_frame.f_globals.get('__name__','').startswith('unadmitted_legacy_DATA_'):names.append(trace.tb_frame.f_code.co_name)
     trace=trace.tb_next
    self.assertTrue(set(names)&{'SQL_obligations','assert_cohort','source_view','reduce_if_selected'})
if __name__=='__main__':unittest.main()
