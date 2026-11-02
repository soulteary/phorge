# Candidate adoption ledger

The native production round is complete within its approved scope. Candidate
adoption is tracked separately in [candidate-adoption.json](candidate-adoption.json).
That snapshot maps all 134 atomic units, 268 scenario anchors, 19 development
packages and the 53 original source capabilities. The capability map directly
covers 130 atoms; B01/B03/B04/B07 are explicitly recorded as cross-cutting
baseline, ADR and protocol work rather than invented source capabilities. Fixed candidate heads are
provenance, not permission to merge the original branches or activate features.

## Current partition

- 42 units: D01–D06 delivered at package scope. This does not enable every UI;
  navigation stays flat, history off, workspace native and history task drafts off.
- 10 units retain native semantics: B04/M07 native ADR and read-only tooling
  are delivered; M05/M06/M08/M09 native compatibility is reconciled; M01–M04
  alternative model changes are not selected or integrated.
- 82 units in D08–D19 remain deferred and unintegrated. Together with M01–M04,
  there are 86 unintegrated candidate changes. These categories overlap the
  implementation dimension and must not be added twice.

The four native axes remain unchanged: child visibility, editing inheritance,
membership aggregation and historical edge handling. Uncertain historical edges
are reported read-only, not guessed or rewritten. No production permission
migration or optional capability activation is part of this ledger update.

A designed anchor or applicability disposition is not a full executed-case
certificate. The existing D01–D06 candidate assertion mapping remains authoritative
for its limited evidence scope; neither all 268 complete cases nor all 32 full ADR
designs are claimed certified. The original C04 first-notification observation
remains unattributed even after later successful notification acceptance.

## Extraction protocol

1. Refresh candidate head, main, conflicts, CI and review before new work.
2. Identify the exact atoms that have not already been absorbed into main.
3. Keep native alternatives and explicitly deferred items out of the change.
4. Extract only the selected code onto current main, adapting it to retained
   application boundaries and Gorge ownership; never merge a conflicting whole
   candidate branch just because some of its code is useful.
5. Add actual success/failure tests for the selected paths, record source head,
   extraction commit, limitations and exact-head review/CI.
6. Obtain a new direction decision before permission semantics, public exposure,
   optional activation or account-maintenance write scope changes.

Validate the snapshot locally or through the existing required atomic CI:

```sh
python3 scripts/upstream/check_candidate_adoption.py
python3 -m unittest discover -s tests/atomic -p 'test_candidate_adoption.py' -v
```

The checker rejects duplicate/dangling mappings, changed native/deferred scope,
false full-case certification and provenance drift. It does not execute optional
candidate features or query production. Existing signed release acceptance remains
a historical artifact; this maintenance-only change does not publish or deploy a
replacement image.

## Governance references

[Gorge #44](https://github.com/soulteary/gorge/pull/44) introduces the always-emitted
required main CI and its protection verification. The fresh cross-service suite
is tracked separately in [Gorge #45](https://github.com/soulteary/gorge/pull/45);
its exact-source CI receipts, not the presence of this link, prove execution.
Neither governance item adopts a new candidate permission feature.
