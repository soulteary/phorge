"""Real Task S + unadmitted E protocol tests; Root-only genuine execution."""
from pathlib import Path
import ast,hashlib,os,subprocess,tempfile,unittest
import assessment_legacy_DATA_model_v3 as model
import test_source_snapshot_assessment_task_v2 as assessment_tests
from assessment_legacy_DATA_model_v3 import legacy_DATA_labels
class LegacyDATATwoRootProtocolV3(unittest.TestCase):
 def fixture(self,root):assessment_tests.AssessmentTaskTopLevelV2('test_minimal_fixture_preserves_original_strict_reader_and_corruption_refusal')._minimal(root)
 def test_new_protocol_positive_flags_and_original19(self):
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve();self.fixture(root);data=legacy_DATA_labels(root)
   self.assertEqual(data['schema'],'Assessment-current-code-legacy-DATA-labels-v3');self.assertEqual(len(data['labels']),8);self.assertEqual(data['validated_project_production_paths'],19)
   for field in('public_default_fixture_executed','old_public_default_positive_proved','E_is_producer_or_current_source','runtime_authority','SQL_qualified','current_source_authority_for_E'):self.assertIs(data[field],False)
   self.assertIs(data['S_source_operation_exit_verified'],True);self.assertEqual(data['DATA_validation_terminal'],'returned-after-S-exit')
 def test_case_missing_symlink_mode_and_actual_corruption_refuse(self):
  for kind in('missing','symlink','writable','corrupt'):
   with self.subTest(kind=kind),tempfile.TemporaryDirectory()as tmp:
    root=Path(tmp).resolve();self.fixture(root);path=root/'support/governance/recovery/assessment-credential-cases.php';foreign=root/'foreign'
    if kind=='missing':path.unlink()
    elif kind=='symlink':foreign.write_bytes(path.read_bytes());path.unlink();path.symlink_to(foreign)
    elif kind=='writable':path.chmod(0o666)
    else:path.write_bytes(path.read_bytes()+b'\nunknown')
    with self.assertRaises((ValueError,OSError)):legacy_DATA_labels(root)
 def test_unknown_project_metadata_and_mixed_cohort_refuse(self):
  for name in('support/governance/project-description-ci/metadata-lock.json','src/__phutil_library_map__.php'):
   with self.subTest(name=name),tempfile.TemporaryDirectory()as tmp:
    root=Path(tmp).resolve();self.fixture(root);path=root/name;path.write_bytes(path.read_bytes()+b'\nunknown')
    with self.assertRaises((ValueError,OSError)):legacy_DATA_labels(root)
 def test_E_root_alias_and_nonprivate_directory_refuse(self):
  with tempfile.TemporaryDirectory()as tmp:
   base=Path(tmp).resolve();root=base/'actual';root.mkdir(mode=0o700);self.fixture(root);alias=base/'alias';alias.symlink_to(root,target_is_directory=True)
   with self.assertRaises(ValueError):legacy_DATA_labels(alias)
   root.chmod(0o755)
   with self.assertRaises(ValueError):legacy_DATA_labels(root)
class ExactCurrentFunctionClosure(unittest.TestCase):
 def test_only_one_reviewed_current_helper_extends_the_closed_model(self):
  # Immutable predecessor syntax is DATA, never executable source.
  prior=subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(model.ROOT),'show','7025c4d29e61d80a58c158be46b3c8fbf684f71d:tests/governance/assessment_legacy_DATA_model_v3.py'],timeout=10)
  self.assertEqual(hashlib.sha256(prior).hexdigest(),'4b2d7280aea79cff6cb1910db0c6822b57aa55fa0fa550392960eacd3b495272')
  tree=ast.parse(prior);expected=next(ast.literal_eval(n.value)for n in tree.body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='EXPECTED_FUNCTIONS'for t in n.targets))
  actual=dict(model.EXPECTED_FUNCTIONS);old=dict(expected)
  self.assertEqual(set(actual),set(old))
  self.assertEqual(set(actual['project_description_admission_delta'])-set(old['project_description_admission_delta']),{'native_pair_parent_DATA'})
  self.assertEqual(tuple((name,tuple(symbol for symbol in symbols if not(name=='project_description_admission_delta'and symbol=='native_pair_parent_DATA')))for name,symbols in model.EXPECTED_FUNCTIONS),expected)

if __name__=='__main__':unittest.main()
