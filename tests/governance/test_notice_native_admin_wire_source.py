"""Host source contracts only; no claim of native SQL or physical delivery."""
from pathlib import Path
import unittest,subprocess,hashlib
ROOT=Path(__file__).resolve().parents[2]
ADDITIONS={
 'PhorgeOrganizationApprovalController':('applications/people/governance/controller/PhorgeOrganizationApprovalController.php','PhabricatorPeopleController'),
 'PhorgeRecoveryFileSourceFacts':('infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php','Phobject'),
 'PhorgeRecoveryFileSourceFactsTestCase':('infrastructure/governance/recovery/__tests__/PhorgeRecoveryFileSourceFactsTestCase.php','PhutilTestCase'),
 'PhorgeRecoveryTaskOwnerSource':('infrastructure/governance/recovery/PhorgeRecoveryTaskOwnerSource.php','Phobject'),
 'PhorgeRecoveryTaskOwnerSourceTestCase':('infrastructure/governance/recovery/__tests__/PhorgeRecoveryTaskOwnerSourceTestCase.php','PhutilTestCase'),
 'PhorgeRecoverySpacesSourceFacts':('infrastructure/governance/recovery/PhorgeRecoverySpacesSourceFacts.php','Phobject'),
 'PhorgeRecoverySpacesSourceFactsTestCase':('infrastructure/governance/recovery/__tests__/PhorgeRecoverySpacesSourceFactsTestCase.php','PhutilTestCase'),
 'PhorgeRecoveryApplicationConfigFacts':('infrastructure/governance/recovery/PhorgeRecoveryApplicationConfigFacts.php','Phobject'),
 'PhorgeRecoveryApplicationConfigFactsTestCase':('infrastructure/governance/recovery/__tests__/PhorgeRecoveryApplicationConfigFactsTestCase.php','PhutilTestCase'),
 'PhorgeRecoveryOwnedRRScope':('infrastructure/governance/recovery/PhorgeRecoveryOwnedRRScope.php','Phobject'),
 'PhorgeRecoveryOwnedRRScopeTestCase':('infrastructure/governance/recovery/__tests__/PhorgeRecoveryOwnedRRScopeTestCase.php','PhutilTestCase'),
 'PhorgeRecoveryProjectSourceFacts':('infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php','Phobject'),
 'PhorgeRecoveryProjectSourceFactsTestCase':('infrastructure/governance/recovery/__tests__/PhorgeRecoveryProjectSourceFactsTestCase.php','PhutilTestCase')}
EXTRA='src/infrastructure/governance/recovery/PhorgeGovernanceRecoveryConfigOptions.php'
RR='5594bac28722ba4cde67e8e326c67e4d38d98d27'
# Exact source-audit pins for this new candidate; not a signature or runtime proof.
PROJECT_SOURCE_PINS={'src/infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php': 'f9ba22cbe2745993631bdc503c5426b8f237672a3ebd527c96e6efa0484beb2d', 'src/infrastructure/governance/recovery/__tests__/PhorgeRecoveryProjectSourceFactsTestCase.php': '8cd4c9e6049f1bea2e45173149ffbd5c27044188e247df4b01c85d20489d85f1'}
SPACES_SOURCE_PINS={'src/infrastructure/governance/recovery/PhorgeRecoverySpacesSourceFacts.php': 'aeb2c53063335ff9e5e6748fbe59823f6238708be831966822872f48f04e2bbc', 'src/infrastructure/governance/recovery/__tests__/PhorgeRecoverySpacesSourceFactsTestCase.php': '7076c406175cb6530cfee32077c32ac2fb77f7d48e4c9e5a1ceed8d2910a5fef'}
TASKOWNER_SOURCE_PINS={'src/infrastructure/governance/recovery/PhorgeRecoveryTaskOwnerSource.php': 'c74504d580aeed96008565a4660d296366171a77914ab6d3469f9333402ee03c', 'src/infrastructure/governance/recovery/__tests__/PhorgeRecoveryTaskOwnerSourceTestCase.php': 'f0b6d931ee30fca467ebf44b71a8f953d680be7011a48cdece4c2cdaaff0f4a2'}
FILE_SOURCE_PINS={'src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php': 'e2ae71b9236bfcf9550ef4c4098ed3b97384c2a62f7c0e818a04a416388af6af', 'src/infrastructure/governance/recovery/__tests__/PhorgeRecoveryFileSourceFactsTestCase.php': '196112b4c2b06f5c1950dc7344ada02843aadb8d7ffe71e87f561912c5755e27'}
WORKBENCH_CANDIDATE_SOURCE_PINS={'src/applications/people/application/PhabricatorPeopleApplication.php': '0af775d044044127d01d79846385caad016a008d47a0d7c4d923b0ba13c7e0d8', 'src/applications/people/governance/approval/PhorgeGovernanceNativeApprovalAdapter.php': 'e47ec9eb30c91dcad6be9f6e9466190d9040681392037c0811cd1f41bf2a8a85', 'src/applications/people/governance/controller/PhorgeOrganizationApprovalController.php': 'c8f3169b71b23fb53bfba5925b4ceca06eb0b5c8a51ad53e7929b4bbe3dc66dd', 'src/applications/people/governance/controller/PhorgeOrganizationController.php': 'c5666b6a72492844e9d22835de0fff191f60463ceb88cad99e8c13406af22604', 'src/applications/people/governance/service/PhorgeOrganizationService.php': '7f2a7e30de9f8229810d3c5894d48439f32afdab9df82c9ddbcb7f9d66dcb18f', 'src/applications/people/governance/service/README.approval-workbench.md': 'd2c3d26fac440c6cf30fa1051e281a93994e2ff63990db42e4d08189f6ae6d45'}

