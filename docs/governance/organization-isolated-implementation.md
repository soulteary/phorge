# Organization writes and durable approvals: isolated component

This increment follows ADR v1.2 and the bounded native pipeline in PR #82.
It implements real organization-table writes and same-transaction durable
approvals in an owned isolated schema. It does not complete the production
N01/N02/N03/N07 packages or the full governance goal.

## Behavior

Ordinary members can be added, updated, suspended and ended by current eligible
system administrators or the exact team's current eligible administrator.
The latter must have both ordinary membership and a valid team_admin appointment.
Parents, departments and self-membership do not confer directory or management
access. A member's validity cannot be shortened beneath an active administrator
appointment. Disabled subjects remain historically explainable and can be
removed from roles without enabling them.

Administrator appointments require a durable submitted/approved/consumed ticket.
An independent current system administrator must approve; requester and executor
may be the same person. The exact action, subject, period, source rows and
versions bind approval consumption. Several separately approved appointments
may form one bounded atomic business batch. Replay requires current authority
and an identical request, and returns no audit/history pointer.

The writer takes real database locks, refreshes database time after lock waits
and rechecks identities, periods and approval TTL after target/audit/ledger
writes before commit. Fault injection proves rollback of members, appointments,
approval consumption, versions, audit rows and the operation ledger together.
It does not prove rollback of unrelated external effects.

A separate SELECT-only reader uses the same organization schema. List, selector,
count and bounded export-page projections share current authorization. Hidden
parent and departmental references remain hidden; other team relationships are
not exposed. Disabled members can remain visible to an authorized administrator
with account_eligible=false, without acquiring access. Audit-history endpoints
are not installed by this increment.

## Ownership and source boundary

The schema is not a registered native production patch. Factories require the
explicit isolated CLI opt-in, a random owned loopback database, schema hash,
private nonce and exact runtime-library profile. Setup, restricted writer and
SELECT-only reader privileges are distinct. The writer cannot mutate identity
rows, audit history, schema or privilege grants. Identity locks use FOR SHARE;
mutable targets use FOR UPDATE. Participating engines and identity collations
are checked against actual metadata, including during factory creation.

Every ASCII identifier uses ascii_bin. Actual returned keys must match the
bound identity byte for byte, so case aliases cannot bypass independent
approval. Failed factories clean up once and preserve sanitized exceptions.
The correctness-first guard-row mutex is explicit, with no throughput claim.

org_user is a synthetic projection maintained only by fixture setup. No caller
Boolean acts as authorization, but this fact alone is not proof of actual
Phorge account/session authentication. Real DAO/Aphront source adapters,
application/query/Web/Conduit entry points, primary department/lifecycle,
handover, full recovery and future sensitive native-transaction execution still
need implementation. Existing Gorge ownership and native four-axis semantics
remain unchanged. No SQL-over-HTTP service is introduced.

## Local validation

Supported PHP 8.5.12 / MySQL 8.4.13 passed 115 native methods and 94 real SQL
integration checks. These include actual multi-process operation/version races,
observed SQL lock waits across membership/approval expiry, multi-approval atomic
commit and rollback, factory privilege/nonce/collation refusals, source filtering
and physical-engine drift. Cleanup removed one owned schema, three principals
and thirteen private worker directories without errors. The strict receipt gate
requires every one of the 94 checks and retains the synthetic identity scope.

The retained native pipeline and actual paired Gorge file/queue acceptance also
passed all 125 checks. Governance Python passed 56 tests and permission Python
passed 84. Local receipts are source-copy development evidence; final-head PR
CI/review, merged-main checks, future release and test-machine/browser acceptance
are distinct stages. None is inferred from this component's local results.

## Continuing scope

implementation-status.json records all fourteen packages without replacing
historical ADR snapshots. The user has now selected the N09 concrete session /
one-use file-credential exchange and authorized eventual test-machine deployment
and browser acceptance. Optional features still default off; this batch neither
deploys nor migrates production permissions. New public domains, permission
semantic changes and concrete activation scope require separate confirmation.
Owlmail v0.14.0-alpha.1 is a separately pinned, verified Standalone test candidate;
its new-version smoke tests are not existing-service upgrade or integration
acceptance. The full persistent goal remains active.

PR review additionally required byte-exact SQL identity/relation comparisons: ascii_bin alone retains PAD SPACE semantics. Actual padded account, membership and appointment negatives verify refusal. Worker startup now registers owned directories before writing credentials or spawning a child; a real failed-start case verifies owned cleanup.
