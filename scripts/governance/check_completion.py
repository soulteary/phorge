#!/usr/bin/env python3
"""Accept bounded native integration receipts, never governance activation."""
import argparse
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MATRIX = 'support/governance/completion/pipeline-matrix.json'
OPS = {'project.description', 'task.title', 'task.description', 'wiki.title',
       'wiki.content', 'policy.replace', 'project.members', 'object.space',
       'object.relationship'}
PATCHES = '''20260907.herald.01.requeststatuskey.sql
20260911.differential.01.appemail.php
20260911.paste.01.appemail.php
20260918.differential.01.heraldrules.php
20260918.differential.02.diffpolicy.php
20260918.owners.01.heraldrules.php
20260918.owners.02.auditrequests.php
20261006.cache.01.gorgecleanup.sql
20261006.conduit.01.gorgecleanup.sql
20261006.daemon.01.gorgecleanup.sql
20261006.feed.01.gorgeoutbox.sql
20261006.metamta.01.gorgeoutbox.sql
20261006.metamta.02.gorgedelivery.sql
20261006.metamta.03.gorgerecovery.sql
20261006.search.01.gorgeprojection.sql
20261006.search.02.gorgedeletion.sql
20261006.worker.01.gorgeinbox.sql
20261007.differential.01.gorgecleanup.sql
20261007.file.01.gorgedeletion.sql
20261007.metamta.01.gorgeinbound.sql
20261007.multimeter.01.cleanupindex.sql
20261007.multimeter.01.gorgecleanup.sql
20261007.worker.scheduler.sql
20261007.worker.scheduleridentity.sql
20261102.people.organization.sql
20261102.people.mediaexchange.sql
20261103.people.organizationmetadata.sql
20261103.people.mediaissuer.sql
20261103.people.mediatargets.sql
20261103.people.organizationtransfer.sql
20261103.project.approvalexecution.sql
20261103.project.configepoch.sql
20261104.people.recoverystop.sql
20261104.metamta.governancenotice.sql
20261104.people.recoveryrestore.sql
20261104.people.organizationparticipants.sql
20261105.people.noticeidentityepoch.sql
20261110.project.contentgrants.sql'''.splitlines()
REQUIRED_CHECKS = {
 'full-current-source-quickstart-and-patch-ledger-restored',
 'completion-reader-real-select-only-grants-and-lineage-proved',
 'completion-writer-forbidden-privilege-denied',
 'completion-reader-forbidden-privilege-denied',
 'completion-writer-cannot-modify-ownership-marker',
 'completion-reader-cannot-modify-ownership-marker',
 'pipeline-parent-member-native-validation-rejected',
 'pipeline-milestone-member-native-validation-rejected',
 'pipeline-support-oldowner-subscribed',
 'pipeline-comment-record-persisted',
 'pipeline-support-comment-implicit-cc',
 'pipeline-support-native-file-reference-attachment',
 'pipeline-support-native-file-transaction-record',
 'pipeline-real-no-rule-herald-transcripts',
 'pipeline-customfield-old-value-loaded-from-native-storage',
 'pipeline-native-wiki-move-cross-references-persisted',
 'pipeline-injected-record-write-failure-observed',
 'pipeline-midwrite-object-row-rolled-back',
 'pipeline-midwrite-transaction-records-rolled-back',
 'pipeline-midwrite-no-publishing-enqueued',
 'pipeline-invalid-task-validation-rejects-unchanged-row',
 'pipeline-validation-no-record-or-publishing',
 'pipeline-empty-wiki-create-native-validation-rejected',
 'pipeline-fixed-native-library-profile-unchanged',
 'pipeline-current-gorge-file-bytes-roundtrip',
 'completion-real-gorge-file-ready-loopback',
 'completion-real-gorge-taskqueue-ready-loopback',
 'completion-gorge-profile-requires-real-http-owner',
 'completion-gorge-queue-rejects-unauthenticated-http',
 'completion-gorge-profile-forbids-native-fallback',
 'completion-gorge-authenticated-http-stats',
 'completion-real-editor-created-http-publication-task',
 'completion-real-publication-task-visible-via-gorge-http',
 'completion-only-owned-publication-worker-selected',
 'completion-owned-publication-data-loaded-from-native-taskdata',
 'completion-publication-transactions-belong-to-owned-object',
 'completion-create-hidden-transactions-produce-no-mail-outbox',
 'completion-real-comment-update-created-second-http-publication-task',
 'completion-comment-publication-task-visible-via-gorge-http',
 'completion-update-publication-data-bound-to-owned-actor-and-object',
 'completion-update-has-owned-native-mail-visible-transactions',
 'completion-native-publish-worker-creates-gorge-mail-outbox',
 'completion-native-publish-worker-creates-gorge-feed-outbox',
 'completion-mail-outbox-not-delivered-or-dispatched',
 'completion-gorge-publication-zero-native-fallbacks',
 'read-view-real-native-project-loader-profile',
 'read-view-real-native-project-view-allow',
 'read-view-real-native-project-edit-allow',
 'read-view-real-native-wiki-loader-profile',
 'read-view-wiki-private-ancestor-local-allow-actor-query-deny',
 'read-view-a-to-b-observes-snapshot-a',
 'read-view-fresh-snapshot-observes-committed-b',
 'read-view-a-b-a-remains-consistent-not-execution-freshness',
 'read-view-fresh-after-a-b-a-observes-current-a',
 'read-view-group-source-fixture-revoked-on-second-connection',
 'read-view-group-revocation-sees-snapshot-membership',
 'read-view-fresh-group-revocation-observes-absence',
 'read-view-forbidden-queries-do-not-end-or-write-snapshot',
 'read-view-straight-join-cross-schema-refused-before-fetch',
 'read-view-pool-drift-blocks-without-closing-caller-owned-connection',
 'read-view-huge-native-task-row-refused-before-select-fetch',
 'read-view-native-sql-mode-drift-blocked',
 'read-view-sql-mode-drift-closes-owned-session-restores-writer',
 'owned-native-write-guard-disposed',
 'owned-writer-environment-and-cache-restored',
 'all-owned-full-native-schemas-and-principals-cleaned',
} | {f'read-view-forbidden-native-query-{i}-refused' for i in range(6)}


