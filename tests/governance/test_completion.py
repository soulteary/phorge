"""Mutation tests for bounded completion evidence; templates are not runtime proof."""
import copy
import hashlib
import importlib.util
import json
import re
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('completion_gate',ROOT/'scripts/governance/check_completion.py')
gate=importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


def valid_template():
    runtime={'runtime_profile':'fixed-native-arcanist-phorge','libraries':['arcanist','phorge'],
        'canonical_roots_verified':True,'library_maps_unextended':True,
        'map_sha256':{'arcanist':'a'*64,'phorge':'b'*64}}
    def service(kind):
        return {'kind':kind,'binary_sha256':'c'*64,'process_stopped':True,'private_storage_removed':True}
    checks=sorted(gate.REQUIRED_CHECKS|gate.matrix_checks())
    return {'batch':'native-governance-completion','phase':'all','passed':True,
        'acceptance_scope':'full-current-profile-attempt','checks':checks,'check_count':len(checks),
        'php_version':'8.5.12','queue_profile':'gorge','successful_owned_service_startups':2,
        'source_restore':{'quickstart_sha256':hashlib.sha256((ROOT/'resources/sql/quickstart.sql').read_bytes()).hexdigest(),
            'app_schema_count':54,'owned_schema_count':55,'quickstart_source_table_count':500,
            'current_table_count':549,'restore_phase_workers_started':0,
            'restore_phase_network_endpoints_configured':0,
            'patch_audit':{'source_patch_count':1575,'applied_patch_count':1575,
                'pending_patch_keys':[],'current_patch_ledger_complete':True,'migrations_executed':len(gate.PATCHES),
                'migration_source_hashes':{'phabricator:'+p:hashlib.sha256(
                    (ROOT/'resources/sql/autopatches'/p).read_bytes()).hexdigest() for p in gate.PATCHES}}},
        'pipeline':{'runtime_profile':copy.deepcopy(runtime),
            'scope':'writable-owned-native-pipeline-current-product-cut','writes_expected':True,
            'can_execute':False,'production_executor':False,'worker_delivery_executed':False,
            'arbitrary_extension_closure_proved':False,'all_external_effects_rollback_proved':False},
        'gorge_publication':{'profile':'gorge','editor_family':'ManiphestTask',
            'runtime_profile':copy.deepcopy(runtime),'real_http_queue':True,
            'owned_publish_workers_executed':2,'create_hidden_mail_negative_and_comment_positive':True,
            'delivery_workers_executed':0,'external_mail_delivered':False,
            'mail_and_feed_outbox_only':True,'service':service('taskqueue'),
            'production_configuration_changed':False},
        'consistency':{'same_physical_connection_tested':True,'same_consistent_read_view_tested':True,
            'a_b_and_a_b_a_tested':True,'execution_freshness_proved':False,
            'production_executor':False,'all_native_dependencies_closed':False},
        'file_service_cleanup':service('file'),
        'writes':{'pipeline_and_services':'intentional-owned-writes',
            'fixture_setup_and_concurrency':'intentional-owned-writes',
            'read_view_observer':'select-only-no-observer-writes'},
        'cleanup':{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True},
        'failure':None,'cleanup_failures':[],'terminal_failure_cleanup_rollbacks':0,
        'captured_native_output_bytes':0,'captured_native_output_sha256':hashlib.sha256(b'').hexdigest(),
        'production_deployment':False,'production_executor':False,'optional_features_enabled':False}


