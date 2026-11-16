"""Real Task S + unadmitted E protocol tests; Root-only genuine execution."""
from pathlib import Path
import os,tempfile,unittest
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
if __name__=='__main__':unittest.main()