def require(value, message):
    if not value:
        raise ValueError(message)


def _equal(actual, expected):
    if type(actual) is not type(expected):
        return False
    if type(expected) is dict:
        return set(actual) == set(expected) and all(_equal(actual[k],v) for k,v in expected.items())
    if type(expected) is list:
        return len(actual) == len(expected) and all(_equal(a,b) for a,b in zip(actual,expected))
    return actual == expected


def exact(actual, expected, message):
    require(_equal(actual, expected), message)


def strict_object(pairs):
    result={}
    for key,value in pairs:
        require(key not in result, 'Duplicate JSON fields refused.')
        result[key]=value
    return result


def fields(obj, required, message):
    require(type(obj) is dict and set(obj) == set(required), message)


def digest(value):
    return type(value) is str and re.fullmatch('[a-f0-9]{64}', value) is not None


def profile(value):
    fields(value, ['runtime_profile','libraries','canonical_roots_verified',
                  'library_maps_unextended','map_sha256'], 'Runtime profile fields refused.')
    exact(value['runtime_profile'], 'fixed-native-arcanist-phorge', 'Runtime profile refused.')
    exact(value['libraries'], ['arcanist','phorge'], 'Runtime libraries refused.')
    exact(value['canonical_roots_verified'], True, 'Runtime roots unproved.')
    exact(value['library_maps_unextended'], True, 'Runtime extensions unproved.')
    fields(value['map_sha256'], ['arcanist','phorge'], 'Runtime map fields refused.')
    require(all(digest(v) for v in value['map_sha256'].values()), 'Runtime map digests refused.')


def service(value, kind):
    fields(value, ['kind','binary_sha256','process_stopped','private_storage_removed'],
           'Service receipt fields refused.')
    exact(value['kind'], kind, 'Service kind refused.')
    require(digest(value['binary_sha256']), 'Service binary digest refused.')
    exact(value['process_stopped'], True, 'Owned service remains active.')
    exact(value['private_storage_removed'], True, 'Owned service storage remains.')


