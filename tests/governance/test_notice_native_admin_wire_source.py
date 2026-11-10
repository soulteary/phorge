"""Host source contracts only; no claim of native SQL or physical delivery."""
from pathlib import Path
import unittest,subprocess,hashlib,sys
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_private_calendar_reminders as calendar_delta
import recovery_conduit_source_delta as conduit_delta
import recovery_oauth_code_source_delta as code_delta
import recovery_oauth_authorization_source_delta as authorization_delta
import recovery_oauth_client_lifecycle_delta as lifecycle_delta
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
FILE_SOURCE_PINS={'src/infrastructure/governance/recovery/PhorgeRecoveryFileSourceFacts.php': '751adef2e7a90220fceac58cb2894edf442fa01d3e5fbdb7b600f561f749c723', 'src/infrastructure/governance/recovery/__tests__/PhorgeRecoveryFileSourceFactsTestCase.php': '8515ee72bdd25b911bc786c698728088e17752701551bb33aefcb21febc321b7'}
WORKBENCH_CANDIDATE_SOURCE_PINS={'src/applications/people/application/PhabricatorPeopleApplication.php': '0af775d044044127d01d79846385caad016a008d47a0d7c4d923b0ba13c7e0d8', 'src/applications/people/governance/approval/PhorgeGovernanceNativeApprovalAdapter.php': 'e47ec9eb30c91dcad6be9f6e9466190d9040681392037c0811cd1f41bf2a8a85', 'src/applications/people/governance/controller/PhorgeOrganizationApprovalController.php': '75e6431cbd5d86eaf85aab90545fb52eba41439e066ef3c5ce74f62be2515d9d', 'src/applications/people/governance/controller/PhorgeOrganizationController.php': 'c5666b6a72492844e9d22835de0fff191f60463ceb88cad99e8c13406af22604', 'src/applications/people/governance/service/PhorgeOrganizationService.php': '7f2a7e30de9f8229810d3c5894d48439f32afdab9df82c9ddbcb7f9d66dcb18f', 'src/applications/people/governance/service/README.approval-workbench.md': '3f015fb4300c7bb2deec5c5e858298d2497674e2058867295655fdda98148e09', 'src/infrastructure/internationalization/translation/PhabricatorChineseTranslation.php': '63655fedfcae251c561efac738d70b7617def93f9a72889449b02ce3050a4213'}

# Versioned independently reviewed N08 delta; exact five paths and bytes only.
PARTIAL_SOURCE_REVIEW_VERSION='n08-partial-source-review-v1'
PARTIAL_SOURCE_REVIEW_COMMIT='1686fc4ca165f5cdb12e88ab0fe5b245d615e91d'
PARTIAL_SOURCE_PINS={'src/infrastructure/governance/recovery/PhorgeRecoveryApplicationConfigFacts.php': '4a1a500648b69d714e49aad0a0b1fd44d463d8826b873881033d227cd5300a0b', 'src/infrastructure/governance/recovery/PhorgeRecoveryProjectSourceFacts.php': 'f3b0065ac240e32af80ce7f48096ef68791d8dcdb02dbba46f64b330e130fe6c', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php': 'dcf6226ee57ed303273a231e4c81f2505751e60a0e5a4c3701d2d703d7d45403', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreRequest.php': '876eafed1c36c11006242587906419561b1688657f6494d90a2a25a0e29489cf', 'src/infrastructure/governance/recovery/PhorgeRecoverySpacesSourceFacts.php': 'e905bf7f8a335440c7313575e28566d26aa83078e141bf3abb024aba8d5b49a7'}

# Versioned isolated selected Session INSERT lease source delta; no SQL acceptance.
SESSION_LEASE_SOURCE_REVIEW_VERSION='n08-native-session-lease-scoped-grants-source-v2'
SESSION_LEASE_SOURCE_PINS={'src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php': 'bf52343c21dffb2eb7fe257e59f023d1ad3f15eb33dc933e3bd4ab14d32e00db', 'src/infrastructure/governance/recovery/PhorgeGovernanceRecoveryConfigOptions.php': 'e00c3042bd8bc82fc80c798246ecf4c46ebcf900fb661a77e48ded5d61e1fac8'}

# Explicit two-path candidate delta; not runtime/SQL certification.
BORROWED_SOURCE_VERSION='n08-borrowed-owner-source-candidate-v1'
BORROWED_SOURCE_PINS={'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php': 'baffc7626462c6c1af2e7765d1901d9b9342a9063a553410dc4f207d466aef9f', 'src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php': 'a3a9c2ff5ab45ad825d817fcc4caeb0b34711a33e8b1210357c37a6dbe8b86bd'}

