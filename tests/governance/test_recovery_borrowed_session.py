"""Real store/participant code with explicit host SQL collaborators; no physical proof."""
from pathlib import Path
import subprocess,json,unittest,sys
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
class BorrowedSession(unittest.TestCase):
 def run_case(self,case):
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/borrowed-session-owner-host.php'),str(ROOT),case],capture_output=True,text=True,timeout=10)
  self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');d=json.loads(p.stdout);self.assertFalse(d['physical_SQL_executed']);return d
 def test_actual_store_owner_is_only_transaction_owner_and_participant_observes_same_event(self):
  d=self.run_case('good');self.assertIsNone(d['error']);self.assertEqual((d['opens'],d['commits'],d['rollbacks'],d['depth']),(1,1,0,0));self.assertEqual(d['statement_sql'],['SET TRANSACTION ISOLATION LEVEL READ COMMITTED']);self.assertTrue(d['cleanup_proved']);self.assertFalse(d['retained_owner']);self.assertEqual(d['terminal']['state'],'committed');self.assertFalse(d['facts']['participant_owns_transaction']);self.assertFalse(d['facts']['production_observer_factory_available']);self.assertFalse(d['facts']['restoration_authorized'])
 def test_primary_rollback_is_owner_only_and_ACK_failure_is_not_reported_as_success(self):
  d=self.run_case('primary');self.assertEqual((d['opens'],d['commits'],d['rollbacks']),(1,0,1));self.assertTrue(d['same_primary']);self.assertTrue(d['cleanup_proved']);self.assertEqual(d['terminal']['state'],'rolled-back')
  d=self.run_case('commit-ACK');self.assertTrue(d['same_primary']);self.assertTrue(d['cleanup_proved']);self.assertEqual(d['terminal']['state'],'committed');self.assertEqual(d['rollbacks'],0)
 def test_unknown_before_return_and_end_are_retained_and_preserve_both_errors(self):
  for case in ['unknown-begin','unknown-end','open-unknown']:
   d=self.run_case(case);self.assertFalse(d['cleanup_proved']);self.assertIsNone(d['terminal']);self.assertTrue(d['retained_owner']);self.assertEqual('primary'in d['aggregate'],case!='unknown-end');self.assertIn('borrowed-terminal',d['aggregate']);self.assertEqual(d['opens'],1)
  d=self.run_case('rollback-error');self.assertFalse(d['same_primary']);self.assertIn('primary',d['aggregate']);self.assertIn('host_primary',d['error']);self.assertIn('host_rollback_failure',d['error'])
 def test_foreign_token_and_bad_observer_role_never_commit(self):
  d=self.run_case('fake-token');self.assertEqual(d['error'],'recovery_borrowed_owner_unknown');self.assertEqual(d['opens'],0)
  d=self.run_case('role');self.assertEqual(d['commits'],0);self.assertFalse(d['cleanup_proved']);self.assertTrue(d['retained_owner'])
 def test_core_has_no_fixture_or_control_dependency_and_borrower_has_no_transaction_mutators(self):
  service=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php').read_text();fence=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php').read_text();borrow=fence.split('  private $borrowed=false;',1)[1].split('  public static function beginLease(',1)[0]
  for token in ['openTransaction(', 'saveTransaction(', 'killTransaction(', 'ROLLBACK', 'SET TRANSACTION']:
   self.assertNotIn(token,borrow)
  for token in ['PhorgeGovernanceCompletionFixture','session-lease-observer.php','USER_ATTRIBUTES','owner_nonce','mysqli $control']:
   self.assertNotIn(token,service)
  self.assertIn("private static $borrowedObserver;",service);self.assertIn("recovery_restore_execution_provider_unavailable",(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php').read_text())

 def test_explicit_two_path_source_candidate_rejects_hash_drift_or_runtime_claims(self):
  import hashlib,copy
  import test_notice_native_admin_wire_source as source
  data=json.loads((ROOT/'docs/governance/recovery-borrowed-owner-source-v1.json').read_text());self.assertEqual(data['source_sha256'],source.BORROWED_SOURCE_PINS);self.assertEqual(len(data['source_sha256']),2)
  for path,pin in data['source_sha256'].items():
   import recovery_conduit_source_delta as conduit_delta
   self.assertEqual(hashlib.sha256(conduit_delta.reduce_owner(path,(ROOT/path).read_bytes())).hexdigest(),pin)
  import check_recovery_borrowed_owner_source as gate
  self.assertTrue(gate.validate(data)['schema_valid'])
  for key in ['runtime_certified','physical_SQL_cases_executed','production_observer_factory_available','business_submit_review_integration_proved','all_sources_complete','restoration_authorized']:
   bad=copy.deepcopy(data);bad[key]=True
   with self.assertRaises(ValueError):gate.validate(bad)
  bad=copy.deepcopy(data);bad['unknown']=False
  with self.assertRaises(ValueError):gate.validate(bad)
  import tempfile
  with tempfile.TemporaryDirectory()as tmp:
   fresh=Path(tmp).resolve()
   for path in gate.SOURCE_INPUTS:
    p=fresh/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/path).read_bytes())
   gate.validate(data,fresh)
   for path in gate.SOURCE_INPUTS:
    p=fresh/path;raw=p.read_bytes();p.write_bytes(raw+b'CORRUPTION')
    with self.assertRaisesRegex(ValueError,'source bytes changed'):gate.validate(data,fresh)
    p.write_bytes(raw)
 def test_six_physical_cases_call_real_store_and_native_worker_but_are_not_host_pass_claims(self):
  code="require$argv[1].'/support/governance/recovery/borrowed-session-cases.php';echo json_encode(governance_recovery_borrowed_session_obligations());"
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);labels=json.loads(p.stdout);self.assertEqual(len(labels),6);self.assertEqual(len(set(labels)),6)
  text=(ROOT/'support/governance/recovery/borrowed-session-cases.php').read_text()
  for token in ['withTransaction','captureBorrowedSessionFacts',"'action'=>'recovery.session.borrowed-owner.issue'",'performance_schema.data_lock_waits','governance_recovery_session_lease_workers_ended','borrowed_intentional_owner_rollback']:
   self.assertIn(token,text)
  support=(ROOT/'support/governance/recovery/borrowed-session-observer.php').read_text();self.assertIn('GovernanceSessionLeaseObserverOwner::prepare',support);self.assertIn('USER_ATTRIBUTES',support);self.assertIn('borrowed_fixture_parent_terminal_unproved',support);self.assertNotIn('GRANT PROCESS',support)

 def test_actual_support_owner_lifetime_preserves_primary_and_never_drops_unknown_or_failed_close(self):
  for case in ['good','primary','unknown','close-failure']:
   p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/borrowed-observer-lifetime-host.php'),str(ROOT),case],capture_output=True,text=True,timeout=10)
   self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');d=json.loads(p.stdout);self.assertFalse(d['physical_SQL_executed']);self.assertEqual(d['creates'],1);self.assertEqual(len(d['grants']),2)
   if case in ['good','primary']:
    self.assertEqual(d['drops'],1);self.assertEqual(d['closes'],1);self.assertFalse(d['account_retained']);self.assertFalse(d['observer_retained']);self.assertTrue(d['cleanup']['cleanup_proved']);self.assertEqual(d['same_primary'],case=='primary')
   else:
    self.assertEqual(d['drops'],0);self.assertTrue(d['account_retained']);self.assertTrue(d['observer_retained']);self.assertFalse(d['cleanup']['cleanup_proved']);self.assertIn('primary',d['aggregate']);self.assertIn('observer-cleanup',d['aggregate'])
 def test_actual_final_native_connection_and_core_reject_unminted_token_without_SQL(self):
  BOOT=r"""$root=$argv[1];function phutil_register_library_map($map){foreach($map['class']as$c=>$p){$GLOBALS['paths'][$c]=$GLOBALS['base'].'/'.$p;}}foreach(['/support/runtime/src','/src']as$b){$GLOBALS['base']=$root.$b;require$root.$b.'/__phutil_library_map__.php';}spl_autoload_register(function($c){if(isset($GLOBALS['paths'][$c]))require_once$GLOBALS['paths'][$c];});require_once$root.'/support/runtime/src/utils/utils.php';"""
  code=BOOT+"$c=new AphrontMySQLiDatabaseConnection([]);$e=null;try{PhorgeRecoverySessionProducerFence::beginBorrowed(new stdClass(),$c,$c);}catch(Throwable$x){$e=$x->getMessage();}echo json_encode(['actual_native_class'=>get_class($c),'final'=>(new ReflectionClass($c))->isFinal(),'inside'=>$c->isInsideTransaction(),'error'=>$e,'physical_SQL_executed'=>false]);"
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');self.assertEqual(json.loads(p.stdout),{'actual_native_class':'AphrontMySQLiDatabaseConnection','final':True,'inside':False,'error':'recovery_borrowed_owner_unknown','physical_SQL_executed':False})
 def test_actual_public_source_CLI_rejects_false_runtime_authority_and_duplicate_JSON(self):
  import tempfile,copy
  original=(ROOT/'docs/governance/recovery-borrowed-owner-source-v1.json').read_text()
  with tempfile.TemporaryDirectory()as tmp:
   path=Path(tmp).resolve()/'source.json';path.touch(mode=0o600)
   for body,good in [(original,True),(original.replace('"runtime_certified": false','"runtime_certified": true'),False),(original.replace('"execution_blocked": true','"execution_blocked": true, "execution_blocked": true'),False)]:
    path.write_text(body);p=subprocess.run([sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_borrowed_owner_source.py'),'--source-artifact',str(path)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode==0,good,p.stderr)
    if good:self.assertFalse(json.loads(p.stdout)['runtime_certified'])

 def test_caught_budget_failure_poisoned_participant_still_blocks_owner_commit(self):
  d=self.run_case('catch-budget');self.assertEqual(d['commits'],0);self.assertEqual(d['rollbacks'],1);self.assertIsNone(d['facts']);self.assertTrue(d['cleanup_proved']);self.assertEqual(d['error'],'recovery_borrowed_not_active')

 def test_terminal_observer_reconnect_identity_change_is_not_cleanup_proof(self):
  d=self.run_case('observer-end');self.assertEqual(d['commits'],1);self.assertEqual(d['rollbacks'],0);self.assertFalse(d['cleanup_proved']);self.assertTrue(d['retained_owner']);self.assertIn('recovery_borrowed_observer_changed',d['aggregate']['borrowed-terminal'])

 def test_unknown_owner_quarantine_rejects_next_store_call_before_any_new_BEGIN(self):
  d=self.run_case('unknown-reentry');self.assertEqual(d['opens'],1);self.assertEqual(d['commits'],1);self.assertEqual(d['retry_error'],'recovery_borrowed_owner_quarantined');self.assertFalse(d['cleanup_proved']);self.assertTrue(d['retained_owner'])

 def test_borrowed_recapture_cost_is_inside_real_store_deadline(self):
  d=self.run_case('slow-finish');self.assertEqual(d['error'],'recovery_restore_operation_deadline');self.assertEqual(d['commits'],0);self.assertEqual(d['rollbacks'],1);self.assertTrue(d['cleanup_proved'])
