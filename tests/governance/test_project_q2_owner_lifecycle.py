"""Real Scope/transaction methods with explicit non-SQL observers, no runtime proof."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]
HARNESS=ROOT/'support/governance/project-description-q2-owner/native-no-db.php'
class ProjectQ2Owner(unittest.TestCase):
    def invoke(self,case):
        p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen',str(HARNESS),case],capture_output=True,text=True,check=True,timeout=10)
        self.assertEqual('',p.stderr)
        value=json.loads(p.stdout);self.assertTrue(value['passed'])
        for key in ('SQL','physical_transaction_lease_proved','actual_Editor_pipeline_executed','execution_authorized'):self.assertFalse(value[key])
        return value['result']
    def test_owned_depth_one_rollback_and_repeated_close(self):
        self.assertTrue(self.invoke('owned-rollback')['safe_bounded_owner_cleanup'])
    def test_normal_commit_restores_own_cache_and_releases_reservation(self):
        self.assertTrue(self.invoke('normal-commit')['safe_bounded_owner_cleanup'])
    def test_own_fence_failure_preserves_pending_primary(self):
        self.assertTrue(self.invoke('fence-with-primary')['primary_preserved'])
    def test_actual_fence_refuses_unadmitted_writer_before_SQL(self):
        self.assertTrue(self.invoke('producer-profile')['actual_unadmitted_writer_refused'])
    def test_committed_close_does_not_rollback_later_transaction_or_modes(self):
        self.assertTrue(self.invoke('committed-foreign')['foreign_transaction_untouched'])
    def test_forced_later_registry_and_cache_owner_are_not_cleared(self):
        self.assertTrue(self.invoke('foreign-owner')['foreign_owner_registry_and_cache_untouched'])
    def test_changed_global_request_cache_never_restores_old_frame(self):
        self.assertTrue(self.invoke('new-cache-object')['foreign_cache_untouched'])
    def test_unknown_nested_frame_is_not_unwound(self):
        self.assertTrue(self.invoke('nested-unknown')['quarantined'])
    def test_commit_query_exception_keeps_local_quarantine(self):
        self.assertFalse(self.invoke('commit-error')['physical_outcome_known'])
    def test_unobserved_native_rollback_depth_zero_is_not_ack_evidence(self):
        self.assertTrue(self.invoke('external-ended-unknown')['quarantined'])
    def test_rollback_ack_error_cannot_release_reservation(self):
        self.assertTrue(self.invoke('rollback-error')['source_reuse_blocked_in_this_process'])
    def test_private_fence_failure_is_cleanup_unknown(self):
        self.assertTrue(self.invoke('fence-error')['quarantined'])
    def test_same_depth_new_native_generation_is_not_rolled_back(self):
        self.assertTrue(self.invoke('same-depth-replaced')['quarantined'])
    def test_replaced_state_object_with_same_counter_is_not_owned(self):
        self.assertTrue(self.invoke('state-object-replaced')['quarantined'])
    def test_old_generation_cannot_be_marked_committed(self):
        self.assertTrue(self.invoke('didCommit-old-generation')['quarantined'])
    def test_actual_service_mode_rejects_wrong_owner_generation_and_shape_before_save(self):
        for case in ('service-wrong-owner','service-old-generation','service-nested','service-lock-mode'):
            with self.subTest(case=case):
                self.assertEqual(0,self.invoke(case)['COMMIT_queries_added'])
    def test_actual_native_storage_generation_and_ACK_unknown_protocol(self):
        result=self.invoke('generation-storage')
        self.assertTrue(all(result.values()))
    def test_generic_Editor_fence_and_policy_implementation_remain_unchanged(self):
        for path in ('src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php','src/infrastructure/governance/execution/PhorgeProjectExecutionProducerFence.php','src/infrastructure/governance/content/PhorgeProjectDescriptionExecutor.php'):
            raw=(ROOT/path).read_bytes()
            if path.endswith('/PhabricatorApplicationTransactionEditor.php'):
                after=b"""        if ($project_scope!==null && $project_scope->getMode()==='content-description') {
          $project_scope->rollbackDescriptionEditor($this,$object);
        } else {
          $object->killTransaction();
        }
"""
                self.assertEqual(1,raw.count(after));raw=raw.replace(after,b'        $object->killTransaction();\n',1)
            self.assertEqual(raw,subprocess.check_output(['git','-C',str(ROOT),'show','577ae2eb82d854753b88f2985a154e409272068a:'+path]))
    # These invoke the real private rollback core with explicit unissued scopes.
    # Public caller positive is deferred to the actual native SQL9 run.
    def test_owned_description_abort_single_ACK_and_primary_preservation(self):
        for case in ('description-rollback-success','description-primary-preserved'):
            with self.subTest(case=case):
                value=self.invoke(case);self.assertEqual(1,value['single_native_rollback_queries_added'])
                self.assertTrue(value['scope_cleanup_adds_no_rollback']);self.assertTrue(value['model_primary_1644_preserved'])
    def test_description_abort_public_requires_actual_native_Editor_caller(self):
        self.assertEqual(0,self.invoke('description-wrong-caller')['single_native_rollback_queries_added'])
    def test_description_abort_closed_editor_object_and_mode(self):
        for case in ('description-wrong-editor','description-wrong-object','description-wrong-mode'):
            with self.subTest(case=case):self.assertEqual(0,self.invoke(case)['single_native_rollback_queries_added'])
    def test_description_abort_never_touches_foreign_or_committed_frames(self):
        for case in ('description-foreign-owner','description-committed'):
            with self.subTest(case=case):self.assertEqual(0,self.invoke(case)['single_native_rollback_queries_added'])
    def test_description_abort_requires_original_generation_and_depth_one(self):
        for case in ('description-same-depth-ABA','description-depth-zero','description-nested'):
            with self.subTest(case=case):self.assertEqual(0,self.invoke(case)['single_native_rollback_queries_added'])
    def test_description_abort_rejects_residual_lock_modes(self):
        for case in ('description-read-mode','description-write-mode'):
            with self.subTest(case=case):self.assertEqual(0,self.invoke(case)['single_native_rollback_queries_added'])
    def test_description_abort_ACK_error_never_retries_or_claims_closed(self):
        value=self.invoke('description-rollback-ACK-error');self.assertEqual(1,value['single_native_rollback_queries_added'])
        self.assertTrue(value['scope_cleanup_adds_no_rollback'])
    def test_description_abort_post_ACK_new_generation_is_not_owned(self):
        self.assertEqual(1,self.invoke('description-post-rollback-ABA')['single_native_rollback_queries_added'])
    def test_description_abort_repeated_call_adds_no_second_rollback(self):
        self.assertEqual(1,self.invoke('description-repeated-rollback')['single_native_rollback_queries_added'])
    def test_description_abort_requires_exact_object_writer_before_unknown(self):
        self.assertEqual(0,self.invoke('description-wrong-connection')['single_native_rollback_queries_added'])
if __name__=='__main__':unittest.main()
