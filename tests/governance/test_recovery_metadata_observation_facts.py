"""Execute actual PHP observation record expression; not a grant-lock test."""
from pathlib import Path
import json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
class Facts(unittest.TestCase):
 def test_actual_emitted_record_never_claims_grant_row_or_revoke_fence(self):
  text=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryOrganizationMetadataObservation.php').read_text()
  start=text.index("    return array('schema_version'=>2");end=text.index(');',start)+2
  code="$actual=['uuid'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'];$description=['source_digest'=>str_repeat('b',64)];$v=(static function()use($actual,$description){"+text[start:end]+"})();echo json_encode($v);"
  p=subprocess.run(['php','-r',code],capture_output=True,text=True,check=True);self.assertEqual('',p.stderr);facts=json.loads(p.stdout)
  self.assertEqual(2,facts['schema_version']);self.assertIs(facts['global_visibility_grant_row_observed'],True)
  for key in ('global_visibility_grant_row_locked','grant_revoke_absence_fence_proved','concurrent_ddl_absence_fence_proved','all_producers_and_absence_fences_proved','restoration_authorized'):self.assertIs(facts[key],False)
 def test_SQL_fixture_demands_false_lock_and_complete_gate_stays_blocked(self):
  text=(ROOT/'support/governance/recovery/organization-live-metadata-cases.php').read_text()
  self.assertIn("$before['global_visibility_grant_row_locked']===false",text);self.assertIn("$before['grant_revoke_absence_fence_proved']===false",text)
  service=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php').read_text()
  self.assertIn("throw new RuntimeException('recovery_restore_sources_incomplete')",service)
if __name__=='__main__':unittest.main()