def reviewed_sources():
 result={}
 for name,(path,parent)in ADDITIONS.items():
  if name=='PhorgeOrganizationApprovalController':continue
  if name.startswith('PhorgeRecoveryFileSourceFacts'):
   raw=(ROOT/'src'/path).read_bytes()
   if hashlib.sha256(raw).hexdigest()!=FILE_SOURCE_PINS['src/'+path]:raise ValueError('File source audit pin changed')
   result['src/'+path]=raw;continue
  if name.startswith('PhorgeRecoveryTaskOwnerSource'):
   raw=(ROOT/'src'/path).read_bytes()
   if hashlib.sha256(raw).hexdigest()!=TASKOWNER_SOURCE_PINS['src/'+path]:raise ValueError('Task owner source audit pin changed')
   result['src/'+path]=raw;continue
  if name.startswith('PhorgeRecoverySpacesSourceFacts'):
   raw=(ROOT/'src'/path).read_bytes()
   if hashlib.sha256(raw).hexdigest()!=SPACES_SOURCE_PINS['src/'+path]:raise ValueError('Space source audit pin changed')
   result['src/'+path]=raw;continue
  if name.startswith('PhorgeRecoveryProjectSourceFacts'):
   raw=(ROOT/'src'/path).read_bytes()
   if hashlib.sha256(raw).hexdigest()!=PROJECT_SOURCE_PINS['src/'+path]:raise ValueError('Project source audit pin changed')
   result['src/'+path]=raw;continue
  rev=RR if name.startswith('PhorgeRecoveryOwnedRRScope') else 'ac5615b1d4f19ee50df39aa4d81fa07dc0231527'
  result['src/'+path]=subprocess.check_output(['git','-C',str(ROOT),'show',rev+':src/'+path])
 result[EXTRA]=subprocess.check_output(['git','-C',str(ROOT),'show',RR+':'+EXTRA])
 for path,pin in WORKBENCH_CANDIDATE_SOURCE_PINS.items():
  raw=(ROOT/path).read_bytes()
  if hashlib.sha256(raw).hexdigest()!=pin:raise ValueError('Workbench candidate source pin changed')
  result[path]=raw
 return result
