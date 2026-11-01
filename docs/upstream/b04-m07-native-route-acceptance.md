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
