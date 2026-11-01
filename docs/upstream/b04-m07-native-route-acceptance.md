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

## Cross-object, formats and leased queue follow-up

The v3 receipt adds separate route and worker coverage, retaining the four
native permission axes and disabled optional controls. Only fixture-owned task
IDs are leased/executed; no generic daemon is started and queue ownership stays
native SQL. This does not certify the separately optional Gorge-owned queue.

- Project-members, project-or-user and ancestor composite datasources add 36
  autocomplete/browse/select observations with owner/outsider positive controls
  and hidden title/identifier checks.
- CSV and tab-separated text exports check owner/outsider visible row counts,
  private file policy/author, quoting/formula protection or separator escaping,
  and empty field positions. Text previously removed empty cells; it now keeps
  those cells so values stay aligned with headers. Excel is tested through its
  native unavailable-dependency rejection, without installing PHPExcel or
  enabling an optional format; positive XLSX output is not certified.
- An actual authenticated export submission has 1,005 visible tasks and one
  hidden task, exceeds the native asynchronous threshold, and creates a real
  queued job. Two tasks lose visibility before execution. Native create/task
  workers must archive successfully and export exactly the remaining 1,003
  unique tasks across the 1,000-row page boundary. Revoked/hidden titles and
  identifiers must be absent, and the generated file stays private to its author.
  Repeating the archived worker must fail permanently without replacing its file.
- This exact-set check detected a native export pagination defect: reusing a
  query retained the internal lookahead cursor and skipped one object per page
  boundary. The export engine now rebuilds the same saved query per page, while
  preserving the viewer and native filtering.
- Real bulk creator/item workers use persisted jobs, items and leased SQL tasks.
  A scoped SQL fault before item execution must retain a transient queue failure,
  increment its counter and delay its lease, without business writes. The native
  negative lease-duration testing API expires only that owned task. Retrying
  either commits one authorized transaction or records a denied business item
  after intervening revocation. The outer worker can archive SUCCESS while the
  business item is FAIL; aggregate job COMPLETE is not an all-items-success flag.
  Administrative unarchive/re-execution cannot duplicate the completed item.
- Ordinary-child and milestone cases use separate processes and a deterministic
  editor-instance barrier. Ordinary children retain their loaded-parent snapshot
  for the in-flight edit even when ancestor revocation commits while paused.
  Milestone synchronization waits on the locked child row: its revoker must be observed waiting
  in MySQL before release. Both then deny fresh edits with unchanged business
  state. These are distinct native boundaries, not a uniform cancellation rule. This is not a new global permission lock or retroactive-cancellation
  guarantee. Native behavior must not be silently replaced by a candidate model.

Raw exports, task payloads, fixture PHIDs, barriers and logs stay private. The
shared receipt contains fixed coverage counts and boolean/alias assertions.
Other application datasources, optional positive XLSX, arbitrary membership/Space
concurrency, mid-export mutations, queue process-crash recovery and Gorge-owned
queue transport remain separate obligations. No live migration or deployment is
performed, and the 32 broader ADR designs remain not fully certified.

The source catalog now includes 66 reviewed files: 43 literal membership-reference
files and 23 selected downstream boundaries. Source review remains distinct
from runtime certification of every path through these files.

The milestone interleaving also exposed a lock cycle: policy-only parent saves
rewrote unchanged datasource tokens before synchronizing the locked child, while
child renames needed token locks. Project saves now skip token replacement when
the existing and desired multisets are identical. Regression checks preserve
parent token rows on policy changes and verify real child renames still update
tokens. Permission and Space synchronization rules remain unchanged. This does
not claim to eliminate every possible project-edit deadlock.

The retry fault exposed process-local lock retention when native bulk creator or
item workers threw before their explicit unlock. Both lock scopes now release
in `finally`, preserving the normal work/status sequence. Same-process transient
retry and repeated permanent-failure creator/task replay verify release and no
duplicate business writes/items. Mid-creator crashes after item creation remain
outside this bounded test; no whole-job exactly-once claim is made.

A second observed lock cycle came from a milestone running the descendant-Space
UPDATE even though milestones cannot contain milestones. That empty range scan
could conflict with parent synchronization. The editor now uses the existing
`supportsMilestones()` invariant to avoid this impossible child operation. A
positive Space-change control verifies the parent still synchronizes the actual
stored milestone Space. This does not change the Space or permission model.
