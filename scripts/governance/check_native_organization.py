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
    'native-media-http-cross-site-configuration-refused-before-state-276dccdd1760a117d0e89f94163e0d013a17f639e2be134df07fb78aa62a1414',
    'native-media-http-cross-site-configuration-refused-before-state-764abda848066874da61f25c69020fe1c5420068c5d5afa4272cbfa7712ede8c',

    'native-media-http-foreign-origin-preparation-refused',
    'native-media-http-missing-origin-preparation-refused',
    'native-media-http-null-origin-preparation-refused',
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
# Explicit previously executed organization obligations; current main union still needs a fresh run.
ORGANIZATION_REQUIRED_CHECKS=frozenset({'native-handover-above-thirty-preview-preserves-server-derived-total-and-explicit-exception',
 'native-handover-above-thirty-requires-distinct-exception-reason',
 'native-handover-actual-execution-consumes-and-extends-one-current-row-with-original-start',
 'native-handover-actual-preview-confirmation-form-persists-pending-ticket',
 'native-handover-actual-preview-form-binds-server-derived-primary-and-unit-versions-without-authority',
 'native-handover-approval-expiry-after-real-wait-cannot-consume-or-extend',
 'native-handover-approver-cannot-become-executor',
 'native-handover-bound-executor-form-requires-explicit-ack-and-switches-primary-once',
 'native-handover-committed-manual-replay-still-requires-current-project-visibility',
 'native-handover-committed-replay-does-not-consume-extend-or-audit-again',
 'native-handover-consumed-execution-form-retry-uses-ledger-without-reconsumption',
 'native-handover-current-disabled-executor-cannot-replay-consumed-form',
 'native-handover-current-native-viewer-query-refuses-hidden-project-receipts',
 'native-handover-different-operation-ids-cannot-amplify-one-deadline-event',
 'native-handover-due-audit-fault-rolls-new-event-and-operation-ledger-back',
 'native-handover-due-event-real-post-without-csrf-has-no-ledger-or-outbox-effects',
 'native-handover-due-fault-trigger-is-owned-and-actually-installed',
 'native-handover-due-operation-id-cannot-bind-another-handover',
 'native-handover-due-operation-replay-does-not-multiply-events-or-audit',
 'native-handover-due-page-does-not-classify-future-deadline-as-due-or-dispatch',
 'native-handover-expiry-worker-reaches-real-approval-row-wait-after-source-locks',
 'native-handover-explicit-due-post-persists-one-undispatched-reference-event',
 'native-handover-extension-preview-derives-total-from-stored-original-calendar',
 'native-handover-extension-real-csrf-route-persists-bound-pending-ticket',
 'native-handover-extension-real-post-without-csrf-cannot-propose',
 'native-handover-form-nonnumeric-calendar-day-cannot-silently-become-immediate-transfer',
 'native-handover-historical-state-due-keyset-pages-progress-by-stored-deadline-and-id',
 'native-handover-independent-approval-form-requires-real-csrf-and-distinct-current-admin',
 'native-handover-independent-current-admin-approval-uses-actual-credential-source',
 'native-handover-independent-review-page-renders-ticket-details-without-private-source-snapshots',
 'native-handover-independent-termination-preserves-current-primary-department',
 'native-handover-management-ordinary-member-cannot-read-admin-forms-or-ticket-identifiers',
 'native-handover-management-real-chinese-html-and-department-tokenizer-config-render',
 'native-handover-management-route-default-off-unavailable',
 'native-handover-manual-project-receipt-is-not-a-project-policy-mutation-or-exhaustive-list',
 'native-handover-native-account-disable-is-not-blocked-by-responsibility',
 'native-handover-ordinary-subject-cannot-read-approval-or-receive-self-exception',
 'native-handover-owned-audit-fault-trigger-actually-installed',
 'native-handover-parallel-approved-execution-extends-once-and-replays-once',
 'native-handover-preview-form-without-real-csrf-cannot-create-ticket',
 'native-handover-project-receipt-output-hides-now-invisible-reference-and-count',
 'native-handover-real-audit-fault-rolls-consumption-deadline-outbox-and-ledger-back',
 'native-handover-real-parallel-executors-wait-on-existing-organization-lock-order',
 'native-handover-requester-cannot-self-approve-extension',
 'native-handover-responsible-replacement-is-descriptive-with-no-team-membership-or-admin-grant',
 'native-noncredential-v1-handover-first-issuer-change-remains-stale-without-reinterpretation',
 'native-noncredential-v1-handover-restored-unchanged-facts-retain-original-execution-rules',
 'native-noncredential-v1-restored-ticket-keeps-original-first-issuer-stale-boundary-without-reinterpretation',
 'native-noncredential-v1-restored-unchanged-facts-remain-executable-under-legacy-rules',
 'native-noncredential-v2-facts-cannot-be-reinterpreted-by-restamping-stored-v1-schema',
 'native-noncredential-v2-first-real-subject-directory-access-preserves-independent-approval-and-execution',
 'native-noncredential-v2-handover-owner-first-real-directory-access-does-not-stale-unchanged-facts',
 'native-noncredential-v2-legacy-agent-aba-without-user-transaction-stales-pending-ticket',
 'native-noncredential-v2-legacy-mail-aba-without-user-transaction-stales-pending-ticket',
 'native-noncredential-v2-missing-epoch-table-never-falls-back-to-absent-row-one',
 'native-noncredential-v2-noninnodb-epoch-source-refused-before-returning-facts',
 'native-noncredential-v2-proposal-binds-locked-generation-one-without-initializing-subject-credential',
 'native-noncredential-v2-real-disable-enable-user-transaction-aba-stales-pending-ticket',
 'native-transfer-actual-csrf-post-dispatches-fixed-native-service-proposal',
 'native-transfer-actual-execute-without-csrf-never-consumes-approved-request',
 'native-transfer-actual-get-revalidates-current-admin-and-restores-read-guard',
 'native-transfer-actual-main-execution-waits-on-approved-row-after-native-source-locks',
 'native-transfer-actual-post-without-csrf-cannot-propose-or-write',
 'native-transfer-approved-consumption-primary-handover-and-ledger-commit-without-member-or-admin-grants',
 'native-transfer-concurrent-identical-execution-commits-one-primary-and-one-durable-result',
 'native-transfer-consumed-request-retries-durable-ledger-without-second-primary-switch-or-outbox',
 'native-transfer-does-not-change-native-project-policies-hierarchy-or-membership-edges',
 'native-transfer-execution-requires-explicit-no-native-permission-change-acknowledgement',
 'native-transfer-expiry-during-real-row-wait-refused-before-consumption-or-primary-change',
 'native-transfer-immediate-zero-day-switch-creates-no-active-handover',
 'native-transfer-independent-current-admin-approves-without-taking-executor-role',
 'native-transfer-ordinary-subject-has-no-request-history-or-self-directory-exception',
 'native-transfer-persisted-outbox-reference-remains-undispatched',
 'native-transfer-real-midwrite-audit-fault-rolls-back-consume-primary-handover-outbox-and-ledger',
 'native-transfer-real-midwrite-audit-trigger-installed-by-owned-setup',
 'native-transfer-real-preview-is-explicit-14-day-plan-not-permission-proof',
 'native-transfer-real-proposal-projects-no-native-credentials-or-private-source-snapshots',
 'native-transfer-requester-cannot-self-approve',
 'native-transfer-same-operation-different-payload-refused-before-consumption',
 'native-transfer-two-real-native-writers-wait-in-existing-organization-lock-order'})
REQUIRED_CHECKS=REQUIRED_CHECKS|ORGANIZATION_REQUIRED_CHECKS
# Fixed candidate union; no combined passing receipt exists yet.
HTTP_REQUIRED_CHECKS=frozenset({'native-media-active-html-forced-attachment-octetstream-sandbox-despite-viewable-config',
 'native-media-active-js-forced-attachment-octetstream-sandbox-despite-viewable-config',
 'native-media-active-pdf-forced-attachment-octetstream-sandbox-despite-viewable-config',
 'native-media-active-svg-forced-attachment-octetstream-sandbox-despite-viewable-config',
 'native-media-active-xml-forced-attachment-octetstream-sandbox-despite-viewable-config',
 'native-media-anonymous-avatar-transform-cache-does-not-leak-redirect',
 'native-media-anonymous-conditional-refused-before-byte-or-cache-metadata',
 'native-media-anonymous-cookie-absence-refused-before-real-file-metadata-or-storage-events',
 'native-media-anonymous-head-refused-before-byte-or-cache-metadata',
 'native-media-anonymous-range-refused-before-byte-or-cache-metadata',
 'native-media-anonymous-transform-generation-refused-before-native-cache-write',
 'native-media-authorized-native-cached-transform-redirect-remains-protected',
 'native-media-authorized-real-gorge-range-retains-correct-bytes',
 'native-media-authorized-uncached-transform-calls-real-paired-gorge-image-service',
 'native-media-cookie-identity-and-native-author-automatic-file-view',
 'native-media-default-favicon-native-producer-uses-fixed-static-origin-route',
 'native-media-derived-file-native-original-capability-allows-author',
 'native-media-direct-avatar-derived-file-is-not-anonymous',
 'native-media-file-author-automatic-capability-remains-native-after-object-revocation',
 'native-media-file-cookie-firewall-does-not-strip-application-session',
 'native-media-file-guard-fixture-uses-real-gorge-storage',
 'native-media-generated-derived-bytes-use-native-original-visibility',
 'native-media-http-app-issue-requires-real-native-csrf',
 'native-media-http-controller-scopes-leave-no-unguarded-depth',
 'native-media-http-duplicate-conflicting-wire-cookie-refused',
 'native-media-http-encoded-wire-cookie-never-uses-php-global-alias',
 'native-media-http-identity-cookie-does-not-bypass-file-object-guard',
 'native-media-http-invalid-wire-cookie-not-replaced-by-valid-php-global',
 'native-media-http-missing-wire-cookie-never-uses-php-global-alias',
 'native-media-http-native-stored-long-unicode-file-uri-remains-a-canonical-target',
 'native-media-http-normalized-wire-cookie-never-uses-php-global-alias',
 'native-media-http-once-code-post-replay-refused',
 'native-media-http-origin-rejection-before-ledger-write',
 'native-media-http-prepare-secret-only-in-host-secure-httponly-cookie',
 'native-media-http-public-post-restores-native-csrf-write-guard',
 'native-media-http-quoted-wire-cookie-never-uses-php-global-alias',
 'native-media-http-ready-verifies-current-native-bound-cookie-without-identity-leak',
 'native-media-http-real-platform-route-authenticated-authorize-page',
 'native-media-http-real-resource-route-pinned-frame-csp',
 'native-media-http-real-session-csrf-issue-code-in-post-response-only',
 'native-media-http-redeem-credential-only-in-host-secure-httponly-cookie',
 'native-media-http-redeem-requires-file-origin-browser-cookie',
 'native-media-http-valid-wire-cookie-does-not-trust-conflicting-php-global',
 'native-media-login-default-favicon-immutable-source-remains-anonymous',
 'native-media-native-any-visible-attachment-file-capability-is-preserved',
 'native-media-new-request-rechecks-object-policy-before-conditional-return',
 'native-media-page-bootstrap-config-requires-real-native-cookie-session-and-app-origin',
 'native-media-page-bootstrap-viewer-object-alone-cannot-export-csrf',
 'native-media-private-file-response-disables-shared-cache-and-sniffing',
 'native-media-real-generated-transform-cache-reuse-performs-no-image-transform-http',
 'native-media-real-image-generation-does-not-invoke-native-service-fallback',
 'native-media-real-image-transform-result-is-stored-in-gorge-with-native-relation',
 'native-media-real-profiler-observes-authorized-file-query-and-gorge-read-positive-control',
 'native-media-real-route-foreign-instance-still-refused-with-valid-cookie',
 'native-media-real-shared-stock-avatar-object-has-no-unique-public-purpose',
 'native-media-resource-bootstrap-strips-native-app-cookie-and-authorization-before-session-engine',
 'native-media-safe-png-mapped-to-active-html-is-still-forced-attachment',
 'native-media-source-policy-revoked-cached-refused-before-image-or-byte-service-call',
 'native-media-source-policy-revoked-derived-refused-before-image-or-byte-service-call',
 'native-media-source-policy-revoked-uncached-refused-before-image-or-byte-service-call',
 'native-media-stock-avatar-data-still-requires-login',
 'native-media-stock-avatar-download-still-requires-login',
 'native-media-uploaded-avatar-get-no-anonymous-conditional-or-range-bypass',
 'native-media-uploaded-avatar-head-no-anonymous-conditional-or-range-bypass',
 'native-media-valid-bearer-file-key-alone-never-authorizes-bytes',
 'native-media-valid-other-user-cookie-does-not-grant-file-permission'})
