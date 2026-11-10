"""Native lifecycle call evidence and explicit current gaps, not SQL lock proof."""
import json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
class NativeOAuthLifecycle(unittest.TestCase):
 def invoke(self,mode,expected_exit=0):
  p=subprocess.run(['php','-d','display_errors=0','-d','log_errors=1','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect',str(ROOT/'support/governance/recovery/tests/oauth-client-lifecycle-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,expected_exit,p.stderr)
  if expected_exit:self.assertIn('open transaction',p.stderr)
  else:self.assertEqual(p.stderr,'')
  d=json.loads(p.stdout);self.assertFalse(d['physical_SQL_executed']);self.assertTrue(d['actual_final_DAOs']);self.assertEqual(d['native_scope_map'],[]);return d
 def test_native_authorization_delete_uses_real_Lisk_single_DELETE(self):
  d=self.invoke('authorization-delete');self.assertFalse(d['failed']);self.assertTrue(d['returned_same']);self.assertEqual(d['events'],['authorization.DELETE']);self.assertEqual(d['transaction_depth'],0)
 def test_all_four_client_field_saves_use_actual_native_UPDATE(self):
  for mode in ['disabled','secret','redirect','trust']:
   d=self.invoke(mode);self.assertFalse(d['failed'],d);self.assertTrue(d['returned_same']);self.assertEqual(d['events'],['client.UPDATE'])
 def test_owned_client_CREATE_and_generic_creator_reassignment_are_real_native_paths(self):
  d=self.invoke('create-owned-client');self.assertFalse(d['failed']);self.assertTrue(d['creator_matches_initial_subject']);self.assertEqual(d['events'],['client.INSERT'])
  d=self.invoke('owner-reassignment');self.assertFalse(d['failed']);self.assertFalse(d['creator_matches_initial_subject']);self.assertEqual(d['events'],['client.UPDATE'])
 def test_actual_native_cascade_is_client_first_then_authorization_access_code(self):
  d=self.invoke('cascade');self.assertFalse(d['failed'],d);self.assertEqual(d['events'],['START','client.DELETE','authorization.SELECT','authorization.DELETE','access.SELECT','access.DELETE','code.SELECT','code.DELETE','COMMIT']);self.assertEqual(d['transaction_depth'],0)
 def test_actual_cascade_body_error_preserves_primary_without_inferred_terminal(self):
  d=self.invoke('cascade-error',255);self.assertTrue(d['failed']and d['same_primary']);self.assertEqual(d['events'],['START','client.DELETE','authorization.SELECT','authorization.DELETE']);self.assertEqual(d['transaction_depth'],1)
 def test_retained_and_reserved_owners_refuse_before_native_body_or_BEGIN(self):
  for prefix,reason in [('quarantined-','recovery_conduit_writer_quarantined'),('reserved-','recovery_oauth_lifecycle_borrowed_owner_reserved'),('lease-','recovery_oauth_lifecycle_selected_lease_reserved')]:
   for operation in ['authorization-delete','authorization-update','trust','create-owned-client','client-begin','client-editor','destroy-engine','cascade']:
    d=self.invoke(prefix+operation);self.assertTrue(d['failed'],(prefix,operation,d));self.assertEqual(d['blocked_reason'],reason);self.assertEqual(d['events'],[]);self.assertEqual(d['transaction_depth'],0)
class CurrentClientCaptureOrder(unittest.TestCase):
 def invoke(self,mode):
  p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen',str(ROOT/'support/governance/recovery/tests/oauth-client-capture-order-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');d=json.loads(p.stdout);self.assertFalse(d['physical_SQL_executed']or d['public_owner_admission_exercised']or d['family_complete']or d['all_sources_complete']or d['can_execute']);return d
 def test_actual_reader_client_PRIMARY_precedes_OAuth_record_SHARES(self):
  read=self.invoke('referenced');self.assertIsNone(read['error']);self.assertEqual(read['client_observations'],1);events=read['events'];self.assertLess(events.index('client.PRIMARY.S'),events.index('authorization.S'))
  destroy=NativeOAuthLifecycle().invoke('cascade')['events'];self.assertLess(destroy.index('client.DELETE'),destroy.index('authorization.DELETE'))
  # These actual statement sequences share client-first order. The original
  # inverse-order witness remains frozen in b55; no physical lock claim here.
 def test_reference_drift_missing_client_and_budget_refuse(self):
  for mode in ['ref-drift','missing-client','plan-budget']:
   d=self.invoke(mode);self.assertIsNotNone(d['error'],(mode,d));self.assertIsNone(d['client_observations'])
 def test_actual_prepare_method_checks_owner_before_IO_and_locks_only_clients(self):
  d=self.invoke('prepare');self.assertIsNone(d['error']);self.assertRegex(d['prepared_digest'],'^[a-f0-9]{64}$');self.assertIn('client.PRIMARY.S',d['events']);self.assertNotIn('authorization.S',d['events'])
  d=self.invoke('prepare-wrong-token');self.assertEqual(d['error'],'host_owner_mismatch');self.assertEqual(d['events'],[])
 def test_actual_existing_client_digest_changes_for_each_lifecycle_field(self):
  for field in ['secret','redirectURI','isTrusted','isDisabled','creatorPHID']:
   d=self.invoke('client-change-'+field);self.assertIsNone(d['error']);self.assertTrue(d['changed_digest'],field)
 def test_current_owned_client_without_credential_refs_is_not_captured(self):
  d=self.invoke('owned-no-refs');self.assertEqual(d['client_observations'],0);self.assertIn('client.constant-empty-predicate',d['events']);self.assertIn('owned-oauth-clients-without-credential-references-uncovered',d['unknown_reasons']);self.assertIn('oauth-client-create-and-owner-reassignment-phantoms-unfenced',d['unknown_reasons'])
class ActualStoreClientPreparation(unittest.TestCase):
 def invoke(self,mode):
  p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen',str(ROOT/'support/governance/recovery/tests/assessment-oauth-client-preparation-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');v=json.loads(p.stdout);self.assertFalse(v['physical_SQL_executed']);return v
 def test_actual_Store_and_participant_prepare_before_any_OAuth_record_read(self):
  v=self.invoke('good');self.assertIsNone(v['error']);self.assertTrue(v['facts']['blocked']);self.assertGreaterEqual(len(v['client_prepare_events']),2)
  first=v['client_prepare_events'][0][1];self.assertFalse(set(v['reads'][:first])&{'oauth-access','oauth-code','oauth-authorization','conduit'});self.assertEqual((v['commits'],v['rollbacks']),(1,0))
 def test_preparation_changed_or_unsupported_never_returns_an_assessment(self):
  for mode in ['mutate-client-prep','unsupported-client-prep']:
   v=self.invoke(mode);self.assertIsNotNone(v['error']);self.assertIsNone(v['facts']);self.assertEqual(v['commits'],0)
   if mode=='unsupported-client-prep':self.assertFalse(set(v['reads'])&{'oauth-access','oauth-code','oauth-authorization','conduit'})
 def test_unknown_owner_still_retains_and_known_primary_is_not_replaced(self):
  v=self.invoke('unknown-reentry');self.assertTrue(v['retained_owner']);self.assertFalse(v['cleanup_proved']);self.assertIsNotNone(v['retry_error'])
  v=self.invoke('primary');self.assertEqual(v['error'],'host_primary');self.assertTrue(v['same_primary']);self.assertEqual(v['commits'],0)
if __name__=='__main__':unittest.main()
