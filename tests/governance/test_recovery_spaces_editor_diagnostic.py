import json, pathlib, subprocess, unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
class SpacesEditorDiagnostic(unittest.TestCase):
 def test_actual_native_validation_projection_and_primary_preservation(self):
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/spaces-editor-diagnostic-controls.php'),str(ROOT)],capture_output=True,text=True,timeout=20)
  self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');self.assertEqual(json.loads(p.stdout),{'controls':7,'passed':True})
 def test_failure_hook_is_profile_specific_and_assertions_unchanged(self):
  driver=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text()
  self.assertIn("if($phase==='spaces-source-facts'&&isset($GLOBALS['governance_spaces_editor_failure']))",driver)
  helper=(ROOT/'support/governance/recovery/spaces-editor-diagnostic.php').read_text()
  for banned in ['getMessage(', 'getShortMessage(', 'getNewValue(', 'getPHID(']:self.assertNotIn(banned,helper)