CALENDAR_SOURCE_PATH='src/applications/calendar/notifications/PhabricatorCalendarNotificationEngine.php'
CALENDAR_SOURCE_PINS={CALENDAR_SOURCE_PATH:calendar_delta.SOURCE_INPUTS[CALENDAR_SOURCE_PATH]}
PRIVATE_MAIL_SETTINGS_PATH='src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php'
PRIVATE_MAIL_SETTINGS_PINS={PRIVATE_MAIL_SETTINGS_PATH:calendar_delta.SOURCE_INPUTS[PRIVATE_MAIL_SETTINGS_PATH]}

def reviewed_partial_sources(root=ROOT):
 result={}
 for path,pin in PARTIAL_SOURCE_PINS.items():
  try:raw=__import__('recovery_assessment_source_delta').reduce_current(path,(root/path).read_bytes())
  except ValueError as e:raise ValueError('N08 partial source audit pin changed')from e
  if hashlib.sha256(raw).hexdigest()!=pin:raise ValueError('N08 partial source audit pin changed')
  result[path]=raw
 return result

def reviewed_sources():
 result=reviewed_partial_sources()
 for name,(path,parent)in ADDITIONS.items():
  if 'src/'+path in PARTIAL_SOURCE_PINS:continue
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
  raw=code_delta.reduce_if_selected(path,(ROOT/path).read_bytes())
  if hashlib.sha256(raw).hexdigest()!=pin:raise ValueError('Workbench candidate source pin changed')
  result[path]=raw
 for path,pin in SESSION_LEASE_SOURCE_PINS.items():
  if path in BORROWED_SOURCE_PINS:continue
  raw=code_delta.reduce_if_selected(path,(ROOT/path).read_bytes())
  import recovery_worker_terminal_delta as worker_terminal
  compared=worker_terminal.reduce_if_selected(path,raw)
  compared=conduit_delta.reduce_owner(path,compared) if path in conduit_delta.BASE_PINS else compared
  if hashlib.sha256(compared).hexdigest()!=pin:raise ValueError('Session lease candidate source pin changed')
  result[path]=raw
 for path,pin in BORROWED_SOURCE_PINS.items():
  raw=code_delta.reduce_if_selected(path,(ROOT/path).read_bytes())
  if hashlib.sha256(conduit_delta.reduce_owner(path,raw)).hexdigest()!=pin:raise ValueError('Borrowed owner candidate source pin changed')
  result[path]=raw
 raw=(ROOT/CALENDAR_SOURCE_PATH).read_bytes()
 old=subprocess.check_output(['git','-C',str(ROOT),'show','59b623978b82964581ccc769221121a03938a2ff:'+CALENDAR_SOURCE_PATH])
 if calendar_delta.reduce_reviewed_calendar_engine(raw)!=old:raise ValueError('Calendar historical product inverse changed')
 result[CALENDAR_SOURCE_PATH]=raw
 raw=(ROOT/PRIVATE_MAIL_SETTINGS_PATH).read_bytes()
 old=subprocess.check_output(['git','-C',str(ROOT),'show','59b623978b82964581ccc769221121a03938a2ff:'+PRIVATE_MAIL_SETTINGS_PATH])
 if calendar_delta.reduce_reviewed_private_mail_settings(raw)!=old:raise ValueError('Private mail settings historical inverse changed')
 result[PRIVATE_MAIL_SETTINGS_PATH]=raw
 for path,pin in conduit_delta.SOURCE_INPUTS.items():
  raw=code_delta.reduce_if_selected(path,(ROOT/path).read_bytes())
  if hashlib.sha256(raw).hexdigest()!=pin:raise ValueError('Conduit source pin changed')
  if path in conduit_delta.BASE_PINS:conduit_delta.reduce_owner(path,raw)
  if path in conduit_delta.oauth_delta.SOURCE_INPUTS:conduit_delta.oauth_delta.reduce_current(path,raw)
  result[path]=raw
 for path in code_delta.SOURCE_INPUTS:
  if path not in result:result[path]=code_delta.reduce_if_selected(path,(ROOT/path).read_bytes())
 for path in authorization_delta.PRODUCTION_PATHS:
  if path not in result:result[path]=authorization_delta.reduce_current(path,(ROOT/path).read_bytes())
 for path in lifecycle_delta.PRODUCTION_PATHS:
  if path not in result:result[path]=lifecycle_delta.reduce_current(path,(ROOT/path).read_bytes())
 return result
