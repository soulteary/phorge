#!/usr/bin/env python3
"""Verify bounded synthetic organization evidence; never production authority."""
import argparse
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCHEMA = ROOT / 'support/governance/organization/schema.sql'
REQUIRED_CHECKS = frozenset({
    'organization-worker-fixed-spawn-failure-observed',
    'organization-worker-start-failure-removes-owned-credential-directory',
    'organization-reader-trailing-pad-actor-not-current-identity',
    'organization-reader-trailing-pad-subject-relation-not-current-fact',
    'organization-reader-trailing-pad-appointment-not-authority',

    'organization-propose-audit-gap-real-wait-observed',
    'organization-propose-audit-gap-final-expiry-refused',
    'organization-propose-audit-gap-ticket-state-and-audit-fully-rolled-back',
    'organization-approve-audit-gap-real-wait-observed',
    'organization-approve-audit-gap-final-expiry-refused',
    'organization-approve-audit-gap-ticket-state-and-audit-fully-rolled-back',

    'organization-writer-and-select-reader-actual-factories',
    'organization-factory-wrong-owner-nonce-refused',
    'organization-factory-extra-ddl-grants-refused',
    'organization-rejected-factories-preserve-mysqli-report-mode',
    'organization-factory-physical-case-folding-schema-refused',
    'organization-reader-physical-case-folding-schema-refused',
    'organization-factory-after-collation-restoration-no-report-leak',
    'organization-runtime-role-forbidden-write-0',
    'organization-runtime-role-forbidden-write-1',
    'organization-runtime-role-forbidden-write-2',
    'organization-runtime-role-forbidden-write-3',
    'organization-own-team-member-add-real-commit',
    'organization-member-readback-real-row',
    'organization-identical-operation-replay-no-extra-write',
    'organization-replay-caller-case-alias-not-current-identity',
    'organization-team-case-alias-not-authorized-team',
    'organization-operation-replay-different-payload-refused',
    'organization-cross-team-refused',
    'organization-parent-not-child-refused',
    'organization-ordinary-no-appointment-refused',
    'organization-disabled-actor-refused',
    'organization-stale-member-version-refused',
    'organization-active-appointment-prerequisite-0-protected',
    'organization-active-appointment-prerequisite-1-protected',
    'organization-active-appointment-prerequisite-2-protected',
    'organization-reader-other-team-relations-hidden',
    'organization-reader-no-self-or-selector-count-export-exception',
    'organization-reader-hidden-parent-and-department-redacted',
    'organization-reader-parent-no-child-inheritance-disabled-no-directory',
    'organization-reader-revoked-appointment-immediately-hidden',
    'organization-reader-unknown-subject-membership-version-not-current-fact',
    'organization-reader-disabled-subject-retained-for-admin-audit-not-access',
    'organization-reader-nontransactional-source-refused',
    'organization-reader-after-engine-restoration-real-read',
    'organization-sql-fault-batch-rejected',
    'organization-sql-fault-rolls-back-members-versions-journal-and-audit',
    'organization-member-lifecycle-member.update-committed',
    'organization-member-lifecycle-member.end-committed',
    'organization-member-lifecycle-member.add-committed',
    'organization-approval-proposal-real-synthetic-source-not-native-authority',
    'organization-approval-self-approval-refused',
    'organization-team-admin-cannot-approve-appointment',
    'organization-requester-case-alias-not-independent-approver',
    'organization-approver-case-alias-not-current-identity',
    'organization-approval-case-alias-does-not-reference-existing-ticket',
    'organization-independent-sysadmin-approval-stored',
    'organization-approval-replays-no-extension-or-new-audit',
    'organization-approval-consume-audit-fault-refused',
    'organization-approval-consume-target-audit-journal-all-rollback',
    'organization-appointment-and-approval-consume-atomic-commit',
    'organization-sensitive-operation-idempotent-no-second-consume',
    'organization-consumed-approval-cannot-authorize-new-operation',
    'organization-approval-expired-consume-refused',
    'organization-approval-expired-keeps-ticket-and-target-unchanged',
    'organization-approval-stale-subject-consume-refused',
    'organization-approval-stale-subject-keeps-ticket-and-target-unchanged',
    'organization-approval-revoked-approver-consume-refused',
    'organization-approval-revoked-approver-keeps-ticket-and-target-unchanged',
    'organization-disabled-subject-can-be-revoked-with-history-retained',
    'organization-real-multi-member-batch-commit',
    'organization-two-sensitive-approvals-and-appointments-atomic-commit',
    'organization-sensitive-batch-second-audit-fault-rejected',
    'organization-sensitive-batch-both-consumes-and-targets-full-rollback',
    'organization-sensitive-batch-second-expired-ticket-rejected',
    'organization-sensitive-batch-first-consume-rollback-on-second-expiry',
    'organization-real-approver-row-lock-wait-observed',
    'organization-approval-approver-row-expiry-after-wait-rejected',
    'organization-approval-approver-row-expiry-all-effects-rolled-back',
    'organization-real-audit-gap-lock-wait-observed',
    'organization-approval-audit-gap-expiry-after-wait-rejected',
    'organization-approval-audit-gap-expiry-all-effects-rolled-back',
    'organization-real-actor-guard-wait-observed',
    'organization-actor-guard-expired-after-lock-wait-refused',
    'organization-actor-guard-expiry-no-partial-effects',
    'organization-real-actor-identity-wait-observed',
    'organization-actor-identity-expired-after-lock-wait-refused',
    'organization-actor-identity-expiry-no-partial-effects',
    'organization-real-concurrency-same-payload-linearized-once',
    'organization-real-concurrency-different-payload-linearized-once',
    'organization-real-concurrency-different-payload-expected-refusal',
    'organization-real-concurrency-same-version-linearized-once',
    'organization-real-concurrency-same-version-expected-refusal',
    'organization-only-owned-schema-and-three-principals-cleaned',
})
FIELDS = {'passed', 'checks', 'check_count', 'failure', 'cleanup_failures',
          'cleanup', 'schema_sha256', 'scope', 'identity_source',
          'production_execution', 'optional_features_enabled',
          'native_permission_axes_changed'}


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('duplicate JSON key')
        result[key] = value
    return result


