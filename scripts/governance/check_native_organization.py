#!/usr/bin/env python3
"""Accept bounded native directory/identity evidence, never activation or deployment."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
_spec = importlib.util.spec_from_file_location('native_completion_gate', ROOT/'scripts/governance/check_completion.py')
completion = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(completion)
REQUIRED_CHECKS = frozenset({
    'membership-lifecycle-audit-fault-rolls-back-member-unit-audit-ledger',
    'membership-lifecycle-concurrent-identical-operation-applies-once-and-replays-once',
    'membership-lifecycle-concurrent-operation-writes-one-audit-and-one-version',
    'membership-lifecycle-end-and-suspend-retain-versioned-native-history',
    'membership-lifecycle-last-item-rolls-back-earlier-member-audit-and-ledger',
    'membership-lifecycle-last-item-stale-refused',
    'membership-lifecycle-member.end-actual-csrf-form-commits',
    'membership-lifecycle-member.end-actual-form-without-csrf-no-write',
    'membership-lifecycle-member.suspend-actual-csrf-form-commits',
    'membership-lifecycle-member.suspend-actual-form-without-csrf-no-write',
    'membership-lifecycle-real-audit-fault-trigger-installed',
    'membership-lifecycle-real-sql-audit-fault-observed-after-member-write',
    'membership-lifecycle-real-two-member-batch-commits-one-unit-version',
    'membership-lifecycle-successful-batch-replay-has-no-additional-writes',
    'membership-lifecycle-two-real-writers-wait-on-native-organization-mutex',
    'native-compound-administrator-form-reviews-fixed-membership-impact-before-approval',
    'native-compound-approved-revoke-and-end-retains-history-and-removes-new-directory-access',
    'native-compound-concurrent-consumed-approval-id-retries-never-reconsume-or-duplicate-ledger',
    'native-compound-consumed-approval-id-form-retries-committed-ledger-without-writes',
    'native-compound-consumed-approval-id-refuses-executor-whose-native-admin-role-was-revoked',
    'native-compound-consumed-approval-id-still-requires-bound-current-executor',
    'native-compound-executor-revocation-uses-real-no-factor-normal-session',
    'native-compound-explicit-member-and-appointment-enable-only-own-team-directory',
    'native-compound-grant-audit-fault-rolls-back-new-member-appointment-consumption-and-ledger',
    'native-compound-grant-audit-trigger-actually-installed',
    'native-compound-independent-live-administrator-approves-exact-before-sources',
    'native-compound-member-appointment-and-approved-consumption-commit-together',
    'native-compound-operation-replay-does-not-reconsume-approved-ticket',
    'native-compound-owned-sql-fault-trigger-actually-installed',
    'native-compound-parameter-tamper-cannot-insert-member',
    'native-compound-preview-declares-explicit-membership-impact-and-independent-approval',
    'native-compound-proposal-keeps-real-before-member-absent',
    'native-compound-real-audit-fault-rolls-back-both-relationships-consumption-unit-and-ledger',
    'native-compound-requester-cannot-self-approve',
    'native-compound-two-approval-id-retries-really-wait-on-organization-mutex',
    'native-revoked-team-manager-cannot-replay-old-successful-operation',
    'native-standalone-appointment-approval-cannot-authorize-compound-member-end',
    'native-team-manager-cannot-remove-own-administrator-membership-prerequisite',
    'native-team-manager-direct-ordinary-membership-needs-no-per-change-approval',
    'native-team-manager-ordinary-suspend-form-missing-csrf-refused',
    'native-team-manager-ordinary-suspend-form-real-csrf-current-role-commits','all-owned-full-native-schemas-and-principals-cleaned',
           'completion-reader-cannot-modify-ownership-marker',
           'completion-reader-forbidden-privilege-denied',
           'completion-reader-real-select-only-grants-and-lineage-proved',
           'completion-real-gorge-file-ready-loopback',
           'completion-writer-cannot-modify-ownership-marker',
           'completion-writer-forbidden-privilege-denied',
           'directory-completion-archive-preview-reports-effects-without-revocation-claim',
           'directory-completion-archive-removes-new-directory-visibility',
           'directory-completion-archive-restore-stays-review-gated',
           'directory-completion-clear-main-department-retains-monotonic-row-fence',
           'directory-completion-cleared-inactive-department-can-explicitly-reassign',
           'directory-completion-contact-visible-only-in-permitted-current-roster',
           'directory-completion-depth-eight-nine-real-hierarchy-soft-warning',
           'directory-completion-depth-nine-is-allowed-not-hard-limit',
           'directory-completion-hidden-parent-department-nonmember-contact-redacted',
           'directory-completion-hidden-phid-shaped-unit-token-never-falls-back-to-native-handle',
           'directory-completion-inactive-attention-has-real-audited-clear-form',
           'directory-completion-member-keyset-and-count-over-first-hundred-native-rows',
           'directory-completion-native-datasource-shares-privacy-projection',
           'directory-completion-phid-shaped-unit-token-resolves-organization-domain',
           'directory-completion-preview-cycle-refused',
           'directory-completion-real-chinese-page-localizes-inactive-department-status',
           'directory-completion-real-chinese-page-localizes-unassigned-status',
           'directory-completion-real-csrf-finite-date-form-persists-native-timestamp',
           'directory-completion-real-form-contact-edit-retains-unavailable-department-reference',
           'directory-completion-real-form-explicit-clear-does-not-add-contact-membership',
           'directory-completion-real-form-rename-retains-unavailable-parent-reference',
           'directory-completion-real-html-sends-directory-literal-normalizer-with-people-default',
           'directory-completion-search-literal-wildcard-keyset-count-native-sql',
           'directory-completion-selector-case-folding-does-not-disclose-hidden-units',
           'directory-completion-selector-lowercase-partial-matches-uppercase-native-name',
           'directory-completion-selector-native-normalized-q-preserves-raw-uppercase-punctuation',
           'directory-completion-selector-percent-underscore-remain-literal-not-wildcards',
           'directory-completion-stale-clear-refused',
           'directory-completion-tokenless-native-datasource-real-csrf-guarded-get',
           'directory-completion-unassigned-admin-warning-real-query',
           'directory-completion-unassigned-query-denies-team-admin',
           'directory-completion-unavailable-current-references-admin-only-not-manager',
           'directory-completion-unknown-model-approval-proposal-refused',
           'directory-completion-unknown-model-independent-approval-refused',
           'directory-completion-unknown-model-no-new-or-approved-ticket',
           'directory-completion-unknown-visibility-model-fails-closed',
           'full-current-source-quickstart-and-patch-ledger-restored',
           'native-actual-media-credential-does-not-revive-after-account-disable-enable',
           'native-api-token-locks-real-token-owner-and-primary',
           'native-approval-cannot-authorize-another-operation',
           'native-approval-consume-and-appointment-commit-together',
           'native-approval-detects-membership-version-and-team-source-change',
           'native-approval-independent-current-admin-approved',
           'native-approval-real-database-clock-expiry-rejected',
           'native-approval-real-request-submitted',
           'native-approval-requester-cannot-self-approve',
           'native-approval-same-approver-idempotent-no-new-approval',
           'native-approval-wrong-operation-keeps-approved-ticket',
           'native-approved-business-operation-replays-with-current-authority',
           'native-approved-manager-list-selector-count-export-same-projection',
           'native-approved-manager-other-team-roster-refused',
           'native-approved-manager-own-explicit-team-roster-visible',
           'native-approved-ticket-invalidated-by-approver-actual-logout',
           'native-approver-logout-rejection-preserves-ticket-and-appointment',
           'native-auth-negative-paths-release-owned-transactions',
           'native-committed-operation-replay-refused-after-real-admin-revocation',
           'native-conduit-authentication-branch-attaches-real-api-context',
           'native-conduit-rejects-nonexistent-api-token',
           'native-consumed-approval-cannot-be-rebound',
           'native-controller-after-get-normal-writes-remain-csrf-guarded',
           'native-controller-current-session-list-real-service',
           'native-controller-feature-off-route-unavailable',
           'native-controller-invalid-cookie-get-no-issuer-metadata-or-unguarded-leak',
           'native-controller-json-operation-replay-no-extra-row',
           'native-controller-json-post-without-csrf-refused-no-write',
           'native-controller-json-post-without-form-action-real-commit',
           'native-controller-missing-json-payload-refused-without-native-warning',
           'native-controller-tokenless-get-first-issuer-metadata-initially-absent',
           'native-controller-tokenless-get-real-csrf-guard-first-issuer-initialized',
           'native-controller-viewer-alone-wrong-cookie-no-count-leak',
           'native-cookie-and-csrf-request-created-sealed-actor',
           'native-current-api-token-deletion-invalidates-prior-context',
           'native-current-api-token-expiry-invalidates-prior-context',
           'native-current-web-session-deletion-invalidates-prior-context',
           'native-directory-child-manager-cannot-read-parent-roster',
           'native-directory-child-manager-hidden-parent-id-redacted',
           'native-directory-child-manager-list-selector-count-export-share-projection',
           'native-directory-child-manager-members-current-team-only',
           'native-directory-count-final-db-clock-expiry-refused-fixture-seam',
           'native-directory-count-guard-revocation-real-writer-wait-observed',
           'native-directory-count-guard-wait-revocation-no-pre-lock-read-view',
           'native-directory-count-real-capture-before-expiry-fixture-seam',
           'native-directory-guard-revocation-real-writer-wait-observed',
           'native-directory-guard-wait-revocation-no-pre-lock-read-view',
           'native-directory-list-expiry-after-lock-wait-refused',
           'native-directory-list-expiry-current-source-row-wait-observed',
           'native-directory-native-session-default-remains-repeatable-read',
           'native-directory-next-native-transaction-retains-repeatable-read-view',
           'native-directory-ordinary-member-self-has-no-roster-exception',
           'native-directory-organization-transaction-does-not-change-session-isolation',
           'native-directory-other-user-hidden-parent-membership-not-disclosed',
           'native-directory-parent-manager-cannot-read-child-roster',
           'native-directory-parent-manager-no-automatic-child-list',
           'native-directory-parent-manager-other-user-relations-filtered',
           'native-directory-parent-reference-only-after-explicit-parent-appointment',
           'native-disable-enable-without-intermediate-read-does-not-revive-issued-binding',
           'native-fixture-background-execution-disabled',
           'native-fresh-session-after-enable-remains-usable',
           'native-invalid-credential-leaves-no-initialization-metadata',
           'native-invented-credential-cannot-initialize-actor',
           'native-legacy-agent-aba-does-not-revive-old-media-credential',
           'native-legacy-agent-aba-increments-independent-epoch-without-native-user-transaction',
           'native-legacy-agent-actual-epoch-sql-fault-observed',
           'native-legacy-agent-fault-leaves-native-role-and-epoch-unchanged',
           'native-legacy-agent-fault-no-owned-lock-or-transaction-leak',
           'native-legacy-agent-fault-rolls-back-and-preserves-only-outer-lock',
           'native-legacy-agent-fault-trigger-actually-installed',
           'native-legacy-agent-fresh-session-after-role-roundtrip-usable',
           'native-legacy-mail-aba-does-not-revive-old-media-credential',
           'native-legacy-mail-aba-increments-independent-epoch-without-native-user-transaction',
           'native-legacy-mail-actual-epoch-sql-fault-observed',
           'native-legacy-mail-fault-leaves-native-role-and-epoch-unchanged',
           'native-legacy-mail-fault-no-owned-lock-or-transaction-leak',
           'native-legacy-mail-fault-rolls-back-and-preserves-only-outer-lock',
           'native-legacy-mail-fault-trigger-actually-installed',
           'native-legacy-mail-fresh-session-after-role-roundtrip-usable',
           'native-media-after-restore-requires-new-browser-transaction-and-current-issuer',
           'native-media-authentication-restores-request-guard-before-ordinary-write',
           'native-media-binding-failures-do-not-prevent-legitimate-redemption',
           'native-media-bounded-cleanup-leaves-rest-for-next-invocation',
           'native-media-browser-binding-refused',
           'native-media-browser-keeps-only-one-unissued-prepared-target',
           'native-media-browser-new-generation-revokes-old-credential',
           'native-media-browser-refusal-no-consume-or-generation-write',
           'native-media-capacity-denial-leaves-code-redeemable-after-space-is-freed',
           'native-media-capacity-refused-redemption-does-not-consume-code-or-revoke-credential',
           'native-media-capacity-released-by-owned-expired-row-removal',
           'native-media-cleanup-can-complete-after-owned-fault-is-removed',
           'native-media-cleanup-fault-rolls-back-earlier-expiration-deletes',
           'native-media-cleanup-retains-current-credential-and-native-accounts',
           'native-media-client-nonce-required-at-redemption',
           'native-media-client-persistent-admission-quota-refuses-new-browser',
           'native-media-client-quota-denial-no-partial-ledger',
           'native-media-clock-regression-refusal-no-partial-admission',
           'native-media-code-binding-refused',
           'native-media-code-cannot-be-reissued',
           'native-media-code-refusal-no-consume-or-generation-write',
           'native-media-code-successfully-consumed-exactly-once',
           'native-media-concurrent-code-redemption-has-one-commit',
           'native-media-concurrent-preparation-does-not-exceed-six-browser-admissions',
           'native-media-concurrent-redemption-consumes-one-code-in-issued-generation',
           'native-media-concurrent-success-authenticates-and-replay-is-refused',
           'native-media-csrf-guarded-get-does-not-enable-public-issuance',
           'native-media-csrf-guarded-get-does-not-mutate-media-ledgers',
           'native-media-csrf-guarded-get-does-not-unguard-public-prepare',
           'native-media-csrf-guarded-get-does-not-unguard-public-redeem',
           'native-media-csrf-guarded-get-releases-identity-transactions',
           'native-media-current-browser-generation-authenticates-new-account',
           'native-media-disabled-authentication-refused',
           'native-media-disabled-backend-does-not-create-or-change-ledger-rows',
           'native-media-disabled-issuance-refused',
           'native-media-disabled-preparation-refused',
           'native-media-disabled-redemption-refused',
           'native-media-expired-cleanup-obeys-per-table-row-budget',
           'native-media-expired-ledger-rows-still-count-against-total-capacity',
           'native-media-failed-authenticated-issue-real-sql-fault-refused',
           'native-media-failed-issue-preserves-current-account-credential',
           'native-media-failed-issue-rolls-back-browser-generation-and-credential-revocation',
           'native-media-future-quota-window-refuses-clock-regression',
           'native-media-global-persistent-admission-quota-refuses-new-browser',
           'native-media-global-quota-denial-no-partial-ledger',
           'native-media-governance_mediabrowser-myisam-early-refused',
           'native-media-governance_mediabrowser-myisam-refusal-no-write',
           'native-media-governance_mediacredential-myisam-early-refused',
           'native-media-governance_mediacredential-myisam-refusal-no-write',
           'native-media-governance_mediaexchange-myisam-early-refused',
           'native-media-governance_mediaexchange-myisam-refusal-no-write',
           'native-media-hard-capacity-denial-rolls-back-new-quota-bucket',
           'native-media-hard-capacity-only-one-real-preparation-commits',
           'native-media-hard-capacity-two-real-preparers-wait-on-capacity-mutex',
           'native-media-incarnation-only-rotation-rejects-all-old-media-retains-native-and-allows-fresh',
           'native-media-invalid-credential-refused-on-csrf-guarded-get',
           'native-media-issue-fault-trigger-really-installed',
           'native-media-issuer-never-accepts-secret-restored-from-config-database',
           'native-media-last-quota-slot-only-one-real-preparation-commits',
           'native-media-last-quota-slot-two-real-preparers-wait-on-capacity-mutex',
           'native-media-later-table-cleanup-real-sql-fault-refused',
           'native-media-legacy-incarnation-refusal-does-not-promote-row',
           'native-media-missing-external-key-does-not-fall-back-to-database-state',
           'native-media-new-authenticated-issue-immediately-revokes-old-credential',
           'native-media-null-legacy-incarnation-is-never-upgraded',
           'native-media-old-account-pending-code-cannot-preempt-new-account-issue',
           'native-media-old-outstanding-transaction-cannot-replace-new-generation',
           'native-media-old-table-snapshot-restored-exactly-through-real-sql',
           'native-media-only-rotation-keeps-underlying-native-api-authentication',
           'native-media-only-rotation-keeps-underlying-native-session-authentication',
           'native-media-preparation-alone-does-not-revoke-current-credential',
           'native-media-prepared-client-nonce-required-at-issue',
           'native-media-prepared-target-cannot-be-changed-at-issue',
           'native-media-prepared-unicode-download-returns-exact-canonical-target',
           'native-media-real-database-window-transition-resets-admission-counter',
           'native-media-redemption-cannot-exceed-total-ledger-capacity',
           'native-media-reissue-refusal-preserves-code',
           'native-media-rejected-preparation-rolls-back-all-quota-and-ledger-writes',
           'native-media-repeat-redemption-no-extra-credential',
           'native-media-restore-does-not-revoke-replace-or-change-native-credential-metadata',
           'native-media-restored-old-browser-slot-cannot-be-reused',
           'native-media-restored-old-credential-refused-by-external-incarnation',
           'native-media-restored-old-state-is-not-promoted-or-upgraded',
           'native-media-restored-old-unconsumed-code-refused',
           'native-media-return-path-binding-refused',
           'native-media-return-path-refusal-no-consume-or-generation-write',
           'native-media-scoped-rc-does-not-change-next-native-repeatable-read',
           'native-media-session-bound-issue-redeem-authenticate',
           'native-media-seventh-browser-preparation-refused-by-persistent-quota',
           'native-media-source-migrated-sha-only-browser-is-not-upgraded',
           'native-media-source-migration-keeps-real-v1-row-unassigned-to-current-issuer',
           'native-media-source-migration-legacy-refusal-performs-no-promotion',
           'native-media-target-and-nonce-refusal-no-generation-or-code-write',
           'native-media-two-real-redeemers-wait-on-capacity-mutex',
           'native-media-unbound-preparation-api-fails-closed',
           'native-media-valid-credential-authenticates-on-real-csrf-guarded-get',
           'native-media-waiting-preparer-fresh-count-honors-exact-total-capacity',
           'native-organization-unit-create-and-protected-count',
           'native-people-dao-synthetic-accounts-created',
           'native-primary-department-real-people-table-commit',
           'native-rejected-token-does-not-create-governance-context',
           'native-replay-revocation-actor-has-actual-no-factor-normal-session',
           'native-replay-revocation-uses-native-no-factor-mfa-path-without-forced-high-security',
           'native-revoked-replay-keeps-one-ledger-record-and-cleans-transaction',
           'native-schema-comparison-owned-people-database-readable',
           'native-schema-comparison-single-owned-native-primary',
           'native-schema-governance_mediabrowser-matches-retained-adjustment-spec',
           'native-schema-governance_mediacredential-matches-retained-adjustment-spec',
           'native-schema-governance_mediaexchange-matches-retained-adjustment-spec',
           'native-schema-governance_mediaquota-matches-retained-adjustment-spec',
           'native-schema-organization_appointment-matches-retained-adjustment-spec',
           'native-schema-organization_approval-matches-retained-adjustment-spec',
           'native-schema-organization_audit-matches-retained-adjustment-spec',
           'native-schema-organization_guard-matches-retained-adjustment-spec',
           'native-schema-organization_identityepoch-matches-retained-adjustment-spec',
           'native-schema-organization_member-matches-retained-adjustment-spec',
           'native-schema-organization_operation-matches-retained-adjustment-spec',
           'native-schema-organization_primarydepartment-matches-retained-adjustment-spec',
           'native-schema-organization_unit-matches-retained-adjustment-spec',
           'native-session-engine-issued-and-loaded-actual-web-session',
           'owned-native-write-guard-disposed',
           'owned-writer-environment-and-cache-restored'})
# Actual prior isolated N11 checks; combined main receipt is required before acceptance.
REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset(['completion-real-gorge-taskqueue-ready-loopback',
 'private-mail-cached-snapshot-quarantined-without-immutable-rewrite',
 'private-mail-current-disabled-recipient-cannot-use-business-mail',
 'private-mail-current-native-file-query-revocation-stops-existing-snapshot',
 'private-mail-fresh-native-task-space-query-restores-request-cache',
 'private-mail-immutable-gorge-snapshot-generic-subject-body-headers',
 'private-mail-mutated-physical-payload-refused',
 'private-mail-owned-queue-cleanup',
 'private-mail-physical-attempt-binds-actual-current-recipient-object-and-message',
 'private-mail-real-config-default-off',
 'private-mail-real-owned-queue-ready-no-workers',
 'private-mail-real-persisted-business-envelope-no-private-bytes',
 'private-mail-regrant-cannot-resurrect-quarantined-immutable-delivery',
 'private-mail-space-revocation-cannot-reuse-stale-native-request-cache',
 'private-mail-unreviewed-producer-fails-closed',
 'private-mail-unsafe-legacy-snapshot-never-upgraded-or-resent'])

FIELDS = {'batch', 'passed', 'scope', 'checks', 'check_count', 'php_version',
    'source_restore', 'native', 'worker_cleanup', 'file_service_cleanup',
    'successful_owned_file_service_startups', 'cleanup', 'failure', 'cleanup_failures',
    'optional_source_reader_adapter_exercised', 'isolated_optional_features_enabled',
    'terminal_failure_cleanup_rollbacks', 'captured_native_output_bytes',
    'captured_native_output_sha256', 'production_deployment', 'production_executor',
    'production_optional_features_enabled', 'native_permission_axes_changed', 'remaining_blocked'}
BLOCKERS = ['media-http-and-cookie-integration', 'file-object-guards',
    'notification-producers', 'global-governance-completion']
NATIVE = {'directory_completion_tests': {'native_hierarchy_and_metadata': True,
                                'native_datasource': True,
                                'schema_migration_test_environment_only': True,
                                'native_project_permissions_changed': False,
                                'audit_cleanup_enabled': False,
                                'handover_implemented': False},
 'actual_native_account_source': True,
 'actual_session_engine': True,
 'actual_csrf_validation': True,
 'isolated_features_enabled': True,
 'production_features_enabled': False,
 'media_backend_only': True,
 'daemon_or_external_delivery_executed': False,
 'schema_tests': {'native_schema_comparison_executed': True,
                  'candidate_tables_compared': 13,
                  'schema_adjustment_executed': False,
                  'all_existing_schema_issues_claimed_resolved': False},
 'route_tests': {'actual_controller_called': True,
                 'http_server_executed': False,
                 'browser_executed': False,
                 'csrf_and_cookie_checked': True,
                 'actual_request_write_guard_callback': True,
                 'readonly_unguarded_scope_restored': True},
 'privacy_tests': {'actual_people_accounts': True,
                   'actual_native_sessions': True,
                   'actual_directory_queries': True,
                   'existing_appointments_fixture_seed_only': True,
                   'appointment_grant_workflow_proof': False,
                   'native_permission_axes_changed': False},
 'concurrency_tests': {'separate_exec_workers': True,
                       'real_guard_lock_wait': True,
                       'real_list_period_lock_wait': True,
                       'count_evidence': 'fixture-only-private-real-capture-and-final-gate',
                       'full_count_route_midrequest_wait_proved': False,
                       'response_delivery_instant_freshness_promised': False},
 'media_tests': {'actual_digest_and_browser_binding': True,
                 'same_browser_generation_invalidation': True,
                 'actual_three_table_engine_drift_refused': True,
                 'latest_authenticated_issue_wins': True,
                 'failed_issue_atomic_rollback': True,
                 'concurrent_redemption_proved': True,
                 'http_origin_cookie_and_active_content_proved': False},
 'auth_tests': {'native_api_authentication_branch_exercised': True,
                'native_durable_two_person_approval': True,
                'native_web_and_api_revocation_checked': True,
                'daemon_or_external_delivery_executed': False},
 'legacy_role_tests': {'legacy_role_epoch_aba_verified': True,
                       'legacy_role_sql_fault_verified': True,
                       'legacy_role_outer_lock_preserved': True,
                       'native_revoked_operation_replay_refused': True,
                       'failed_role_search_queue_deltas': {'agent': 1, 'mail': 1},
                       'rollback_claim': 'native user role and identityepoch only; existing '
                                         'indexing enqueue effects measured separately'},
 'native_approval_implemented': True,
 'issuer_tests': {'restore_scope': 'three-media-tables-logical-snapshot-and-real-sql-restore',
                  'restored_snapshot_sha256': 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
                  'media_only_external_rotation': True,
                  'incarnation_only_rotation_verified': True,
                  'old_native_credentials_retained_and_verified': True,
                  'native_credential_metadata_changed': False,
                  'old_media_rows_promoted': False,
                  'full_disaster_recovery_certified': False},
 'issuer_upgrade_tests': {'scope': 'disposable-v1-media-schema-reconstruction-and-real-source-v2-alter',
                          'migration_sha256': '2cb870c03069cffd2be3ef801b963265f1f5d7c866c0ee80935f1ebfdaac2957',
                          'old_rows_reassigned_current_issuer': False},
 'target_tests': {'admission_tests': {'real_prepare_races_verified': True,
                                      'hard_total_capacity_verified': True,
                                      'database_window_transition_verified': True,
                                      'cleanup_sql_rollback_verified': True,
                                      'next_native_rr_preserved': True},
                  'prepared_target_and_nonce_verified': True,
                  'persistent_admission_quotas_verified': True,
                  'bounded_expired_cleanup_verified': True,
                  'http_routes_enabled': False,
                  'native_authentication_rows_deleted': False}}
NATIVE.update({'compound_tests': {'before_source_membership_not_fabricated': True,
                    'explicit_compound_native_transaction': True,
                    'full_compound_failure_matrix_complete': False,
                    'independent_approval': True,
                    'native_project_permissions_changed': False,
                    'production_activation': False},
 'membership_lifecycle_tests': {'compound_approval_implemented': False,
                                'native_batch_atomicity': True,
                                'native_sql_fault_rollback': True,
                                'production_activation': False,
                                'public_csrf_lifecycle_complete': False,
                                'retained_relationship_history': True}})
WORKERS = 13


def exact_tree(actual, expected):
    if type(actual) is not type(expected):
        raise ValueError('untyped evidence')
    if isinstance(expected, dict):
        if set(actual) != set(expected):
            raise ValueError('unknown or missing evidence fields')
        for key in expected:
            exact_tree(actual[key], expected[key])
    elif actual != expected:
        raise ValueError('evidence scope or result refused')


REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset(['private-mail-admin-notice-current-demotion-blocks-existing-immutable-delivery',
 'private-mail-admin-queue-generic-body-subject-no-pending-account-disclosure',
 'private-mail-approval-demoted-admin-copy-refused-owner-security-notice-preserved',
 'private-mail-configured-cluster-routing-refused-before-native-authorization',
 'private-mail-email-verification-rotated-native-code-rejects-old-message',
 'private-mail-email-verification-unverified-native-address-and-real-code-preserved',
 'private-mail-native-account-secret-rotation-rejects-prepared-login-challenge',
 'private-mail-native-invite-regeneration-rejects-prior-challenge',
 'private-mail-native-primary-switch-retaining-old-address-refuses-bound-login-delivery',
 'private-mail-native-readonly-profile-refused-without-replica-fallback',
 'private-mail-no-primary-email-native-approval-commits-without-owner-notice',
 'private-mail-no-primary-email-native-rename-commits-without-owner-notice',
 'private-mail-no-primary-email-native-withdrawal-commits-without-owner-notice',
 'private-mail-real-email-verification-producer-single-native-message',
 'private-mail-real-invite-worker-native-code-and-current-author-bound',
 'private-mail-real-login-producer-retains-native-one-time-challenge',
 'private-mail-real-native-approval-transaction-owner-admin-separated-and-event-bound',
 'private-mail-real-native-username-transaction-binds-committed-user-event',
 'private-mail-real-registration-admin-notice-producer-splits-native-recipients',
 'private-mail-real-welcome-producer-retains-native-challenge-and-admin-binding',
 'private-mail-security-class-name-alone-cannot-authorize-legacy-challenge',
 'private-mail-security-user-edits-use-real-native-session-and-request',
 'private-mail-welcome-current-sender-administrator-required-after-prepare'])

NATIVE['private_mail_tests'] = {'actual_native_queue': True,
 'actual_native_source_and_object_query': True,
 'all_producers_runtime_complete': False,
 'physical_callback_runtime_complete': False,
 'real_business_serialization_and_legacy_quarantine': True,
 'security_challenge_runtime_matrix_complete': False,
 'smtp_or_delivery_workers_executed': False}

NATIVE['private_mail_tests']['security_flow_tests'] = {'actual_login_welcome_email_verification_and_registration_producers': True,
 'actual_username_approval_transactions_and_invite_worker': True,
 'all_security_producer_runtime_complete': False,
 'current_admin_and_challenge_sources_checked': True,
 'legalpad_signature_delivery_supported': False,
 'registration_private_method_seam_not_full_registration_http': True,
 'smtp_security_matrix_complete': False}

def validate_restore(value, root):
    fields = {'quickstart_sha256', 'app_schema_count', 'owned_schema_count',
        'quickstart_source_table_count', 'current_table_count', 'patch_audit',
        'restore_phase_workers_started', 'restore_phase_network_endpoints_configured'}
    if type(value) is not dict or set(value) != fields:
        raise ValueError('restore fields refused')
    expected = {'quickstart_sha256': hashlib.sha256((root/'resources/sql/quickstart.sql').read_bytes()).hexdigest(),
        'app_schema_count': 54, 'owned_schema_count': 55, 'quickstart_source_table_count': 500,
        'restore_phase_workers_started': 0, 'restore_phase_network_endpoints_configured': 0}
    for key, target in expected.items():
        exact_tree(value[key], target)
    exact_tree(value['current_table_count'], 530)
    audit=value['patch_audit']
    if type(audit) is not dict or set(audit) != {'source_patch_count', 'applied_patch_count',
        'pending_patch_keys', 'current_patch_ledger_complete', 'migration_source_hashes', 'migrations_executed'}:
        raise ValueError('patch audit fields refused')
    # Use the same reviewed executable migration set as the full native fixture.
    hashes={'phabricator:'+p: hashlib.sha256((root/'resources/sql/autopatches'/p).read_bytes()).hexdigest()
        for p in completion.PATCHES}
    exact_tree(audit['migration_source_hashes'], hashes)
    exact_tree(audit['migrations_executed'], len(completion.PATCHES))
    exact_tree(audit['pending_patch_keys'], [])
    exact_tree(audit['current_patch_ledger_complete'], True)
    exact_tree(audit['source_patch_count'], 1566)
    exact_tree(audit['applied_patch_count'], audit['source_patch_count'])


def validate(data, root=ROOT, file_digest=None):
    if type(data) is not dict or set(data) != FIELDS:
        raise ValueError('native receipt fields refused')
    native=data['native']
    if type(native) is not dict or type(native.get('issuer_tests')) is not dict:
        raise ValueError('native issuer summary refused')
    snapshot=native['issuer_tests'].get('restored_snapshot_sha256')
    if type(snapshot) is not str or not re.fullmatch(r'[a-f0-9]{64}',snapshot):
        raise ValueError('media restore snapshot digest refused')
    expected_native=dict(NATIVE)
    expected_native['issuer_tests']=dict(NATIVE['issuer_tests'],restored_snapshot_sha256=snapshot)
    exact_tree(native,expected_native)
    for key, target in {'batch': 'native-organization-media-integration', 'passed': True,
        'scope': 'owned-full-native-source-synthetic-accounts', 'php_version': '8.5.12',
        'failure': None, 'cleanup_failures': [], 'terminal_failure_cleanup_rollbacks': 0,
        'production_deployment': False, 'production_executor': False,
        'production_optional_features_enabled': False, 'native_permission_axes_changed': False,
        'successful_owned_file_service_startups': 1,
        'optional_source_reader_adapter_exercised': True, 'isolated_optional_features_enabled': True,
        'remaining_blocked': BLOCKERS,
        'worker_cleanup': {'workers_registered': WORKERS, 'private_directories_removed': WORKERS,
            'private_directories_remaining': 0, 'live_processes_after_cleanup': 0,
            'owned_processes_stopped': 0, 'cleanup_failures': 0},
        'cleanup': {'ownedSchemasRemoved': 55, 'ownedRuntimeUsersRemoved': 2, 'readerRemoved': True}}.items():
        exact_tree(data[key], target)
    service=data['file_service_cleanup']
    if type(service) is not dict or set(service) != {'kind','binary_sha256','process_stopped','private_storage_removed'}:
        raise ValueError('paired file service cleanup fields refused')
    for key, target in {'kind':'file','process_stopped':True,'private_storage_removed':True}.items():
        exact_tree(service[key], target)
    if type(file_digest) is not str or not re.fullmatch(r'[a-f0-9]{64}',file_digest):
        raise ValueError('paired binary input required')
    if service['binary_sha256'] != file_digest:
        raise ValueError('paired binary digest refused')
    checks=data['checks']
    if (type(checks) is not list or not REQUIRED_CHECKS or any(type(c) is not str for c in checks)
        or len(checks) != len(set(checks)) or set(checks) != REQUIRED_CHECKS):
        raise ValueError('native scenario coverage refused')
    exact_tree(data['check_count'], len(checks))
    validate_restore(data['source_restore'], Path(root))
    if (type(data['captured_native_output_bytes']) is not int or data['captured_native_output_bytes'] < 0
        or type(data['captured_native_output_sha256']) is not str
        or not re.fullmatch(r'[a-f0-9]{64}', data['captured_native_output_sha256'])):
        raise ValueError('captured output summary refused')
    return {'schema_valid': True, 'accepted_scope': 'bounded-native-directory-identity-media-backend',
        'check_count': len(checks), 'production_optional_features_enabled': False,
        'deployment_proved': False, 'browser_proved': False, 'global_completion': False,
        'remaining_blocked': BLOCKERS}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--receipt', type=Path, required=True)
    parser.add_argument('--file-binary', type=Path, required=True)
    args=parser.parse_args()
    try:
        if args.receipt.stat().st_size > 1048576:
            raise ValueError('receipt size refused')
        data=json.loads(args.receipt.read_text(), object_pairs_hook=completion.strict_object,
            parse_constant=lambda unused: (_ for _ in ()).throw(ValueError('invalid JSON constant')))
        if not args.file_binary.is_file():
            raise ValueError('paired binary missing')
        accepted=validate(data, file_digest=hashlib.sha256(args.file_binary.read_bytes()).hexdigest())
    except (OSError, ValueError, TypeError, KeyError):
        parser.exit(1, 'Native organization receipt rejected.\n')
    print(json.dumps(accepted, sort_keys=True))


if __name__ == '__main__':
    main()
