"""Host source-bound contracts; these do not stand in for native SQL execution."""
from pathlib import Path
import re,unittest
ROOT=Path(__file__).resolve().parents[2]
class AdminGrantSource(unittest.TestCase):
 def test_native_persistence_phase_is_before_original_commit(self):
  s=(ROOT/'src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php').read_text()
  pos=s.index('$xactions = $this->applyFinalEffects($object, $xactions);')
  self.assertGreater(pos,s.index('$xaction->save();',s.index('final public function applyTransactions')))
  self.assertLess(pos,s.index('$object->saveTransaction();',pos))
  pre=s.index('$xactions = $this->didApplyInternalEffects($object, $xactions);')
  self.assertLess(pre,s.index('$object->save();',pre))
 def test_grant_only_context_always_closes(self):
  s=(ROOT/'src/applications/people/editor/PhabricatorUserTransactionEditor.php').read_text()
  self.assertIn('$xaction->getOldValue() === false && $xaction->getNewValue() === true',s)
  self.assertIn('finally{$this->noticeAdminGrantContext=null;}',s)
  self.assertNotIn('static $noticeAdminGrantContext',s)
 def test_producer_borrows_user_connection_and_real_native_source(self):
  s=(ROOT/'src/infrastructure/governance/notification/PhorgeGovernanceNoticeOutbox.php').read_text()
  part=s.split('public static function nativeAdminGrant(',1)[1].split('public static function recoveryStoppedAudit',1)[0]
  self.assertIn('$editor->assertNoticeAdminGrantActive($object,$xaction)',part)
  self.assertIn("self::append($conn,'user',PhorgeGovernanceNoticeSource::nativeAdminGrantAudit($row))",part)
  self.assertNotIn('saveTransaction(',part);self.assertNotIn('->save(',part)
 def test_focused_fixture_keeps_real_native_and_failure_controls(self):
  s=(ROOT/'support/governance/notification/notice-admin-grant-cases.php').read_text()
  ids=re.findall(r"'(notice-admin-grant-[A-Za-z0-9-]+)'\);",s)
  self.assertEqual(len(ids),17);self.assertEqual(len(set(ids)),17)
  self.assertIn("$error->getCode()!==1644",s)
  self.assertIn('owned-native-admin-grant-fault',s)
  self.assertIn("'SMTP_proved'=>false",s)
  self.assertNotIn('setPrivateMailProducer(',s)
if __name__=='__main__':unittest.main()
