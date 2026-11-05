"""Host source contracts only; no claim of native SQL or physical delivery."""
from pathlib import Path
import unittest,subprocess
ROOT=Path(__file__).resolve().parents[2]
class NativeAdminWire(unittest.TestCase):
 def test_actual_native_source_not_handcrafted_notice_or_mail(self):
  text=(ROOT/'support/governance/notification/notice-admin-wire-hold.php').read_text()
  self.assertIn('new PhabricatorUserTransactionEditor()',text)
  self.assertIn("->setContentSourceFromRequest($operator_auth['request'])",text)
  self.assertIn('PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE',text)
  self.assertIn("$xaction['authorPHID']!==$operator->getPHID()",text)
  self.assertIn('nativeAdminGrantAudit($xaction)',text)
  self.assertIn("'native_transaction_phid'=>$xaction['phid']",text)
  for forbidden in ('PhorgeGovernanceNoticeOutbox::','setPrivateMailProducer(','new PhabricatorMetaMTAMail','->setIsAdmin(1)'):
   self.assertNotIn(forbidden,text)
 def test_native_noeffect_and_independent_primary_commit_observation(self):
  text=(ROOT/'support/governance/notification/notice-admin-wire-hold.php').read_text()
  for token in ('setContinueOnNoEffect(true)','@@autocommit ac',"$observer['thread']===(string)$primary['thread']",'e.sourceID=x.id',"$committed[0]['phid']===$phid",'count($destinations)!==1'):
   self.assertIn(token,text)
  self.assertIn("'notice-admin-wire-real-native-noeffect-keeps-one-USER-source-and-event'",text)
 def test_revoke_control_real_session_csrf_editor_and_no_prepare(self):
  text=(ROOT/'support/governance/notification/notice-admin-wire-control.php').read_text()
  for token in ('loadUserForSession(',"$request->validateCSRF()",'new PhabricatorUserTransactionEditor()',"->setNewValue(false)","$row['newValue']!=='false'",'@@autocommit ac',"$committed[0]['isAdmin']"):
   self.assertIn(token,text)
  self.assertIn("array('allow','admin-revoked')",text)
  self.assertNotIn('PhorgePrivateMailPolicy::authorize',text)
  self.assertNotIn('prepareForGorgeDelivery(',text)
  self.assertNotIn('setDeliverySnapshot',text)
 def test_separate_fixture_entry_and_frozen_product(self):
  text=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text()
  self.assertIn("'--notice-admin-grant-wire-hold'=>'notice-admin-grant-wire-hold'",text)
  self.assertIn('governance_notice_admin_wire_hold($fixture,$check)',text)
  self.assertIn('governance_notice_wire_hold($fixture,$check)',text)
  self.assertEqual('',subprocess.check_output(['git','-C',str(ROOT),'diff','e5f89aac566b121f2ebb3894aa4ba76b31222c10','--','src','resources'],text=True))
if __name__=='__main__':unittest.main()