REQUIRED_CHECKS=REQUIRED_CHECKS|HTTP_REQUIRED_CHECKS
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

# Exact PR94 review regressions; no new passing receipt exists until fresh runtime.
REQUIRED_CHECKS=REQUIRED_CHECKS|frozenset({
 'native-handover-manual-confirmation-binds-two-observed-receipt-and-handover-versions',
 'native-handover-stale-manual-confirmation-cannot-overwrite-another-administrator-and-real-csrf-is-required',
 'native-handover-termination-ticket-before-primary-clear-still-refuses-changed-bound-source',
 'native-handover-independent-termination-after-primary-clear-preserves-cleared-primary-and-ends-active-record',
 'native-handover-manual-confirmation-refuses-lifecycle-version-changed-after-preview',
 'native-handover-terminated-after-primary-clear-no-longer-blocks-next-approved-transfer'})
# Explicit initial-transfer DST UI regression obligations, runtime pending.
REQUIRED_CHECKS=REQUIRED_CHECKS|frozenset({
 'native-handover-fixed-calendar-render-seam-shows-both-dst-offsets-without-authorizing-or-proposing',
 'native-handover-current-http-resolution-requires-explicit-valid-offset-and-real-csrf',
 'native-handover-current-http-selected-offset-runs-fresh-server-preview-and-new-identifiers',
 'native-handover-current-http-proposal-uses-real-db-time-and-chosen-offset-not-synthetic-calendar-start'})
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
REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset(['native-history-365-day-candidate-report-retains-all-records', 'native-history-actual-get-current-admin-without-csrf-read-scope-restored', 'native-history-administrator-revoked-between-pages-cannot-use-old-cursor', 'native-history-current-team-administrator-still-cannot-read-global-audit', 'native-history-has-no-post-delete-or-mutation-surface', 'native-history-never-loads-or-returns-private-snapshots-or-credential-binding', 'native-history-ordinary-user-cannot-read-audit-or-retention-count', 'native-history-reader-revocation-uses-actual-no-factor-normal-session', 'native-history-real-keyset-pages-have-no-duplicate-records'])
REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset({'native-history-retention-raw-scan-is-bounded-and-empty-page-cursor-reaches-later-candidate', 'native-history-empty-retention-page-real-html-keeps-next-scan-link'})
NATIVE['history_tests'] = {'current_system_administrator_only': True, 'snapshot_fields_returned': False, 'retention_days': 365, 'history_deleted': False, 'full_browser_acceptance': False, 'production_activation': False}
REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset(['native-schema-organization_handover-matches-retained-adjustment-spec', 'native-schema-organization_outbox-matches-retained-adjustment-spec', 'native-schema-organization_projectdisposition-matches-retained-adjustment-spec', 'native-schema-organization_transfer-matches-retained-adjustment-spec', 'native-transfer-foundation-exact-duplicate-event-fault-rolls-back-version-and-all-workflow-tables', 'native-transfer-foundation-fixture-storage-does-not-switch-primary-or-grant-membership', 'native-transfer-foundation-outbox-holds-only-protected-references-and-never-dispatches', 'native-transfer-foundation-physical-binary-identity-rejects-case-and-trailing-pad-aliases', 'native-transfer-foundation-real-people-dao-roundtrip-calendar-and-version', 'native-transfer-foundation-real-version-cas-refuses-stale-update'])
NATIVE['transfer_foundation_tests'] = {'storage_only': True, 'version_cas_verified': True, 'storage_rollback_verified': True, 'independent_approval_implemented': False, 'native_primary_switch_implemented': False, 'outbox_dispatch_registered': False, 'production_activation': False}
NATIVE['schema_tests']['candidate_tables_compared'] = 17
NATIVE.update({'noncredential_source_v2_tests': {'noncredential_first_issuer_stable': True,
                                   'native_and_legacy_aba_refused': True,
                                   'legacy_v1_not_reinterpreted': True,
                                   'missing_and_nontransactional_schema_refused': True,
                                   'credential_source_downgraded': False,
                                   'production_activation': False},
 'handover_form_tests': {'native_html_forms': True,
                         'two_current_native_admins': True,
                         'actual_request_csrf': True,
                         'committed_form_retry': True,
                         'disabled_current_executor_refused': True,
                         'wire_or_browser_proof': False,
                         'production_activation': False},
 'handover_lifecycle_tests': {'native_extension_termination_responsible_backend': True,
                              'independent_native_approval': True,
                              'cumulative_server_derived': True,
                              'manual_project_query_filtered': True,
                              'due_future_negative_exercised': True,
                              'positive_historical_due_fixture_exercised': True,
                              'new_concurrency_exercised': True,
                              'disabled_responsible_replacement_exercised': True,
                              'native_ui_forms_exercised': False,
                              'dispatcher_registered': False,
                              'production_activation': False},
 'transfer_execution_tests': {'initial_transfer_profile_implemented': True,
                              'independent_current_admin_approval': True,
                              'one_native_people_transaction': True,
                              'native_csrf_transport_exercised': True,
                              'conduit_transport_proved': False,
                              'same_operation_replay_verified': True,
                              'native_concurrency_exercised': True,
                              'real_expiry_wait_exercised': True,
                              'extension_termination_responsible_implemented': False,
                              'native_project_permissions_changed': False,
                              'dispatcher_registered': False,
                              'production_activation': False}})
WORKERS = 19
NATIVE['http_tests']={'native_site_and_controller_exchange_verified': True,
 'host_only_cookie_attributes_verified': True,
 'real_origin_csrf_and_once_code_verified': True,
 'wire_https_verified': False,
 'browser_verified': False,
 'file_guard_tests': {'real_gorge_bytes_and_native_file_query': True,
                      'native_author_and_any_attachment_verified': True,
                      'all_file_avatar_origins_require_login': True,
                      'cached_transform_original_guard_verified': True,
                      'anonymous_transform_generation_blocked': True,
                      'active_formats_forced_attachment': True,
                      'default_favicon_producer_static_route_verified': True,
                      'actual_transform_generation_verified': True,
                      'image_tests': {'actual_paired_image_generation_verified': True,
                                      'backend_revision_sha256': 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
                                      'real_cached_generation_reuse_verified': True,
                                      'source_revocation_blocks_cached_derived_and_uncached': True,
                                      'php_transform_fallback_enabled': False,
                                      'service_lifecycle_owned_by_parent': True},
                      'custom_branding_verified': False,
                      'browser_active_content_verified': False,
                      'wire_https_verified': False},
 'file_guard_complete': False,
 'production_enabled': False}
WORKERS = 19

REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset({
 'project-membership-native-conduit-api-token-self-join-commits-without-ticket',
 'project-membership-native-conduit-api-token-self-leave-commits-without-ticket',
 'project-membership-native-conduit-rejects-inapplicable-ticket-without-write'})

# Fixed execution profile: exact native summary and check IDs, never caller claims.
NATIVE.update({'producer_fence_tests': {'actual_native_producer_range_waits': True,
                          'actual_native_webhook_status_wait': True,
                          'native_postcommit_execution_not_yet_tested': True,
                          'primary_target_transaction_not_wrapped': True},
 'project_execution_tests': {'approver_view_not_edit': True,
                             'final_approver_expiry': {'actual_session_expiry': True,
                                                       'actual_audit_write_delay': True,
                                                       'same_native_transaction_rollback': True,
                                                       'external_effect_rollback_claimed': False},
                             'configuration_generations': {'actual_cli_set_delete': True,
                                                           'actual_config_editor': True,
                                                           'actual_native_dao': True,
                                                           'features_off_aba': True,
                                                           'optional_backend_compatibility_claimed': False,
                                                           'stale_approval_case_exercised': True,
                                                           'transaction_rollback': True},
                             'configured_native_mfa': {'actual_current_native_role_revocation_enforced': True,
                                                       'actual_editor_challenge_response': True,
                                                       'actual_native_totp_provider_and_secret': True,
                                                       'all_source_factor_lifecycle_closed': False,
                                                       'configured_factor_browser_login_tested': False,
                                                       'native_security_challenge_completion_survives_mutation_rollback': True,
                                                       'native_workflow_session_and_replay_enforced': True,
                                                       'one_time_token_not_session_upgrade': True},
                             'flat_source_checks': {'actual_child_not_cached_flags': True,
                                                    'actual_child_parent_range_wait': True,
                                                    'actual_native_editor_used': True,
                                                    'all_hierarchy_and_raw_producers_closed': False,
                                                    'automatic_permission_repair': False,
                                                    'flat_direct_and_derived_mismatch_refused': True},
                             'native_capabilities_retained': True,
                             'native_project_edit_policy': True,
                             'native_self_membership': {'actual_inverse_and_derived_fault_rollback': True,
                                                        'actual_native_self_join_leave': True,
                                                        'current_native_capabilities_not_approval_grants': True,
                                                        'hierarchy_membership_implemented': False,
                                                        'high_risk_administrator_member_change_implemented': False,
                                                        'same_project_transaction_for_exact_direct_inverse_derived': True},
                             'negative_cases': {'actual_project_transaction_write_fault': True,
                                                'external_postcommit_rollback_claim': False,
                                                'same_transaction_rollback': True},
                             'same_native_transaction': True,
                             'whole_family_ready': False,
                             'whole_pipeline_side_effect_atomic': False}})
REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset({'native-config-generation-always-on-with-all-governance-features-disabled',
 'native-config-generation-direct-native-dao-aba-monotonic',
 'native-config-generation-direct-update-and-replace-advance-once',
 'native-config-generation-fault-preserves-external-native-transaction-and-lock-level',
 'native-config-generation-fault-rolls-back-native-value-and-epoch-with-no-lock-leak',
 'native-config-generation-owned-fault-trigger-installed',
 'native-config-generation-physical-native-dao-delete-advances',
 'native-config-generation-real-cli-set-delete-advances-while-features-off',
 'native-config-generation-real-ui-editor-common-save-seam',
 'native-config-generation-replace-binds-tuple-and-phid-collision-victims',
 'project-approval-actual-child-blocks-despite-stale-root-flags',
 'project-approval-actual-empty-parent-range-blocks-concurrent-child-producer',
 'project-approval-actual-native-nested-update-error-quarantines-without-savepoint-guess',
 'project-approval-actual-native-record-write-fault-raised',
 'project-approval-caller-cannot-spoof-native-author',
 'project-approval-caller-producer-metadata-blocked',
 'project-approval-child-producer-actually-committed-after-range-release',
 'project-approval-child-producer-unblocks-after-owned-scope-release',
 'project-approval-default-disabled-actual-config',
 'project-approval-derived-without-direct-blocked-without-repair',
 'project-approval-direct-policy-dao-write-blocked',
 'project-approval-direct-without-derived-blocked-without-repair',
 'project-approval-failed-native-execution-releases-owned-locks',
 'project-approval-independent-current-native-admin-approved',
 'project-approval-invalid-feature-flag-never-falls-back-to-native-write',
 'project-approval-native-config-aba-while-feature-off-invalidates-old-ticket-after-values-restored',
 'project-approval-owned-audit-expiry-wait-trigger-installed',
 'project-approval-approver-natural-expiry-during-audit-rolls-back-all-native-domain-writes',
 'project-approval-native-mysql-port-default-null-actual-fences',
 'project-approval-native-mysql-port-native-string-actual-fences',
 'project-approval-native-failure-rolls-back-consume-row-record-and-audit-with-no-publish',
 'project-approval-native-mutation-consume-native-record-audit-committed',
 'project-approval-native-nested-error-isolated-uncommitted-while-child-quarantined',
 'project-approval-native-nested-error-child-terminal-transaction-gone-and-no-commit',
 'project-approval-never-overrides-native-self-loss-policy-validation',
 'project-approval-no-self-nonadmin-approval',
 'project-approval-noop-replay-is-not-a-second-native-execution',
 'project-approval-noop-replay-preserves-consumed-ticket-and-owned-frame',
 'project-approval-owned-native-transaction-fault-installed',
 'project-approval-real-commit-and-native-locks-released',
 'project-approval-real-editor-postcommit-returned',
 'project-approval-real-native-proposal-persisted',
 'project-approval-reviewer-native-view-without-edit',
 'project-approval-service-cannot-commit-executor-scope',
 'project-current-firehose-hook-profile-explicitly-unsupported',
 'project-current-rule-profile-explicitly-unsupported',
 'project-existing-webhook-status-change-blocked-until-fence-release',
 'project-membership-derived-actual-sql-fault-installed',
 'project-membership-derived-fault-rolls-back-direct-inverse-derived-record-and-preserves-approval-audit',
 'project-membership-fixture-native-join-without-edit',
 'project-membership-inverse-actual-sql-fault-installed',
 'project-membership-inverse-fault-rolls-back-direct-inverse-derived-record-and-preserves-approval-audit',
 'project-membership-native-join-and-leave-recover-after-real-sql-faults',
 'project-membership-native-self-join-direct-inverse-derived-and-record-committed',
 'project-membership-native-self-leave-direct-inverse-derived-and-record-committed',
 'project-membership-raw-source-0-refused-before-edge-data',
 'project-membership-raw-source-1-refused-before-edge-data',
 'project-membership-raw-source-2-refused-before-edge-data',
 'project-membership-raw-source-3-refused-before-edge-data',
 'project-membership-raw-source-4-refused-before-edge-data',
 'project-membership-raw-source-5-refused-before-edge-data',
 'project-membership-standalone-derived-permission-repair-refused',
 'project-membership-standalone-proved-noop-materialization-preserved',
 'project-mfa-actual-native-record-fault-installed',
 'project-mfa-configured-native-factor-issues-real-challenge-before-consume',
 'project-mfa-consumed-native-response-not-reusable-for-second-operation',
 'project-mfa-current-role-case-issues-actual-challenge',
 'project-mfa-invalid-native-factor-response-refuses-before-consume',
 'project-mfa-native-challenge-binds-actual-session-and-editor-target',
 'project-mfa-native-mutation-consume-record-audit-rollback-does-not-revive-used-security-challenge',
 'project-mfa-real-challenged-one-use-token-commits-native-mutation-without-session-upgrade',
 'project-mfa-rollback-case-requires-real-native-challenge',
 'project-mfa-rolled-back-action-cannot-reuse-consumed-native-token',
 'project-mfa-valid-code-cannot-cross-native-editor-workflow',
 'project-mfa-valid-code-cannot-cross-native-session',
 'project-mfa-valid-factor-response-does-not-restore-revoked-native-role-or-trust-cached-admin',
 'project-native-rule-actually-inserted-after-fence-release',
 'project-producer-fence-honest-access-mode-and-atomicity-boundary',
 'project-producer-fence-survives-real-target-commit-hook-insert',
 'project-producer-fence-survives-real-target-commit-hook-status',
 'project-producer-fence-survives-real-target-commit-rule-insert',
 'project-producer-fence-test-leaves-no-target-transaction',
 'project-producer-statement-running-and-pending-under-range-fence-hook-insert',
 'project-producer-statement-running-and-pending-under-range-fence-hook-status',
 'project-producer-statement-running-and-pending-under-range-fence-rule-insert',
 'project-producer-unblocks-only-after-independent-fence-release-hook-insert',
 'project-producer-unblocks-only-after-independent-fence-release-hook-status',
 'project-producer-unblocks-only-after-independent-fence-release-rule-insert'})

NATIVE['project_execution_tests']['approved_native_membership'] = {
 'actual_typed_member_add_remove': True, 'actual_multi_subject_delta': True,
 'same_writer_approval_native_and_edges': True, 'native_policy_not_granted_by_approval': True,
 'all_hierarchy_or_raw_producers_complete': False, 'production_activation': False}
REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset({
 'project-approved-members-add-disabled-target-refused-without-write',
 'project-approved-members-add-unapproved-target-refused-without-write',
 'project-approved-members-add-agent-target-refused-without-write',
 'project-approved-members-add-mailing-target-refused-without-write',
 'project-approved-members-disabled-current-target-can-be-removed-with-approval',
 'project-approved-members-current-admin-can-read-durable-add-history-after-subject-disabled',
 'project-approved-members-history-at-current-capacity-does-not-replay-removed-subject',
 'project-approved-members-new-addition-over-current-capacity-still-refused-without-write',
 'project-approved-members-first-issuer-beneficiary-approver-is-not-a-role-change',
 'project-approved-members-self-only-ticket-refused-retaining-native-path',
 'project-approved-members-unapproved-ticket-cannot-mutate',
 'project-approved-members-valid-ticket-does-not-grant-native-edit',
 'project-approved-members-wrong-subject-ticket-refused-without-write',
 'project-approved-members-two-user-add-consume-native-inverse-derived-audit-commit',
 'project-approved-members-wrong-operation-ticket-refused-without-write',
 'project-approved-members-two-user-remove-consume-native-inverse-derived-audit-commit',
 'project-approved-members-inverse-owned-native-fault-installed',
 'project-approved-members-inverse-actual-fault-rolls-back-ticket-edges-record-audit-queue',
 'project-approved-members-derived-owned-native-fault-installed',
 'project-approved-members-derived-actual-fault-rolls-back-ticket-edges-record-audit-queue',
 'project-approved-members-record-owned-native-fault-installed',
 'project-approved-members-record-actual-fault-rolls-back-ticket-edges-record-audit-queue',
 'project-approved-members-audit-owned-native-fault-installed',
 'project-approved-members-audit-actual-fault-rolls-back-ticket-edges-record-audit-queue',
 'project-approved-members-subject-native-disable-enable-aba-invalidates-ticket',
 'project-approved-members-actual-api-token-proposal-and-member-edit-commit',
 'project-approved-members-current-approver-role-revocation-invalidates-ticket'})


VIEW_FORM_CHECKS = frozenset(['project-view-form-existing-json-edit-proposal-approve-and-admin-history-compatible', 'project-view-form-existing-json-view-proposal-approve-and-admin-history-compatible', 'project-view-form-existing-json-history-still-denies-nonadmin-current-editor', 'project-view-form-policy-options-use-current-scope-actor-after-real-role-source-change', 'project-view-form-blank-native-controller-has-project-selector-and-no-business-write', 'project-view-form-preview-selects-corresponding-current-policy-without-persisting', 'project-view-form-native-post-without-csrf-refused-without-write', 'project-view-form-unknown-operation-refused-without-write', 'project-view-form-mixed-json-and-form-payload-refused-without-write', 'project-view-form-absent-operation-keeps-legacy-edit-policy-meaning', 'project-view-form-edit-ticket-cannot-authorize-native-view-post', 'project-view-form-approved-noone-still-refused-by-native-self-view-validator', 'project-view-form-submits-server-typed-view-intent', 'project-view-form-approved-native-web-post-commits-view-ticket-and-native-record'])
REQUIRED_CHECKS = REQUIRED_CHECKS | VIEW_FORM_CHECKS
NATIVE['project_execution_tests']['native_policy_proposal_form'] = {'actual_native_controller_form': True, 'actual_chinese_form_render': True, 'policy_options_use_current_actor': True, 'actual_native_post_csrf': True, 'server_typed_view_intent': True, 'legacy_edit_meaning_retained': True, 'existing_json_controller_compatible': True, 'admin_history_gate_retained': True, 'native_self_view_validator_retained': True, 'actual_http_server': False, 'browser_proved': False}

