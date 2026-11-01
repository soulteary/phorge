# B04/M07 native route acceptance

The acceptance harness exercises **native-v1 only**. Candidate models remain
read-only policy previews; no candidate HTTP handler, migration or role switch
is introduced. Optional navigation, history, workspace and draft controls remain
`flat`, `off`, `native` and `false` throughout the run.

## Dataset and independent contract

`tests/permissions/routes.py` upgrades a unique disposable namespace, constructs
12 native projects and nine synthetic accounts, exports all native schemas,
destroys that namespace, restores the export and verifies the ACL fingerprint.
The graph contains ordinary children, a grandparent chain, milestones, a private
sibling, descendant-only membership and an intentionally unmaterialized direct
parent edge. This last edge is preserved as an ambiguity, never promoted or
deleted as a migration. Project creation and rename use native transactions;
Feed uses the native publisher without executing mail or generic worker queues.

The finite oracle in `route_spec.py` is independent of the HTTP responses and
is checked against native capability queries before requests begin. It covers
owner, inherited editor, explicit child editor, member, outsider, historical
member, administrator, disabled/unapproved stale credentials and anonymous.
Admin status does not bypass project visibility. Effective edit requires view.
Milestones inherit parent behavior. Parent editing remains inherited by ordinary
children; changing the parent editor transfers this inherited permission.

## Route evidence

Each phase has a distinct immutable logical snapshot hash and a permission-state
fingerprint; the revoked comparison must not reuse the restored snapshot identity.

Two phases × ten subjects × twelve projects × six route families = **1,440**
required observations. The second phase reuses existing web sessions and API
tokens after membership and parent-edit revocation. No fulltext reindex or Feed
republishing occurs. Each phase checks native project profile, custom project
query including milestones, token-authenticated Conduit project search, global
search through the paired Gorge/Meilisearch service, Feed detail and native board.
Additional checks cover Feed lists, native edit forms, denied Conduit edits and
unchanged ACL state after requests. Empty denial responses cannot pass without
positive controls for those same objects and routes.

Browser cookies and token-only Conduit clients are separate. The HTTP client
connects only to the owned loopback port, disables environment proxies and
refuses redirects to other hosts. Native server JIT is explicitly disabled.
Raw responses, SQL, credentials, names, PHIDs and server logs stay private and
are deleted; CI uploads only the aggregate/alias-based JSON receipt.

## Restored-source use

Operators may pass `--base-snapshot` and `--base-manifest` for an already
approved private logical snapshot. The private manifest contains `namespace`
and `snapshotSHA256`. The driver validates the hash and exact five-database
inventory (project, user, spaces, policy, config), remaps database-selection
statements into its owned namespace and refuses leftover qualified source
identifiers. Raw data remains on the operator host. Existing restored accounts
are never selected for HTTP or API requests. The controlled graph is synthetic
enrichment, not evidence that production contains those relationships.

Inherited `PHORGE_DEPLOYMENT_CONFIG` and `PHORGE_CONTROL_PLANE` selectors are
refused before any PHP execution. Every child environment explicitly selects
the owned deployment path and deployment mode, including cleanup commands.
Wrappers must forward these pinned values rather than inheriting image defaults.

Both local and highest-precedence deployment configuration files are exclusively
owned and hash-checked before cleanup. An isolated network must fence the
restore from deployment services. Never point the harness at a live database.
The driver requires explicit disposable environment variables; it does not take
an existing instance configuration. Privileged setup/export/restore is distinct
from the SELECT-only collector used for candidate comparisons. The offline
native rule adapter runs only after owned configuration files are removed.

## Interpretation

A passing native route matrix establishes the sampled current behavior, including
revocation against stale sessions, indexes and stories. It does not establish
candidate route behavior, arbitrary custom policies, Space combinations, task
card visibility or every application permission. Candidate comparisons retain
`routeVerification: not-verified` and `migrationReady: false`.

Review access expansion, edit loss and membership provenance separately before
an owner decision. Keep native behavior by default. Do not bundle independent
visibility, explicit editing and member aggregation into one activation switch.

## Transaction and disclosure follow-up

The native suite additionally checks these bounded cases. These tests retain
all four native axes and run only in the owned disposable restored namespace.
No application authorization code or runtime feature default changes. Task
export omits null owner/closer handle lookups to avoid PHP 8.5 deprecations;
export values and permission filtering remain native. The export-byte fixture
turns deprecations into test failures.

- A project instance loaded before edit-policy revocation is reloaded by the
  native transaction editor and cannot commit a stale authorized edit.
- Two independent PHP processes edit and revoke the **same project row**. A
  test-instance validation extension pauses after native transaction/read locking,
  before capability checks. MySQL `performance_schema.data_lock_waits`, joined
  to the revoker's connection ID, must prove an actual wait. Releasing the
  barrier allows the earlier edit to commit, then the revocation; a subsequent
  edit is denied with an identical object/history fingerprint. This proves row
  serialization, not retroactive cancellation of an already authorized write,
  nor locking of unrelated membership, ancestor, Space or policy rows.
- An owned-schema SQL trigger raises a recognizable error at transaction-history
  insertion, after the object save. A two-field edit must roll back both fields
  and history, then the same edit without the fault must commit two transactions.
- Native bulk `createTasks`/`runTask` select two objects, revoke one before
  execution, commit the allowed item, observe a typed native policy exception for the
  revoked item, and leave that item's object/history unchanged. A separate two-field bulk task fails at history insertion and
  must roll back both fields/history, while a different item still commits
  two transactions. This checks the native per-object execution boundary; cross-object
  jobs are not claimed to have whole-batch rollback. Queue scheduling, retry and
  final job-status accounting are not certified by direct task execution.
- Native project autocomplete and browse/select exercise visible and hidden
  projects with positive controls, rejecting hidden titles and identifiers.
- Native task queries use one-row cursor pages on mixed and hidden-only saved
  queries. Exact visible sets, deduplicated counts and actual JSON export bytes
  are checked for an owner and an outsider. Export files are uploaded/read back
  through the paired Gorge service and must retain private policy/author.
  Native HTTP export-confirmation routes must render their CSRF/format forms;
  authenticated form submissions follow the native download and verify actual
  JSON row counts and task titles for the same four cases.

The v2 boundary receipt requires 36 selector cells, four query/export cases,
four HTTP export confirmations/downloads, both worker processes, and observed database
lock waiting. The catalog now reviews 56 source files (43 literal membership
reference files plus 13 selected downstream boundaries). Historical v1 receipts
retain their earlier scope and are not retroactively certified by v2.

Barriers have bounded deadlines and process cleanup. The extension is injected
into one editor instance using reflection, never registered in the application
class map. Trigger cleanup runs in `finally`; normal namespace cleanup removes
all test data. Raw worker logs, export bytes and saved-query identifiers remain
private. CI publishes only boolean results and fixed coverage counts.

This follow-up narrows ADR-E03/E04 and ADR-V03/L02 gaps. It does not certify
arbitrary concurrent ancestor/member-policy changes, every selector datasource,
all export formats, queued large exports, or all supplementary ADR test designs.
B04/M07 and D07 remain partial; candidate models and optional features remain off.
