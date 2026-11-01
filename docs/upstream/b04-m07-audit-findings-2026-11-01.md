# B04/M07 restored-snapshot findings — 2026-11-01

## Decision

**Retain the native permission model.** Do not activate independent child view,
remove inherited parent edit, replace membership indexes or introduce roles.
No live permission migration or optional feature activation is part of this work.
This is a recommendation based on bounded evidence, not a finding that every
candidate is unsafe or that a zero difference proves future compatibility.

## Initial root-only evidence and representativeness

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

## Controlled enrichment and native route evidence

PR #64 adds six ordinary children and two milestones in a controlled graph on
the already authorized restored snapshot. The full comparison inventory is
**13 projects and 13 subjects**: the retained root and original accounts plus
synthetic fixture accounts, anonymous and a synthetic logged-in nonmember.
The 12 synthetic projects are exported with all 517 native tables, the owned
namespace is destroyed and restored, and the permission-state fingerprint is
checked before requests begin. Existing restored accounts are never used for
web or API requests. No fresh live snapshot or permission migration is required.

The native-v1 route contract contains **1,440 cells** across two phases, ten
synthetic/anonymous actors, twelve projects and six route families. A further
**519 checks** cover capability oracles, Feed lists, edit forms, denied Conduit
mutations, restoration and unchanged permission state. Search uses the paired
Gorge service with Meilisearch; the restored-source verification uses version
1.56.0. Stale browser sessions/API tokens are reused after revocation, without
reindexing search or republishing stories. Each phase has its own logical dump
hash and permission-state fingerprint. See the [test protocol](b04-m07-native-route-acceptance.md).

Candidate comparisons remain policy previews: 845 cells per candidate per
phase, five candidates and two phases. The following are counts of affected
project/subject/capability cells, not people or HTTP requests. Each pair is
**restored / revoked**, and all other capability counts are unchanged.

| Candidate | View grants | View losses | Edit losses | Join losses | Member-index grants / losses | Leave losses |
| --- | ---: | ---: | ---: | ---: | --- | ---: |
| independent-view-v1 | 29 / 31 | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0; 0 / 0 | 0 / 0 |
| explicit-edit-v1 | 0 / 0 | 0 / 0 | 3 / 2 | 3 / 2 | 0 / 0; 0 / 0 | 0 / 0 |
| self-descendants-v1 | 2 / 2 | 0 / 0 | 0 / 0 | 0 / 0 | +1 / -0; +1 / -0 | 0 / 0 |
| direct-members-v1 | 2 / 2 | 9 / 6 | 0 / 0 | 0 / 0 | +1 / -7; +1 / -5 | 4 / 3 |
| combined-v1 | 30 / 32 | 0 / 0 | 3 / 2 | 3 / 2 | +1 / -0; +1 / -0 | 0 / 0 |

There are no unknown evaluations or graph integrity issues in this controlled
inventory. That does not certify arbitrary named/custom/Space policies.
The auditor marks six direct edges before revocation and five afterward as
provenance-ambiguous. The fixture deliberately creates one old-shaped parent
edge absent from native materialization; the other edges have known setup
origins only in fixture metadata. These are not six discovered production
historical defects. Every ambiguity is retained without automatic repair.

The earlier root-only zero-difference result is retained above as historical
evidence. The enriched comparison supersedes its lack of hierarchy coverage,
but synthetic relationships still do not establish actual organizational intent.

## Updated recommendation and remaining gates

**Retain native-v1.** Independent child visibility demonstrably widens access;
explicit editing removes inherited permissions; direct-only membership can hide
ancestors from descendant members. Self-plus-descendants also changes access
when old direct edges are present. Combining these axes does not cancel their
risks. Choose view, edit and aggregation semantics independently in a versioned
ADR after owners review the corresponding expansions and losses.

The native route evidence is scoped to sampled project profile/query, Conduit,
search, Feed and board behavior, including current-policy revocation. It does
not implement candidate route semantics, task-card permission combinations,
optional history, arbitrary custom policies or all Space boundaries. Candidate
`routeVerification` values remain `not-verified`; `migrationReady` stays false.
No candidate model activation, new role system, live permission migration or
optional feature activation occurs. D07 and the original complete B04/M07 atoms
remain partial; a future migration still requires owner decisions, shadow-index
replay and rollback evidence, and separate explicit authorization.