VIEW_EDIT_ROUTE_CHECKS = frozenset(['project-view-form-policy-options-render-under-current-space-cache-without-space-mutation-control', 'project-view-default-edit-controller-real-route-get-renders-native-fields-and-ticket-input', 'project-view-default-edit-controller-real-post-without-csrf-refused-without-write', 'project-view-default-edit-controller-second-actual-change-refuses-whole-transition', 'project-view-default-edit-controller-edit-ticket-cannot-authorize-view-form', 'project-view-default-edit-controller-noone-still-renders-native-self-view-validation', 'project-view-default-edit-controller-get-to-post-native-view-record-ticket-commit', 'project-view-default-edit-controller-provenance-and-native-locks-released'])
VIEW_EDIT_ROUTE_CHECKS = VIEW_EDIT_ROUTE_CHECKS | frozenset(['project-view-default-edit-controller-native-content-only-post-needs-no-policy-ticket', 'project-view-default-edit-controller-empty-submit-retains-native-no-effect', 'project-view-default-edit-controller-join-change-and-forged-default-flags-refused-before-effects'])
VIEW_EDIT_ROUTE_CHECKS = VIEW_EDIT_ROUTE_CHECKS | frozenset(['project-view-default-edit-controller-explicit-space-alias-inputs-refused'])
VIEW_EDIT_ROUTE_CHECKS = VIEW_EDIT_ROUTE_CHECKS | frozenset(['project-view-default-edit-controller-uninitialized-empty-description-no-effect','project-view-default-edit-controller-uninitialized-description-policy-only-commit'])
VIEW_EDIT_ROUTE_CHECKS = VIEW_EDIT_ROUTE_CHECKS | frozenset(['project-view-default-edit-controller-no-effect-crosses-native-clock-before-join-refusal'])
REQUIRED_CHECKS = REQUIRED_CHECKS | VIEW_EDIT_ROUTE_CHECKS
NATIVE['project_execution_tests']['native_policy_proposal_form'].update(policy_options_use_current_spaces=True,space_mutation_control_hidden=True,native_policy_behavior_metadata_checked=True)
NATIVE['project_execution_tests']['native_default_policy_edit_form'] = {'actual_platform_routing': True, 'actual_default_edit_controller_get_post': True, 'native_field_generation_and_filtering_retained': True, 'single_effect_after_native_filter': True, 'caller_default_flag_authority': False, 'actual_http_server': False, 'browser_proved': False}
NATIVE['project_execution_tests']['native_default_policy_edit_form'].update(native_content_only_post_retained=True,native_no_effect_post_retained=True,native_no_effect_cross_second_clock_proved=True,unsupported_join_post_refused_early=True,explicit_space_alias_inputs_refused=True,native_description_initialized_before_policy=True,uninitialized_description_policy_profile_proved=True)

# R02.1 records actual current account observations only. All source families
# remain incomplete and this summary cannot be promoted into a recovery permit.
NATIVE['recovery_account_capture_tests'] = {
    'actual_account_adapter': True, 'source_writes_by_capture': False,
    'all_sources_complete': False, 'can_execute': False,
    'other_twelve_families_complete': False}
RECOVERY_ACCOUNT_CHECKS = frozenset(['recovery-account-uninitialized-actor-refused-without-initialization', 'recovery-account-unknown-refusal-no-source-write-or-open-transaction', 'recovery-account-real-storage-capture-keeps-absence-and-thirteen-family-block', 'recovery-account-success-no-hidden-epoch-initialization', 'recovery-account-outer-transaction-refused', 'recovery-account-refusal-does-not-end-or-rollback-caller-transaction', 'recovery-account-untracked-physical-outer-transaction-refused-without-rollback', 'recovery-account-real-native-disable-enable-aba-is-observable-not-recovery-authority', 'recovery-account-real-current-nonadmin-cannot-capture', 'recovery-account-missing-actual-source-table-refused-with-owned-transaction-cleanup'])
REQUIRED_CHECKS = REQUIRED_CHECKS | RECOVERY_ACCOUNT_CHECKS


# Direct stored web/temporary rows are observed, not certified usable/recoverable.
NATIVE['recovery_web_capture_tests'] = {
    'actual_native_session_producer': True, 'actual_native_logout': True,
    'actual_native_one_time_token_producer': True, 'actual_native_single_token_revoke': True,
    'actual_password_controller_or_oauth_complete': False, 'web_family_complete': False,
    'all_sources_complete': False, 'can_execute': False, 'source_writes_by_capture': False}
RECOVERY_WEB_CHECKS = frozenset(['recovery-web-real-full-partial-expired-and-foreign-session-observations', 'recovery-web-native-token-and-session-keys-are-not-returned', 'recovery-web-real-single-session-logout-is-observed-without-global-revocation', 'recovery-web-real-logout-and-reissue-changes-credential-set-binding', 'recovery-web-real-native-one-time-token-is-captured-without-code-or-uri', 'recovery-web-real-single-temporary-token-revocation-is-observed', 'recovery-web-unknown-kind-email-resource-and-foreign-owner-remain-partial', 'recovery-web-native-account-aba-is-distinct-from-retained-credential-rows', 'recovery-web-selected-session-row-budget-refuses-before-payload-fetch', 'recovery-web-oversized-native-property-fixture-is-really-oversized', 'recovery-web-temporary-property-byte-budget-refuses-before-payload-fetch', 'recovery-web-aggregate-selected-property-byte-budget-refuses-before-payload-fetch', 'recovery-web-budget-refusals-release-owned-scope-without-source-poisoning'])
REQUIRED_CHECKS = REQUIRED_CHECKS | RECOVERY_WEB_CHECKS


# Direct API storage observations do not establish a complete recovery family.
NATIVE['recovery_api_capture_tests'] = {'actual_standard_token_authentication': True, 'actual_single_token_revocation': True, 'actual_legacy_certificate_authentication': True, 'actual_legacy_session_authentication': True, 'actual_oauth_server_producers': True, 'api_family_complete': False, 'all_sources_complete': False, 'can_execute': False, 'source_writes_by_capture': False}
RECOVERY_API_CHECKS = frozenset(['recovery-api-real-standard-token-authentication-and-storage-observation','recovery-api-real-owned-token-revocation-changes-capture-and-native-authentication','recovery-api-real-legacy-certificate-authentication-and-rotation-bound-to-account','recovery-api-real-legacy-conduit-session-authentication-is-distinct-from-web','recovery-api-real-single-legacy-session-logout-is-observed','recovery-api-real-oauth-server-producers-and-associated-client-rows-captured','recovery-api-real-oauth-authorization-revocation-does-not-erase-retained-token','recovery-api-real-expired-standard-token-storage-is-not-native-authority','recovery-api-selected-token-row-budget-closes-own-scope-before-payload-fetch','recovery-api-no-raw-secrets-recovery-permit-or-open-owned-transaction','recovery-api-aggregate-above-web-budget-below-api-budget-is-observed','recovery-api-aggregate-above-api-budget-refuses-and-closes-owned-scope','recovery-api-unrelated-web-session-budget-does-not-consume-api-budget','recovery-api-mixed-session-capture-selects-only-native-conduit-type','recovery-api-selected-legacy-session-row-budget-remains-enforced'])
REQUIRED_CHECKS = REQUIRED_CHECKS | RECOVERY_API_CHECKS

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


# Explicit N12 Query-only producer obligations, not an adopted revocation SLA.
MEASUREMENT_CHECKS = frozenset({
    'n12-native-query-independent-current-session-pretransition-allow',
    'n12-native-policy-transition-returns-only-after-owned-transaction-commit',
    'n12-native-transition-record-and-group-persisted-with-actual-view-policy',
    'n12-independent-fresh-native-query-observes-committed-source-and-denial',
    'n12-query-attempts-use-serial-parent-clock-around-actual-commit',
})
REQUIRED_CHECKS = REQUIRED_CHECKS | MEASUREMENT_CHECKS
WORKERS = 22
_measurement_spec = importlib.util.spec_from_file_location('n12_observation_consumer', ROOT/'support/governance/revocation-measurement/consumer.py')
measurement = importlib.util.module_from_spec(_measurement_spec)
_measurement_spec.loader.exec_module(measurement)
MEASUREMENT_PRODUCER = 'support/governance/native-organization/revocation-measurement-cases.php'
# Synthetic template used only by gate mutation tests, never an executed receipt.
NATIVE['revocation_measurement_tests'] = {
    'profile': 'native-session-authenticated-project-query-only', 'actual_source_checked': True,
    'actual_independent_query_processes': 3, 'HTTP_proved': False, 'gateway_proved': False,
    'long_connection_proved': False, 'SLA_proved': False, 'convergence_proved': False,
    'native_source_binding_sha256': 'f'*64,
    'event': {'schema': 1, 'source_revision': 'a'*40,
        'producer_sha256': hashlib.sha256((ROOT/MEASUREMENT_PRODUCER).read_bytes()).hexdigest(),
        'family': 'app-session', 'clock': {'kind': 'monotonic_ns', 'boot_id_sha256': 'b'*64},
        'transition': {'id': 'c'*64, 'action': 'revoke', 'native_commit_id_sha256': 'd'*64,
            'boundary': 'native-transaction-operation-call-return', 'operation_start_ns': 80,
            'commit_confirmed_return_ns': 100},
        'attempts': [{'id': i, 'transition_id': 'c'*64, 'boot_id_sha256': 'b'*64,
                     'start_ns': start, 'finish_ns': end, 'outcome': outcome}
            for i,start,end,outcome in [(1,10,20,'allowed'),(2,110,120,'denied'),(3,130,140,'denied')]]}}