def matrix_checks(root=ROOT):
    data = json.loads((Path(root)/MATRIX).read_text())
    rows = data.get('rows')
    require(type(rows) is list and len(rows) == 24, 'Native shape matrix count refused.')
    exact(data.get('shape_count'), 24, 'Native shape count refused.')
    exact(data.get('operation_count'), 9, 'Native operation count refused.')
    ids, operations, checks = set(), set(), set()
    for row in rows:
        require(type(row) is dict and type(row.get('shape_id')) is str and
                row['shape_id'] not in ids, 'Native shape identity refused.')
        ids.add(row['shape_id']); operations.add(row.get('operation'))
        names = row.get('check_ids')
        require(type(names) is list and bool(names) and
                all(type(n) is str and n for n in names) and len(set(names)) == len(names),
                'Native shape checks refused.')
        checks.update(names)
    require(operations == OPS, 'Native operation vocabulary refused.')
    return checks


def validate(data, root=ROOT):
    fields(data, ['batch','phase','passed','acceptance_scope','checks','check_count','php_version',
        'source_restore','pipeline','consistency','queue_profile','gorge_publication',
        'file_service_cleanup','successful_owned_service_startups','writes','cleanup',
        'failure','cleanup_failures','terminal_failure_cleanup_rollbacks',
        'captured_native_output_bytes','captured_native_output_sha256',
        'production_deployment','production_executor','optional_features_enabled'],
        'Completion receipt fields refused.')
    for key, expected in {'batch':'native-governance-completion','phase':'all','passed':True,
        'acceptance_scope':'full-current-profile-attempt','queue_profile':'gorge',
        'failure':None,'cleanup_failures':[],'production_deployment':False,
        'production_executor':False,'optional_features_enabled':False,
        'terminal_failure_cleanup_rollbacks':0,'successful_owned_service_startups':2}.items():
        exact(data[key], expected, 'Completion gate refused: '+key)
    require(type(data['php_version']) is str and re.fullmatch(r'8\.5\.12(?:[-+].*)?',data['php_version']),
            'Supported PHP runtime refused.')
    checks = data['checks']
    require(type(checks) is list and all(type(c) is str and re.fullmatch(
        r'(?:pipeline|completion|read-view|owned|full|all|native)[a-zA-Z0-9:._-]{0,180}',c)
        for c in checks), 'Unsafe or malformed check identifiers refused.')
    require(len(checks) == len(set(checks)), 'Duplicate evidence checks refused.')
    exact(data['check_count'], len(checks), 'Evidence check count refused.')
    required = matrix_checks(root) | REQUIRED_CHECKS
    require(required.issubset(set(checks)), 'Required native matrix or boundary evidence missing.')
    fields(data['writes'], ['pipeline_and_services','fixture_setup_and_concurrency','read_view_observer'],
           'Write boundary fields refused.')
    exact(data['writes'], {'pipeline_and_services':'intentional-owned-writes',
        'fixture_setup_and_concurrency':'intentional-owned-writes',
        'read_view_observer':'select-only-no-observer-writes'}, 'Write boundaries refused.')
    restore = data['source_restore']
    fields(restore, ['quickstart_sha256','app_schema_count','owned_schema_count',
        'quickstart_source_table_count','current_table_count','patch_audit',
        'restore_phase_workers_started','restore_phase_network_endpoints_configured'],
        'Restore receipt fields refused.')
    exact(restore['quickstart_sha256'], hashlib.sha256((Path(root)/'resources/sql/quickstart.sql').read_bytes()).hexdigest(),
          'Quickstart source digest drift.')
    for key, expected in {'app_schema_count':54,'owned_schema_count':55,
        'quickstart_source_table_count':500,'restore_phase_workers_started':0,
        'restore_phase_network_endpoints_configured':0}.items():
        exact(restore[key], expected, 'Restore boundary refused: '+key)
    exact(restore['current_table_count'], 549, 'Current table inventory refused.')
    audit=restore['patch_audit']
    fields(audit,['source_patch_count','applied_patch_count','pending_patch_keys',
        'current_patch_ledger_complete','migration_source_hashes','migrations_executed'],
        'Patch audit fields refused.')
    exact(audit['pending_patch_keys'], [], 'Pending current patches refused.')
    exact(audit['current_patch_ledger_complete'], True, 'Current patches unproved.')
    exact(audit['migrations_executed'], len(PATCHES), 'Actual migration count refused.')
    exact(audit['source_patch_count'], 1575, 'Source patch count refused.')
    exact(audit['applied_patch_count'],audit['source_patch_count'],'Applied patch count drift.')
    hashes=audit['migration_source_hashes']
    fields(hashes, ['phabricator:'+p for p in PATCHES], 'Reviewed migration set drift.')
    for name in PATCHES:
        exact(hashes['phabricator:'+name],hashlib.sha256(
            (Path(root)/'resources/sql/autopatches'/name).read_bytes()).hexdigest(),
            'Executed migration source digest drift.')
    pipeline=data['pipeline']
    fields(pipeline,['runtime_profile','scope','writes_expected','can_execute','production_executor',
        'worker_delivery_executed','arbitrary_extension_closure_proved','all_external_effects_rollback_proved'],
        'Pipeline fields refused.')
    profile(pipeline['runtime_profile'])
    exact(pipeline['scope'],'writable-owned-native-pipeline-current-product-cut','Pipeline scope refused.')
    for key in ['can_execute','production_executor','worker_delivery_executed',
                'arbitrary_extension_closure_proved','all_external_effects_rollback_proved']:
        exact(pipeline[key],False,'Unproved pipeline/global claim refused.')
    exact(pipeline['writes_expected'],True,'Native pipeline writes unproved.')
    pub=data['gorge_publication']
    fields(pub,['profile','editor_family','runtime_profile','real_http_queue','owned_publish_workers_executed',
        'create_hidden_mail_negative_and_comment_positive','delivery_workers_executed',
        'external_mail_delivered','mail_and_feed_outbox_only','service','production_configuration_changed'],
        'Publication fields refused.')
    profile(pub['runtime_profile'])
    for key, expected in {'profile':'gorge','editor_family':'ManiphestTask','real_http_queue':True,
        'owned_publish_workers_executed':2,'create_hidden_mail_negative_and_comment_positive':True,
        'delivery_workers_executed':0,'external_mail_delivered':False,
        'mail_and_feed_outbox_only':True,'production_configuration_changed':False}.items():
        exact(pub[key],expected,'Publication gate refused: '+key)
    service(pub['service'],'taskqueue'); service(data['file_service_cleanup'],'file')
    read=data['consistency']
    fields(read,['same_physical_connection_tested','same_consistent_read_view_tested',
        'a_b_and_a_b_a_tested','execution_freshness_proved','production_executor',
        'all_native_dependencies_closed'],'Read-view fields refused.')
    for key in read:
        exact(read[key],key.endswith('_tested'),'Read-view or future-execution claim refused.')
    exact(data['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True},
          'Owned cleanup evidence refused.')
    require(type(data['captured_native_output_bytes']) is int and data['captured_native_output_bytes'] >= 0 and
            digest(data['captured_native_output_sha256']), 'Captured output summary refused.')
    return {'schema_valid':True,'accepted_scope':'fixed-native-N05-N07-integration',
        'native_shapes':24,'operation_vocabularies':9,'phase':'all','gorge_profile_accepted':True,
        'can_execute':False,'global_completion':False,'N03_completed':False,
        'remaining_blocked':['N03 organization/appointment sources and optional governance writer',
            'Future business-write authorization and execution freshness',
            'Arbitrary third-party runtime/extension closure and external delivery acceptance'],
        'hosted_ci_or_review_proved':False}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--receipt',type=Path,required=True)
    args=parser.parse_args()
    try:
        require(args.receipt.stat().st_size <= 1048576, 'Receipt byte budget refused.')
        result=validate(json.loads(args.receipt.read_text(),object_pairs_hook=strict_object,
            parse_constant=lambda unused: (_ for _ in ()).throw(ValueError('Invalid JSON constant.'))))
    except (OSError,ValueError,TypeError,KeyError):
        parser.exit(1,'Completion receipt rejected.\n')
    print(json.dumps(result,sort_keys=True))


if __name__ == '__main__':
    main()
