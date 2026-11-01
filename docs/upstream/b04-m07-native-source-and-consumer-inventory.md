# B04/M07: native membership sources and consumer boundaries

The four ADR axes retain native behavior by explicit instruction. No candidate
model, runtime selector, role system, optional feature or permission migration
is introduced. This work implements P0 read-only observations and native tests.

## Stored facts are not provenance or permission

The auditor reports each observed project/subject relation as direct-only
(type 13), materialized-only (type 60), or both. Disabled and unapproved flags
are separate overlapping lifecycle dimensions, not replacements for structural
classification. Unknown endpoints remain integrity issues; they are not counted
as valid resolved relations. Every origin remains unknown: these tables alone
cannot distinguish an old automatic move from an explicit invitation.

The summary contains counts only. The restricted report uses the existing keyed
pseudonyms; it contains no inferred correction, grant or executable repair.
Candidate selection does not change the observed-source inventory. Snapshot
and time budgets still apply. No collector privileges, SQL statements or native
materializer behavior are changed. The independent SELECT-only restore tests
remain mandatory. Native member expansion, policy evaluation and final route
admission remain different layers.

## Consumer catalog

`scripts/permissions/consumers.json` is the reviewed catalog.
`scripts/permissions/inventory.py` only reads source plus Git metadata: it does
not bootstrap PHP, connect to a database, invoke edge editors, run materializers,
call Gorge, or collect production data. Every matched file must have a reviewed
meaning and authority. New or removed literal references fail the inventory
until reviewed. Reports include exact source commit, file SHA-256 and matching
line numbers, and are uploaded with the route artifact in mandatory CI.

Discovery is deliberately bounded to the published literal symbols plus selected
downstream authorization files. This is not a claim to prove a complete dynamic
call graph. Generated map entries and test fixtures are classified separately.
A source-reviewed entry is not a route-certified consumer. Calendar, mail,
Herald rule fields, directory and subscriber-policy consumers are explicitly
listed; they must not silently inherit a different meaning of membership.
Native Herald rule fields here are unrelated to the separately deployed login
verification service with the same name.

The authority boundaries are:

- Type 13: native transaction/edge write path, not evidence of invitation origin.
- Type 60: current native materializer; ancestors use native descendant/leaf
  interpretation and milestones retain parent behavior.
- ProjectQuery, object policy and PolicyFilter: current viewer and ancestor
  policy, automatic membership grants and applicable Space checks.
- API, selectors and directory display: serialize/query only native visible
  projects; `members` attachments use loaded native membership.
- Mail/calendar/Herald/subscriptions: consumer-specific native semantics;
  membership expansion alone is not proof of permission to deliver or view.
- Gorge search transport/adapter: candidate retrieval and indexing. Native
  SearchDocumentQuery reloads handles under current viewer capabilities.
- Feed and board: project access does not grant access to required story objects
  or private task cards. Effective editing requires view plus edit and native
  transaction checks.

## Native boundary follow-up

The original two-phase, ten-subject, twelve-project 1,440-cell contract is kept.
Unknown slug/invalid-ID responses must not disclose project metadata. A real
editable form is captured before revocation and replayed afterward with its
original CSRF fields: a typed native policy rejection, not a generic CSRF error,
is required and business transaction counts must stay unchanged. Native policy
dialogs can legitimately use HTTP 200; status alone is not a denial oracle.
Additional checks cover canonical view/member/slug routes; bounded, duplicate-free
Conduit pagination with members and visible-ancestor attachments; historical
parent direct edges absent from type60; positive edit persistence and exactly one
native transaction per successful edit; and denied edits with unchanged names,
policies and transaction counts. Synthetic successful edits restore the original
fixture name. This is test setup, not an audit mutation or production migration.

A restored default Space is reused without modifying its name, policies or
archive state; a synthetic default is created only if absent. The fixture
queries the same default twice to regress duplicate creation.

After the original matrix and its audit snapshots, adjunct synthetic objects
exercise configured user-list policies, a restricted Space, private/public task
cards and native Feed stories. These objects are deliberately excluded from the
original candidate comparison snapshots: the offline evaluator still reports
Space/custom-policy uncertainty rather than claiming to implement it. Native
routes exercise the actual application policy evaluator. All optional project
and search controls stay off. No original restored account is used.

The new finite coverage validator requires both phases, member attachments,
positive and denied writes, and cross-object/Space/custom-policy cases. Missing
phases, omitted positive controls, empty checks or failed checks are rejected.
No broad security or arbitrary custom-policy certification is claimed.

## Remaining boundaries

This closes a bounded P0 implementation, not all 32 supplementary ADR designs
or all of D07. The catalog makes untested consumers visible, rather than
claiming email/calendar/Herald runtime certification. The transaction/disclosure
follow-up in `b04-m07-native-route-acceptance.md` now checks same-row revocation
serialization, object/bulk-item rollback, selected project selectors and task
query/count/JSON export paths. The v3 follow-up additionally characterizes
ordinary-child and milestone ancestor-policy interleavings, three composite
selectors, CSV/text exports, an actual queued multi-page JSON export and native
SQL lease retries with business/queue status separation. Its token, Space and
lock controls remain native. Ordinary in-flight edits retain loaded-parent
semantics; this is not a retroactive cancellation guarantee.

The v4 isolated extension now verifies positive XLSX files with a locked,
explicitly preloaded test-only dependency, member/Space revocation interleavings,
eight actual process-crash boundaries under native/Gorge ownership and a real
Gorge transient retry. Versioned batch creation is atomic and replay-safe;
indeterminate items fail closed, while unversioned legacy items stay untouched
for reconciliation. See `b04-m07-isolated-recovery-acceptance.md` for exact scope.

Other application datasources, arbitrary membership/Space hierarchy changes,
mid-export revocation, HTTP XLSX downloads/office-reader matrices, network
partitions and stale-lease fencing, Redis and mixed-version workers remain
separate follow-ups. Source review now covers 75 files (43 literal references
and 32 selected boundaries), not a complete call graph. Candidate routes,
permission migration workers and historical correction manifests remain
unimplemented and unauthorized. Keep legacy edges and the native four axes intact.
