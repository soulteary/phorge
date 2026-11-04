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

## Range integration successor (not yet run)

The subsequent isolated component adds a fixed `--recovery-policy-only` driver.
It has a distinct batch and explicit `all_mode_native_evidence=false`; it cannot
satisfy the all-mode strict checker. No old required case or family is removed.
The exact development migration is explicitly added to the native fixture
reviewed patch allowlist and source checker list. Native applyPatchSQL applies
it during bootstrap; the real service verifies all three restore table layouts
before entering the test callback. Full storage-upgrade and all-mode acceptance
remain separate gates. No hand-created table shape substitutes for the native
patch loader.

The real RestoreService owns next-transaction RR; only its own transaction is
committed/rolled back. Media size preflights are current row reads before this
isolation change, preventing an RR consistent-view budget mismatch. A private
fixture phase seam invokes that genuine transaction routine with current sealed
admin identity, sorted User locking, final credential/time checks and normal
commit. No HTTP, review approval or executable permit is synthesized by it.

Two independent fixed native Policy DAO workers prove an actually empty PRIMARY
range and a populated PRIMARY range. Their current bootstrap authentication and
CSRF precede readiness, whose connection ID is the real Policy writer. The only
work item is a fixed native custom-policy INSERT; no SQL or policy parameters
are accepted. Owned handshake release starts the INSERT after capture. Actual
performance_schema lock_waits must link that writer to the exact parent People
connection and policy.PRIMARY. Stable capture is required during the wait; both
commit and exception rollback must release the producer and independently
observe the native saved PHID digest. The worker is a physical DAO producer
probe, not an application publication authorization or HTTP endpoint.

The focused component has nine explicit SQL obligations (five range/lifecycle
and four source mutation/rollback), six bootstrap/grant checks and three required
cleanup checks (18 total), two registered workers, and bounded exact
cleanup inherited from the main native harness. Pure registration is expected
230 methods / 39 test classes, subject to genuine supported map/pure execution.
No SQL, supported pure, next-transaction/default-isolation or range result has
yet been observed. Empty-range source completion still stays false in public
capture until actual proof and a versioned integrated assessment provider exist.

The first 9c9 SQL attempt reached bootstrap but correctly refused the presumed
empty policy table: the shared native baseline owns one known synthetic custom
policy. A focused-only successor checks all six fields against that exact owned
baseline row (including project rule, native PHIDs and zero timestamps), deletes
it in an explicit transaction and COMMITs. An independent setup connection must
observe zero rows before the parent range transaction begins. No parent-held
DELETE lock can stand in for an empty-table gap. In a finally after the real producer races (including failure),
the original seed bytes are restored and independently checked, preserving the
shared named-policy reference for the remaining source cases. All-mode fixture
seeding is unchanged. Unknown rows or changed seed bytes refuse, never delete.
The old infrastructure errno2002 and seeded-policy failures remain historical;
this changed source has not run SQL. The original 15-count plan omitted three
existing cleanup assertions; the corrected exact contract retains all 18 IDs.

The temporary dangling named-policy/task references during the dedicated empty
range interval are deliberately not an interpreter or restore-validity profile.
Only the identified row is removed; no TRUNCATE or unqualified DELETE is used.
The random owned-fixture marker is verified before seed access. Restoration
cleanup failures remain separate from the original case failure, and cannot
produce a passed component or erase the original cause. All-mode baseline
seeding and its reference graph are untouched by this focused-only setup.
