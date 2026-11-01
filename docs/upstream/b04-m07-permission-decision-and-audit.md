# B04 / M07 permission-model decision and read-only difference audit

Status: proposed decision procedure; no model change, migration, feature
activation or production audit execution is authorized by this document.
The existing native permission model remains authoritative until a reviewed
ADR selects a versioned successor and all migration gates pass.

## Decision boundary and atomic deliverables

B04 produces a versioned ADR, subject/capability matrix, model identifier and
migration/rollback prerequisites. M07 produces a read-only comparison report
and human disposition of ambiguous historical membership. Neither atom is a
runtime boolean switch. M01–M06/M08/M09 are subsequent independent changes;
M07 does not complete them. Team/role packages D08/D09 and handoff D11 depend
on this decision, not the reverse.

The competing #47 and #48 contributions must not be merged together. Evaluate
these choices explicitly against the current baseline:

| Decision | Current/default retained | Candidate requiring approval | Evidence before decision |
| --- | --- | --- | --- |
| Ordinary child view | Native ancestor visibility guard | Child policy independent of hidden ancestor | Newly visible subjects/objects; no hidden ancestor names/PHIDs in lists, selectors, API, counts, feed or backlinks |
| Ordinary child edit | Native policy including inherited semantics | Remove automatic parent-editor grant | Lost editor subjects; explicit child grants preserved; no orphan administration |
| Membership index | Native materialized interpretation | Separate direct writable edges and self/descendant read index | Direct/derived provenance, join/leave semantics, obj.project.members consumers and identical replay |
| Milestones | Native parent membership/policy inheritance | No independent role or membership set | Parent changes propagate; direct child modifications rejected or routed to parent |
| Roles/creator | Native policy, no new roles | D09 viewer/editor/admin as separate later ADR | No historical creator promotion; last-admin and lifecycle rules independently specified |
| Unknown legacy edge provenance | Preserve, classify ambiguous | Owner-approved correction manifest | No inferred deletion or silent "safe" migration |

Recommended default is to retain native access semantics while measuring
impact. If child independence is selected, assign a new immutable model version,
not a hot switch. Decide ordinary child view, edit and member aggregation
separately; a choice on one does not imply the others. Reject unknown model
identifiers before any write or index publication. Do not select a candidate
based solely on more permissive results or lower implementation effort.

## Read-only audit architecture

1. Pin baseline source, candidate source/model IDs, schema identity and a
   snapshot/replica watermark. First run on a disposable restored snapshot.
   Any later live run needs an approved window and a least-privilege read-only
   connection. Do not invoke storage upgrade, transactions, edge editors,
   indexing/rebuild commands, repair, user activation or notification workers.
2. Use SELECT-only access to projects, ancestry, milestone links, direct and
   materialized membership edges, policy rules and referenced active subjects.
   Inventory actual tables/column definitions from the pinned schema before
   writing queries. No guessed historical table names. Execute in a consistent
   read transaction where supported; otherwise report the snapshot limitation.
3. Fetch by stable primary-key cursor (initial page 500, configurable hard cap
   2,000), bounded query/statement timeout and maximum total rows. Never OFFSET
   a mutable production scan. Stop with `incomplete` on timeout, row cap,
   missing policy evaluator, inconsistent snapshot or unresolved references.
   Do not represent a truncated subject set as complete or an empty diff.
4. Evaluate current and proposed semantics **in memory** against identical
   immutable objects. Reuse native policy evaluation in a no-write sandbox;
   do not enable proposed behavior in the live query engine. Keep direct,
   inherited and materialized grants distinct. Native test harnesses that
   perform initialization writes are forbidden on the read-only connection.
5. Enumerate relevant subjects: direct members/watchers, ancestors/descendants,
   explicit policy users/projects, administrators, logged-in nonmembers and
   anonymous public visitors. Include disabled/unapproved users and stale
   actor objects as denial cases. For arbitrary external/custom policy rules,
   return `unknown` unless the evaluator and subject domain are demonstrably
   complete. Directory sampling is supplemental, never an exhaustive proof.
