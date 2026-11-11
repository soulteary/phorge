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


def validate(data, **kwargs):
    kwargs.setdefault('render_digest','d05250617dfa12f0b7cfc9108423b55a91ce9dd56224c96335f847deb40c22d2')
    return gate.validate(data, measurement_revision='a'*40, **kwargs)


def template():
    hashes={'phabricator:'+p: hashlib.sha256((ROOT/'resources/sql/autopatches'/p).read_bytes()).hexdigest()
        for p in gate.completion.PATCHES}
    return {'batch': 'native-organization-media-integration', 'passed': True,
        'scope': 'owned-full-native-source-synthetic-accounts', 'checks': sorted(gate.REQUIRED_CHECKS),
        'check_count': len(gate.REQUIRED_CHECKS), 'php_version': '8.5.12',
        'source_restore': {'quickstart_sha256': hashlib.sha256((ROOT/'resources/sql/quickstart.sql').read_bytes()).hexdigest(),
            'app_schema_count': 54, 'owned_schema_count': 55, 'quickstart_source_table_count': 500,
            'current_table_count': 549, 'restore_phase_workers_started': 0,
            'restore_phase_network_endpoints_configured': 0, 'patch_audit': {
                'source_patch_count': 1575, 'applied_patch_count': 1575, 'pending_patch_keys': [],
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
    def test_actual_nested_helper_terminal_summary_matches_strict_gate(self):
        # Parse the actual producer's terminal literal without including or
        # executing its SQL/process function. Only a flat scalar PHP array is
        # permitted, so this cannot execute fixture setup or an arbitrary call.
        helper = ROOT/'support/governance/native-execution/project-policy-nested-process.php'
        parser = r"""
        $src=file_get_contents($argv[1]);$pos=strrpos($src,"\n  return array(");
        if($pos===false||!preg_match('/\A\n  return (array\(.*\));\n}\n\z/s',substr($src,$pos),$m)||strlen($m[1])>4096){exit(2);}
        $tokens=array_values(array_filter(token_get_all('<?php return '.$m[1].';'),
          static fn($t)=>!(is_array($t)&&in_array($t[0],array(T_OPEN_TAG,T_WHITESPACE),true))));
        $at=0;$take=function()use(&$at,$tokens){if(!array_key_exists($at,$tokens)){exit(3);}return $tokens[$at++];};
        $kind=function($t,$type){return is_array($t)&&$t[0]===$type;};
        $string=function($t)use($kind){
          if(!$kind($t,T_CONSTANT_ENCAPSED_STRING)||!preg_match("/\\A'[a-z_]{1,128}'\\z/D",$t[1])){exit(4);}
          return substr($t[1],1,-1);
        };
        if(!$kind($take(),T_RETURN)||!$kind($take(),T_ARRAY)||$take()!=='('){exit(5);}
        $value=array();
        while(true){
          $t=$take();if($t===')'){break;}$key=$string($t);
          if(array_key_exists($key,$value)||!$kind($take(),T_DOUBLE_ARROW)){exit(6);}
          $t=$take();
          if($kind($t,T_STRING)&&in_array($t[1],array('true','false'),true)){$v=$t[1]==='true';}
          else{$v=$string($t);}
          $value[$key]=$v;$next=$take();
          if($next===')'){break;}if($next!==','){exit(7);}
        }
        if($take()!==';'||$at!==count($tokens)){exit(8);}
        echo json_encode($value,JSON_THROW_ON_ERROR);
        """
        actual = json.loads(subprocess.check_output(['php','-r',parser,str(helper)], text=True, timeout=5))
        with tempfile.TemporaryDirectory(prefix='native-summary-parser-') as folder:
            candidate = Path(folder)/'synthetic.php'
            marker = Path(folder)/'owned_marker'
            marker.write_text('must remain')
            for expression in [
                "array('x'=>('unlink')('"+str(marker)+"'))",
                "array('x'=>true,'x'=>false)",
                "array('x'=>array('y'=>true))",
            ]:
                with self.subTest(refused_literal=expression.split('(', 1)[0]):
                    candidate.write_text('<?php\nfunction synthetic() {\n  return '+expression+';\n}\n')
                    refused = subprocess.run(['php','-r',parser,str(candidate)], capture_output=True, timeout=5)
                    self.assertNotEqual(0, refused.returncode)
                    self.assertTrue(marker.is_file(), 'literal parsing must never execute a dynamic call')
        data = template()
        negative = data['native']['project_execution_tests']['negative_cases']
        negative['nested_unknown'] = actual
        self.assertEqual(936, validate(data, file_digest='b'*64)['check_count'])
        self.assertEqual(7, len(actual))
        for key, value in actual.items():
            with self.subTest(field=key):
                bad = copy.deepcopy(data)
                bad['native']['project_execution_tests']['negative_cases']['nested_unknown'][key] = (
                    not value if isinstance(value, bool) else 'unmeasured-or-all-worker-tables')
                with self.assertRaises(ValueError):
                    validate(bad, file_digest='b'*64)
        missing = copy.deepcopy(data)
        del missing['native']['project_execution_tests']['negative_cases']['nested_unknown']
        with self.assertRaises(ValueError):
            validate(missing, file_digest='b'*64)

    def test_policy_unknown_nested_and_noop_evidence_cannot_be_omitted(self):
        obligations = {
            'project-approval-actual-native-nested-update-error-quarantines-without-savepoint-guess',
            'project-approval-native-nested-error-isolated-uncommitted-while-child-quarantined',
            'project-approval-native-nested-error-child-terminal-transaction-gone-and-no-commit',
            'project-approval-noop-replay-preserves-consumed-ticket-and-owned-frame',
        }
        self.assertTrue(obligations <= gate.REQUIRED_CHECKS)
        for name in obligations:
            with self.subTest(name=name):
                data = template()
                data['checks'].remove(name)
                data['check_count'] -= 1
                with self.assertRaises(ValueError):
                    validate(data, file_digest='b'*64)


    def test_governance_notice_cannot_drop_sources_attempt_checks_or_promote_transport(self):
        self.assertEqual(36,len(gate.GOVERNANCE_NOTICE_CHECKS))
        for name in gate.GOVERNANCE_NOTICE_CHECKS:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.subTest(name=name),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for key in gate.NATIVE['private_mail_tests']['governance_notice_tests']:
            data=template();row=data['native']['private_mail_tests']['governance_notice_tests'];row[key]=not row[key]
            with self.subTest(key=key),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();data['worker_cleanup']['workers_registered']=38
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_default_edit_controller_route_and_post_filter_scope_are_required(self):
        for name in gate.VIEW_EDIT_ROUTE_CHECKS:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for key in gate.NATIVE['project_execution_tests']['native_default_policy_edit_form']:
            data=template();row=data['native']['project_execution_tests']['native_default_policy_edit_form']
            row[key]=not row[key]
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_actual_view_form_checks_and_scope_cannot_be_omitted_or_upgraded(self):
        for name in gate.VIEW_FORM_CHECKS:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for key in gate.NATIVE['project_execution_tests']['native_policy_proposal_form']:
            data=template();summary=data['native']['project_execution_tests']['native_policy_proposal_form']
            summary[key]=not summary[key]
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
    def test_real_project_create_visibility_evidence_is_mandatory(self):
        for ending in ('normal','outer-commit','outer-rollback'):
            name='private-business-project-'+ending+'-actual-native-create-envelope-visible-initial-fields-hidden'
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.subTest(ending=ending),self.assertRaises(ValueError):
                validate(data,file_digest='b'*64)
        data=template();data['native']['private_mail_tests']['business_publication_tests']['actual_native_project_create_envelope_verified']=False
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_api_capture_cannot_omit_native_sources_or_claim_recovery_permission(self):
        self.assertEqual(15,len(gate.RECOVERY_API_CHECKS))
        self.assertEqual(len(gate.REQUIRED_CHECKS),validate(template(),file_digest='b'*64)['check_count'])
        for name in gate.RECOVERY_API_CHECKS:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.subTest(obligation=name),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for name in ('api_family_complete','all_sources_complete','can_execute','source_writes_by_capture'):
            data=template();data['native']['recovery_api_capture_tests'][name]=True
            with self.subTest(claim=name),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();data['native']['recovery_api_capture_tests']['actual_legacy_certificate_authentication']=1
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)


    def test_recovery_web_partial_observations_cannot_approve_credentials(self):
        self.assertEqual(13,len(gate.RECOVERY_WEB_CHECKS))
        for obligation in gate.RECOVERY_WEB_CHECKS:
            data=template();data['checks'].remove(obligation);data['check_count']-=1
            with self.subTest(obligation=obligation), self.assertRaises(ValueError):
                validate(data,file_digest='b'*64)
        for key in ('actual_password_controller_or_oauth_complete','web_family_complete',
                    'all_sources_complete','can_execute','source_writes_by_capture'):
            data=template();data['native']['recovery_web_capture_tests'][key]=True
            with self.subTest(key=key), self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();data['native']['recovery_web_capture_tests']['actual_native_logout']=1
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)



    def test_recovery_account_requires_all_real_source_obligations(self):
        self.assertEqual(10, len(gate.RECOVERY_ACCOUNT_CHECKS))
        for obligation in gate.RECOVERY_ACCOUNT_CHECKS:
            data=template();data['checks'].remove(obligation);data['check_count']-=1
            with self.subTest(obligation=obligation), self.assertRaises(ValueError):
                validate(data,file_digest='b'*64)

    def test_recovery_account_capture_cannot_self_attest_full_recovery(self):
        for key,value in [('actual_account_adapter',False),('actual_account_adapter',1),
                          ('source_writes_by_capture',True),('all_sources_complete',True),
                          ('can_execute',True),('other_twelve_families_complete',True)]:
            data=template();data['native']['recovery_account_capture_tests'][key]=value
            with self.subTest(key=key,value=value), self.assertRaises(ValueError):
                validate(data,file_digest='b'*64)
        data=template();data['native']['recovery_account_capture_tests']['permit']='complete'
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_native_invitation_capability_regressions_cannot_be_omitted(self):
        for name in ['private-mail-invite-native-nonadmin-people-capability-granted', 'private-mail-invite-native-nonadmin-worker-prepares-genuine-challenge', 'private-mail-invite-legacy-admin-binding-must-equal-author-subject', 'private-mail-invite-current-capability-revocation-refuses-callback', 'private-mail-invite-regrant-cannot-revive-permanently-quarantined-snapshot', 'private-mail-invite-nonadmin-without-native-capability-refused', 'private-mail-invite-native-default-admin-still-authorized', 'private-mail-invite-custom-policy-admin-result-matches-real-native-capability', 'private-mail-invite-capability-does-not-authorize-admin-only-welcome']:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.subTest(check=name),self.assertRaises(ValueError):
                validate(data,file_digest='b'*64)

    def test_large_security_payload_regressions_are_required(self):
        for name in ['private-mail-physical-large-security-body-current-source-allowed', 'private-mail-physical-large-security-attachment-current-source-allowed', 'private-mail-physical-oversized-message-refused-without-poisoning-valid-snapshot', 'private-mail-physical-large-security-payload-still-requires-current-native-source']:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
    def test_view_policy_public_entry_proof_cannot_be_omitted(self):
        for name in ['project-view-real-api-token-edit-missing-ticket-refused-without-write', 'project-view-real-api-token-view-ticket-cannot-authorize-edit-policy', 'project-view-real-api-token-proposal-and-public-edit-commit-native-view-only']:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for key in ('actual_api_token_conduit_path','view_ticket_cannot_edit_policy'):
            data=template();data['native']['project_execution_tests']['approved_native_view_policy'][key]=False
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_view_policy_requires_actual_native_checks_and_refuses_family_claim(self):
        for name in ['project-view-approval-does-not-override-native-self-view-validator', 'project-view-approved-transition-may-remove-approver-future-view', 'project-view-approver-current-view-does-not-require-edit', 'project-view-history-still-requires-current-native-view', 'project-view-native-record-policy-ticket-audit-commit-together', 'project-view-native-writer-scope-released']:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for name in ('whole_family_ready','join_and_space_supported'):
            data=template();data['native']['project_execution_tests']['approved_native_view_policy'][name]=True
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_valid_schema_does_not_prove_activation_browser_or_completion(self):
        accepted=validate(template(), file_digest='b'*64)
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
                validate(data,file_digest='b'*64)
        data=template();data['native']['issuer_upgrade_tests']['migration_sha256']='0'*64
        with self.assertRaises(ValueError): validate(data,file_digest='b'*64)

    def test_random_snapshot_digest_only_is_dynamic(self):
        data=template();data['native']['issuer_tests']['restored_snapshot_sha256']='f'*64
        validate(data,file_digest='b'*64)
        for value in (None, True, 4, 'F'*64, 'f'*63, 'f'*65):
            mutated=template();mutated['native']['issuer_tests']['restored_snapshot_sha256']=value
            with self.subTest(value=value),self.assertRaises(ValueError):
                validate(mutated,file_digest='b'*64)
        data['native']['issuer_tests']['unknown_claim']=True
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_transfer_foundation_never_certifies_approval_execution_or_dispatch(self):
        for key,value in [('storage_only',False),('version_cas_verified',False),
            ('storage_rollback_verified',False),('independent_approval_implemented',True),
            ('native_primary_switch_implemented',True),('outbox_dispatch_registered',True),
            ('production_activation',True)]:
            data=template();data['native']['transfer_foundation_tests'][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):
                validate(data,file_digest='b'*64)
        for key,value in [('current_table_count',530)]:
            data=template();data['source_restore'][key]=value
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_history_metadata_scope_cannot_delete_expose_or_promote(self):
        for key,value in [('current_system_administrator_only',False),
            ('snapshot_fields_returned',True),('retention_days',364),
            ('history_deleted',True),('full_browser_acceptance',True),
            ('production_activation',True)]:
            data=template();data['native']['history_tests'][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):
                validate(data,file_digest='b'*64)

    def test_partial_lifecycle_and_compound_scope_cannot_be_promoted(self):
        for section,key in [('compound_tests','full_compound_failure_matrix_complete'),
            ('compound_tests','production_activation'),('membership_lifecycle_tests','public_csrf_lifecycle_complete'),
            ('membership_lifecycle_tests','production_activation')]:
            data=template();data['native'][section][key]=True
            with self.subTest(section=section,key=key),self.assertRaises(ValueError):
                validate(data,file_digest='b'*64)
        for old_worker_count in (5,7,9):
            data=template();data['worker_cleanup']['workers_registered']=old_worker_count
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_admission_claims_require_all_runtime_boundaries_and_owned_workers(self):
        for key in gate.NATIVE['target_tests']['admission_tests']:
            data=template();data['native']['target_tests']['admission_tests'][key]=False
            with self.subTest(key=key), self.assertRaises(ValueError): validate(data,file_digest='b'*64)
        data=template();data['worker_cleanup']['workers_registered']=5
        with self.assertRaises(ValueError): validate(data,file_digest='b'*64)


    def test_organization_successor_cannot_promote_partial_form_or_credential_claims(self):
        for section,key in [('noncredential_source_v2_tests','credential_source_downgraded'),
            ('noncredential_source_v2_tests','production_activation'),('handover_form_tests','wire_or_browser_proof'),
            ('handover_lifecycle_tests','dispatcher_registered'),('handover_lifecycle_tests','native_ui_forms_exercised')]:
            data=template();data['native'][section][key]=True
            with self.subTest(section=section,key=key),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for section in ('noncredential_source_v2_tests','handover_form_tests','handover_lifecycle_tests','transfer_execution_tests'):
            data=template();data['native'].pop(section)
            with self.subTest(section=section),self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_http_candidate_never_claims_wire_browser_or_full_guard_completion(self):
        for path,key in [(('http_tests',),'wire_https_verified'),(('http_tests',),'browser_verified'),
            (('http_tests',),'file_guard_complete'),(('http_tests',),'production_enabled'),
            (('http_tests','file_guard_tests'),'custom_branding_verified'),
            (('http_tests','file_guard_tests'),'browser_active_content_verified'),
            (('http_tests','file_guard_tests'),'wire_https_verified')]:
            data=template();part=data['native']
            for section in path:part=part[section]
            part[key]=True
            with self.subTest(path=path,key=key),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for value in (None,1,'x'*64,'d'*63):
            data=template();data['native']['http_tests']['file_guard_tests']['image_tests']['backend_revision_sha256']=value
            with self.subTest(value=value),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();data['native']['http_tests']['unknown_claim']=True
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_old_component_receipts_and_missing_http_obligations_are_refused(self):
        for name in gate.HTTP_REQUIRED_CHECKS:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.subTest(name=name),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();data['native'].pop('http_tests')
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
    def test_private_notification_scope_requires_current_source_and_preserves_unproved_gates(self):
        for key in ('actual_native_source_and_object_query','actual_native_queue',
                    'real_business_serialization_and_legacy_quarantine'):
            data=template();data['native']['private_mail_tests'][key]=False
            with self.subTest(key=key),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for key in ('smtp_or_delivery_workers_executed','security_challenge_runtime_matrix_complete',
                    'all_producers_runtime_complete','physical_callback_runtime_complete'):
            data=template();data['native']['private_mail_tests'][key]=True
            with self.subTest(key=key),self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_each_actual_scenario_is_mandatory_and_duplicates_refused(self):
        for name in gate.REQUIRED_CHECKS:
            with self.subTest(name=name):
                data=template(); data['checks'].remove(name); data['check_count']-=1
                with self.assertRaises(ValueError): validate(data, file_digest='b'*64)
        data=template(); data['checks'].append(data['checks'][0]); data['check_count']+=1
        with self.assertRaises(ValueError): validate(data, file_digest='b'*64)

    def test_failure_and_cleanup_rollback_never_accepted(self):
        for key,value in [('passed',False),('failure',{}),('cleanup_failures',[{}]),
                          ('terminal_failure_cleanup_rollbacks',1)]:
            data=template();data[key]=value
            with self.subTest(key=key), self.assertRaises(ValueError): validate(data, file_digest='b'*64)
        for key,value in [('ownedSchemasRemoved',54),('ownedRuntimeUsersRemoved',1),('readerRemoved',False)]:
            data=template();data['cleanup'][key]=value
            with self.subTest(key=key), self.assertRaises(ValueError): validate(data, file_digest='b'*64)
        for key,value in [('workers_registered',0),('private_directories_removed',0),
                          ('private_directories_remaining',1),('live_processes_after_cleanup',1),
                          ('owned_processes_stopped',1),('cleanup_failures',1)]:
            data=template();data['worker_cleanup'][key]=value
            with self.subTest(key=key), self.assertRaises(ValueError): validate(data, file_digest='b'*64)
        for key,value in [('process_stopped',False),('private_storage_removed',False),('kind','taskqueue')]:
            data=template();data['file_service_cleanup'][key]=value
            with self.subTest(key=key), self.assertRaises(ValueError): validate(data, file_digest='b'*64)

    def test_activation_scope_and_boolean_coercion_refused_recursively(self):
        for key in ('production_deployment','production_executor','production_optional_features_enabled','native_permission_axes_changed'):
            for value in (True,0,None):
                data=template();data[key]=value
                with self.subTest(key=key,value=value), self.assertRaises(ValueError): validate(data, file_digest='b'*64)
        data=template();data['native']['actual_native_account_source']=1
        with self.assertRaises(ValueError): validate(data, file_digest='b'*64)
        data=template();data['native']['route_tests']['browser_executed']=True
        with self.assertRaises(ValueError): validate(data, file_digest='b'*64)

    def test_patch_bytes_and_full_restore_ledger_required(self):
        data=template();data['source_restore']['patch_audit']['migration_source_hashes']['phabricator:20261102.people.organization.sql']='0'*64
        with self.assertRaises(ValueError): validate(data, file_digest='b'*64)
        for key,value in [('pending_patch_keys',['pending']),('current_patch_ledger_complete',False),
                          ('applied_patch_count',1562),('migrations_executed',1)]:
            data=template();data['source_restore']['patch_audit'][key]=value
            with self.subTest(key=key), self.assertRaises(ValueError): validate(data, file_digest='b'*64)

    def test_current_recovery_restore_profile_refuses_old_counts_and_missing_migration(self):
        self.assertEqual(936, validate(template(), file_digest='b'*64)['check_count'])
        for value in (540, True, '543', 544):
            data=template();data['source_restore']['current_table_count']=value
            with self.subTest(table_count=value), self.assertRaises(ValueError):
                validate(data, file_digest='b'*64)
        for value in (1571, True, '1575', 1574, 1576):
            data=template();audit=data['source_restore']['patch_audit']
            audit['source_patch_count']=value;audit['applied_patch_count']=value
            with self.subTest(patch_count=value), self.assertRaises(ValueError):
                validate(data, file_digest='b'*64)
        name='phabricator:20261104.people.recoveryrestore.sql'
        self.assertIn(name, template()['source_restore']['patch_audit']['migration_source_hashes'])
        for mutation in ('missing-source', 'bad-source-hash', 'old-executed-count'):
            data=template();audit=data['source_restore']['patch_audit']
            if mutation=='missing-source':del audit['migration_source_hashes'][name]
            elif mutation=='bad-source-hash':audit['migration_source_hashes'][name]='0'*64
            else:audit['migrations_executed']=33
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                validate(data, file_digest='b'*64)

    def test_raw_or_unknown_fields_refused_at_every_evidence_level(self):
        for path in ((),('native',),('native','auth_tests'),('cleanup',),('source_restore',),('source_restore','patch_audit')):
            data=template();target=data
            for key in path:target=target[key]
            target['password']='synthetic-private-canary'
            with self.subTest(path=path), self.assertRaises(ValueError): validate(data, file_digest='b'*64)

    def test_unknown_runtime_and_unproved_completion_refused(self):
        for key,value in [('php_version','8.5.8'),('scope','production'),('remaining_blocked',[]),('check_count',True),
                          ('batch','native-legacy-role-sql-compatibility'),
                          ('optional_source_reader_adapter_exercised',False),('isolated_optional_features_enabled',False)]:
            data=template();data[key]=value
            with self.subTest(key=key), self.assertRaises(ValueError): validate(data, file_digest='b'*64)

    def test_security_source_receipt_cannot_be_promoted_to_smtp_or_legalpad_proof(self):
        for key in ('smtp_security_matrix_complete','all_security_producer_runtime_complete','legalpad_signature_delivery_supported'):
            data=template();data['native']['private_mail_tests']['security_flow_tests'][key]=True
            with self.subTest(key=key),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();name='private-mail-no-primary-email-native-rename-commits-without-owner-notice'
        data['checks'].remove(name);data['check_count']-=1
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
    def test_native_producer_race_receipt_cannot_claim_unproved_delivery_or_reactivation(self):
        for key in ('ssh_login_or_cryptographic_handshake_proved',
                    'smtp_producer_matrix_complete', 'device_ssh_producer_matrix_complete',
                    'legalpad_supported_signer_flow'):
            data=template(); data['native']['private_mail_tests']['remaining_producer_tests'][key]=True
            with self.subTest(key=key), self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for key in ('precommit_pending', 'precommit_quarantined', 'postcompletion_allowed'):
            for case in ('commit', 'rollback', 'recipient-revoked', 'admin-revoked'):
                data=template(); sample=data['native']['private_mail_tests']['commit_race_tests']['samples'][case]
                sample[key]=not sample[key]
                with self.subTest(case=case,key=key), self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template(); data['native']['private_mail_tests']['commit_race_tests']['physical_smtp_race_proved']=True
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_legalpad_native_receipt_cannot_promote_delivery_or_substitute_sequential_work(self):
        for key in ('anonymous_raw_or_third_party_adoption_supported',
                    'configured_mfa_factor_proved', 'physical_smtp_legalpad_proved'):
            data=template(); data['native']['private_mail_tests']['legalpad_tests'][key]=True
            with self.subTest(key=key), self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for key,value in (('independent_php_processes',1),('actual_same_source_lock_waiters',1),
                          ('actual_consumption_updates',2),('sequential_repeat_substituted_for_concurrency',True),
                          ('physical_smtp_proved',True)):
            data=template(); data['native']['private_mail_tests']['legalpad_tests']['concurrency'][key]=value
            with self.subTest(key=key), self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_actual_paired_binary_digest_is_required_and_must_match(self):
        with self.assertRaises(ValueError): validate(template())
        for digest in ('c'*64, None, True):
            with self.subTest(digest=digest), self.assertRaises(ValueError):
                validate(template(), file_digest=digest)

    def test_render_input_is_mandatory_and_architecture_digest_must_match(self):
        with self.assertRaises(ValueError):gate.validate(template(),file_digest='b'*64,measurement_revision='a'*40)
        for value in (None,True,'wrong','c'*64):
            with self.subTest(value=value),self.assertRaises(ValueError):validate(template(),file_digest='b'*64,render_digest=value)
        data=template();data['native']['private_mail_tests']['wiki_content_publication_tests']['render_cleanup']['binary_sha256']='c'*64
        self.assertTrue(validate(data,file_digest='b'*64,render_digest='c'*64)['schema_valid'])
        data['native']['private_mail_tests']['wiki_content_publication_tests']['render_cleanup']['source_revision']='f'*40
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64,render_digest='c'*64)

    def test_cli_duplicate_keys_and_non_json_output_fail_safely(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'receipt.json'
            binary=Path(directory)/'paired-file'; binary.write_bytes(b'synthetic gate input')
            for raw in ('{"passed":true,"passed":true}', 'PHLOG: unsafe\n'+json.dumps(template()),'{"passed":NaN}'):
                path.write_text(raw)
                result=subprocess.run([sys.executable,str(ROOT/'scripts/governance/check_native_organization.py'),'--receipt',str(path),'--file-binary',str(binary),'--render-binary',str(binary),'--measurement-source-revision','a'*40],capture_output=True,text=True)
                self.assertNotEqual(result.returncode,0)
                self.assertEqual(result.stdout,'')
                self.assertNotIn('synthetic-private-canary',result.stderr)

    def test_wiki_content_profile_requires_all_checks_and_actual_boundaries(self):
        self.assertEqual(16,len(gate.WIKI_CONTENT_CHECKS))
        for name in gate.WIKI_CONTENT_CHECKS:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.subTest(name=name),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for key in ('actual_existing_wiki_content_editor','real_before_commit_execute_race_proved','move_mail_claimed','SMTP_proved'):
            data=template();summary=data['native']['private_mail_tests']['wiki_content_publication_tests'];summary[key]=not summary[key]
            with self.subTest(key=key),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();data['native']['private_mail_tests']['wiki_content_publication_tests']['private_directories_removed']=4
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_wiki_real_renderer_and_sixth_missing_service_worker_are_required(self):
        for key,value in [('actual_paired55_prose_diff',False),('missing_render_transient_no_mail_proved',False),
                          ('independent_php_workers_executed',5),('private_directories_removed',5)]:
            data=template();data['native']['private_mail_tests']['wiki_content_publication_tests'][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for key,value in [('binary_sha256','0'*64),('process_stopped',False),('private_storage_removed',False)]:
            data=template();data['native']['private_mail_tests']['wiki_content_publication_tests']['render_cleanup'][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_all_successor_checks_are_mandatory(self):
        self.assertEqual(len(gate.NOTIFICATION_BOUNDARY_CHECKS),21)
        self.assertEqual(len(gate.REQUIRED_CHECKS),936)
        for name in gate.NOTIFICATION_BOUNDARY_CHECKS:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.subTest(name=name),self.assertRaises(ValueError):
                validate(data,file_digest='b'*64)
        data=template();data['worker_cleanup']['workers_registered']=21
        data['worker_cleanup']['private_directories_removed']=21
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_gorge_native_project_create_visibility_is_required(self):
        for name in gate.BUSINESS_GORGE_CREATE_CHECKS:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.subTest(name=name),self.assertRaises(ValueError):
                validate(data,file_digest='b'*64)
        data=template();data['native']['private_mail_tests']['business_gorge_publication_tests']['actual_native_project_create_envelope_verified']=False
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_gorge_publication_requires_real_lifecycle_and_bounded_scope(self):
        for name in gate.BUSINESS_GORGE_CHECKS:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.subTest(name=name),self.assertRaises(ValueError):
                validate(data,file_digest='b'*64)
        for key,value in [('actual_native_editor_http_enqueue',False),
                          ('actual_service_leases_and_fenced_outcomes',False),
                          ('actual_native_conduit_handler',False),
                          ('worker_execute_http_gateway_proved',True),
                          ('gorge_worker_daemon_proved',True),
                          ('permanent_source_denial_automatically_retried',True),
                          ('physical_smtp_proved',True),('private_directories_removed',9)]:
            data=template();data['native']['private_mail_tests']['business_gorge_publication_tests'][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):
                validate(data,file_digest='b'*64)
        for count in (True,0,1,4):
            data=template();data['native']['private_mail_tests']['business_gorge_publication_tests']['samples']['task']['normal']['mail_envelopes']=count
            with self.subTest(count=count),self.assertRaises(ValueError):
                validate(data,file_digest='b'*64)

    def test_business_summary_scope_counts_and_cleanup_cannot_be_upgraded(self):
        path=('private_mail_tests','business_publication_tests')
        for key,value in [('actual_child_native_mail_taskqueue_http_proved',False),
                          ('paired_gorge_queue_profile_proved',True),
                          ('manual_diagnostic_reexecution_is_daemon_retry',True),
                          ('physical_smtp_proved',True),('independent_php_workers_executed',9),
                          ('private_directories_removed',9),('private_directories_remaining',1),
                          ('live_processes_after_cleanup',1)]:
            data=template();data['native'][path[0]][path[1]][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for invalid in (None,[], 'synthetic-private-canary'):
            data=template();data['native'][path[0]][path[1]]=invalid
            with self.subTest(invalid=invalid),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for family,ending,values in [('task','normal',[True,0,1,4]),
                                     ('project','normal',[True,0,2]),
                                     ('task','outer-rollback',[True,1])]:
            for value in values:
                data=template();data['native'][path[0]][path[1]]['samples'][family][ending]['postcompletion_mail_envelopes']=value
                with self.subTest(family=family,value=value),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();data['native'][path[0]][path[1]]['samples']['task']['normal']['postcompletion_mail_envelopes']=3
        validate(data,file_digest='b'*64)

    def test_legalpad_transitive_and_inflight_obligations_remain_native_bounded(self):
        for section,key,value in [('policy_source_tests','actual_named_effective_policy',False),
                                  ('policy_source_tests','all_policy_sources_atomically_locked',True),
                                  ('policy_source_tests','document_space_acl_invented',True),
                                  ('inflight_source_tests','last_view_to_commit_global_atomicity_proved',True)]:
            data=template();data['native']['private_mail_tests']['legalpad_tests'][section][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for kind in ('version',):
            data=template();data['native']['private_mail_tests']['legalpad_tests']['inflight_source_tests']['actual_independent_source_mutation_cases'][kind]['actual_lock_wait']=False
            with self.subTest(kind=kind),self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_index_race_committed_facts_never_turn_deadlock_into_permission_denial(self):
        allowed=((True,False,'none','source-refused',False),
                 (True,False,'verifier','deadlock',False),
                 (False,True,'source-writer','verified',True))
        keys=('source_writer_commit_ack','source_writer_deadlock_rollback','observed_victim','service_outcome','old_code_consumed')
        for facts in allowed:
            data=template();index=data['native']['private_mail_tests']['legalpad_tests']['inflight_source_tests']['index_race'];index.update(zip(keys,facts))
            validate(data,file_digest='b'*64)
        for facts in ((True,False,'none','verified',True),
                      (False,True,'source-writer','source-refused',False),
                      (True,False,'verifier','source-refused',False),
                      (1,False,'none','source-refused',False)):
            data=template();index=data['native']['private_mail_tests']['legalpad_tests']['inflight_source_tests']['index_race'];index.update(zip(keys,facts))
            with self.subTest(facts=facts),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for key,value in [('actual_engine_victim_proved',False),('locks_released_before_connection_close',False),
                          ('automatic_consume_retry_used',True),('controller_tested',True),('controller_http500_proved',True)]:
            data=template();data['native']['private_mail_tests']['legalpad_tests']['inflight_source_tests']['forced_verifier_victim'][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):validate(data,file_digest='b'*64)


class NativePolicyErrorRouteGateTest(unittest.TestCase):
    def test_expected_controller_boundary_checks_are_mandatory(self):
        self.assertEqual(7,len(gate.POLICY_ERROR_ROUTE_CHECKS))
        validate(template(),file_digest='b'*64)
        for check in gate.POLICY_ERROR_ROUTE_CHECKS:
            data=template();data['checks'].remove(check);data['check_count']-=1
            with self.subTest(check=check),self.assertRaises(ValueError):validate(data,file_digest='b'*64)


class NativeMeasurementGateTest(unittest.TestCase):
    def test_missing_producer_checks_or_workers_cannot_pass(self):
        self.assertEqual(len(gate.REQUIRED_CHECKS),936)
        for check in gate.MEASUREMENT_CHECKS:
            data=template();data['checks'].remove(check);data['check_count']-=1
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();data['worker_cleanup']['workers_registered']=19
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_source_clock_and_scope_promotions_cannot_pass(self):
        for key,value in [('HTTP_proved',True),('SLA_proved',True),('actual_independent_query_processes',True)]:
            data=template();data['native']['revocation_measurement_tests'][key]=value
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();data['native']['revocation_measurement_tests']['event']['source_revision']='f'*40
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();data['native']['revocation_measurement_tests']['event']['attempts'][1]['boot_id_sha256']='f'*64
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();data['native']['revocation_measurement_tests']['event']['attempts'][0]['outcome']='denied'
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        with self.assertRaises(ValueError):gate.validate(template(),file_digest='b'*64,render_digest='d05250617dfa12f0b7cfc9108423b55a91ce9dd56224c96335f847deb40c22d2')


class NativeTokenMeasurementGateTest(unittest.TestCase):
    def test_token_profile_obligations_and_three_workers_are_mandatory(self):
        self.assertEqual(len(gate.API_TOKEN_MEASUREMENT_CHECKS),5)
        for check in gate.API_TOKEN_MEASUREMENT_CHECKS:
            data=template();data['checks'].remove(check);data['check_count']-=1
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();data['worker_cleanup']['workers_registered']=22
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_token_scope_source_and_unknown_auth_are_not_denial_proof(self):
        for key,value in [('HTTP_proved',True),('native_transaction_record_available',True),('token_rows_deleted',True)]:
            data=template();data['native']['api_token_revocation_measurement_tests'][key]=value
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();event=data['native']['api_token_revocation_measurement_tests']['event'];event['attempts'][1]['outcome']='unknown'
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();data['native']['api_token_revocation_measurement_tests']['event']['producer_sha256']='f'*64
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

    def test_stop_storage_evidence_cannot_grant_recovery_or_hide_missing_checks(self):
        ids={v for v in gate.REQUIRED_CHECKS if v.startswith('recovery-stop-')} - gate.STOP_BOUNDARY_CHECKS
        self.assertEqual(15,len(ids))
        for check in ids:
            data=template();data['checks'].remove(check);data['check_count']=len(data['checks'])
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for key,value in [('actual_audit_failure_rollback',False),('emergency_disable_depends_on_ledger',True),
                          ('recovery_authorized',True),('concurrent_writer_proof',True),('production_entry_registered',True)]:
            data=template();data['native']['recovery_stop_tests'][key]=value
            with self.assertRaises(ValueError):validate(data,file_digest='b'*64)


class StopBoundaryEvidenceTest(unittest.TestCase):
    def test_only_complete_bound_fixture_obligations_can_satisfy_summary(self):
        self.assertEqual(15,len(gate.STOP_BOUNDARY_CHECKS))
        self.assertEqual(936,validate(template(),file_digest='b'*64)['check_count'])
        for name in gate.STOP_BOUNDARY_CHECKS:
            data=template();data['checks'].remove(name);data['check_count']-=1
            with self.subTest(name=name),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        for key,value in [('two_session_same_operation_proof',False),('recovery_authorized',True),('all_sources_complete',True)]:
            data=template();data['native']['recovery_stop_boundary_tests'][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):validate(data,file_digest='b'*64)
        data=template();data['worker_cleanup']['workers_registered']=25
        with self.assertRaises(ValueError):validate(data,file_digest='b'*64)

class NativeHTTPMeasurementGateTest(unittest.TestCase):
    def test_six_real_HTTP_obligations_and_local_cleanup_are_required(self):
        self.assertEqual(6,len(gate.HTTP_MEASUREMENT_CHECKS))
        for name in gate.HTTP_MEASUREMENT_CHECKS:
            value=template();value['checks'].remove(name);value['check_count']-=1
            with self.subTest(name=name),self.assertRaises(ValueError):validate(value,file_digest='b'*64)
        for key,item in [('stopped',2),('directories_removed',True),('remaining',1),('live',1)]:
            value=template();value['native']['conduit_HTTP_revocation_measurement_tests']['server_cleanup'][key]=item
            with self.subTest(key=key),self.assertRaises(ValueError):validate(value,file_digest='b'*64)

    def test_unknown_HTTP_or_missing_router_source_cannot_prove_denial(self):
        for key,item in [('gateway_proved',True),('HTTP_proved',False),('actual_Conduit_HTTP_requests',True),('router_sha256','f'*64)]:
            value=template();value['native']['conduit_HTTP_revocation_measurement_tests'][key]=item
            with self.subTest(key=key),self.assertRaises(ValueError):validate(value,file_digest='b'*64)
        value=template();value['native']['conduit_HTTP_revocation_measurement_tests']['event']['attempts'][1]['outcome']='unknown'
        with self.assertRaises(ValueError):validate(value,file_digest='b'*64)


class RecoveryNativeProducerGateTest(unittest.TestCase):
    def test_selected_producer_checks_and_non_authority_are_mandatory(self):
        self.assertEqual(20,len(gate.RECOVERY_NATIVE_PRODUCER_CHECKS))
        self.assertEqual(936,validate(template(),file_digest='b'*64)['check_count'])
        for name in gate.RECOVERY_NATIVE_PRODUCER_CHECKS:
            value=template();value['checks'].remove(name);value['check_count']-=1
            with self.subTest(name=name),self.assertRaises(ValueError):validate(value,file_digest='b'*64)
        for key in ('restore_service_review_profile_proved','all_13_source_families_complete','can_execute'):
            value=template();value['native']['recovery_native_producer_tests'][key]=True
            with self.subTest(key=key),self.assertRaises(ValueError):validate(value,file_digest='b'*64)
        for key in ('original_covering_share_positive_control','actual_primary_user_record_fence'):
            value=template();value['native']['recovery_native_producer_tests']['sessions'][key]=False
            with self.subTest(key=key),self.assertRaises(ValueError):validate(value,file_digest='b'*64)
        value=template();value['worker_cleanup']['workers_registered']=38
        with self.assertRaises(ValueError):validate(value,file_digest='b'*64)


# Native policy fixture source views: pure byte projections, never execution.
import ast as _policy_ast
from unittest.mock import patch as _policy_patch
sys.path.insert(0, str(ROOT/'scripts/governance'))
import recovery_conduit_source_delta as _policy_delta
import check_private_calendar_reminders as _policy_calendar
import check_recovery_assessment_credentials_v2 as _policy_assessment
import check_recovery_file_parent_policy as _policy_fileparent

_POLICY_DRIVER = 'support/governance/native-organization/test-native-organization-mysql.php'
_POLICY_CURRENT = 'f1c157e44a1eeca49529929f099fb5c57bcbbddc'
_POLICY_PRIOR = 'b93916d8a2beb4a9ab28ea1769692c0839cfc60b'
_POLICY_HISTORICAL = '20b138b4cd9a2cf61522613b4a8e5e21eca7341b'
_POLICY_CALENDAR_MAIN = 'e804125e8ad0b716cbbbba59818fb140691bf4f0'
_POLICY_CONDUIT_BASE = 'c496e2cf0ae25fb3e5153c6a1070eb53cf56b504'


def _policy_git_bytes(ref, path=_POLICY_DRIVER):
    return subprocess.check_output(['/usr/bin/git', '--no-replace-objects', '-C', str(ROOT), 'show', ref+':'+path])


def _policy_literal(raw, name):
    return _policy_ast.literal_eval(next(n.value for n in _policy_ast.parse(raw).body
                                if isinstance(n, _policy_ast.Assign) and isinstance(n.targets[0], _policy_ast.Name)
                                and n.targets[0].id == name))


class NativePolicyDriverSourceViewTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.current = _policy_git_bytes(_POLICY_CURRENT)
        cls.prior = _policy_git_bytes(_POLICY_PRIOR)

    def test_exact_signed_source_driver_to_whole_prior_git_object(self):
        self.assertEqual(39959, len(self.current))
        self.assertEqual(_policy_delta.POLICY_CLEANUP_DRIVER_SHA256, hashlib.sha256(self.current).hexdigest())
        self.assertEqual(39675, len(self.prior))
        self.assertEqual(self.prior, _policy_git_bytes(_POLICY_HISTORICAL))
        self.assertEqual(self.prior, _policy_delta.reduce_policy_cleanup_driver(self.current))
        self.assertEqual(self.prior, _policy_delta.reduce_policy_cleanup_driver(self.prior))
        self.assertEqual(1, self.current.count(_policy_delta.POLICY_CLEANUP_DRIVER_ADDITION))
        self.assertEqual(self.prior, self.current.replace(_policy_delta.POLICY_CLEANUP_DRIVER_ADDITION, b'', 1))

    def test_existing_calendar_and_conduit_whole_git_inverses_preserved(self):
        for raw in (self.current, self.prior):
            with self.subTest(size=len(raw)):
                self.assertEqual(_policy_git_bytes(_POLICY_CALENDAR_MAIN), _policy_delta.reduce_calendar_wire_union(raw))
                self.assertEqual(_policy_git_bytes(_POLICY_CONDUIT_BASE), _policy_delta.reduce_driver(raw))
                self.assertEqual(_policy_git_bytes(_POLICY_CONDUIT_BASE), _policy_calendar.reduce_reviewed_calendar_wire_driver(raw))

    def test_calendar_pre_guard_preserves_exact_historical_source_map(self):
        path = 'scripts/governance/check_private_calendar_reminders.py'
        self.assertEqual(_policy_literal(_policy_git_bytes(_POLICY_CURRENT, path), 'SOURCE_INPUTS'), _policy_calendar.SOURCE_INPUTS)
        old = {'__file__': str(ROOT/path), '__name__': 'historical_calendar_source_view'}
        exec(compile(_policy_git_bytes(_POLICY_CURRENT, path), str(ROOT/path), 'exec'), old)
        expected = old['reduce_reviewed_calendar_driver'](self.prior)
        self.assertEqual(expected, _policy_calendar.reduce_reviewed_calendar_driver(self.current))
        self.assertEqual(_policy_calendar.CALENDAR_BASE_SOURCE_SHA256[_POLICY_DRIVER], hashlib.sha256(expected).hexdigest())

    def test_assessment_adapter_retains_whole_historical_module_role(self):
        path = 'scripts/governance/check_recovery_assessment_credentials_v2.py'
        old = _policy_literal(_policy_git_bytes(_POLICY_CURRENT, path), 'ADAPTER_SOURCE_INPUTS')
        self.assertEqual(old, _policy_assessment.ADAPTER_SOURCE_INPUTS)
        module = 'scripts/governance/recovery_conduit_source_delta.py'
        historical = _policy_git_bytes(_POLICY_HISTORICAL, module)
        self.assertEqual(_policy_git_bytes(_POLICY_CURRENT, module), historical)
        registry = json.loads(_policy_git_bytes(_POLICY_CURRENT, 'support/governance/source-operation/registry-v1.json'))
        self.assertEqual(registry['entries'][module],
                         {'bytes': len(historical), 'sha256': hashlib.sha256(historical).hexdigest()})
        coordinator_raw = _policy_git_bytes(_POLICY_CURRENT,
                                           'scripts/governance/recovery_oauth_code_coordinator_delta.py')
        selected = _policy_literal(coordinator_raw, 'SOURCE_INPUTS')
        baseline = _policy_literal(coordinator_raw, 'BASE_PINS')
        self.assertEqual(selected[module], hashlib.sha256(historical).hexdigest())
        reduced = historical
        for added, removed in _policy_literal(coordinator_raw, 'EDITS')[module]:
            self.assertEqual(1, reduced.count(added))
            reduced = reduced.replace(added, removed, 1)
        actual_prior = _policy_git_bytes('17f333801d67db7fa0f36b67501889cf04aceaf4', module)
        self.assertEqual(actual_prior, reduced)
        self.assertEqual(baseline[module], hashlib.sha256(reduced).hexdigest())
        self.assertEqual(old[module], hashlib.sha256(reduced).hexdigest())
        self.assertEqual(_policy_literal(_policy_git_bytes(_POLICY_CURRENT, path), 'SOURCE_INPUTS'),
                         _policy_assessment.SOURCE_INPUTS)

    def test_fileparent_source_view_preserves_all_twelve_original_pins(self):
        path = 'scripts/governance/check_recovery_file_parent_policy.py'
        self.assertEqual(_policy_literal(_policy_git_bytes(_POLICY_CURRENT, path), 'SOURCE_INPUTS'),
                         _policy_fileparent.SOURCE_INPUTS)
        self.assertEqual(12, len(_policy_fileparent.SOURCE_INPUTS))
        expected = (_policy_fileparent.shared.BOOTSTRAP | _policy_fileparent.shared.PREPARATION |
                    _policy_fileparent.SQL | _policy_fileparent.shared.CLEANUP)
        self.assertEqual(expected, _policy_fileparent.source_obligations(ROOT))

    def test_fileparent_refuses_unreviewed_driver_in_actual_source_obligations(self):
        original = _policy_fileparent.read_source_checked
        for raw in (self.current+b'\n', self.current.replace(b'<?php', b'<?pHp', 1)):
            def reader(path):
                return raw if path == ROOT/_POLICY_DRIVER else original(path)
            with self.subTest(size=len(raw)), _policy_patch.object(
                    _policy_fileparent, 'read_source_checked', side_effect=reader):
                with self.assertRaises(ValueError):
                    _policy_fileparent.source_obligations(ROOT)

    def test_unknown_whole_bytes_or_wrong_type_are_refused(self):
        for raw in (self.current+b'\n', self.current[:-1], self.prior+b'\n', bytearray(self.current), None,
                    self.current.replace(b'Keep exact owned', b'Keep other owned', 1)):
            with self.subTest(kind=type(raw).__name__):
                with self.assertRaises(ValueError):
                    _policy_delta.reduce_policy_cleanup_driver(raw)

    def test_input_hash_alone_cannot_authorize_unrelated_change(self):
        bad = self.current.replace(b'<?php', b'<?pHp', 1)
        with _policy_patch.object(_policy_delta, 'POLICY_CLEANUP_DRIVER_SHA256', hashlib.sha256(bad).hexdigest()):
            with self.assertRaisesRegex(ValueError, 'other historical driver bytes'):
                _policy_delta.reduce_policy_cleanup_driver(bad)

    def test_missing_inverse_site_is_refused_even_with_matching_input_pin(self):
        block = _policy_delta.POLICY_CLEANUP_DRIVER_ADDITION
        bad = self.current.replace(block, b' '*len(block), 1)
        with _policy_patch.object(_policy_delta, 'POLICY_CLEANUP_DRIVER_SHA256', hashlib.sha256(bad).hexdigest()):
            with self.assertRaisesRegex(ValueError, 'inverse shape'):
                _policy_delta.reduce_policy_cleanup_driver(bad)

    def test_legacy_guard_override_does_not_accept_new_unknown_bytes(self):
        bad = self.current.replace(b'<?php', b'<?pHp', 1)
        with _policy_patch.object(_policy_delta, 'DRIVER_SHA256', hashlib.sha256(bad).hexdigest()):
            with self.assertRaises(ValueError):
                _policy_delta.reduce_driver(bad)

    def test_driver_adapter_preserves_original_inverse_program(self):
        old = _policy_git_bytes(_POLICY_CURRENT, 'scripts/governance/recovery_conduit_source_delta.py').decode()
        current = (ROOT/'scripts/governance/recovery_conduit_source_delta.py').read_text()
        start = current.index('# Exact fixture-source projection only;')
        end = current.index('DRIVER_SHA256=', current.index('\nDRIVER_SHA256=', start)+1)
        # Remove only the named new projection and one explicit invocation.
        inverse = current[:start] + current[end:]
        inverse = inverse.replace('def reduce_calendar_wire_union(raw):\n raw=reduce_policy_cleanup_driver(raw)\n',
                                  'def reduce_calendar_wire_union(raw):\n', 1)
        self.assertEqual(old, inverse)