def validate_notice_product_delta(changed,current,frozen,baseline_map):
 import project_description_admission_delta as n05_delta
 changed,current=n05_delta.historical_inputs(ROOT,'e5f89aac566b121f2ebb3894aa4ba76b31222c10',changed,current)
 allowed={'src/__phutil_library_map__.php',EXTRA}|{'src/'+path for path,parent in ADDITIONS.values()}|set(WORKBENCH_CANDIDATE_SOURCE_PINS)|set(PARTIAL_SOURCE_PINS)|set(SESSION_LEASE_SOURCE_PINS)|set(BORROWED_SOURCE_PINS)|set(CALENDAR_SOURCE_PINS)|set(PRIVATE_MAIL_SETTINGS_PINS)|set(conduit_delta.SOURCE_INPUTS)|set(code_delta.SOURCE_INPUTS)|set(authorization_delta.PRODUCTION_PATHS)|set(lifecycle_delta.PRODUCTION_PATHS)
 if set(changed)!=allowed or set(current)!=allowed:raise ValueError('unreviewed notice product delta')
 current={path:code_delta.reduce_if_selected(path,raw)for path,raw in current.items()}
 calendar_delta.reduce_reviewed_calendar_engine(current[CALENDAR_SOURCE_PATH])
 calendar_delta.reduce_reviewed_private_mail_settings(current[PRIVATE_MAIL_SETTINGS_PATH])
 for path in allowed-{'src/__phutil_library_map__.php'}:
  compared=__import__('recovery_assessment_source_delta').reduce_current(path,current[path])if path in PARTIAL_SOURCE_PINS else current[path]
  if compared!=frozen[path]:raise ValueError('reviewed config/RR source bytes changed')
 for path in conduit_delta.BASE_PINS:conduit_delta.reduce_owner(path,current[path])
 for path in conduit_delta.oauth_delta.SOURCE_INPUTS:conduit_delta.oauth_delta.reduce_current(path,current[path])
 reduced=current['src/__phutil_library_map__.php']
 conduit_lines=[b"    'PhorgeRecoveryConduitProducerFence' => 'infrastructure/governance/recovery/PhorgeRecoveryConduitProducerFence.php',\n",b"    'PhorgeRecoveryConduitProducerFence' => 'Phobject',\n"]
 if [reduced.count(x)for x in conduit_lines]not in ([0,0],[1,1]):raise ValueError('partial Conduit mapper delta')
 for line in conduit_lines:
  if reduced.count(line):reduced=reduced.replace(line,b'',1)
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
 def test_versioned_partial_review_matches_exact_reviewed_commit_and_rejects_corruption(self):
  import tempfile
  baseline='6da0112b8ee1723e0f688e1b8f18d6dd330f10af'
  changed=set(subprocess.check_output(['git','-C',str(ROOT),'diff','--name-only',baseline,PARTIAL_SOURCE_REVIEW_COMMIT,'--','src','resources'],text=True).splitlines())
  self.assertEqual(changed,set(PARTIAL_SOURCE_PINS))
  self.assertEqual(PARTIAL_SOURCE_REVIEW_VERSION,'n08-partial-source-review-v1')
  for path,pin in PARTIAL_SOURCE_PINS.items():
   frozen=subprocess.check_output(['git','-C',str(ROOT),'show',PARTIAL_SOURCE_REVIEW_COMMIT+':'+path])
   self.assertEqual(hashlib.sha256(frozen).hexdigest(),pin)
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp)
   for path in PARTIAL_SOURCE_PINS:
    p=root/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/path).read_bytes())
   self.assertEqual(set(reviewed_partial_sources(root)),changed)
   for path in PARTIAL_SOURCE_PINS:
    p=root/path;raw=p.read_bytes();p.write_bytes(raw+b'CORRUPTION')
    with self.assertRaisesRegex(ValueError,'N08 partial source audit pin changed'):reviewed_partial_sources(root)
    p.write_bytes(raw)

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
  allowed={'src/__phutil_library_map__.php',EXTRA}|{'src/'+path for path,parent in ADDITIONS.values()}|set(WORKBENCH_CANDIDATE_SOURCE_PINS)|set(PARTIAL_SOURCE_PINS)|set(SESSION_LEASE_SOURCE_PINS)|set(BORROWED_SOURCE_PINS)|set(CALENDAR_SOURCE_PINS)|set(PRIVATE_MAIL_SETTINGS_PINS)|set(conduit_delta.SOURCE_INPUTS)|set(code_delta.SOURCE_INPUTS)|set(authorization_delta.PRODUCTION_PATHS)|set(lifecycle_delta.PRODUCTION_PATHS)|set(__import__('project_content_grant_source_delta').PRODUCTION_PINS)
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
  allowed={'src/__phutil_library_map__.php',EXTRA}|{'src/'+path for path,parent in ADDITIONS.values()}|set(WORKBENCH_CANDIDATE_SOURCE_PINS)|set(PARTIAL_SOURCE_PINS)|set(SESSION_LEASE_SOURCE_PINS)|set(BORROWED_SOURCE_PINS)|set(CALENDAR_SOURCE_PINS)|set(PRIVATE_MAIL_SETTINGS_PINS)|set(conduit_delta.SOURCE_INPUTS)|set(code_delta.SOURCE_INPUTS)|set(authorization_delta.PRODUCTION_PATHS)|set(lifecycle_delta.PRODUCTION_PATHS)|set(__import__('project_content_grant_source_delta').PRODUCTION_PINS)
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
  allowed={'src/__phutil_library_map__.php',EXTRA}|{'src/'+path for path,parent in ADDITIONS.values()}|set(WORKBENCH_CANDIDATE_SOURCE_PINS)|set(PARTIAL_SOURCE_PINS)|set(SESSION_LEASE_SOURCE_PINS)|set(BORROWED_SOURCE_PINS)|set(CALENDAR_SOURCE_PINS)|set(PRIVATE_MAIL_SETTINGS_PINS)|set(conduit_delta.SOURCE_INPUTS)|set(code_delta.SOURCE_INPUTS)|set(authorization_delta.PRODUCTION_PATHS)|set(lifecycle_delta.PRODUCTION_PATHS)|set(__import__('project_content_grant_source_delta').PRODUCTION_PINS)
  current={path:(ROOT/path).read_bytes()for path in allowed}
  frozen=reviewed_sources()
  old_map=subprocess.check_output(['git','-C',str(ROOT),'show',baseline+':src/__phutil_library_map__.php'])
  self.assertTrue(validate_notice_product_delta(allowed,current,frozen,old_map))
  for extra in ('src/applications/people/storage/PhabricatorUser.php','resources/sql/quickstart.sql','src/ExtraClass.php'):
   with self.assertRaises(ValueError):validate_notice_product_delta(allowed|{extra},current,frozen,old_map)
  for path in allowed:
   bad=dict(current);bad[path]+=b'UNREVIEWED_CHANGE'
   with self.assertRaises(ValueError):validate_notice_product_delta(allowed,bad,frozen,old_map)
  # Legacy cold-mapper variants follow the exact N05 inverse; no broad source skip.
  import project_description_admission_delta as n05_delta
  allowed,current=n05_delta.historical_inputs(ROOT,baseline,allowed,current)
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