API_TOKEN_MEASUREMENT_PRODUCER = 'support/governance/native-organization/revocation-api-token-cases.php'
API_TOKEN_MEASUREMENT_CHECKS = frozenset({
    'n12-native-api-independent-real-token-authentication-pretransition-allow',
    'n12-native-api-token-source-and-no-outer-transaction-proved',
    'n12-native-api-actual-owned-token-dao-delete-commits-exactly-one-row',
    'n12-native-api-independent-absence-and-native-authentication-denial-after-revoke',
    'n12-native-api-serial-parent-clock-binds-actual-delete-and-fresh-authentication',
})
REQUIRED_CHECKS = REQUIRED_CHECKS | API_TOKEN_MEASUREMENT_CHECKS
WORKERS = 32
NATIVE['api_token_revocation_measurement_tests'] = {
    'profile':'native-conduit-token-revoke-authentication-only','actual_source_checked':True,
    'actual_independent_authentication_processes':3,'token_rows_deleted':1,
    'native_transaction_record_available':False,'HTTP_proved':False,'gateway_proved':False,
    'SLA_proved':False,'convergence_proved':False,'before_source_binding_sha256':'e'*64,
    'after_source_binding_sha256':'f'*64,
    'event':{'schema':2,'source_revision':'a'*40,
        'producer_sha256':hashlib.sha256((ROOT/API_TOKEN_MEASUREMENT_PRODUCER).read_bytes()).hexdigest(),
        'family':'api-token','clock':{'kind':'monotonic_ns','boot_id_sha256':'b'*64},
        'transition':{'id':'c'*64,'action':'revoke','operation_binding_sha256':'c'*64,
            'boundary':'native-token-dao-delete-own-transaction','operation_start_ns':80,
            'commit_confirmed_return_ns':100},
        'attempts':[{'id':i,'transition_id':'c'*64,'boot_id_sha256':'b'*64,
                    'start_ns':start,'finish_ns':end,'outcome':outcome}
            for i,start,end,outcome in [(1,10,20,'allowed'),(2,110,120,'denied'),(3,130,140,'denied')]]}}
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

REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset(['private-mail-invite-native-nonadmin-people-capability-granted', 'private-mail-invite-native-nonadmin-worker-prepares-genuine-challenge', 'private-mail-invite-legacy-admin-binding-must-equal-author-subject', 'private-mail-invite-current-capability-revocation-refuses-callback', 'private-mail-invite-regrant-cannot-revive-permanently-quarantined-snapshot', 'private-mail-invite-nonadmin-without-native-capability-refused', 'private-mail-invite-native-default-admin-still-authorized', 'private-mail-invite-custom-policy-admin-result-matches-real-native-capability', 'private-mail-invite-capability-does-not-authorize-admin-only-welcome'])

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

# Independently executed native producer and transaction-race obligations.
REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset(['private-mail-approval-independent-admin-revoked-durable-retry-rechecks-source-and-current-destination',
 'private-mail-approval-independent-admin-revoked-precommit-defers-without-quarantine-snapshot-rewrite-or-send',
 'private-mail-previously-quarantined-message-never-auto-restores-after-source-repair',
 'private-mail-ssh-no-primary-create-does-not-authorize-undeliverable-notice',
 'private-mail-ssh-real-create-notice-current-owner-key-and-address-bound',
 'private-mail-ssh-real-rename-publisher-owner-notice-bound',
 'private-mail-ssh-revocation-alert-retains-native-inactive-key-history',
 'private-mail-ssh-withmail-native-reactivation-stays-refused',
 'private-mail-ssh-withmail-real-native-create-and-owned-publish-commit',
 'private-mail-ssh-withmail-real-native-rename-commits',
 'private-mail-ssh-withmail-real-native-revoke-commits',
 'private-mail-ssh-withoutmail-native-reactivation-stays-refused',
 'private-mail-ssh-withoutmail-real-native-create-and-owned-publish-commit',
 'private-mail-ssh-withoutmail-real-native-rename-commits',
 'private-mail-ssh-withoutmail-real-native-revoke-commits',
 'private-mail-user-event-present-hash-mismatch-is-permanent-denial-not-pending',
 'private-mail-username-independent-commit-durable-retry-rechecks-source-and-current-destination',
 'private-mail-username-independent-commit-precommit-defers-without-quarantine-snapshot-rewrite-or-send',
 'private-mail-username-independent-recipient-revoked-durable-retry-rechecks-source-and-current-destination',
 'private-mail-username-independent-recipient-revoked-precommit-defers-without-quarantine-snapshot-rewrite-or-send',
 'private-mail-username-independent-rollback-durable-retry-rechecks-source-and-current-destination',
 'private-mail-username-independent-rollback-precommit-defers-without-quarantine-snapshot-rewrite-or-send',
 'private-mail-username-outer-native-rollback-orphan-notice-cannot-deliver',
 'private-mail-username-real-producer-created-mail-inside-outer-native-transaction',
 'private-mail-username-rolled-back-missing-source-remains-unprivileged-during-bounded-retry'])
NATIVE['private_mail_tests']['remaining_producer_tests'] = {
    'actual_ssh_create_rename_revoke_and_reactivation_checks': True,
    'owned_publication_tasks_executed': 6,
    'actual_native_username_outer_rollback_source_checked': True,
    'ssh_login_or_cryptographic_handshake_proved': False,
    'smtp_producer_matrix_complete': False,
    'device_ssh_producer_matrix_complete': False,
    'legalpad_supported_signer_flow': False,
}
NATIVE['private_mail_tests']['commit_race_tests'] = {
    'independent_process_native_commit_race_reproduced': True,
    'samples': {name: {'precommit_pending': True, 'precommit_quarantined': False,
                       'postcompletion_allowed': name == 'commit'}
                for name in ('commit', 'rollback', 'recipient-revoked', 'admin-revoked')},
    'production_fix_implemented': True,
    'physical_smtp_race_proved': False,
}


# The approved Legalpad endpoint candidate adds two independently exec-created
# native web workers. Physical SMTP and configured-factor claims remain absent.
REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset(['private-mail-legalpad-actual-corporate-sign-controller-and-native-high-security-path-commits',
 'private-mail-legalpad-corporate-native-actor-remains-distinct-from-null-signer-and-verified-secondary-contact',
 'private-mail-legalpad-genuine-producer-binds-owned-verified-secondary-and-generic-challenge-without-private-document-content',
 'private-mail-legalpad-anonymous-and-switched-account-get-cannot-read-title-signer-or-email',
 'private-mail-legalpad-matching-native-session-and-current-view-can-read-verification-without-consuming',
 'private-mail-legalpad-real-csrf-failure-does-not-consume-signature',
 'private-mail-legalpad-current-document-view-revocation-denies-endpoint-and-queued-mail',
 'private-mail-legalpad-disabled-account-cannot-verify-or-authorize-queued-challenge',
 'private-mail-legalpad-native-secret-rotation-invalidates-old-link-and-immutable-old-mail',
 'private-mail-legalpad-current-contact-unverified-refuses-before-consuming',
 'private-mail-legalpad-current-document-version-change-refuses-old-signature',
 'private-mail-legalpad-legacy-unbound-corporate-row-not-adopted-by-owned-email',
 'private-mail-legalpad-signature-contact-data-mismatch-refuses',
 'private-mail-legalpad-actual-nontransactional-document-table-refused-without-signature-write',
 'private-mail-legalpad-real-update-fault-rolls-back-signature-and-closes-owned-transaction',
 'private-mail-legalpad-native-logout-old-cookie-cannot-consume',
 'private-mail-legalpad-two-independent-native-web-processes-actually-block-before-consumption',
 'private-mail-legalpad-concurrent-native-cas-consumes-once-second-observes-verified-without-extra-mail-or-worker',
 'private-mail-legalpad-after-concurrent-cas-sequential-repeat-keeps-state-and-corporate-null-signer'])
NATIVE['private_mail_tests']['legalpad_tests'] = {
    'actual_corporate_sign_and_verification_routes': True,
    'verified_secondary_contact_supported': True,
    'anonymous_raw_or_third_party_adoption_supported': False,
    'current_view_and_identity_rechecked': True,
    'configured_mfa_factor_proved': False,
    'concurrent_process_verification_proved': True,
    'concurrency': {'independent_php_processes': 2,
                    'actual_same_source_lock_waiters': 2,
                    'actual_consumption_updates': 1,
                    'sequential_repeat_substituted_for_concurrency': False,
                    'physical_smtp_proved': False},
    'physical_smtp_legalpad_proved': False,
}
WORKERS = 21
# Durable callback denials must not revive after a later regrant.
REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset(['private-mail-attempt-quarantine-persists-reload-with-immutable-snapshots',
 'private-mail-callback-and-direct-permanent-denial-quarantine-without-preparation',
 'private-mail-callback-only-regrant-cannot-revive-denied-delivery',
 'private-mail-malformed-attempt-payload-cannot-poison-current-mail',
 'private-mail-primary-email-aba-cannot-revive-quarantined-callback-delivery',
 'private-mail-primary-email-policy-change-denied-at-callback-without-prepare',
 'private-mail-unprepared-authorization-probe-does-not-quarantine'])


# Host-prepared successor obligations; a matching runtime receipt is mandatory.
NOTIFICATION_BOUNDARY_CHECKS = frozenset(['private-business-project-normal-actual-publisher-current-source-and-isolated-native-recipient-envelopes',
 'private-business-project-outer-commit-actual-publisher-current-source-and-isolated-native-recipient-envelopes',
 'private-business-project-outer-commit-independent-actual-worker-before-outer-commit-missing-source-not-mail-quarantine',
 'private-business-project-outer-rollback-independent-actual-worker-before-outer-commit-missing-source-not-mail-quarantine',
 'private-business-project-outer-rollback-orphan-worker-cannot-create-mail',
 'private-business-task-normal-actual-publisher-current-source-and-isolated-native-recipient-envelopes',
 'private-business-task-normal-fanout-current-native-owner-automatic-and-revoked-subscriber-are-independent',
 'private-business-task-outer-commit-actual-publisher-current-source-and-isolated-native-recipient-envelopes',
 'private-business-task-outer-commit-fanout-current-native-owner-automatic-and-revoked-subscriber-are-independent',
 'private-business-task-outer-commit-independent-actual-worker-before-outer-commit-missing-source-not-mail-quarantine',
 'private-business-task-outer-rollback-independent-actual-worker-before-outer-commit-missing-source-not-mail-quarantine',
 'private-business-task-outer-rollback-orphan-worker-cannot-create-mail',
 'private-mail-legalpad-actual-named-effective-policy-change-rechecks-new-request-with-native-positive-controls',
 'private-mail-legalpad-actual-project-membership-policy-allows-current-signer-new-request',
 'private-mail-legalpad-before-first-signature-read-user-source-barrier-observes-committed-secret-and-refuses-old-code',
 'private-mail-legalpad-native-project-membership-revocation-denies-new-request-and-mail-source',
 'private-mail-legalpad-project-membership-restoration-is-real-native-positive-control',
 'private-mail-legalpad-real-inflight-version-change-after-independent-source-lock-wait-refuses-stale-consumption',
 'private-mail-legalpad-signature-index-race-follows-committed-rotation-or-observed-source-writer-rollback',
 'private-mail-legalpad-verifier-victim-rolls-back-and-releases-locks-before-connection-close-with-no-mail',
 'private-mail-legalpad-weighted-index-race-proves-actual-verifier-deadlock-victim-not-permission-denial'])
