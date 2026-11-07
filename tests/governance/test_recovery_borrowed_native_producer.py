"""Borrowed-only producer lock topology; explicit host SQL/model collaborators, not SQL6 proof."""
from pathlib import Path
import json,subprocess,unittest,ast,hashlib
ROOT=Path(__file__).resolve().parents[2]
class BorrowedNativeProducer(unittest.TestCase):
 def php(self,file,mode):
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests'/file),str(ROOT),mode],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
 def test_actual_native_transaction_and_real_engine_method_commit_current_actor_order(self):
  d=self.php('borrowed-producer-native-host.php','good');self.assertIsNone(d['error_type']);self.assertFalse(d['physical_SQL_executed']);self.assertTrue(d['model_identity_transport_collaborators']);self.assertTrue(d['actual_SessionEngine_method_source']);self.assertEqual(d['actual_native_transaction_method'],'AphrontDatabaseConnection');self.assertEqual(d['trace'],['SET TRANSACTION ISOLATION LEVEL READ COMMITTED','START TRANSACTION','thread-identity','native-identity-preflight','COMMIT','SET TRANSACTION ISOLATION LEVEL READ COMMITTED','START TRANSACTION','thread-identity','DAO-save','native-session-result','native-actor-revalidate','COMMIT']);self.assertTrue(d['preflight_marker']);self.assertTrue(d['result']['actor_revalidated_before_commit']);self.assertFalse(d['result']['restoration_authorized'])
 def test_late_admin_revalidation_and_primary_cleanup_fail_closed(self):
  for mode in ['late-admin','revalidate-fail','dual','identity-fail','begin-fail','commit-fail','thread-changed']:
   d=self.php('borrowed-producer-native-host.php',mode);self.assertIsNone(d['result']);self.assertFalse(d['physical_SQL_executed']);self.assertIsNotNone(d['error_type'])
   if mode=='late-admin':self.assertTrue(d['late_admin_refused'])
   elif mode=='dual':self.assertTrue(d['aggregate_exact'])
   elif mode=='thread-changed':self.assertEqual(d['error_file'],'borrowed-session-producer.php');self.assertNotIn('DAO-save',d['trace']);self.assertFalse(d['preflight_marker'])
   else:self.assertTrue(d['primary_exact'])
   if mode in ['late-admin','revalidate-fail','dual']:self.assertEqual(d['trace'].count('COMMIT'),1);self.assertEqual(d['trace'][-1],'ROLLBACK');self.assertLess(d['trace'].index('DAO-save'),d['trace'].index('native-actor-revalidate'))
   if mode in ['begin-fail','commit-fail']:self.assertNotIn('ROLLBACK',d['trace'])
   if mode=='thread-changed':self.assertEqual(d['trace'][-1],'ROLLBACK')
 def test_actual_preflight_observer_event_and_no_locks_gate(self):
  for mode in ['good','missing-consumer','disabled-instrument','disabled-thread','active','rolled-back','wrong-isolation','active-trx','subject-lock','boolean-zero','wrong-marker','late-reply']:
   d=self.php('borrowed-preflight-observation-host.php',mode);self.assertEqual(d['native_classifier'],'PhorgeRecoverySessionProducerFence');self.assertFalse(d['physical_SQL_executed']);self.assertEqual(d['passed'],mode=='good')
   if mode=='wrong-marker':self.assertEqual(d['queries'],0)
 def test_old_lease_worker_and_handle_bodies_byteexact_and_new_profile_bound(self):
  base='4e12882595da85afc8ffc8b7b83aed1feb255c5a'
  def old(p):return subprocess.check_output(['git','-C',str(ROOT),'show',base+':'+p]).decode()
  p='support/governance/native-organization/native-worker.php';a=old(p);b=(ROOT/p).read_text();start="  }else if($kind==='recovery.session.issue'){";end="  }else if($kind==='recovery.stop.record'";self.assertEqual(a[a.index(start):a.index(end,a.index(start))],b[b.index(start):b.index(end,b.index(start))])
  p='support/governance/native-organization/workers.php';a=old(p);b=(ROOT/p).read_text()
  for name in ['governance_native_session_producer_handle','governance_native_wait_session_producer_ready','governance_native_continue_session_producer','governance_native_wait_session_covering_control','governance_native_continue_session_fence','governance_native_session_producer_result']:
   def body(s):
    start=s.index('function '+name+'(');end=s.find('\nfunction ',start+1);return s[start:end if end!=-1 else len(s)]
   self.assertEqual(body(a),body(b),name)
  source=(ROOT/'support/governance/recovery/borrowed-session-producer.php').read_text();self.assertNotIn('covering-control',source);self.assertIn('PhabricatorAuthSessionEngine())->establishSession',source);self.assertIn('PhorgeGovernanceNativeIdentitySource::revalidate($snapshot,$conn)',source)

 def test_versioned_receipt_rejects_old_profile_and_missing_new_source(self):
  import sys,copy
  sys.path.insert(0,str(ROOT/'scripts/governance'));import check_recovery_borrowed_session as gate
  from test_recovery_borrowed_wiring import BorrowedWiring
  data=BorrowedWiring().template();self.assertEqual(len(gate.SOURCE_INPUTS),30);self.assertIn('support/governance/recovery/borrowed-session-producer.php',gate.SOURCE_INPUTS);self.assertEqual(len(gate.SQL_obligations()),6);self.assertEqual(len(gate.obligations()),16)
  for key,value in [('profile','isolated-borrowed-real-store-session-owner-v1'),('borrowed_only_native_producer',False),('child_preflight_committed_before_owner',False),('child_preflight_no_record_locks_observed',False),('actor_revalidated_before_child_commit',False),('old_covering_positive_query_used',True)]:
   bad=copy.deepcopy(data);bad['native'][key]=value
   with self.assertRaises((ValueError,TypeError)):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