6. Compute view/edit/join/leave/member-index results per subject/object and
   authorization route. Compare newly granted, newly denied, unchanged and
   unknown. Track the grant source and hidden-ancestor exposure separately.
   Read authorization is checked for direct URL, query/list, Conduit, search,
   feed/backlink and native board/history consumers; storage access alone is
   not proof of route behavior.
7. Emit an aggregate sanitized report plus a locally restricted review manifest.
   Use per-audit keyed pseudonyms for users/projects and deterministic sorting.
   Keep keys separate; omit names, email, credentials, hosts, raw policy text
   and capability links from PR artifacts. Do not publish raw PHIDs or hashes
   of low-entropy names. Report counts, model/source IDs, completeness, query
   budget, duration, ambiguities and explicit owner decisions.

No automatic repair is produced. Historical parent invitations cannot reliably
be distinguished from explicit membership by the current edge alone. M07-B
therefore creates an ambiguity item for an authorized owner, never a deletion,
retroactive role promotion or authorization inference. Auditing an inaccessible
ancestor requires administrative data access, but that privilege must not leak
into the simulated ordinary viewer's evaluation.

## Minimum audit and decision tests

| Case | Fixture | Required result |
| --- | --- | --- |
| B04-A | Same subject matrix on native and each proposed model | Versioned expected results; all observed deltas attributed to a decision |
| B04-B | Unknown model / no signed decision manifest | Refusal with zero writes and no partial index publication |
| M07-A | Parent direct member absent from old derived index | Explicit potential new grant; not silently omitted |
| M07-B | Historical edge could be automatic or explicit | Ambiguous classification; zero automatic removal |
| Hidden chain | Visible grandparent, hidden parent, public child | Route-specific leak checks; no dropping hidden query constraints |
| Parent editor | Parent edit only vs explicit child edit | Separate inherited loss and preserved explicit grant |
| Milestone | Ordinary child and milestone under same parent | Native milestone invariant remains unchanged |
| Subject lifecycle | Anonymous/nonmember/disabled/unapproved/stale actor | Current denial semantics retained; no creator resurrection |
| Pathological graph | Orphan edge, deleted subject, cycle, deep chain | Bounded evaluation and unknown/error; no guessed safe result |
| Policy domain | Project rule, custom/retired rule, Space boundary | Correct native evaluation or explicit unknown; no bypass |
| Repeatability | Same snapshot twice, changed page order | Byte-stable sanitized deltas; no duplicates or missing objects |
| Budget/failure | Mid-page timeout, snapshot drift, row cap | Incomplete report, nonzero exit; never all-clear |
| Read-only guard | DB user rejects INSERT/UPDATE/DELETE/DDL | Audit still works; attempted write aborts the run and fails the test |
| Privacy | Names/email/hosts/secret seeded in fixtures | No sensitive strings in shareable output; restricted manifest separate |

Add corresponding pure evaluator and restored-snapshot integration suites
before implementing the auditor. Validate read-only behavior using a database
principal lacking all mutation privileges, not a boolean `dry-run` assertion.
Run route-level native authorization tests separately on disposable data.

## Decision and rollout gates for a later change

The maintainer selects a model only after complete deltas, unknowns and all
potential new grants are reviewed. Security/policy owners approve deliberate
expansions; project owners resolve ambiguous membership. This approval is a
future migration gate, not a request for additional permission for this plan.

Specify direct edge authority, descendant aggregation, milestone inheritance,
last-owner recovery and compatibility readers in one ADR. Build a single
idempotent, resumable migration with generation-stamped shadow indexes and
atomic publication; no mixed-model workers. Prove backup restoration, replay,
crash recovery and rollback on restored data. Rollback must include newly
created direct edges and writes after cutover; merely restoring an old binary
is insufficient. Keep reads compatible before enabling any new writes. An
unresolved privilege expansion, missing provenance or unknown policy blocks
migration, while optional features remain off. Audit completion is not migration
completion and never counts D07's ten atoms as delivered.