REQUIRED_CHECKS = REQUIRED_CHECKS | NOTIFICATION_BOUNDARY_CHECKS
WORKERS = 40  # Root25 plus six N12 independent probes; local business subprocesses excluded.
NATIVE['private_mail_tests']['legalpad_tests']['policy_source_tests'] = {'actual_named_effective_policy': True,
 'actual_project_membership_editor_and_materialized_source': True,
 'all_policy_sources_atomically_locked': False,
 'document_space_acl_invented': False,
 'new_request_revocation_checked': True}
NATIVE['private_mail_tests']['legalpad_tests']['inflight_source_tests'] = {'actual_independent_source_mutation_cases': {'version': {'actual_lock_wait': True,
                                                          'independent_process': True,
                                                          'stale_consumption_refused': True}},
 'before_first_signature_read': {'current_code_get_positive': True,
                                 'native_user_barrier': True,
                                 'old_code_refused': True,
                                 'source_rotation_committed': True},
 'document_space_acl_invented': False,
 'forced_verifier_victim': {'actual_engine_victim_proved': True,
                            'heavier_source_transaction_proved': True,
                            'automatic_consume_retry_used': False,
                            'controller_http500_proved': False,
                            'controller_tested': False,
                            'exact_native_deadlock_exception': True,
                            'locks_released_before_connection_close': True,
                            'no_followup_mail': True,
                            'source_rotation_committed': True,
                            'unverified_after_rollback': True},
 'index_race': {'actual_lock_wait': True,
                'manual_retry_used': False,
                'observed_victim': 'source-writer',
                'old_code_consumed': True,
                'outcome_follows_committed_facts': True,
                'service_outcome': 'verified',
                'source_writer_commit_ack': False,
                'source_writer_deadlock_rollback': True},
 'last_view_to_commit_global_atomicity_proved': False,
 'production_source_changed': False}
NATIVE['private_mail_tests']['business_publication_tests'] = {'actual_native_sql_queue_profile': True,
 'actual_child_native_mail_taskqueue_http_proved': True,
 'actual_task_and_project_editors': True,
 'independent_php_workers_executed': 10,
 'live_processes_after_cleanup': 0,
 'manual_diagnostic_reexecution_is_daemon_retry': False,
 'paired_gorge_queue_profile_proved': False,
 'physical_smtp_proved': False,
 'private_directories_remaining': 0,
 'private_directories_removed': 10,
 'production_permission_flags_changed': False,
 'samples': {'project': {'normal': {'postcompletion_mail_envelopes': 1,
                                    'postcompletion_source_visible': True,
                                    'precompletion_native_permanent_failure': False,
                                    'precompletion_source_visible': True},
                         'outer-commit': {'postcompletion_mail_envelopes': 1,
                                          'postcompletion_source_visible': True,
                                          'precompletion_native_permanent_failure': True,
                                          'precompletion_source_visible': False},
                         'outer-rollback': {'postcompletion_mail_envelopes': 0,
                                            'postcompletion_source_visible': False,
                                            'precompletion_native_permanent_failure': True,
                                            'precompletion_source_visible': False}},
             'task': {'normal': {'postcompletion_mail_envelopes': 2,
                                 'postcompletion_source_visible': True,
                                 'precompletion_native_permanent_failure': False,
                                 'precompletion_source_visible': True},
                      'outer-commit': {'postcompletion_mail_envelopes': 2,
                                       'postcompletion_source_visible': True,
                                       'precompletion_native_permanent_failure': True,
                                       'precompletion_source_visible': False},
                      'outer-rollback': {'postcompletion_mail_envelopes': 0,
                                         'postcompletion_source_visible': False,
                                         'precompletion_native_permanent_failure': True,
                                         'precompletion_source_visible': False}}}}
HTTP_MEASUREMENT_PRODUCER = 'support/governance/native-organization/revocation-http-cases.php'
HTTP_MEASUREMENT_ROUTER = 'support/governance/native-organization/revocation-http-router.php'
HTTP_MEASUREMENT_CHECKS = frozenset(['n12-conduit-http-independent-standard-token-pretransition-allow', 'n12-conduit-http-native-parse-error-is-unknown-not-auth-denial', 'n12-conduit-http-owned-token-delete-commit-and-absence-proof', 'n12-conduit-http-fresh-postcommit-native-auth-denial', 'n12-conduit-http-serial-parent-clock-same-boot-source-binding', 'n12-conduit-http-three-owned-servers-reaped-and-private-directories-removed'])
REQUIRED_CHECKS = REQUIRED_CHECKS | HTTP_MEASUREMENT_CHECKS
# Mutation-test template only, never an executed observation.
_http_event=json.loads(json.dumps(NATIVE['api_token_revocation_measurement_tests']['event']))
_http_event['schema']=3
_http_event['producer_sha256']=hashlib.sha256((ROOT/HTTP_MEASUREMENT_PRODUCER).read_bytes()).hexdigest()
_http_event['transition']['boundary']='native-token-dao-delete-http-new-requests'
NATIVE['conduit_HTTP_revocation_measurement_tests']={
    'profile':'native-conduit-http-token-revocation-only','HTTP_proved':True,'gateway_proved':False,
    'long_connection_proved':False,'SLA_proved':False,'convergence_proved':False,
    'actual_Conduit_HTTP_requests':4,'actual_independent_HTTP_processes':3,'native_parse_error_control_observed':True,
    'token_rows_deleted':1,'native_transaction_record_available':False,
    'server_cleanup':{'started':3,'stopped':3,'directories_removed':3,'remaining':0,'live':0},
    'router_sha256':hashlib.sha256((ROOT/HTTP_MEASUREMENT_ROUTER).read_bytes()).hexdigest(),
    'before_source_binding_sha256':'e'*64,'after_source_binding_sha256':'f'*64,'event':_http_event}


# Metadata-only governance notice vertical; never full producer/SMTP acceptance.
GOVERNANCE_NOTICE_CHECKS=frozenset(['native-schema-governance_notice_delivery-matches-retained-adjustment-spec', 'native-schema-governance_notice_event-matches-retained-adjustment-spec', 'notice-actual-audit-row-change-refuses-bound-physical-delivery', 'notice-actual-nontransactional-outbox-refused-with-business-rollback', 'notice-current-native-admin-receives-one-durable-mail-mapping', 'notice-disabled-real-organization-write-creates-no-event', 'notice-disabled-recipient-refused-before-physical-send', 'notice-duplicate-primary-is-unknown-and-refused', 'notice-event-binds-actual-audit-without-private-body-name-or-authority', 'notice-independent-native-process-real-insert-lock-wait-proved', 'notice-independent-reader-sees-business-audit-event-and-relay-only-after-real-commit', 'notice-independent-reader-sees-no-business-audit-event-or-relay-before-commit', 'notice-malformed-event-metadata-with-valid-digest-is-not-authority', 'notice-native-benign-username-edit-does-not-discard-current-eligible-delivery', 'notice-native-disable-enable-without-intermediate-callback-cannot-revive-account-binding', 'notice-native-operation-replay-does-not-duplicate-event', 'notice-native-prepared-snapshot-is-private-link-only', 'notice-native-worker-retry-does-not-duplicate-fanout', 'notice-ordinary-user-is-not-implicitly-added-to-admin-audience', 'notice-physical-attempt-rechecks-current-admin-source-and-primary-email', 'notice-primary-email-change-denies-and-restore-cannot-revive-delivery', 'notice-project-audience-requires-real-view-even-for-system-admin', 'notice-project-current-view-revocation-stops-existing-prepared-mail', 'notice-real-gorge-event-inbox-replay-has-one-task', 'notice-real-gorge-lease-to-native-conduit-handler-prepares-mail-outbox', 'notice-real-native-audit-and-qualified-outbox-commit-together', 'notice-real-post-outbox-sql-fault-rolls-back-business-audit-event-and-relay', 'notice-real-project-approval-producer-commits-metadata-only-event', 'notice-real-stop-classification-producer-is-not-recovery-authority', 'notice-reenabled-recipient-cannot-revive-quarantined-snapshot', 'notice-same-address-new-native-email-row-refuses-old-binding', 'notice-stop-classification-replay-does-not-duplicate-event', 'notice-stop-worker-prepares-admin-mail-without-enabling-subject', 'notice-unverified-primary-refused-at-physical-attempt', 'notice-worker-retry-after-rollback-prepares-one-eligible-recipient-mapping', 'notice-worker-sql-fault-rolls-back-native-mail-recipient-edges-mapping-and-mail-outbox'])
REQUIRED_CHECKS=REQUIRED_CHECKS|GOVERNANCE_NOTICE_CHECKS
WORKERS=41
NATIVE['schema_tests']['candidate_tables_compared']=20
REQUIRED_CHECKS=REQUIRED_CHECKS|frozenset(['native-schema-governance_notice_identityepoch-matches-retained-adjustment-spec'])
NATIVE['private_mail_tests']['governance_notice_tests']={'actual_native_producer': True, 'qualified_source_transaction': True, 'actual_gorge_inbox_and_lease': True, 'actual_native_handler': True, 'per_physical_current_eligibility': True, 'link_only': True, 'automatic_go_outbox_relay_proved': False, 'Go_daemon_proved': False, 'HTTP_gateway_proved': False, 'SMTP_proved': False, 'all_governance_producers_complete': False}