def validate(value):
    if not isinstance(value, dict) or set(value) != FIELDS:
        raise ValueError('receipt fields refused')
    if value['passed'] is not True or value['failure'] is not None or value['cleanup_failures'] != []:
        raise ValueError('failed receipt refused')
    for key in ('production_execution', 'optional_features_enabled', 'native_permission_axes_changed'):
        if value[key] is not False:
            raise ValueError('activation or untyped flag refused')
    if value['scope'] != 'owned-synthetic-organization-integration' or value['identity_source'] != 'synthetic-native-account-projection-not-production-authority':
        raise ValueError('scope refused')
    if value['schema_sha256'] != hashlib.sha256(SCHEMA.read_bytes()).hexdigest():
        raise ValueError('schema source mismatch')
    checks = value['checks']
    if (not isinstance(checks, list) or any(type(x) is not str for x in checks) or
        len(checks) != len(set(checks)) or set(checks) != REQUIRED_CHECKS or
        type(value['check_count']) is not int or value['check_count'] != len(checks)):
        raise ValueError('check coverage refused')
    cleanup = value['cleanup']
    if (not isinstance(cleanup, dict) or set(cleanup) != {'owned_schema_removed', 'principals_removed', 'private_directories_removed'} or
        cleanup['owned_schema_removed'] is not True or type(cleanup['principals_removed']) is not int or
        cleanup['principals_removed'] != 3 or type(cleanup['private_directories_removed']) is not int or
        cleanup['private_directories_removed'] != 13):
        raise ValueError('cleanup refused')
    return {'passed': True, 'scope': value['scope'], 'check_count': len(checks),
            'identity_source': value['identity_source'], 'production_execution': False,
            'remaining_blocked': ['production-native-identity-projection',
                'production-activation', 'global-governance-completion']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--receipt', required=True, type=Path)
    args = parser.parse_args()
    try:
        if args.receipt.stat().st_size > 262144:
            raise ValueError('receipt too large')
        result = validate(json.loads(args.receipt.read_text(), object_pairs_hook=unique_object))
    except (OSError, UnicodeError, ValueError, TypeError, KeyError):
        print(json.dumps({'passed': False, 'reason': 'organization_receipt_refused'}))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