class SessionLeaseExactSourcePins(unittest.TestCase):
 def test_both_new_reviewed_pins_refuse_corruption_and_unknown_product(self):
  baseline='e5f89aac566b121f2ebb3894aa4ba76b31222c10'
  allowed={'src/__phutil_library_map__.php',EXTRA}|{'src/'+path for path,parent in ADDITIONS.values()}|set(WORKBENCH_CANDIDATE_SOURCE_PINS)|set(PARTIAL_SOURCE_PINS)|set(SESSION_LEASE_SOURCE_PINS)|set(BORROWED_SOURCE_PINS)|set(CALENDAR_SOURCE_PINS)|set(PRIVATE_MAIL_SETTINGS_PINS)|set(conduit_delta.SOURCE_INPUTS)|set(code_delta.SOURCE_INPUTS)|set(authorization_delta.PRODUCTION_PATHS)|set(lifecycle_delta.PRODUCTION_PATHS)|set(__import__('project_content_grant_source_delta').PRODUCTION_PINS)
  current={path:(ROOT/path).read_bytes()for path in allowed};frozen=reviewed_sources()
  old_map=subprocess.check_output(['git','-C',str(ROOT),'show',baseline+':src/__phutil_library_map__.php'])
  for path in SESSION_LEASE_SOURCE_PINS:
   bad=dict(current);bad[path]+=b'corrupt'
   with self.assertRaises(ValueError):validate_notice_product_delta(allowed,bad,frozen,old_map)
  bad=dict(current);bad['src/infrastructure/governance/recovery/Unknown.php']=b'unknown'
  with self.assertRaises(ValueError):validate_notice_product_delta(set(bad),bad,frozen,old_map)
