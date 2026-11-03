"""Mutation tests for the native evidence gate, not runtime permission proof."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('native_organization_gate', ROOT/'scripts/governance/check_native_organization.py')
gate=importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


def template():
    hashes={'phabricator:'+p: hashlib.sha256((ROOT/'resources/sql/autopatches'/p).read_bytes()).hexdigest()
        for p in gate.completion.PATCHES}
    return {'batch': 'native-organization-media-integration', 'passed': True,
        'scope': 'owned-full-native-source-synthetic-accounts', 'checks': sorted(gate.REQUIRED_CHECKS),
        'check_count': len(gate.REQUIRED_CHECKS), 'php_version': '8.5.12',
        'source_restore': {'quickstart_sha256': hashlib.sha256((ROOT/'resources/sql/quickstart.sql').read_bytes()).hexdigest(),
            'app_schema_count': 54, 'owned_schema_count': 55, 'quickstart_source_table_count': 500,
            'current_table_count': 538, 'restore_phase_workers_started': 0,
            'restore_phase_network_endpoints_configured': 0, 'patch_audit': {
                'source_patch_count': 1569, 'applied_patch_count': 1569, 'pending_patch_keys': [],
                'current_patch_ledger_complete': True, 'migration_source_hashes': hashes,
                'migrations_executed': len(hashes)}},
        'native': copy.deepcopy(gate.NATIVE), 'cleanup': {
            'ownedSchemasRemoved': 55, 'ownedRuntimeUsersRemoved': 2, 'readerRemoved': True},
        'worker_cleanup': {'workers_registered': gate.WORKERS,
            'private_directories_removed': gate.WORKERS, 'private_directories_remaining': 0,
            'live_processes_after_cleanup': 0, 'owned_processes_stopped': 0, 'cleanup_failures': 0},
        'file_service_cleanup': {'kind':'file','binary_sha256':'b'*64,
            'process_stopped':True,'private_storage_removed':True},
        'successful_owned_file_service_startups': 1,
        'optional_source_reader_adapter_exercised': True, 'isolated_optional_features_enabled': True,
        'failure': None, 'cleanup_failures': [], 'terminal_failure_cleanup_rollbacks': 0,
        'captured_native_output_bytes': 323, 'captured_native_output_sha256': 'a'*64,
        'production_deployment': False, 'production_executor': False,
        'production_optional_features_enabled': False, 'native_permission_axes_changed': False,
        'remaining_blocked': list(gate.BLOCKERS)}


class NativeOrganizationEvidenceTest(unittest.TestCase):
    def test_native_invitation_capability_regressions_cannot_be_omitted(self):
        for name in ['private-mail-invite-native-nonadmin-people-capability-granted', 'private-mail-invite-native-nonadmin-worker-prepares-genuine-challenge', 'private-mail-invite-legacy-admin-binding-must-equal-author-subject', 'private-mail-invite-current-capability-revocation-refuses-callback', 'private-mail-invite-regrant-cannot-revive-permanently-quarantined-snapshot', 'private-mail-invite-nonadmin-without-native-capability-refused', 'private-mail-invite-native-default-admin-still-authorized', 'private-mail-invite-custom-policy-admin-result-matches-real-native-capability', 'private-mail-invite-capability-does-not-authorize-admin-only-welcome']:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.subTest(check=name),self.assertRaises(ValueError):
                gate.validate(data,file_digest='b'*64)

    def test_large_security_payload_regressions_are_required(self):
        for name in ['private-mail-physical-large-security-body-current-source-allowed', 'private-mail-physical-large-security-attachment-current-source-allowed', 'private-mail-physical-oversized-message-refused-without-poisoning-valid-snapshot', 'private-mail-physical-large-security-payload-still-requires-current-native-source']:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
    def test_view_policy_public_entry_proof_cannot_be_omitted(self):
        for name in ['project-view-real-api-token-edit-missing-ticket-refused-without-write', 'project-view-real-api-token-view-ticket-cannot-authorize-edit-policy', 'project-view-real-api-token-proposal-and-public-edit-commit-native-view-only']:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        for key in ('actual_api_token_conduit_path','view_ticket_cannot_edit_policy'):
            data=template();data['native']['project_execution_tests']['approved_native_view_policy'][key]=False
            with self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)

    def test_view_policy_requires_actual_native_checks_and_refuses_family_claim(self):
        for name in ['project-view-approval-does-not-override-native-self-view-validator', 'project-view-approved-transition-may-remove-approver-future-view', 'project-view-approver-current-view-does-not-require-edit', 'project-view-history-still-requires-current-native-view', 'project-view-native-record-policy-ticket-audit-commit-together', 'project-view-native-writer-scope-released']:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        for name in ('whole_family_ready','join_and_space_supported'):
            data=template();data['native']['project_execution_tests']['approved_native_view_policy'][name]=True
            with self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)

    def test_valid_schema_does_not_prove_activation_browser_or_completion(self):
        accepted=gate.validate(template(), file_digest='b'*64)
        for key in ('production_optional_features_enabled', 'deployment_proved', 'browser_proved', 'global_completion'):
            self.assertIs(accepted[key], False)

    def test_media_only_restore_cannot_claim_native_revocation_or_full_recovery(self):
        for key,value in [('media_only_external_rotation',False),
                          ('incarnation_only_rotation_verified',False),
                          ('old_native_credentials_retained_and_verified',False),
                          ('native_credential_metadata_changed',True),
                          ('old_media_rows_promoted',True),
                          ('full_disaster_recovery_certified',True),
                          ('restored_snapshot_sha256','not-a-hash')]:
            data=template();data['native']['issuer_tests'][key]=value
            with self.subTest(key=key), self.assertRaises(ValueError):
                gate.validate(data,file_digest='b'*64)
        data=template();data['native']['issuer_upgrade_tests']['migration_sha256']='0'*64
        with self.assertRaises(ValueError): gate.validate(data,file_digest='b'*64)

    def test_random_snapshot_digest_only_is_dynamic(self):
        data=template();data['native']['issuer_tests']['restored_snapshot_sha256']='f'*64
        gate.validate(data,file_digest='b'*64)
        for value in (None, True, 4, 'F'*64, 'f'*63, 'f'*65):
            mutated=template();mutated['native']['issuer_tests']['restored_snapshot_sha256']=value
            with self.subTest(value=value),self.assertRaises(ValueError):
                gate.validate(mutated,file_digest='b'*64)
        data['native']['issuer_tests']['unknown_claim']=True
        with self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)

    def test_transfer_foundation_never_certifies_approval_execution_or_dispatch(self):
        for key,value in [('storage_only',False),('version_cas_verified',False),
            ('storage_rollback_verified',False),('independent_approval_implemented',True),
            ('native_primary_switch_implemented',True),('outbox_dispatch_registered',True),
            ('production_activation',True)]:
            data=template();data['native']['transfer_foundation_tests'][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):
                gate.validate(data,file_digest='b'*64)
        for key,value in [('current_table_count',530)]:
            data=template();data['source_restore'][key]=value
            with self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)

    def test_history_metadata_scope_cannot_delete_expose_or_promote(self):
        for key,value in [('current_system_administrator_only',False),
            ('snapshot_fields_returned',True),('retention_days',364),
            ('history_deleted',True),('full_browser_acceptance',True),
            ('production_activation',True)]:
            data=template();data['native']['history_tests'][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):
                gate.validate(data,file_digest='b'*64)

    def test_partial_lifecycle_and_compound_scope_cannot_be_promoted(self):
        for section,key in [('compound_tests','full_compound_failure_matrix_complete'),
            ('compound_tests','production_activation'),('membership_lifecycle_tests','public_csrf_lifecycle_complete'),
            ('membership_lifecycle_tests','production_activation')]:
            data=template();data['native'][section][key]=True
            with self.subTest(section=section,key=key),self.assertRaises(ValueError):
                gate.validate(data,file_digest='b'*64)
        for old_worker_count in (5,7,9):
            data=template();data['worker_cleanup']['workers_registered']=old_worker_count
            with self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)

    def test_admission_claims_require_all_runtime_boundaries_and_owned_workers(self):
        for key in gate.NATIVE['target_tests']['admission_tests']:
            data=template();data['native']['target_tests']['admission_tests'][key]=False
            with self.subTest(key=key), self.assertRaises(ValueError): gate.validate(data,file_digest='b'*64)
        data=template();data['worker_cleanup']['workers_registered']=5
        with self.assertRaises(ValueError): gate.validate(data,file_digest='b'*64)


    def test_organization_successor_cannot_promote_partial_form_or_credential_claims(self):
        for section,key in [('noncredential_source_v2_tests','credential_source_downgraded'),
            ('noncredential_source_v2_tests','production_activation'),('handover_form_tests','wire_or_browser_proof'),
            ('handover_lifecycle_tests','dispatcher_registered'),('handover_lifecycle_tests','native_ui_forms_exercised')]:
            data=template();data['native'][section][key]=True
            with self.subTest(section=section,key=key),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        for section in ('noncredential_source_v2_tests','handover_form_tests','handover_lifecycle_tests','transfer_execution_tests'):
            data=template();data['native'].pop(section)
            with self.subTest(section=section),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)

    def test_http_candidate_never_claims_wire_browser_or_full_guard_completion(self):
        for path,key in [(('http_tests',),'wire_https_verified'),(('http_tests',),'browser_verified'),
            (('http_tests',),'file_guard_complete'),(('http_tests',),'production_enabled'),
            (('http_tests','file_guard_tests'),'custom_branding_verified'),
            (('http_tests','file_guard_tests'),'browser_active_content_verified'),
            (('http_tests','file_guard_tests'),'wire_https_verified')]:
            data=template();part=data['native']
            for section in path:part=part[section]
            part[key]=True
            with self.subTest(path=path,key=key),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        for value in (None,1,'x'*64,'d'*63):
            data=template();data['native']['http_tests']['file_guard_tests']['image_tests']['backend_revision_sha256']=value
            with self.subTest(value=value),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        data=template();data['native']['http_tests']['unknown_claim']=True
        with self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)

    def test_old_component_receipts_and_missing_http_obligations_are_refused(self):
        for name in gate.HTTP_REQUIRED_CHECKS:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.subTest(name=name),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        data=template();data['native'].pop('http_tests')
        with self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
    def test_private_notification_scope_requires_current_source_and_preserves_unproved_gates(self):
        for key in ('actual_native_source_and_object_query','actual_native_queue',
                    'real_business_serialization_and_legacy_quarantine'):
            data=template();data['native']['private_mail_tests'][key]=False
            with self.subTest(key=key),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        for key in ('smtp_or_delivery_workers_executed','security_challenge_runtime_matrix_complete',
                    'all_producers_runtime_complete','physical_callback_runtime_complete'):
            data=template();data['native']['private_mail_tests'][key]=True
            with self.subTest(key=key),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)

    def test_each_actual_scenario_is_mandatory_and_duplicates_refused(self):
        for name in gate.REQUIRED_CHECKS:
            with self.subTest(name=name):
                data=template(); data['checks'].remove(name); data['check_count']-=1
                with self.assertRaises(ValueError): gate.validate(data, file_digest='b'*64)
        data=template(); data['checks'].append(data['checks'][0]); data['check_count']+=1
        with self.assertRaises(ValueError): gate.validate(data, file_digest='b'*64)

    def test_failure_and_cleanup_rollback_never_accepted(self):
        for key,value in [('passed',False),('failure',{}),('cleanup_failures',[{}]),
                          ('terminal_failure_cleanup_rollbacks',1)]:
            data=template();data[key]=value
            with self.subTest(key=key), self.assertRaises(ValueError): gate.validate(data, file_digest='b'*64)
        for key,value in [('ownedSchemasRemoved',54),('ownedRuntimeUsersRemoved',1),('readerRemoved',False)]:
            data=template();data['cleanup'][key]=value
            with self.subTest(key=key), self.assertRaises(ValueError): gate.validate(data, file_digest='b'*64)
        for key,value in [('workers_registered',0),('private_directories_removed',0),
                          ('private_directories_remaining',1),('live_processes_after_cleanup',1),
                          ('owned_processes_stopped',1),('cleanup_failures',1)]:
            data=template();data['worker_cleanup'][key]=value
            with self.subTest(key=key), self.assertRaises(ValueError): gate.validate(data, file_digest='b'*64)
        for key,value in [('process_stopped',False),('private_storage_removed',False),('kind','taskqueue')]:
            data=template();data['file_service_cleanup'][key]=value
            with self.subTest(key=key), self.assertRaises(ValueError): gate.validate(data, file_digest='b'*64)

    def test_activation_scope_and_boolean_coercion_refused_recursively(self):
        for key in ('production_deployment','production_executor','production_optional_features_enabled','native_permission_axes_changed'):
            for value in (True,0,None):
                data=template();data[key]=value
                with self.subTest(key=key,value=value), self.assertRaises(ValueError): gate.validate(data, file_digest='b'*64)
        data=template();data['native']['actual_native_account_source']=1
        with self.assertRaises(ValueError): gate.validate(data, file_digest='b'*64)
        data=template();data['native']['route_tests']['browser_executed']=True
        with self.assertRaises(ValueError): gate.validate(data, file_digest='b'*64)

    def test_patch_bytes_and_full_restore_ledger_required(self):
        data=template();data['source_restore']['patch_audit']['migration_source_hashes']['phabricator:20261102.people.organization.sql']='0'*64
        with self.assertRaises(ValueError): gate.validate(data, file_digest='b'*64)
        for key,value in [('pending_patch_keys',['pending']),('current_patch_ledger_complete',False),
                          ('applied_patch_count',1562),('migrations_executed',1)]:
            data=template();data['source_restore']['patch_audit'][key]=value
            with self.subTest(key=key), self.assertRaises(ValueError): gate.validate(data, file_digest='b'*64)

    def test_raw_or_unknown_fields_refused_at_every_evidence_level(self):
        for path in ((),('native',),('native','auth_tests'),('cleanup',),('source_restore',),('source_restore','patch_audit')):
            data=template();target=data
            for key in path:target=target[key]
            target['password']='synthetic-private-canary'
            with self.subTest(path=path), self.assertRaises(ValueError): gate.validate(data, file_digest='b'*64)

    def test_unknown_runtime_and_unproved_completion_refused(self):
        for key,value in [('php_version','8.5.8'),('scope','production'),('remaining_blocked',[]),('check_count',True),
                          ('batch','native-legacy-role-sql-compatibility'),
                          ('optional_source_reader_adapter_exercised',False),('isolated_optional_features_enabled',False)]:
            data=template();data[key]=value
            with self.subTest(key=key), self.assertRaises(ValueError): gate.validate(data, file_digest='b'*64)

    def test_security_source_receipt_cannot_be_promoted_to_smtp_or_legalpad_proof(self):
        for key in ('smtp_security_matrix_complete','all_security_producer_runtime_complete','legalpad_signature_delivery_supported'):
            data=template();data['native']['private_mail_tests']['security_flow_tests'][key]=True
            with self.subTest(key=key),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        data=template();name='private-mail-no-primary-email-native-rename-commits-without-owner-notice'
        data['checks'].remove(name);data['check_count']-=1
        with self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
    def test_native_producer_race_receipt_cannot_claim_unproved_delivery_or_reactivation(self):
        for key in ('ssh_login_or_cryptographic_handshake_proved',
                    'smtp_producer_matrix_complete', 'device_ssh_producer_matrix_complete',
                    'legalpad_supported_signer_flow'):
            data=template(); data['native']['private_mail_tests']['remaining_producer_tests'][key]=True
            with self.subTest(key=key), self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        for key in ('precommit_pending', 'precommit_quarantined', 'postcompletion_allowed'):
            for case in ('commit', 'rollback', 'recipient-revoked', 'admin-revoked'):
                data=template(); sample=data['native']['private_mail_tests']['commit_race_tests']['samples'][case]
                sample[key]=not sample[key]
                with self.subTest(case=case,key=key), self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        data=template(); data['native']['private_mail_tests']['commit_race_tests']['physical_smtp_race_proved']=True
        with self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)

    def test_legalpad_native_receipt_cannot_promote_delivery_or_substitute_sequential_work(self):
        for key in ('anonymous_raw_or_third_party_adoption_supported',
                    'configured_mfa_factor_proved', 'physical_smtp_legalpad_proved'):
            data=template(); data['native']['private_mail_tests']['legalpad_tests'][key]=True
            with self.subTest(key=key), self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        for key,value in (('independent_php_processes',1),('actual_same_source_lock_waiters',1),
                          ('actual_consumption_updates',2),('sequential_repeat_substituted_for_concurrency',True),
                          ('physical_smtp_proved',True)):
            data=template(); data['native']['private_mail_tests']['legalpad_tests']['concurrency'][key]=value
            with self.subTest(key=key), self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)

    def test_actual_paired_binary_digest_is_required_and_must_match(self):
        with self.assertRaises(ValueError): gate.validate(template())
        for digest in ('c'*64, None, True):
            with self.subTest(digest=digest), self.assertRaises(ValueError):
                gate.validate(template(), file_digest=digest)

    def test_cli_duplicate_keys_and_non_json_output_fail_safely(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'receipt.json'
            binary=Path(directory)/'paired-file'; binary.write_bytes(b'synthetic gate input')
            for raw in ('{"passed":true,"passed":true}', 'PHLOG: unsafe\n'+json.dumps(template()),'{"passed":NaN}'):
                path.write_text(raw)
                result=subprocess.run([sys.executable,str(ROOT/'scripts/governance/check_native_organization.py'),'--receipt',str(path),'--file-binary',str(binary)],capture_output=True,text=True)
                self.assertNotEqual(result.returncode,0)
                self.assertEqual(result.stdout,'')
                self.assertNotIn('synthetic-private-canary',result.stderr)

    def test_all_successor_checks_are_mandatory(self):
        self.assertEqual(len(gate.NOTIFICATION_BOUNDARY_CHECKS),21)
        self.assertEqual(len(gate.REQUIRED_CHECKS),717)
        for name in gate.NOTIFICATION_BOUNDARY_CHECKS:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.subTest(name=name),self.assertRaises(ValueError):
                gate.validate(data,file_digest='b'*64)
        data=template();data['worker_cleanup']['workers_registered']=21
        data['worker_cleanup']['private_directories_removed']=21
        with self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)

    def test_business_summary_scope_counts_and_cleanup_cannot_be_upgraded(self):
        path=('private_mail_tests','business_publication_tests')
        for key,value in [('actual_child_native_mail_taskqueue_http_proved',False),
                          ('paired_gorge_queue_profile_proved',True),
                          ('manual_diagnostic_reexecution_is_daemon_retry',True),
                          ('physical_smtp_proved',True),('independent_php_workers_executed',9),
                          ('private_directories_removed',9),('private_directories_remaining',1),
                          ('live_processes_after_cleanup',1)]:
            data=template();data['native'][path[0]][path[1]][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        for invalid in (None,[], 'synthetic-private-canary'):
            data=template();data['native'][path[0]][path[1]]=invalid
            with self.subTest(invalid=invalid),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        for family,ending,values in [('task','normal',[True,0,1,4]),
                                     ('project','normal',[True,0,2]),
                                     ('task','outer-rollback',[True,1])]:
            for value in values:
                data=template();data['native'][path[0]][path[1]]['samples'][family][ending]['postcompletion_mail_envelopes']=value
                with self.subTest(family=family,value=value),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        data=template();data['native'][path[0]][path[1]]['samples']['task']['normal']['postcompletion_mail_envelopes']=3
        gate.validate(data,file_digest='b'*64)

    def test_legalpad_transitive_and_inflight_obligations_remain_native_bounded(self):
        for section,key,value in [('policy_source_tests','actual_named_effective_policy',False),
                                  ('policy_source_tests','all_policy_sources_atomically_locked',True),
                                  ('policy_source_tests','document_space_acl_invented',True),
                                  ('inflight_source_tests','last_view_to_commit_global_atomicity_proved',True)]:
            data=template();data['native']['private_mail_tests']['legalpad_tests'][section][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        for kind in ('version',):
            data=template();data['native']['private_mail_tests']['legalpad_tests']['inflight_source_tests']['actual_independent_source_mutation_cases'][kind]['actual_lock_wait']=False
            with self.subTest(kind=kind),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)

    def test_index_race_committed_facts_never_turn_deadlock_into_permission_denial(self):
        allowed=((True,False,'none','source-refused',False),
                 (True,False,'verifier','deadlock',False),
                 (False,True,'source-writer','verified',True))
        keys=('source_writer_commit_ack','source_writer_deadlock_rollback','observed_victim','service_outcome','old_code_consumed')
        for facts in allowed:
            data=template();index=data['native']['private_mail_tests']['legalpad_tests']['inflight_source_tests']['index_race'];index.update(zip(keys,facts))
            gate.validate(data,file_digest='b'*64)
        for facts in ((True,False,'none','verified',True),
                      (False,True,'source-writer','source-refused',False),
                      (True,False,'verifier','source-refused',False),
                      (1,False,'none','source-refused',False)):
            data=template();index=data['native']['private_mail_tests']['legalpad_tests']['inflight_source_tests']['index_race'];index.update(zip(keys,facts))
            with self.subTest(facts=facts),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
        for key,value in [('actual_engine_victim_proved',False),('locks_released_before_connection_close',False),
                          ('automatic_consume_retry_used',True),('controller_tested',True),('controller_http500_proved',True)]:
            data=template();data['native']['private_mail_tests']['legalpad_tests']['inflight_source_tests']['forced_verifier_victim'][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):gate.validate(data,file_digest='b'*64)
