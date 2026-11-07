"""Execute the real lease/producer hook with declared SQL/DAO collaborators.
These controls do not certify physical transactions, authentication or SQL races.
"""
from pathlib import Path
import json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
class SessionLease(unittest.TestCase):
 def run_case(self,case):
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/session-lease-host-controls.php'),str(ROOT),case],capture_output=True,text=True,timeout=10)
  self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');d=json.loads(p.stdout);self.assertFalse(d['physical_SQL_executed']);return d
 def test_actual_capture_recheck_commit_and_rollback_terminal_methods(self):
  for case,state in [('commit','committed'),('rollback','rolled-back')]:
   d=self.run_case(case);self.assertIsNone(d['error']);self.assertEqual(d['terminal']['state'],state);self.assertTrue(d['terminal']['cleanup_proved']);self.assertFalse(d['facts']['restoration_authorized']);self.assertFalse(d['facts']['durable_review_compatible']);self.assertFalse(d['facts']['all_sources_complete']);self.assertTrue(d['facts']['execution_blocked']);self.assertEqual(d['opens'],1)
 def test_actual_native_AuthSession_insert_hook_invokes_real_fence_and_parent_collaborator(self):
  d=self.run_case('native-hook');self.assertIsNone(d['error']);self.assertTrue(d['facts']['native_hook_returned_actual_model']);self.assertEqual(d['DAO_inserts'],1);self.assertEqual(d['opens'],1)
  d=self.run_case('reserved-hook');self.assertTrue(d['reserved']);self.assertEqual(d['DAO_inserts'],0);self.assertEqual(d['terminal']['state'],'committed')
 def test_default_off_outer_and_raw_transactions_never_adopt_caller(self):
  d=self.run_case('off');self.assertEqual(d['error'],'recovery_session_lease_scope_disabled');self.assertEqual(d['SQL_calls'],0)
  d=self.run_case('outer');self.assertEqual(d['error'],'recovery_session_lease_outer_transaction_refused');self.assertEqual(d['opens'],0);self.assertEqual(d['rollbacks'],0)
  d=self.run_case('raw-outer');self.assertEqual(d['error'],'host_1568');self.assertEqual(d['opens'],0);self.assertEqual(d['rollbacks'],0)
 def test_source_identity_and_physical_event_ABA_refuse(self):
  for case,error in [('source-change','recovery_session_lease_source_changed'),('identity-change','recovery_session_lease_physical_identity_unknown'),('ABA','recovery_session_lease_event_changed')]:
   d=self.run_case(case);self.assertEqual(d['error'],error);self.assertNotEqual(d['terminal']['state'],'committed')
  d=self.run_case('source-change');self.assertTrue(d['terminal']['cleanup_proved']);self.assertEqual(d['terminal']['failure_physical_state'],'rollback-observed')
 def test_unknown_event_quarantines_and_lost_commit_ACK_never_reports_normal_success(self):
  d=self.run_case('unknown-event');self.assertEqual(d['error'],'recovery_session_lease_event_changed');self.assertFalse(d['terminal']['cleanup_proved']);self.assertTrue(d['quarantined']);self.assertEqual(d['rollbacks'],0)
  d=self.run_case('lost-commit-ACK');self.assertEqual(d['error'],'host_lost_ACK');self.assertEqual(d['terminal']['state'],'invalid');self.assertEqual(d['terminal']['failure_physical_state'],'committed-observed');self.assertTrue(d['terminal']['cleanup_proved']);self.assertEqual(d['rollbacks'],0)
 def test_unknown_roles_grants_failclosed_and_SQL_is_bounded(self):
  for case in ('role','unknown-grants'):
   d=self.run_case(case);self.assertIsNotNone(d['error']);self.assertEqual(d['DAO_inserts'],0)
  s=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php').read_text();self.assertIn('LIMIT 65 LOCK IN SHARE MODE',s);self.assertLess(s.index('OCTET_LENGTH(phid)'),s.index("SELECT id,phid,userPHID"));self.assertIn('FORCE INDEX (PRIMARY) WHERE id=%d FOR UPDATE',s)
 def test_callable_SQL_cases_use_real_worker_and_both_release_boundaries(self):
  s=(ROOT/'support/governance/recovery/session-lease-cases.php').read_text()
  for token in ("array('commit','rollback')","'action'=>'recovery.session.issue'",'governance_native_wait_session_producer_ready','governance_native_continue_session_fence','performance_schema.data_lock_waits',"['lock_data']===(string)$subject->getID()",'governance_native_finish_worker','$scope->leaseCommit()','$scope->leaseRollback()',"$before['source_binding']!==$after['source_binding']"):
   self.assertIn(token,s)
  options=(ROOT/'src/infrastructure/governance/recovery/PhorgeGovernanceRecoveryConfigOptions.php').read_text();self.assertIn("'governance.account-recovery.session-lease.enabled','bool',false",options)
  assessment=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php').read_text();self.assertIn("throw new RuntimeException('recovery_restore_execution_provider_unavailable')",assessment)
 def test_budget_refusal_precedes_full_rows_and_rejected_capture_cannot_be_reused(self):
  for case in ('budget','oversize'):
   d=self.run_case(case);self.assertEqual(d['error'],'recovery_session_lease_budget');self.assertEqual(d['rollbacks'],1);self.assertEqual(d['DAO_inserts'],0)
  d=self.run_case('poisoned-recapture');self.assertEqual(d['error'],'recovery_session_lease_scope_not_active');self.assertTrue(d['terminal']['cleanup_proved'])
