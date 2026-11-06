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
Root owns native-runner dispatch, VM scheduling and CI/PR operations. No passing
SQL receipt is included. The integration entry for Root to dispatch in its fresh
owned full-source fixture is:

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
flags false. The native runner has not been edited to advertise this phase;
Root must review and add that dispatch before any measured run. No classes were
added and no class map was hand-edited.