# Selected User/Session producer evidence, never a restoration permit.
RECOVERY_NATIVE_PRODUCER_CHECKS = frozenset(['recovery-guard-first-approval-remains-explicit-unreviewed-admission', 'recovery-guard-flag-off-real-native-dao-permissive-coercion-control', 'recovery-guard-native-aux-phid-allocation-preserves-only-pending-new-registration', 'recovery-guard-permissive-raw-double-zero-refused', 'recovery-guard-permissive-raw-false-word-refused', 'recovery-guard-permissive-raw-zero-decimal-refused', 'recovery-guard-real-emergency-disable-does-not-depend-on-review-storage', 'recovery-guard-real-native-dao-enable-cannot-bypass-source-closure', 'recovery-guard-real-native-user-editor-enable-rolls-back-without-permit', 'recovery-guard-real-permissive-session-sql-mode-control', 'recovery-guard-replace-cannot-reclassify-existing-native-identity-as-new', 'recovery-guard-untracked-native-raw-transaction-refused-without-implicit-commit', 'recovery-producer-schema-recovery_restore_audit-matches-retained-adjustment-spec', 'recovery-producer-schema-recovery_restore_operation-matches-retained-adjustment-spec', 'recovery-producer-schema-recovery_restore_request-matches-retained-adjustment-spec', 'recovery-session-nested-producer-savepoint-preserves-real-outer-rollback', 'recovery-session-original-covering-share-returns-under-primary-x-without-session-write', 'recovery-session-release-persists-two-independent-native-sessions-without-restoration', 'recovery-session-two-real-native-inserts-wait-on-user-fence-before-source-write', 'recovery-session-untracked-raw-transaction-1568-preserves-caller-write'])
REQUIRED_CHECKS = REQUIRED_CHECKS | RECOVERY_NATIVE_PRODUCER_CHECKS
NATIVE['recovery_native_producer_tests'] = {'schema': {'native_schema_comparison_executed': True, 'candidate_tables_compared': 3, 'schema_adjustment_executed': False, 'all_existing_schema_issues_claimed_resolved': False}, 'user': {'actual_native_orm_entry_profile': True, 'actual_flag_off_native_DAO_control': True, 'permissive_SQL_mode_raw_flags_refused': True, 'initial_approval_supported': False, 'complete_restoration_permit': False, 'global_sources_complete': False, 'production_activation': False}, 'sessions': {'selected_native_session_insert_serialization': True, 'original_covering_share_positive_control': True, 'actual_primary_user_record_fence': True, 'independent_current_actor_credentials': True, 'actor_epoch_initialization_outside_observed_fence': True, 'all_session_producers_closed': False, 'all_sources_complete': False, 'restoration_executed': False}, 'selected_mechanical_producers_only': True, 'restore_service_review_profile_proved': False, 'all_13_source_families_complete': False, 'can_execute': False}

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
    exact_tree(value['current_table_count'], 549)
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
    exact_tree(audit['source_patch_count'], 1575)
    exact_tree(audit['applied_patch_count'], audit['source_patch_count'])


REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset(['private-mail-physical-large-security-body-current-source-allowed', 'private-mail-physical-large-security-attachment-current-source-allowed', 'private-mail-physical-oversized-message-refused-without-poisoning-valid-snapshot', 'private-mail-physical-large-security-payload-still-requires-current-native-source'])
NATIVE['project_execution_tests']['approved_native_view_policy'] = {'native_view_only': True, 'future_approver_view_not_added': True, 'whole_family_ready': False, 'join_and_space_supported': False}
REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset(['project-view-approval-does-not-override-native-self-view-validator', 'project-view-approved-transition-may-remove-approver-future-view', 'project-view-approver-current-view-does-not-require-edit', 'project-view-history-still-requires-current-native-view', 'project-view-native-record-policy-ticket-audit-commit-together', 'project-view-native-writer-scope-released'])

REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset(['project-view-real-api-token-edit-missing-ticket-refused-without-write', 'project-view-real-api-token-view-ticket-cannot-authorize-edit-policy', 'project-view-real-api-token-proposal-and-public-edit-commit-native-view-only'])
NATIVE['project_execution_tests']['approved_native_view_policy'].update(actual_api_token_conduit_path=True,view_ticket_cannot_edit_policy=True)


REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset(['recovery-stop-active-account-not-classified-as-stopped', 'recovery-stop-actual-native-disable-committed-before-classification', 'recovery-stop-actual-session-logout-refuses-classification', 'recovery-stop-actual-session-logout-refuses-history', 'recovery-stop-conflicting-operation-payload-refused', 'recovery-stop-current-admin-history-has-bounded-native-id-pagination', 'recovery-stop-current-ordinary-user-cannot-replay-admin-operation', 'recovery-stop-disabled-candidate-refuses-before-transaction', 'recovery-stop-exact-operation-replay-does-not-duplicate-or-rewrite-audit', 'recovery-stop-existing-classification-not-silently-replaced', 'recovery-stop-history-not-visible-to-ordinary-user', 'recovery-stop-old-event-rejected-after-real-disable-enable-disable', 'recovery-stop-real-audit-fault-rolls-back-ledger-but-preserves-native-emergency-stop', 'recovery-stop-real-ledger-and-audit-commit-without-recovery-grant', 'recovery-stop-refusal-preserves-unrelated-native-outer-transaction'])
NATIVE['recovery_stop_tests'] = {'actual_native_disable_then_separate_ledger': True, 'actual_audit_failure_rollback': True, 'emergency_disable_depends_on_ledger': False, 'recovery_authorized': False, 'concurrent_writer_proof': False, 'production_entry_registered': False}


STOP_BOUNDARY_CHECKS = frozenset(['recovery-stop-concurrent-same-operation-commits-once-and-replays-once', 'recovery-stop-history-expired-during-native-user-lock-wait-is-refused', 'recovery-stop-history-rechecks-native-role-after-revocation', 'recovery-stop-independent-current-admin-history-before-revocation', 'recovery-stop-missing-audit-table-refuses-without-ledger-change', 'recovery-stop-nontransactional-audit-table-refuses-without-ledger-change', 'recovery-stop-raw-outer-rollback-restores-only-caller-write', 'recovery-stop-record-expired-during-native-user-lock-wait-is-refused', 'recovery-stop-record-expiry-wait-begins-with-real-live-credential', 'recovery-stop-revoked-current-admin-cannot-replay-after-real-wait', 'recovery-stop-session-expiry-wait-begins-with-real-live-credential', 'recovery-stop-two-real-sessions-wait-on-native-user-row', 'recovery-stop-unsupported-cluster-profile-refused-before-ledger-access', 'recovery-stop-untracked-physical-transaction-remains-owned-by-caller', 'recovery-stop-waiting-writer-observes-real-native-admin-removal'])
REQUIRED_CHECKS = REQUIRED_CHECKS | STOP_BOUNDARY_CHECKS
NATIVE['recovery_stop_boundary_tests'] = {'two_session_same_operation_proof': True, 'current_admin_revocation_after_wait': True, 'real_session_expiry_during_wait': True, 'missing_and_nontransactional_table_refused': True, 'unsupported_routing_refused': True, 'raw_outer_transaction_preserved': True, 'recovery_authorized': False, 'all_sources_complete': False, 'production_entry_registered': False}

BUSINESS_GORGE_CHECKS = frozenset(['private-business-gorge-task-normal-native-editor-http-enqueue-real-task',
 'private-business-gorge-task-normal-native-handler-and-fenced-queue-outcome-current-source',
 'private-business-gorge-task-outer-commit-native-editor-http-enqueue-real-task',
 'private-business-gorge-task-outer-commit-native-handler-and-fenced-queue-outcome-current-source',
 'private-business-gorge-task-outer-commit-real-handler-prepare-before-source-commit-no-mail',
 'private-business-gorge-task-outer-rollback-native-editor-http-enqueue-real-task',
 'private-business-gorge-task-outer-rollback-native-handler-and-fenced-queue-outcome-current-source',
 'private-business-gorge-task-outer-rollback-real-handler-prepare-before-source-commit-no-mail',
 'private-business-gorge-task-fenced-yield-awaken-same-task-released-again',
 'private-business-gorge-project-normal-native-editor-http-enqueue-real-task',
 'private-business-gorge-project-normal-native-handler-and-fenced-queue-outcome-current-source',
 'private-business-gorge-project-outer-commit-native-editor-http-enqueue-real-task',
 'private-business-gorge-project-outer-commit-native-handler-and-fenced-queue-outcome-current-source',
 'private-business-gorge-project-outer-commit-real-handler-prepare-before-source-commit-no-mail',
 'private-business-gorge-project-outer-rollback-native-editor-http-enqueue-real-task',
 'private-business-gorge-project-outer-rollback-native-handler-and-fenced-queue-outcome-current-source',
 'private-business-gorge-project-outer-rollback-real-handler-prepare-before-source-commit-no-mail',
 'private-business-gorge-project-fenced-yield-awaken-same-task-released-again'])
REQUIRED_CHECKS = REQUIRED_CHECKS | BUSINESS_GORGE_CHECKS
NATIVE['private_mail_tests']['business_gorge_publication_tests'] = {'actual_native_conduit_handler': True,
 'actual_native_editor_http_enqueue': True,
 'actual_service_leases_and_fenced_outcomes': True,
 'explicit_yield_retry_proved': True,
 'gorge_worker_daemon_proved': False,
 'independent_php_workers_executed': 10,
 'live_processes_after_cleanup': 0,
 'permanent_source_denial_automatically_retried': False,
 'physical_smtp_proved': False,
 'private_directories_remaining': 0,
 'private_directories_removed': 10,
 'samples': {'project': {'normal': {'handler_result': 'success', 'mail_envelopes': 1},
                         'outer-commit': {'handler_result': 'success', 'mail_envelopes': 1},
                         'outer-rollback': {'handler_result': 'permanent-failure', 'mail_envelopes': 0}},
             'task': {'normal': {'handler_result': 'success', 'mail_envelopes': 2},
                      'outer-commit': {'handler_result': 'success', 'mail_envelopes': 2},
                      'outer-rollback': {'handler_result': 'permanent-failure', 'mail_envelopes': 0}}},
 'worker_execute_http_gateway_proved': False}

BUSINESS_GORGE_CREATE_CHECKS = frozenset([
 'private-business-gorge-project-normal-actual-native-create-envelope-visible-initial-fields-hidden',
 'private-business-gorge-project-outer-commit-actual-native-create-envelope-visible-initial-fields-hidden',
 'private-business-gorge-project-outer-rollback-actual-native-create-envelope-visible-initial-fields-hidden'])
REQUIRED_CHECKS = REQUIRED_CHECKS | BUSINESS_GORGE_CREATE_CHECKS
NATIVE['private_mail_tests']['business_gorge_publication_tests']['actual_native_project_create_envelope_verified'] = True

REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset([
 'private-business-project-normal-actual-native-create-envelope-visible-initial-fields-hidden',
 'private-business-project-outer-commit-actual-native-create-envelope-visible-initial-fields-hidden',
 'private-business-project-outer-rollback-actual-native-create-envelope-visible-initial-fields-hidden'])
NATIVE['private_mail_tests']['business_publication_tests']['actual_native_project_create_envelope_verified'] = True

