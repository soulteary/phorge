# Typed partial restoration facts

The v3 restoration assessment calls the real Config, Project, Spaces and Policy
adapters. It binds each result's fixed provider version, scope, transaction mode
and stable source digest into the existing durable review binding. Changing a
selected source therefore makes an existing review stale. Missing dependencies,
unsupported source classes, invalid schemas and adapter budgets propagate as
failures inside the existing owned review transaction.

This does not complete any source family or enable restoration. All thirteen
families remain required. Every new descriptor has `complete: false`; existing
permit and native-transition refusals remain unchanged. The optional feature
continues to default off. No schema or deployed permission change is included.

## Connection and lifetime contracts

- Config reads exact already-initialized native source dictionaries. Its random
  private HMAC still checks object identity and all bounded source values within
  one capture. Its separate durable digest covers selected values, source class
  order and selected resolution, without process object IDs or raw value output.
  It does not reload database configuration or bind the separate Env cache.
- Project and Spaces use the exact caller-owned User writer and current locking
  reads checked by `assertOwnedWriter`. Their random HMAC capture/finish checks
  remain; a stable digest of the actual bounded snapshot is now returned too.
- Policy already returns hashes of the actual selected row bytes. Composition
  hashes that real result, including its incomplete-scope controls.
- RestoreService owns its READ COMMITTED transaction and recaptures the complete
  assessment before committing review work. These four adapters neither start
  nor commit transactions. This is endpoint recapture, not a continuous lease.
- Task and File require `PhorgeRecoveryOwnedRRScope`; they are deliberately not
  called by this composition. No shared RR snapshot is claimed.

Spaces includes request-cache state in its selected digest. A different valid
cache state can conservatively stale a ticket; stale cached rows can fail earlier
with a cache-specific error. Neither outcome becomes an authorization grant.
Stable digests are internal durable-binding metadata, not public source exports.
An identical source restored between endpoints can still match; physical restore
lineage, ABA coverage, producer absence and deployment immutability remain gaps.

## Validation and Root handoff

Offline tests execute actual native Config controls and composer/descriptor
negative controls. PHP seams and synthetic gate receipts are not SQL evidence.
The new isolated SQL function creates real native Project/Space sources and
calls the existing durable submit/review/preview paths. It tests selected Config,
Project and Space mutations, a Policy insertion, and a Policy budget failure.
Every refusal checks unchanged durable domain state and an unwound transaction.
The original independent-admin, replay, audit fault, expiry/incarnation and
blocked execution checks remain in the fixture.

The SQL cases and supported mapper have **not been run** for this candidate.
Root owns VM scheduling and CI/PR operations. No passing SQL receipt is included.
The native driver now selects this entry with the single exact argument
`--recovery-partial-composition` in its fresh owned full-source fixture:

`governance_recovery_partial_composition_focused($fixture, $check)`

from `partial-composition-focused.php`. The expected native batch is
`native-recovery-partial-composition`, scope
`owned-native-partial-composition-no-permit`. The strict gate is:

```sh
python3 scripts/governance/check_recovery_partial_composition.py \
  --receipt /absolute/path/to/fresh-native-receipt.json \
  --source-revision FULL_CANDIDATE_COMMIT
```

It requires 30 exact obligations (6 bootstrap, 21 real-source/review, 3 cleanup),
the existing full-source restore provenance and least-privilege bootstrap,
zero residual workers, all 55 owned schemas/principals cleaned, and all authority
flags false. The CI step runs after the Space fixture, creates an exclusive
private artifact directory, runs the native driver and then the public checker.
`accepted.json` is published only after checker success. A native or checker
failure leaves no accepted receipt. The artifact action is success-only: a
rejected pre-existing directory or symlink is never uploaded after setup failure.
No classes were added and no class map was
hand-edited.

The focused wrapper also checks the actual case return flags and exactly 21
unique executed case labels before returning its measured summary. Wrong source
selection, missing/duplicate/foreign obligations and execution/authority flags
are rejected. The 21 source/review cases are the original 14 durable review
cases plus 7 partial-source cases; the public gate additionally requires all 9
bootstrap/cleanup obligations.

The Space mutation case inspects the already validated caller cache without
creating or clearing it. It expects exactly `recovery_spaces_cache_rows_changed`
when that selected Space is in an actual cached map, exactly
`recovery_spaces_cache_default_changed` when it is the cached default, or exactly
`recovery_restore_sources_stale` when no selected cached row shadows it. Other
exceptions fail the run; cache errors are never relabeled as source-stale. Static
inspection found no Space-query cache creation in the direct restoration
profile/identity/credential/inventory call path after the fixture's explicit
fresh-cache setup. That observation is not a replacement for the pending SQL run.