def validate_notice_product_delta(changed,current,frozen,baseline_map):
 allowed={'src/__phutil_library_map__.php',EXTRA}|{'src/'+path for path,parent in ADDITIONS.values()}|set(WORKBENCH_CANDIDATE_SOURCE_PINS)
 if set(changed)!=allowed or set(current)!=allowed:raise ValueError('unreviewed notice product delta')
 for path in allowed-{'src/__phutil_library_map__.php'}:
  if current[path]!=frozen[path]:raise ValueError('reviewed config/RR source bytes changed')
 reduced=current['src/__phutil_library_map__.php']
 project_lines=[];spaces_lines=[];task_lines=[];file_lines=[];workbench_lines=[]
 for name,(path,parent)in ADDITIONS.items():
  for value in (path,parent):
   line=("    '"+name+"' => '"+value+"',\n").encode()
   if name=='PhorgeOrganizationApprovalController':workbench_lines.append(line);continue
   if name.startswith('PhorgeRecoveryFileSourceFacts'):file_lines.append(line);continue
   if name.startswith('PhorgeRecoveryTaskOwnerSource'):task_lines.append(line);continue
   if name.startswith('PhorgeRecoveryProjectSourceFacts'):project_lines.append(line);continue
   if name.startswith('PhorgeRecoverySpacesSourceFacts'):spaces_lines.append(line);continue
   if reduced.count(line)!=1:raise ValueError('reviewed config/RR map delta changed')
   reduced=reduced.replace(line,b'',1)
 # Before the cold mapper all four new lines are absent. Only an all-four
 # additive result is accepted afterwards; partial/extra/drift is refused.
 counts=[reduced.count(line)for line in project_lines]
 if counts not in ([0]*4,[1]*4):raise ValueError('partial Project map delta')
 for line in project_lines:
  if reduced.count(line):reduced=reduced.replace(line,b'',1)
 counts=[reduced.count(line)for line in spaces_lines]
 if counts not in ([0]*4,[1]*4):raise ValueError('partial Spaces map delta')
 for line in spaces_lines:
  if reduced.count(line):reduced=reduced.replace(line,b'',1)
 counts=[reduced.count(line)for line in task_lines]
 if counts not in ([0]*4,[1]*4):raise ValueError('partial Task owner map delta')
 for line in task_lines:
  if reduced.count(line):reduced=reduced.replace(line,b'',1)
 counts=[reduced.count(line)for line in file_lines]
 if counts not in ([0]*4,[1]*4):raise ValueError('partial File map delta')
 for line in file_lines:
  if reduced.count(line):reduced=reduced.replace(line,b'',1)
 counts=[reduced.count(line)for line in workbench_lines]
 if counts not in ([0]*2,[1]*2):raise ValueError('partial Workbench map delta')
 for line in workbench_lines:
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
  # The independently reviewed config/RR, Project, Spaces and Task owner components add exactly twelve classes,
  # the exact default-off RR option file, and their actual mapper entries. Preserve every pre-existing notice/product
  # byte; never turn this historical fixture contract into a blanket new base.
  baseline='e5f89aac566b121f2ebb3894aa4ba76b31222c10'
  config='ac5615b1d4f19ee50df39aa4d81fa07dc0231527'
  allowed={'src/__phutil_library_map__.php',EXTRA}|{'src/'+path for path,parent in ADDITIONS.values()}|set(WORKBENCH_CANDIDATE_SOURCE_PINS)
  changed=set(subprocess.check_output(['git','-C',str(ROOT),'diff','--name-only',baseline,'--','src','resources'],text=True).splitlines())
  current={path:(ROOT/path).read_bytes()for path in allowed}
  frozen=reviewed_sources()
  old_map=subprocess.check_output(['git','-C',str(ROOT),'show',baseline+':src/__phutil_library_map__.php'])
  self.assertTrue(validate_notice_product_delta(changed,current,frozen,old_map))
 def test_exact_workbench_document_pin_rejects_corruption_and_other_docs(self):
  doc='src/applications/people/governance/service/README.approval-workbench.md'
  # Use the existing fixed baseline and actual validator; documentation is an
  # exact reviewed source addition, never a suffix or directory exclusion.
  baseline='e5f89aac566b121f2ebb3894aa4ba76b31222c10'
  allowed={'src/__phutil_library_map__.php',EXTRA}|{'src/'+path for path,parent in ADDITIONS.values()}|set(WORKBENCH_CANDIDATE_SOURCE_PINS)
  current={path:(ROOT/path).read_bytes()for path in allowed};frozen=reviewed_sources()
  old_map=subprocess.check_output(['git','-C',str(ROOT),'show',baseline+':src/__phutil_library_map__.php'])
  self.assertEqual(WORKBENCH_CANDIDATE_SOURCE_PINS[doc],hashlib.sha256(current[doc]).hexdigest())
  self.assertTrue(validate_notice_product_delta(allowed,current,frozen,old_map))
  bad=dict(current);bad[doc]+=b'corrupt'
  with self.assertRaises(ValueError):validate_notice_product_delta(allowed,bad,frozen,old_map)
  foreign='src/applications/people/governance/service/OTHER-README.md';bad=dict(current);bad[foreign]=b'unknown'
  with self.assertRaises(ValueError):validate_notice_product_delta(allowed|{foreign},bad,frozen,old_map)
 def test_actual_product_validator_rejects_unreviewed_and_corrupt_deltas(self):
  baseline='e5f89aac566b121f2ebb3894aa4ba76b31222c10';config='ac5615b1d4f19ee50df39aa4d81fa07dc0231527'
  allowed={'src/__phutil_library_map__.php',EXTRA}|{'src/'+path for path,parent in ADDITIONS.values()}|set(WORKBENCH_CANDIDATE_SOURCE_PINS)
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
  file_lines=[("    '"+name+"' => '"+value+"',\n").encode()for name,pair in ADDITIONS.items()if name.startswith('PhorgeRecoveryFileSourceFacts')for value in pair]
  no_file=dict(current)
  for line in file_lines:no_file['src/__phutil_library_map__.php']=no_file['src/__phutil_library_map__.php'].replace(line,b'')
  self.assertTrue(validate_notice_product_delta(allowed,no_file,frozen,old_map))
  for count in (1,2,3):
   bad_file=dict(no_file);bad_file['src/__phutil_library_map__.php']+=b''.join(file_lines[:count])
   with self.assertRaises(ValueError):validate_notice_product_delta(allowed,bad_file,frozen,old_map)
  full_file=dict(no_file);full_file['src/__phutil_library_map__.php']+=b''.join(file_lines);self.assertTrue(validate_notice_product_delta(allowed,full_file,frozen,old_map))
  bad=dict(current);bad['src/__phutil_library_map__.php']+=b"    'ExtraClass' => 'extra.php',\n"
  with self.assertRaises(ValueError):validate_notice_product_delta(allowed,bad,frozen,old_map)
if __name__=='__main__':unittest.main()
