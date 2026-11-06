"""Actual production grant decoder with authored MySQL SHOW GRANTS controls.
No accounts, SQL execution or privileges are created by these host tests.
"""
from pathlib import Path
import copy,json,re,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
NS='governance_native_'+'a'*16
WRITER='gov_c_writer_'+'b'*12+'@%';OBSERVER='gov_c_metadata_'+'c'*16+'@%'
def to(principal):return ' TO `'+principal[:-2]+'`@`%`'
def scoped(app,priv='SELECT, INSERT, UPDATE, DELETE'):return 'GRANT '+priv+' ON `'+(NS+'_'+app).replace('_','\\_')+'`.*'+to(WRITER)
def minimal():return ['GRANT USAGE ON *.*'+to(WRITER),scoped('user'),scoped('governance','SELECT')]
class SessionLeaseGrants(unittest.TestCase):
 def decode(self,grants,role='writer',principal=WRITER):
  code="class Phobject{} require$argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php';$x=json_decode($argv[2],true);try{$r=PhorgeRecoverySessionProducerFence::leaseDescribeGrants($x[0],$x[1],$x[2],$x[3]);echo json_encode(['accepted'=>true,'facts'=>$r]);}catch(RuntimeException$e){echo json_encode(['accepted'=>false,'reason'=>$e->getMessage()]);}"
  p=subprocess.run(['php','-r',code,str(ROOT),json.dumps([grants,role,principal,NS])],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_actual_fixture54scoped_CRUD_and_governance_SELECT_needs_no_writer_global_privileges(self):
  apps=re.findall(r'^CREATE DATABASE .*`\{\$NAMESPACE\}_([a-z_]+)`',(ROOT/'resources/sql/quickstart.sql').read_text(),re.M)
  self.assertEqual(len(apps),54)
  grants=['GRANT USAGE ON *.*'+to(WRITER)]+[scoped(app)for app in apps]+[scoped('governance','SELECT')]
  for rows in (minimal(),grants,list(reversed(grants))):
   d=self.decode(rows);self.assertTrue(d['accepted']);self.assertFalse(d['facts']['writer_global_privileges_required']);self.assertFalse(d['facts']['grant_absence_lease_proved']);self.assertFalse(d['facts']['restoration_authorized'])
  self.assertEqual(self.decode(grants)['facts']['source_digest'],self.decode(list(reversed(grants)))['facts']['source_digest'])
 def test_writer_rejects_foreign_database_wildcard_and_grant_option_or_global_rights(self):
  base=minimal();bad=[]
  for extra in ('GRANT ALL PRIVILEGES ON *.*','GRANT PROCESS ON *.*','GRANT SYSTEM_USER ON *.*','GRANT SELECT ON `mysql`.*','GRANT SELECT ON `performance_schema`.*'):
   bad.append(base+[extra+to(WRITER)])
  bad += [base+[scoped('foreign')],base+[scoped('user')],base+base[:1],base[1:],base[:1]+base[2:],base[:2],base+[scoped('policy')+' WITH GRANT OPTION'],[x.replace('\\_','_')for x in base],[x.replace(NS.replace('_','\\_'),(NS[:-1]+'b').replace('_','\\_'))for x in base],base+['REVOKE SELECT ON `mysql`.* FROM `x`@`%`'],base+['GRANT `role`@`%`'+to(WRITER)],base+['GRANT PROXY ON `x`@`%`'+to(WRITER)]]
  for rows in bad:self.assertFalse(self.decode(rows)['accepted'],rows)
  self.assertFalse(self.decode(base,principal='gov_c_writer_'+'d'*12+'@%')['accepted'])
 def test_only_independent_observer_accepts_PROCESS_and_known_legacy_TRIGGER_unused_profile(self):
  for rights in ('SELECT, PROCESS','SELECT, TRIGGER, PROCESS'):
   rows=['GRANT '+rights+' ON *.*'+to(OBSERVER)]
   self.assertTrue(self.decode(rows,'observer',OBSERVER)['accepted']);self.assertTrue(self.decode(['GRANT USAGE ON *.*'+to(OBSERVER)]+rows,'observer',OBSERVER)['accepted'])
   self.assertFalse(self.decode([x.replace(to(OBSERVER),to(WRITER))for x in rows])['accepted'])
  for rights in ('SELECT','PROCESS','ALL PRIVILEGES','SELECT, PROCESS, SYSTEM_USER','SELECT, PROCESS, UPDATE'):
   self.assertFalse(self.decode(['GRANT '+rights+' ON *.*'+to(OBSERVER)],'observer',OBSERVER)['accepted'])
  self.assertFalse(self.decode(['GRANT SELECT, PROCESS ON *.*'+to(OBSERVER)+' WITH GRANT OPTION'],'observer',OBSERVER)['accepted'])
 def test_SQL6_owned_entry_uses_supplied_existing_observer_and_never_widens_writer(self):
  s=(ROOT/'support/governance/recovery/session-lease-cases.php').read_text();body=s.split('function governance_recovery_session_lease_owned_entry',1)[1]
  for x in ('getWriterConfig()','information_schema.USER_ATTRIBUTES','CURRENT_USER() principal,CURRENT_ROLE() role,@@proxy_user proxy','governance_recovery_session_lease_cases($fixture,$observer,$state,$check)','closeConnection()'):self.assertIn(x,body)
  for x in ('->prepare(',"'GRANT ","'CREATE USER ","'REVOKE ",'getAdmin()','getReaderConfig()'):self.assertNotIn(x,body)
 def test_actual_owned_SQL_entry_validates_live_identity_and_owner_before_actual_cases(self):
  code=r'''class PhorgeGovernanceCompletionFixture{function assertOwned(){$GLOBALS['asserts']++;if($GLOBALS['asserts']===2)throw new RuntimeException('host_actual_SQL_cases_reached');}function getNamespace(){return'governance_native_'.str_repeat('a',16);}function getWriterConfig(){return['host'=>'127.0.0.1','port'=>3306];}}class AphrontDatabaseConnection{}class PhutilOpaqueEnvelope{function __construct($v){}}class AphrontMySQLiDatabaseConnection extends AphrontDatabaseConnection{function __construct($config){$GLOBALS['constructed']++;}function closeConnection(){$GLOBALS['closed']=true;}}function queryfx_one($conn,$sql){return['principal'=>$GLOBALS['case']==='principal'?'gov_c_writer_'.str_repeat('b',12).'@%':'gov_c_metadata_'.str_repeat('c',16).'@%','role'=>$GLOBALS['case']==='role'?'role':'NONE','proxy'=>null];}function queryfx_all($conn,$sql,...$args){$owner=$GLOBALS['owner'];if($GLOBALS['case']==='owner')$owner['governance_fixture_owner']=str_repeat('d',64);return[['attributes'=>json_encode($owner)]];}require$argv[1].'/support/governance/recovery/session-lease-cases.php';$case=$argv[2];putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=1');putenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION='.str_repeat('f',40));$owner=['governance_fixture_namespace'=>'governance_native_'.str_repeat('a',16),'governance_fixture_owner'=>str_repeat('e',64),'governance_run_nonce'=>str_repeat('d',32),'governance_source_revision'=>str_repeat('f',40)];$config=['metadata_owner'=>$owner,'observer'=>['host'=>'127.0.0.1','port'=>3306,'namespace'=>$owner['governance_fixture_namespace'],'user'=>'gov_c_metadata_'.str_repeat('c',16),'password'=>str_repeat('b',48)]];if($case==='foreign-namespace')$config['observer']['namespace']='foreign';if($case==='unknown-key')$config['extra']=true;if($case==='revision')$config['metadata_owner']['governance_source_revision']=str_repeat('a',40);$asserts=0;$constructed=0;$closed=false;$reason=null;try{governance_recovery_session_lease_owned_entry(new PhorgeGovernanceCompletionFixture(),[],$config,function(){throw new Exception('unexpected');});}catch(Throwable$e){$reason=$e->getMessage();}echo json_encode(['reason'=>$reason,'asserts'=>$asserts,'constructed'=>$constructed,'closed'=>$closed,'physical_SQL_executed'=>false]);'''
  for case,reason,constructed in [('good','host_actual_SQL_cases_reached',1),('principal','session_lease_entry_observer_unknown',1),('role','session_lease_entry_observer_unknown',1),('owner','session_lease_entry_owner_unknown',1),('foreign-namespace','session_lease_entry_configuration_unknown',0),('unknown-key','session_lease_entry_configuration_unknown',0),('revision','session_lease_entry_configuration_unknown',0)]:
   p=subprocess.run(['php','-r',code,str(ROOT),case],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');d=json.loads(p.stdout);self.assertEqual(d['reason'],reason);self.assertEqual(d['constructed'],constructed);self.assertEqual(d['closed'],bool(constructed));self.assertEqual(d['asserts'],2 if case=='good' else 1);self.assertFalse(d['physical_SQL_executed'])
 def test_actual_SQL_cleanup_helper_requires_proof_and_preserves_original_error(self):
  code=r'''require$argv[1].'/support/governance/recovery/session-lease-cases.php';class Scope{function __construct(private$value){}function leaseAbort(){return['cleanup_proved'=>$this->value];}}$original=new RuntimeException('original');$out=[];foreach([true,false,null]as$value){foreach([null,$original]as$primary){try{governance_recovery_session_lease_cleanup(new Scope($value),$primary);$out[]='ok';}catch(Throwable$e){$out[]=$e===$original?'same-original':$e->getMessage();}}}echo json_encode($out);'''
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');self.assertEqual(json.loads(p.stdout),['ok','ok','session_lease_cleanup_unproved','same-original','session_lease_cleanup_unproved','same-original'])