POLICY_ERROR_ROUTE_CHECKS = frozenset(['project-policy-errors-real-controller-input-json-is-400', 'project-policy-errors-real-controller-missing-ticket-is-generic-403-without-write', 'project-policy-errors-real-controller-stored-json-corruption-propagates-backend-error', 'project-policy-errors-real-native-edit-missing-ticket-renders-validation-without-write', 'project-policy-errors-explicit-description-extra-effect-refuses-actionably-without-consumption', 'project-policy-errors-native-separate-content-save-persists-description-and-old-proposal-stays-stale', 'project-policy-errors-fresh-proposal-after-separate-content-save-executes-only-approved-policy'])
REQUIRED_CHECKS = REQUIRED_CHECKS | POLICY_ERROR_ROUTE_CHECKS

WIKI_CONTENT_CHECKS = frozenset(['private-wiki-content-real-native-editable-root-precondition', 'private-wiki-content-normal-actual-existing-content-transaction-visible-for-mail', 'private-wiki-content-normal-native-editor-real-gorge-http-enqueue', 'private-wiki-content-normal-native-publisher-current-source-and-fenced-outcome', 'private-wiki-content-outer-commit-actual-existing-content-transaction-visible-for-mail', 'private-wiki-content-outer-commit-native-editor-real-gorge-http-enqueue', 'private-wiki-content-outer-commit-native-publisher-current-source-and-fenced-outcome', 'private-wiki-content-outer-rollback-actual-existing-content-transaction-visible-for-mail', 'private-wiki-content-outer-rollback-native-editor-real-gorge-http-enqueue', 'private-wiki-content-outer-rollback-native-publisher-current-source-and-fenced-outcome', 'private-wiki-content-outer-commit-independent-prepare-sees-old-content-before-commit-no-mail', 'private-wiki-content-outer-rollback-independent-prepare-sees-old-content-before-commit-no-mail', 'private-wiki-content-owned-independent-workers-and-directories-clean'])
WIKI_CONTENT_CHECKS = WIKI_CONTENT_CHECKS | frozenset(['private-wiki-content-paired55-render-owned-ready','private-wiki-content-missing-render-transient-no-mail-or-success','private-wiki-content-render-owned-process-storage-clean'])
REQUIRED_CHECKS = REQUIRED_CHECKS | WIKI_CONTENT_CHECKS
NATIVE['private_mail_tests']['wiki_content_publication_tests'] = {'actual_existing_wiki_content_editor': True, 'actual_paired_queue_and_native_handler': True, 'before_commit_probe_is_prepare_only': True, 'real_before_commit_execute_race_proved': False, 'move_mail_claimed': False, 'automatic_retry_after_permanent_source_failure': False, 'HTTP_gateway_proved': False, 'Go_daemon_proved': False, 'SMTP_proved': False, 'samples': {'normal': {'handler_result': 'success', 'safe_subscriber_mail': True}, 'outer-commit': {'handler_result': 'success', 'safe_subscriber_mail': True}, 'outer-rollback': {'handler_result': 'permanent-failure', 'safe_subscriber_mail': False}}, 'independent_php_workers_executed': 6, 'private_directories_removed': 6, 'private_directories_remaining': 0, 'live_processes_after_cleanup': 0, 'actual_paired55_prose_diff': True, 'missing_render_transient_no_mail_proved': True, 'render_cleanup': {'binary_sha256': 'd05250617dfa12f0b7cfc9108423b55a91ce9dd56224c96335f847deb40c22d2', 'source_revision': '83351b0e7eba38e50ff076ba6763ca68ebc57e1b', 'process_stopped': True, 'private_storage_removed': True}}

def validate(data, root=ROOT, file_digest=None, measurement_revision=None, render_digest=None):
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
    revision=native.get('http_tests',{}).get('file_guard_tests',{}).get('image_tests',{}).get('backend_revision_sha256')
    if type(revision) is not str or not re.fullmatch(r'[a-f0-9]{64}',revision):
        raise ValueError('paired image revision diagnostic digest refused')
    expected_native['http_tests']=dict(NATIVE['http_tests'])
    expected_native['http_tests']['file_guard_tests']=dict(NATIVE['http_tests']['file_guard_tests'])
    expected_native['http_tests']['file_guard_tests']['image_tests']=dict(NATIVE['http_tests']['file_guard_tests']['image_tests'],backend_revision_sha256=revision)
    # Native self-mail preferences may omit the author, but both independent
    # Task owner/subscriber envelopes are required. Accept only the bounded
    # actual count (2 or 3); every other summary field remains exact and typed.
    private_mail=native.get('private_mail_tests')
    if type(private_mail) is not dict or type(private_mail.get('business_publication_tests')) is not dict:
        raise ValueError('native business publication summary refused')
    actual_business=private_mail['business_publication_tests']
    if type(actual_business.get('samples')) is not dict:
        raise ValueError('native business publication samples refused')
    expected_business=json.loads(json.dumps(NATIVE['private_mail_tests']['business_publication_tests']))
    for family in ('task','project'):
        for ending in ('normal','outer-commit','outer-rollback'):
            family_samples=actual_business['samples'].get(family)
            if type(family_samples) is not dict or type(family_samples.get(ending)) is not dict:
                raise ValueError('native business publication sample refused')
            count=family_samples[ending].get('postcompletion_mail_envelopes')
            low,high=(0,0) if ending=='outer-rollback' else ((2,3) if family=='task' else (1,1))
            if type(count) is not int or not low <= count <= high:
                raise ValueError('native business publication envelope count refused')
            expected_business['samples'][family][ending]['postcompletion_mail_envelopes']=count
    actual_gorge=private_mail.get('business_gorge_publication_tests')
    if type(actual_gorge) is not dict or type(actual_gorge.get('samples')) is not dict:
        raise ValueError('native Gorge publication summary refused')
    expected_gorge=json.loads(json.dumps(NATIVE['private_mail_tests']['business_gorge_publication_tests']))
    for family in ('task','project'):
        for ending in ('normal','outer-commit','outer-rollback'):
            samples=actual_gorge['samples'].get(family)
            if type(samples) is not dict or type(samples.get(ending)) is not dict:
                raise ValueError('native Gorge publication sample refused')
            count=samples[ending].get('mail_envelopes')
            low,high=(0,0) if ending=='outer-rollback' else ((2,3) if family=='task' else (1,1))
            if type(count) is not int or not low <= count <= high:
                raise ValueError('native Gorge publication envelope count refused')
            expected_gorge['samples'][family][ending]['mail_envelopes']=count
    legalpad=private_mail.get('legalpad_tests')
    if type(legalpad) is not dict or type(legalpad.get('inflight_source_tests')) is not dict:
        raise ValueError('native Legalpad race summary refused')
    index=legalpad['inflight_source_tests'].get('index_race')
    expected_index=NATIVE['private_mail_tests']['legalpad_tests']['inflight_source_tests']['index_race']
    if type(index) is not dict or set(index)!=set(expected_index):
        raise ValueError('native Legalpad index facts refused')
    for key in ('actual_lock_wait','outcome_follows_committed_facts','manual_retry_used'):
        exact_tree(index[key],expected_index[key])
    for key in ('source_writer_commit_ack','source_writer_deadlock_rollback','old_code_consumed'):
        if type(index[key]) is not bool:
            raise ValueError('native Legalpad index boolean refused')
    if type(index['observed_victim']) is not str or type(index['service_outcome']) is not str:
        raise ValueError('native Legalpad index outcome refused')
    facts=(index['source_writer_commit_ack'],index['source_writer_deadlock_rollback'],
           index['observed_victim'],index['service_outcome'],index['old_code_consumed'])
    if facts not in ((True,False,'none','source-refused',False),
                     (True,False,'verifier','deadlock',False),
                     (False,True,'source-writer','verified',True)):
        raise ValueError('native Legalpad outcome contradicts committed facts')
    expected_legalpad=json.loads(json.dumps(NATIVE['private_mail_tests']['legalpad_tests']))
    expected_legalpad['inflight_source_tests']['index_race']=index
    expected_native['private_mail_tests']=dict(NATIVE['private_mail_tests'],
        business_gorge_publication_tests=expected_gorge, business_publication_tests=expected_business,legalpad_tests=expected_legalpad)
    if type(render_digest)is not str or not re.fullmatch(r'[a-f0-9]{64}',render_digest):
        raise ValueError('paired render binary input required')
    expected_wiki=json.loads(json.dumps(NATIVE['private_mail_tests']['wiki_content_publication_tests']))
    expected_wiki['render_cleanup']['binary_sha256']=render_digest
    expected_native['private_mail_tests']['wiki_content_publication_tests']=expected_wiki

    if type(measurement_revision) is not str or not re.fullmatch('[a-f0-9]{40}', measurement_revision):
        raise ValueError('explicit measurement source revision required')
    producer_digest=hashlib.sha256((Path(root)/MEASUREMENT_PRODUCER).read_bytes()).hexdigest()
    observed=native.get('revocation_measurement_tests')
    measurement.describe_native_query_receipt(observed,measurement_revision,producer_digest)
    expected_native['revocation_measurement_tests']=observed
    token_observed=native.get('api_token_revocation_measurement_tests')
    token_digest=hashlib.sha256((Path(root)/API_TOKEN_MEASUREMENT_PRODUCER).read_bytes()).hexdigest()
    measurement.describe_native_token_receipt(token_observed,measurement_revision,token_digest)
    expected_native['api_token_revocation_measurement_tests']=token_observed
    http_observed=native.get('conduit_HTTP_revocation_measurement_tests')
    http_digest=hashlib.sha256((Path(root)/HTTP_MEASUREMENT_PRODUCER).read_bytes()).hexdigest()
    router_digest=hashlib.sha256((Path(root)/HTTP_MEASUREMENT_ROUTER).read_bytes()).hexdigest()
    measurement.describe_native_http_receipt(http_observed,measurement_revision,http_digest,router_digest)
    expected_native['conduit_HTTP_revocation_measurement_tests']=http_observed
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
    parser.add_argument('--render-binary', type=Path, required=True)
    parser.add_argument('--measurement-source-revision', required=True)
    args=parser.parse_args()
    try:
        if args.receipt.stat().st_size > 1048576:
            raise ValueError('receipt size refused')
        data=json.loads(args.receipt.read_text(), object_pairs_hook=completion.strict_object,
            parse_constant=lambda unused: (_ for _ in ()).throw(ValueError('invalid JSON constant')))
        if not args.file_binary.is_file() or not args.render_binary.is_file():
            raise ValueError('paired binary missing')
        accepted=validate(data, file_digest=hashlib.sha256(args.file_binary.read_bytes()).hexdigest(), measurement_revision=args.measurement_source_revision, render_digest=hashlib.sha256(args.render_binary.read_bytes()).hexdigest())
    except (OSError, ValueError, TypeError, KeyError):
        parser.exit(1, 'Native organization receipt rejected.\n')
    print(json.dumps(accepted, sort_keys=True))


if __name__ == '__main__':
    main()
