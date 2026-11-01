# B04/M07 restored-snapshot findings — 2026-11-01

## Decision

**Retain the native permission model.** Do not activate independent child view,
remove inherited parent edit, replace membership indexes or introduce roles.
No live permission migration or optional feature activation is part of this work.
This is a recommendation based on bounded evidence, not a finding that every
candidate is unsafe or that a zero difference proves future compatibility.

## Evidence and representativeness

The existing archived snapshot was restored first. It contained no projects,
so it could validate restoration but could not support a model recommendation.
The auditor now returns incomplete/nonzero for such an empty project inventory.

After explicit authorization, a fresh single-transaction logical snapshot was
captured without table locks or stopping services, and restored on an isolated
database with no published ports. Raw snapshot data, private configuration and
review keys stay in restricted operator storage. Audit credentials have only
SELECT on the four restored application databases; production root credentials
are not given to the collector or offline comparator.

- Snapshot capture: `2026-11-01T08:15:11Z`.
- Snapshot SHA-256: `83f7ebef282ad38b7d0f736ac7676d12c446f7229d511c82d46d5652951cfc5a`.
- Native runtime baseline: `5298239e0fe364046b2a60d5f76ab733a284fcd0`.
- Audited implementation: `c3dfc7466258dda1399cabede37163e4020f5f74`.
- Native project/global/user rule source files match the baseline runtime.
- Domain: **1 root project, 0 ordinary children, 0 milestones, 4 subjects**
  (all stored accounts plus anonymous and a synthetic logged-in nonmember).
- Five candidate comparisons, 20 project/subject/capability cells each.
- Every model was rerun with the same key and snapshot; both reports were
  byte-identical. Restored application data fingerprints were unchanged.
- Optional effective controls stayed `flat` / `off` / `native` / `false`.

| Candidate | Newly granted | Newly denied | Unknown | Interpretation |
| --- | ---: | ---: | ---: | --- |
| independent-view-v1 | 0 | 0 | 0 | No child-view boundary exists in this snapshot |
| explicit-edit-v1 | 0 | 0 | 0 | No inherited parent-edit boundary exists |
| self-descendants-v1 | 0 | 0 | 0 | No descendant aggregation boundary exists |
| direct-members-v1 | 0 | 0 | 0 | No populated hierarchy distinguishes the indexes |
| combined-v1 | 0 | 0 | 0 | Combining axes adds no evidence beyond the individual results |

There were no orphan membership edges or ambiguous direct edges in this
snapshot. This does **not** prove that historical membership provenance is
recoverable elsewhere. On datasets containing old direct edges, the tool
preserves them and emits owner-review ambiguity items.

## What the tests establish

22 focused unit tests and 27 restored integration checks passed. The integration
uses actual native quickstart table definitions, including `spaces_namespace`,
not independently handwritten projected tables. It logically dumps/restores
those tables and exercises the real collector/native rule adapter under a
SELECT-only principal. INSERT, UPDATE, DELETE, ALTER, DROP and TRUNCATE are
rejected; privileged/global-read/delegating principals are refused.

Synthetic fixtures deliberately cover the cases absent from the real snapshot:
hidden parent/public child, parent-only editor, explicit child edit, parent
direct member absent from old materialization, descendant-only membership,
milestones, disabled/unapproved accounts, broken graphs, unsupported rules and
Space boundaries. They demonstrate that the tool reports changes and unknowns,
not that those candidate semantics should be enabled in a real organization.

The first CI run exposed a test-driver exit-status collision; it was corrected
and verified using the actual process exit code. Review also found the initial
Spaces table-name mismatch and a GRANT OPTION exception-order defect. Both
were corrected with canonical schema restoration and real grant refusal tests.
These fixes do not relax the required checks or production authorization.

## Remaining gates

The reports are **policy-layer and membership previews**, not full effective
HTTP/Conduit/search/feed/board authorization proofs. All route categories remain
explicitly unverified and `migrationReady` is false. Unsupported custom/named/
project rules and Space controls produce unknown results and block a complete
comparison; no policy fallback converts them into known denials.

Before reconsidering the native model, obtain a representative approved snapshot
with ordinary children and milestones, review every possible access expansion
and edit loss, and run native route/transaction tests on disposable restored
data. Select view, edit and aggregation decisions independently in a versioned
ADR. Any migration still requires owner decisions on ambiguous old edges,
shadow-index/replay/rollback evidence and explicit authorization. D07's remaining
implementation atoms and optional packages are not completed by this auditor.
