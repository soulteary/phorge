# Governance batch 5: pipeline boundaries and policy composition

This follows ADR v1.2 and #80. Four project permission axes remain native;
optional features remain disabled. No application, route, policy, writer,
executor or schema patch is registered. No deployment or production permission
migration is performed.

## N05: selected validation and source-reviewed boundaries

The exact native Project editor's local cross-transaction validator is observed
after its local expansion context. It detects incompatible parent/milestone
transactions and direct-member changes on parent or milestone projects. A local
valid result means only that this selected native method returned no errors;
full validation, capability checks, MFA and execution remain unproved.

`batch-5-pipeline.json` records 28 reviewed lexical phase boundaries in the
native application transaction editor. A fixed source SHA-256 and stage
anchors/order fail closed on drift. The checker opens no database and does not
bootstrap PHP. Six test methods cover inventory invariants and reject missing/reordered/unknown stages,
source/hash refresh tricks, false certification and unsafe source paths.
This is not an AST/control-flow graph or proof of transitive side effects.

The inventory includes conditional initial effects before the preview return,
internal/final effects and hooks, object/comment/transaction records, commit,
cache, Herald/subeditors, indexes, transcript headers and publishing. Initial
effects are conditional and the base editor defaults to false; the list does
not claim every preview writes. Preview or rollback alone is not a read-only
proof. Full pipeline execution, support expansion, plugin/modular validation,
Herald and external effects are not invoked by these observers.

## N07: actual composition and bounded sampled consistency

The verifier reloads native users and targets through actual ORM queries in the
verified owned SELECT-only context, then calls actual PolicyFilter and automatic
capabilities. Tests cover ordered allow/deny, defaults, empty all-project local
rules, named policy resolution, owner automatic rights, Space and effective
application configuration. No replacement policy algorithm is introduced.

Bounded native source samples and fixed effective policy/application config
samples bracket the filter observation. Changed samples block without returning
capability observations. Known unsupported rules, missing dependencies and
ineligible account/session sources block before entering the native Filter;
final samples remain checked. Inputs and configuration containers are detached.

An equal sample remains `stable-sampled-not-atomic`. Different connections/read
views, ABA, unobserved runtime dependencies and full query time/memory bounds
remain explicit unknowns. Fixed effective keys are not a complete configuration
closure. No snapshot is held through a business write; every outcome remains
partial, `can_execute=false`, and not an authorization token.

## Isolated validation and remaining gates

The new required native CI step retains earlier fixtures and publishes a
separate batch-5 artifact, including the source-review boundary receipt. Actual
ORM/text-protocol reads are used; privileged seed/mutation/cleanup is separate
from SELECT-only observations. Source and table fingerprints do not claim that
unexecuted hooks or arbitrary external effects were dynamically tested.

Complete caller/source closure, all transaction validation and effects,
execution-time locking/atomicity, full resource bounds and deferred optional
packages remain blocked. Historical atomic-feature counts and immutable ADR
snapshots are not reclassified. Supported local test counts, final-head review,
PR CI and merged-main results are separate evidence stages.

Local PHP 8.5.12 / MySQL 8.4.13 validation passed 97 native methods, the prior
85/54 SQL checks and 50 new pipeline/composition SQL checks. Governance Python
passed 37 methods; permission Python passed 83. All 35 executed governance
PHP/SQL/map/runner files matched the worktree. These are content-matched
source-copy development receipts, not clean-release or production acceptance.
The owned network-none fixtures publish no ports and each SQL run removes its
eight owned schemas and reader. Hosted PR/main evidence is recorded separately.