class CompletionReceiptTests(unittest.TestCase):
    def reject(self,data):
        with self.assertRaises((ValueError,TypeError,KeyError)):
            gate.validate(data)

    def test_accepts_only_bounded_current_scope(self):
        result=gate.validate(valid_template())
        self.assertEqual(result['native_shapes'],24)
        self.assertEqual(result['operation_vocabularies'],9)
        self.assertFalse(result['global_completion'])
        self.assertFalse(result['N03_completed'])
        self.assertFalse(result['hosted_ci_or_review_proved'])
        self.assertFalse(result['can_execute'])

    def test_diagnostic_and_failed_receipts_never_count_as_completion(self):
        for patch in [{'phase':'readview-only'}, {'passed':False}, {'passed':'true'},
                      {'acceptance_scope':'diagnostic-readview-only-not-full-acceptance'},
                      {'queue_profile':'native'}, {'failure':{'stage':'failed'}},
                      {'cleanup_failures':[{'stage':'cleanup'}]},
                      {'terminal_failure_cleanup_rollbacks':1}]:
            with self.subTest(patch=patch):
                data=valid_template();data.update(patch);self.reject(data)

    def test_each_atomic_shape_requires_every_runtime_check(self):
        matrix=json.loads((ROOT/gate.MATRIX).read_text())
        self.assertEqual(len(matrix['rows']),24)
        for row in matrix['rows']:
            for check in row['check_ids']:
                with self.subTest(shape=row['shape_id'],check=check):
                    data=valid_template();data['checks'].remove(check)
                    data['check_count']=len(data['checks']);self.reject(data)

    def test_duplicate_missing_and_count_drift_evidence_rejected(self):
        data=valid_template();data['checks'].append(data['checks'][0]);data['check_count']+=1
        self.reject(data)
        for check in ['completion-gorge-publication-zero-native-fallbacks',
                      'read-view-straight-join-cross-schema-refused-before-fetch',
                      'completion-real-comment-update-created-second-http-publication-task',
                      'completion-create-hidden-transactions-produce-no-mail-outbox',
                      'pipeline-midwrite-object-row-rolled-back',
                      'read-view-pool-drift-blocks-without-closing-caller-owned-connection',
                      'read-view-wiki-private-ancestor-local-allow-actor-query-deny',
                      'read-view-huge-native-task-row-refused-before-select-fetch']:
            data=valid_template();data['checks'].remove(check);data['check_count']-=1;self.reject(data)
        data=valid_template();data['check_count']+=1;self.reject(data)
        data=valid_template();data['check_count']=True;self.reject(data)

    def test_publication_and_delivery_flags_are_not_interchangeable(self):
        for key,value in [('real_http_queue',False),('owned_publish_workers_executed',1),
            ('delivery_workers_executed',1),('external_mail_delivered',True),
            ('mail_and_feed_outbox_only',False),('create_hidden_mail_negative_and_comment_positive',False)]:
            data=valid_template();data['gorge_publication'][key]=value;self.reject(data)
        data=valid_template();data['successful_owned_service_startups']=1;self.reject(data)

    def test_future_executor_and_global_claims_rejected(self):
        for section,key,value in [('pipeline','can_execute',True),
            ('pipeline','all_external_effects_rollback_proved',True),
            ('pipeline','arbitrary_extension_closure_proved',True),
            ('consistency','same_physical_connection_tested',False),
            ('consistency','same_consistent_read_view_tested',False),
            ('consistency','execution_freshness_proved',True),
            ('consistency','all_native_dependencies_closed',True)]:
            data=valid_template();data[section][key]=value;self.reject(data)
        for key in ['production_deployment','production_executor','optional_features_enabled']:
            data=valid_template();data[key]=True;self.reject(data)

    def test_source_patch_execution_and_owned_cleanup_required(self):
        self.assertIn('20261104.people.recoveryrestore.sql', gate.PATCHES)
        data=valid_template();del data['source_restore']['patch_audit']['migration_source_hashes']['phabricator:20261104.people.recoveryrestore.sql'];self.reject(data)
        data=valid_template();data['source_restore']['patch_audit']['pending_patch_keys']=['unapplied'];self.reject(data)
        data=valid_template();data['source_restore']['patch_audit']['migrations_executed']=0;self.reject(data)
        data=valid_template();data['source_restore']['patch_audit']['migration_source_hashes'][
            'phabricator:'+gate.PATCHES[0]]='0'*64;self.reject(data)
        data=valid_template();data['source_restore']['quickstart_sha256']='0'*64;self.reject(data)
        data=valid_template();data['cleanup']['ownedSchemasRemoved']=54;self.reject(data)
        data=valid_template();data['file_service_cleanup']['process_stopped']=False;self.reject(data)
        data=valid_template();data['gorge_publication']['service']['private_storage_removed']=False;self.reject(data)

    def test_unknown_sensitive_fields_and_runtime_extensions_rejected(self):
        data=valid_template();data['file_service_cleanup']['token']='sensitive-placeholder';self.reject(data)
        data=valid_template();data['gorge_publication']['service']['uri']='sensitive-placeholder';self.reject(data)
        data=valid_template();data['pipeline']['runtime_profile']['libraries'].append('extra');self.reject(data)
        data=valid_template();data['pipeline']['runtime_profile']['library_maps_unextended']=False;self.reject(data)
        data=valid_template();data['credentials']='sensitive-placeholder';self.reject(data)

    def test_cleanup_boolean_and_numeric_types_are_strict(self):
        for key,value in [('readerRemoved',1),('ownedSchemasRemoved',55.0),
                          ('ownedRuntimeUsersRemoved',True)]:
            data=valid_template();data['cleanup'][key]=value;self.reject(data)

    def test_cli_rejects_duplicate_json_fields(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'receipt.json'
            path.write_text(json.dumps(valid_template()).replace(
                '"passed": true','"passed": false, "passed": true',1))
            result=subprocess.run([sys.executable,'-B',str(ROOT/'scripts/governance/check_completion.py'),
                '--receipt',str(path)],capture_output=True,text=True)
            self.assertEqual(result.returncode,1)
            self.assertEqual(result.stdout,'')

    def test_cli_rejects_prefix_json_and_never_echoes_untrusted_values(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'receipt.json'
            path.write_text('untrusted-secret-prefix\n'+json.dumps(valid_template()))
            result=subprocess.run([sys.executable,'-B',str(ROOT/'scripts/governance/check_completion.py'),
                '--receipt',str(path)],capture_output=True,text=True)
            self.assertEqual(result.returncode,1)
            self.assertEqual(result.stdout,'')
            self.assertNotIn('untrusted-secret-prefix',result.stderr)
            path.write_text(json.dumps(valid_template()))
            result=subprocess.run([sys.executable,'-B',str(ROOT/'scripts/governance/check_completion.py'),
                '--receipt',str(path)],capture_output=True,text=True)
            self.assertEqual(result.returncode,0)
            self.assertEqual(json.loads(result.stdout)['native_shapes'],24)


class NativeStopMigrationCompatibilityTest(unittest.TestCase):
    def test_native_semicolon_reader_receives_two_create_statements(self):
        # Match the existing applyPatchSQL reader, rather than a general SQL
        # parser: a semicolon in a line comment used to break this migration.
        sql=(ROOT/'resources/sql/autopatches/20261104.people.recoverystop.sql').read_text()
        def statements(payload):
            return ['\n'.join(line for line in part.splitlines()
                    if line.strip() and not line.lstrip().startswith('--'))
                    for part in re.split(r';\s+',payload) if part.strip()]
        parts=statements(sql)
        self.assertEqual(2,len(parts))
        self.assertTrue(parts[0].startswith('CREATE TABLE {$NAMESPACE}_user.recovery_stop ('))
        self.assertTrue(parts[1].startswith('CREATE TABLE {$NAMESPACE}_user.recovery_stop_audit ('))
        broken=sql.replace('recovery permit. No native', 'recovery permit; No native')
        self.assertEqual('',statements(broken)[0])


if __name__ == '__main__':
    unittest.main()
