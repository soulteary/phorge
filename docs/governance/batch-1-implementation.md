# Governance batch 1: isolated foundations

This implements the first isolated slice of ADR v1.2, not an enabled organization
or authorization feature. The selected decisions are V01=A, V02=A, V03=A,
V04=B, V05=A, V06=A, V07=A, V08=A, V09=A and V10=A. The four native project
axes remain unchanged: subproject visibility, edit inheritance, membership
aggregation and historical-edge interpretation.

## Implemented and tested scope

- **N00:** a partial, source-linked consumer inventory and mutation-tested checker.
  It pins the selected branches, inactive interfaces and unresolved coverage.
  Structural validation never certifies runtime authorization.
- **N01/N02:** synthetic InnoDB tables and a fixture-only, bounded directory
  reader. It queries current eligibility, active membership and an effective
  appointment; ordinary members have no self-directory exception. Parent,
  home-department and other-team relations do not grant visibility. The store
  accepts explicit loopback fixture databases with an initialized marker only.
  No native user tables or deployment configuration are imported. The fixture
  uses administrative test credentials; it does not prove SELECT-only account
  grants. No writer, production patch, application, controller or policy rule
  is registered.
- **N05-A/N07-A:** pure, non-executable content and approval contracts. The new
  content path cannot manage policies or members. Existing native edit
  eligibility is preserved in the model, but high-risk operations require
  independent approval. Parameters, identities, target revision and source
  read-set are bound. Unknown coverage, stale bindings, invalid transitions,
  expired requests and revoked actors block the model. Even an eligible result
  always has `can_execute=false`; no target adapter or durable replay ledger
  exists.

The synthetic schema preserves history independently of current relationships.
Current roster reads exclude ended, suspended, future and expired relationships.
A disabled account's stored relationship can be shown to an authorized manager
with `accountEligible=0`; it is never represented as an effective grant.
An appointment cannot implicitly create a missing membership.

## Accepted design, not implemented in this slice

High-risk changes eventually require mechanically enforced dual control (V01).
Only the new content path is strictly separated: this is not a claim that every
legacy editor has lost management powers (V02). Leaving/security recovery must
block account enable until all supported sources are reviewed; unknown sources
remain blocking (V03). Approval/recovery history is system-administrator-only
(V05). Future media credentials must depend on a specific application session
and cannot revive after re-enabling an account (V06); this does not select D15.
Team administrators must already be active members (V07), without automatic
membership creation or an extra approval for ordinary member maintenance.

Existing join policies remain unchanged (V08). Handover uses a 14-day default
and 30-day per-approval limit with explicit extensions, reminders and manual
project disposition, not automatic expiry authorization (V09). A 365-day audit
retention design and eight-level soft limit apply to the new directory only;
no cleanup or native project-depth change is performed (V10).

## Local and CI verification

Use supported PHP 8.5.x, at least 8.5.12, with JIT disabled. These commands must
run in a clean development checkout without deployment configuration:

```sh
python3 scripts/governance/check_contracts.py --check-worktree
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests/governance -p 'test_*.py' -v
php bin/rebuild-library-map
php tests/governance/run.php
```

The native runner refuses deployment config and database-initializing test
classes. It uses the retained native test framework, enumerates every public
test method in the three selected classes, and rejects missing, duplicate,
unexpected or non-passing results. Real InnoDB assertions are separate:
see [the disposable fixture instructions](../../support/governance/fixtures/README.directory.md).
The database entry point refuses unsupported PHP before connecting. It creates
a random owned database and drops it only after successful creation; an injected
CREATE collision verifies that a pre-existing database is not deleted.

The existing required native CI job runs these checks and publishes their JSON
receipts. Local verification used PHP 8.5.12 and MySQL 8.4.13 in an owned,
network-none pod with tmpfs data and no published ports: 14 Python mutation
tests, 23 native methods and 34 real-MySQL checks passed. The copied executed
PHP/SQL files were hash-compared to the worktree. This is dirty-development
fixture evidence, not a clean release receipt. The database's second-connection
revocation check is sequential; it is not a mid-transaction race proof.

## Activation blockers

The eight unresolved scopes in [the inventory](batch-1-contracts.json) remain
partial and activation-blocking. In particular: complete inverse/legacy callers,
all enable and recovery sources, cross-database consistency, per-object automatic
capabilities, media identity/cache routes, all mail producers, capacity/retention
exceptions, and API/long-lived/background credential lifecycle are not certified.
Production writers, API/HTTP routes, feature toggles and schema registration stay
absent. A Boolean contract input is not authenticated proof. The fixture viewer
is a synthetic identity, not a real application session.

No production permission migration, deployment, optional feature activation,
public endpoint or mail delivery is part of this PR. Existing atomic delivery
counts and historical acceptance receipts are not relabeled by this batch.

## PR review correction: detached approval inputs

Approval canonicalization now constructs fresh arrays at every depth. Copying
an input array and assigning into its existing reference slots could retain
caller-owned PHP references: later mutation changed stored identities or times
without changing the precomputed digest. Three new regressions cover validity
windows, independent requester/executor identity checks, and nested maps, ordered
lists and authorization read-set bindings. All three failed against the original
implementation; all 23 native methods passed after the fix on PHP 8.5.12.
This is immutable-input correctness, not a durable replay or execution guarantee.
