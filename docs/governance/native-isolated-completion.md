# N05/N07: isolated native implementation and evidence

This increment follows ADR v1.2 and batch 5. It addresses only the current N05
native transaction pipeline and N07 read-view evidence. All four project
permission axes retain native semantics. Optional features remain disabled.
No production executor, application registration, deployment, or production
permission migration is introduced.

## N05: execute the actual pipeline in owned disposable storage

The completion fixture restores the source quickstart and executes the reviewed
pending native migrations before marking them applied. It separates control,
schema setup, runtime writer and eight-schema SELECT-only reader privileges.
All native mutations are intentional isolated writes, protected by a scoped
native write guard and private ownership marker. This is not a read-only test.

`support/governance/completion/pipeline-matrix.json` names 24 shapes across nine
existing operation vocabularies. Each shape requires its own receipt checks.
Project, task and Wiki editors execute their actual validation, transaction
records, support expansion and publishing pipeline. Creation is setup; it does
not authorize previously unsupported update shapes. Historical member writes
remain contract-blocked. Existing parent/milestone mutation refusals remain
native refusals. A real SQL fault verifies object/transaction/publishing rollback;
it does not certify rollback of arbitrary external side effects.

The fixed paired Gorge profile requires actual file byte storage/readback and
authenticated HTTP task publication with native fallback prohibited. Precisely
owned native publication workers demonstrate both the legitimate hidden-create
no-mail case and a mailable comment-update mail/feed outbox case. Delivery
workers and external mail transports are never executed. This task-family
publication evidence does not claim separate Project/Wiki delivery acceptance.

## N07: observe native consumers in one physical read view

The isolated observer lends the verified SELECT-only connection to the native
ORM through private Lisk bridges. Source samples, native PolicyFilter,
automatic capabilities and actor-viewer queries share the same actual
repeatable-read, read-only transaction. Snapshot A, concurrent B, fresh B, ABA
and membership revocation distinguish snapshot consistency from current state.

Local policy allowance and actor-query visibility are reported separately:
a Wiki with a private ancestor can pass its local policy and remain absent
from the actor query. SQL mutation, transaction control, locks, file output,
unrecognized query shapes and SQL-mode drift fail closed. SQL-side row/byte
preflight bounds precede native materialization. The fixed native runtime map
and roots must be unextended. These finite bounds do not prove arbitrary
plugin/query-plan resource behavior.

The observer restores owned routing/cache state and preserves foreign pool
connections encountered during lifecycle drift. Cleanup failures remain
failures. A consistent historical snapshot never grants future execution
freshness, a business-write lock, or permission to execute.

## Evidence and remaining gates

Run the no-argument completion CLI with the explicit Gorge profile in an owned
network-isolated disposable environment. The `--readview-only` phase exists
for diagnosis and must never count as full acceptance. Runtime results, source
content matches, PR CI/review and merged-main CI are separate evidence stages.
`scripts/governance/check_completion.py --receipt <receipt.json>` rejects partial phases, missing matrix checks,
service fallbacks, delivery execution and cleanup failures.

Earlier native source, rule/hierarchy and composition SQL regressions remain
required when the shared source reader changes. New records must retain their
independent counts and clearly distinguish local source-copy tests from hosted
clean-checkout evidence.

N03 organization appointments, the optional governance writer, complete caller
closure, arbitrary extensions/Herald actions, external delivery and future
write-time freshness remain blocked or outside this increment. N03–N13 are
not included by implication. D08–D19 stay deferred; immutable ADR decisions and
historical atomic counts are not reclassified. Public evidence contains no
deployment addresses, credentials, raw identities or private fixture values.

Supported local validation on PHP 8.5.12 / MySQL 8.4.13 passed 105 native test
methods, 125 full integration checks, the independent 85/54/66 earlier SQL
checks, 48 governance Python tests and 84 permission Python tests. The strict
completion gate accepted all 24 shapes/nine operations. All 42 executed
governance PHP, fixture and map files matched the worktree. Full integration
cleanup removed 55 owned schemas, two runtime principals and the reader;
earlier fixtures each removed their eight schemas/reader and performed zero
reader writes. These are source-copy development receipts; hosted PR/main
results remain separate and must not be inferred from these local passes.

Independent PR review additionally found an unsupported STRAIGHT_JOIN table-
enumeration bypass. Complete anchored FROM-clause profiles now reject unconsumed
table-reference syntax; pure adversarial cases and an actual bridge probe verify
cross-schema rejection before fetch. Native query SQL is never rewritten.
