# Required main gates and atomic evidence checkpoint

## Merge policy

The repository's `main` branch requires these GitHub Actions checks, bound to
App ID 15368, with the branch up to date before merging:

- `Native packages (PHP 8.5.12)` — all registered native packages, deterministic
  maps, retained product cuts, fixture preflight and real HTTP persistence.
- `contracts` — paired runtime/service contracts.
- `runtime-compatibility (8.5.12)` — runtime safety and selective upstream fixes.

Administrators are subject to the same rules. Pull requests and resolution of
review conversations are required; force pushes and deletion are disabled.
The path-filtered `image-contracts` job is deliberately not a required context:
it may legitimately skip. This policy does not authorize skipping an applicable
image job, failed review finding or release verification. Record the effective
protection API response after applying the policy: committing this document
alone does not protect a branch. Do not bypass the rules to merge this change.

`tests/atomic/paired-gorge.json` pins the formal reviewed Gorge revision.
Both workflows reject a missing, moving or mismatched variable/manual input.
Updating the pair requires a lock change, matching repository variable and
fresh check results. Use only PHP 8.5.12 with JIT explicitly disabled and zero
buffer; historical minor-version matrices are not supported configurations.

## Counts and evidence boundaries

The current six package manifests select **141 native methods**; HTTP acceptance
has **30 assertions**. Record observed counts, source HEAD, source cleanliness,
paired revision, PHP/runtime settings and cleanup in each fresh result. These
are distinct from **134 atoms / 268 designed cases / 19 packages**. D01–D06
account for 42 atoms; the remaining 92 atoms in D07–D19 remain deferred.
The original plan's 99 atoms / 198 cases / 17 packages is an earlier snapshot.

The design-to-method ledger is `tests/atomic/design-coverage.json`. It records
both design anchors for all 42 delivered-scope atoms. Its references are
reviewed candidate evidence, not automatic proof that every designed assertion
ran. The ledger validator rejects invented/duplicate anchors, absent methods,
and missing mandatory scope limitations. A green validator proves ledger
integrity; the native/HTTP reports prove execution. Browser actions require a
separate visual/persistence receipt. Never multiply two anchors per atom into
a purported pass count.

## Corrected F02/B08 contract

D02 implements ADR-04's **ANY visible real source** in the native file query.
Stable visible-witness selection, VOID plus real source, private/public source
combinations, missing/deleted sources, cycles/deep chains and batch-query
isolation are native tests. `F02-A` and `B08-A` are split in the ledger:

- Core portion: the selected native ANY model, tested in D02.
- Optional guard portion: bearer on/off × route combinations, deferred to D14.

The extra bearer guard was not imported and must not be marked passed or
silently enabled. A dedicated file origin provides origin/credential isolation;
it does not implement D14's per-request authorization model. Capability-link
compatibility and private embedding require a separate decision before D14.

## Browser evidence protocol

Use the disposable fixture in `tests/project-workspace/README.md`, a new schema,
synthetic accounts and the exact source/Gorge pair. Optional controls may be
changed **only in that isolated fixture**. No production flag activation is
part of this work. An HTTP pass does not establish correct drag/drop or layout.

Record UI01–UI12 separately. W04 needs actual screenshots and mouse actions:
long-column bottom to fourth-column drag, reload and persisted destination;
unchanged native task status; full-screen entry/exit; narrow viewport; native
hidden-column control and its count; horizontal and vertical scroll. Capture
viewport sizes, before/after screenshots and independent database assertions.
Do not call a check passed if the browser cannot reach the fixture or an action
was not exercised. A blocked/unrun layout check remains an explicit release
limitation. Screenshots/reports must omit hosts, login links and credentials.

## Optional controls and ownership

Keep `projects.default-navigation=flat`, `project.history-state=off`,
`project.workspace-mode=native`, `project.history-task-drafts=false` in the
existing deployment. Hierarchy, history and unified workspace remain opt-in.
No global task-state dictionary or permission model changes. Gorge continues
to own file storage/processing and notifications; PHP retains native policies,
transactions and front-controller rendering. Retired applications stay retired.
