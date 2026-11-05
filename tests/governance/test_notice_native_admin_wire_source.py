"""Host source contracts only; no claim of native SQL or physical delivery."""
from pathlib import Path
import unittest,subprocess,hashlib
ROOT=Path(__file__).resolve().parents[2]
ADDITIONS={
 'PhorgeRecoveryApplicationConfigFacts':('infrastructure/governance/recovery/PhorgeRecoveryApplicationConfigFacts.php','Phobject'),
 'PhorgeRecoveryApplicationConfigFactsTestCase':('infrastructure/governance/recovery/__tests__/PhorgeRecoveryApplicationConfigFactsTestCase.php','PhutilTestCase'),
 'PhorgeRecoveryOwnedRRScope':('infrastructure/governance/recovery/PhorgeRecoveryOwnedRRScope.php','Phobject'),
 'PhorgeRecoveryOwnedRRScopeTestCase':('infrastructure/governance/recovery/__tests__/PhorgeRecoveryOwnedRRScopeTestCase.php','PhutilTestCase'),
 'PhorgeRecoveryProjectSourceFacts':('infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php','Phobject'),
 'PhorgeRecoveryProjectSourceFactsTestCase':('infrastructure/governance/recovery/__tests__/PhorgeRecoveryProjectSourceFactsTestCase.php','PhutilTestCase')}
EXTRA='src/infrastructure/governance/recovery/PhorgeGovernanceRecoveryConfigOptions.php'
RR='5594bac28722ba4cde67e8e326c67e4d38d98d27'
# Exact source-audit pins for this new candidate; not a signature or runtime proof.
PROJECT_SOURCE_PINS={'src/infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php': '4380464b53f8b51c488dbf0bc29b9b1fc4e4aac302a4883ffb0ce3f5e1796eb9', 'src/infrastructure/governance/recovery/__tests__/PhorgeRecoveryProjectSourceFactsTestCase.php': '92ef366fa93d2f11b4bafe1210ec44ac353f24712ebc15aa198597f4bc9fd5a5'}
def reviewed_sources():
 result={}
 for name,(path,parent)in ADDITIONS.items():
  if name.startswith('PhorgeRecoveryProjectSourceFacts'):
   raw=(ROOT/'src'/path).read_bytes()
   if hashlib.sha256(raw).hexdigest()!=PROJECT_SOURCE_PINS['src/'+path]:raise ValueError('Project source audit pin changed')
   result['src/'+path]=raw;continue
  rev=RR if name.startswith('PhorgeRecoveryOwnedRRScope') else 'ac5615b1d4f19ee50df39aa4d81fa07dc0231527'
  result['src/'+path]=subprocess.check_output(['git','-C',str(ROOT),'show',rev+':src/'+path])
 result[EXTRA]=subprocess.check_output(['git','-C',str(ROOT),'show',RR+':'+EXTRA])
 return result
def validate_notice_product_delta(changed,current,frozen,baseline_map):
 allowed={'src/__phutil_library_map__.php',EXTRA}|{'src/'+path for path,parent in ADDITIONS.values()}
 if set(changed)!=allowed or set(current)!=allowed:raise ValueError('unreviewed notice product delta')
 for path in allowed-{'src/__phutil_library_map__.php'}:
  if current[path]!=frozen[path]:raise ValueError('reviewed config/RR source bytes changed')
 reduced=current['src/__phutil_library_map__.php']
 project_lines=[]
 for name,(path,parent)in ADDITIONS.items():
  for value in (path,parent):
   line=("    '"+name+"' => '"+value+"',\n").encode()
   if name.startswith('PhorgeRecoveryProjectSourceFacts'):project_lines.append(line);continue
   if reduced.count(line)!=1:raise ValueError('reviewed config/RR map delta changed')
   reduced=reduced.replace(line,b'',1)
 # Before the cold mapper all four new lines are absent. Only an all-four
 # additive result is accepted afterwards; partial/extra/drift is refused.
 counts=[reduced.count(line)for line in project_lines]
 if counts not in ([0]*4,[1]*4):raise ValueError('partial Project map delta')
 for line in project_lines:
  if reduced.count(line):reduced=reduced.replace(line,b'',1)
 if reduced!=baseline_map:raise ValueError('old product map bytes changed')
 return True

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
  # The independently reviewed config/RR and Project components add exactly six classes,
  # the exact default-off RR option file, and their actual mapper entries. Preserve every pre-existing notice/product
  # byte; never turn this historical fixture contract into a blanket new base.
  baseline='e5f89aac566b121f2ebb3894aa4ba76b31222c10'
  config='ac5615b1d4f19ee50df39aa4d81fa07dc0231527'
  allowed={'src/__phutil_library_map__.php',EXTRA}|{'src/'+path for path,parent in ADDITIONS.values()}
  changed=set(subprocess.check_output(['git','-C',str(ROOT),'diff','--name-only',baseline,'--','src','resources'],text=True).splitlines())
  current={path:(ROOT/path).read_bytes()for path in allowed}
  frozen=reviewed_sources()
  old_map=subprocess.check_output(['git','-C',str(ROOT),'show',baseline+':src/__phutil_library_map__.php'])
  self.assertTrue(validate_notice_product_delta(changed,current,frozen,old_map))
 def test_actual_product_validator_rejects_unreviewed_and_corrupt_deltas(self):
  baseline='e5f89aac566b121f2ebb3894aa4ba76b31222c10';config='ac5615b1d4f19ee50df39aa4d81fa07dc0231527'
  allowed={'src/__phutil_library_map__.php',EXTRA}|{'src/'+path for path,parent in ADDITIONS.values()}
  current={path:(ROOT/path).read_bytes()for path in allowed}
  frozen=reviewed_sources()
  old_map=subprocess.check_output(['git','-C',str(ROOT),'show',baseline+':src/__phutil_library_map__.php'])
  self.assertTrue(validate_notice_product_delta(allowed,current,frozen,old_map))
  for extra in ('src/applications/people/storage/PhabricatorUser.php','resources/sql/quickstart.sql','src/ExtraClass.php'):
   with self.assertRaises(ValueError):validate_notice_product_delta(allowed|{extra},current,frozen,old_map)
  for path in allowed:
   bad=dict(current);bad[path]+=b'UNREVIEWED_CHANGE'
   with self.assertRaises(ValueError):validate_notice_product_delta(allowed,bad,frozen,old_map)
  project_map_lines=[("    '"+name+"' => '"+value+"',\n").encode()for name,pair in ADDITIONS.items()if name.startswith('PhorgeRecoveryProjectSourceFacts')for value in pair]
  unregistered=dict(current)
  for line in project_map_lines:unregistered['src/__phutil_library_map__.php']=unregistered['src/__phutil_library_map__.php'].replace(line,b'')
  self.assertTrue(validate_notice_product_delta(allowed,unregistered,frozen,old_map))
  registered=dict(unregistered);registered['src/__phutil_library_map__.php']+=b''.join(project_map_lines)
  self.assertTrue(validate_notice_product_delta(allowed,registered,frozen,old_map))
  partial=dict(unregistered);partial['src/__phutil_library_map__.php']+=project_map_lines[0]
  with self.assertRaises(ValueError):validate_notice_product_delta(allowed,partial,frozen,old_map)
  bad=dict(current);bad['src/__phutil_library_map__.php']+=b"    'ExtraClass' => 'extra.php',\n"
  with self.assertRaises(ValueError):validate_notice_product_delta(allowed,bad,frozen,old_map)
if __name__=='__main__':unittest.main()
