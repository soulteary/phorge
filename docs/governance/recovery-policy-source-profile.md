# Native policy and Space source profile development

This isolated successor implements current locked source-row capture for native
policy.policy, policy.policy_namedpolicy and spaces.spaces_namespace. The exact
column sets come from the signed native quickstart and current DAO definitions.
The fixed cross-schema SQL runs only on the evidenced owned People writer and
requires the native primary id index, exact columns and InnoDB under MDL.

The aggregate limit is 64 selected rows, 16 KiB per row and 64 KiB total across
all three tables. A current locking primary-index size pass precedes payload
hashing; locked rows remain stable. SQL SHA256 field framing preserves NULL vs
empty and byte length, without returning policy names, descriptions, rules or
identifiers. Oversize, unknown schema and SQL errors are refusals, never empty
source proofs. Even bounded scans can block unrelated policy/Space edits; this
cost is explicit and not proof of production-scale readiness.

The reader does not claim phantom exclusion under the current service RC
transaction. Empty source sets and unmodeled native automatic object grants
remain incomplete. It is not yet wired into assessment, whose v2 shape remains
frozen. The later integrated binding must explicitly introduce source version3;
old v1/v2 proposals must keep their old comparison semantics and become stale
when compared to a newer provider, never silently gain completeness.

Before integration: convert the relevant source preflight reads to current
reads, then hold a proved RR primary-key next-key range through final restore
commit. Verify real concurrent inserts/updates, empty-table gaps, DDL/timeout,
rollback, and actor/session expiry. A time-of-check COUNT or disabled feature
flag is not an absence fence. The supported interpreter must also resolve each
native custom rule and named effective policy against current object sources,
Space policies and configuration. Unknown extensions or dynamic producers stay
hard blocked. No policy decision or native permission field is changed here.

No new class map, supported PHP pure run, SQL run, catalog gate or restoration
success is claimed. This source-only checkpoint follows the raw-flag correction;
the historical b3 229-method run applies only to b3. R04/R05 adoption remains
blocked pending a genuinely complete deployment profile and two-person native
restore success with durable one-use permit/audit and final source validation.

The separate prepared policy-source-cases.php contains four additive actual SQL
obligations: native DAO row capture, same-transaction source mutation changes
the digest, actual LONGTEXT over-budget refusal preserves the caller transaction,
and caller rollback restores original native bytes. It is not wired into the
runner yet and supplies no empty-range or concurrent-producer proof.

## Bounded isolation comparison acceptance

The focused `--recovery-policy-only` profile explicitly distinguishes the retained
READ COMMITTED service from a fixture-owned REPEATABLE READ control. The previous
integrated profile incorrectly claimed that invoking the service acquired RR gap
locks. PR108 review identified that mismatch; changing the session default cannot
override the service's next-transaction RC setting. That old profile is not
current-source acceptance and is not repaired by extending a wait timeout.

The new profile keeps product PHP/SQL unchanged. Two genuine service transactions
capture an empty, then populated policy range under RC. An independent fixed
native Policy DAO producer must actually commit while each service transaction
is still open. A second current capture must include its new row and change the
digest. A fixture-only comparator refuses that observed change, causing the real
service transaction to roll back while the producer's independent commit remains.
Unknown or falsely complete input facts also refuse. This comparator is test
observation, not a production absence lease, restoration permit or interpreter.

Separately, two fixture-owned RR transactions demonstrate empty and populated
PRIMARY-range insertion waits and release on commit/rollback. They do not invoke
or describe the service transaction routine. P_S ENGINE-composite lock identities
and the blocking ENGINE_TRANSACTION_ID mapped to INNODB_TRX ownership must bind
the actual worker/parent threads; lock-creation THREAD_ID is not owner evidence.
Only the exact two native rows created by these RR workers may be deleted and
committed before the empty RC control. The fixed native seed is independently
recognized, removed and restored in finally; no held DELETE gap substitutes for
an empty table. All-mode baseline seeding remains unchanged.

The new exact contract has 14 SQL obligations, six bootstrap/grant checks and
three cleanup checks: 23 total, with four registered Policy DAO workers. The
source still registers 250 pure methods / 42 classes. The typed gate rejects the
old 18-count/two-worker/service-RR profile, missing IDs, authority claims, unknown
fields and wrong source revisions. Native patch loading and exact restore
543-table/1572-patch/35-migration verification remain required.

Root executed signed source `661fd799efad2c1c64926afea2bec3223d098198`: 250 pure
methods / 42 classes and all 23 focused checks passed. All 6,855 runtime
PHP/SQL/public-suffix hashes matched the signed archive; strict CLI and in-process
acceptance agreed. All 55 schemas, two runtime users, reader, four workers,
image and owned staging resources were cleaned. The immutable manifest SHA256
is `3d99f56c037afa2c4f2cb4111b6bc2d26d379ede7b1f8e2edf31987c2cda87fa`.
These are bounded comparison results, not a new run of this docs successor or
final-head hosted CI. Policy/Space
capture remains partial, `empty_range_absence_proved=false`, and all thirteen
source families and restoration authorization remain incomplete. RR comparison
results never upgrade the RC service to an absence fence. Automatic policy
interpretation, source/DDL leases and actual restoration remain blocked.

The historical 9c9 seeded-table failure and later isolated RR component receipts
remain separate evidence for their original sources. They do not certify this
protected-main successor. The first 15-count plan omitted three cleanup checks;
18 was its corrected historical count, not this new 23-check profile.
