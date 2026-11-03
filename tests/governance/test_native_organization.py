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
            'current_table_count': 534, 'restore_phase_workers_started': 0,
            'restore_phase_network_endpoints_configured': 0, 'patch_audit': {
                'source_patch_count': 1568, 'applied_patch_count': 1568, 'pending_patch_keys': [],
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
