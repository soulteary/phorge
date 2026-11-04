# Indexed organization lifecycle facts — isolated development checkpoint

This successor is based on the signed a27 metadata-tokenization candidate. The
actual a70f result remains 235 pure methods and 33 focused SQL checks; b997 did
not execute its six metadata assertions. Neither result proves this successor.
No source in this checkpoint has run in the supported PHP/SQL environment.

Approval requester, executor and subject indexes are derived copies of the
original binding. New proposals persist them, and every load verifies each copy
against the binding. Existing rows are version zero and remain explicitly
unindexed. Capture refuses any such row; it never backfills authority. An eventual
isolated backfill test must decode and validate known fixture bindings and verify
exact derived values. There is no online backfill command or migration of policy.

Lifecycle capture uses fixed role indexes for approvals, transfer participants,
handover participants, manual project receipt actors, and linked outbox records.
It locks current rows before fetching bounded JSON/text payloads. The budget is
64 unique lifecycle rows, 32 KiB per large field and 128 KiB aggregate large
fields. It hashes stored facts privately. No receipt grants project access or
publishes hidden project identifiers/counts. Provider schema 2 changes the source
digest; old snapshots are not reinterpreted as a complete lifecycle read set.

Existing Service.record already increments the organization guard, including
transfer and handover execution. Proposal and approval paths only append audits
and previously omitted that increment. With recovery enabled, audit and record
now share a once-per-transaction canonical uint64 increment with exact CAS and
readback. Replay paths append no audit and increment nothing. With recovery off,
record retains its original behavior and audit does not add an increment. No
native team, project, policy or Space grant operation is introduced.

Prepared actual native SQL probes cover Service success/replay, real proposal
and independent approval success/replay, persisted indexed roles and current
source capture, plus an installed audit SIGNAL trigger proving proposal and
approval changes and generation roll back together. These probes are currently
UNRUN and deliberately not added to the old passed component gate. Next required
work includes Transfer/Handover success/replay/rollback, overflow and tampered or
legacy participant rows, producer concurrency and retained metadata leases.
The new migration must be explicitly reviewed and included by the isolated
fixture loader before any run; the previous migration count is not evidence.

All 13 families remain required. Unknown producers, unclosed metadata/privilege
leases, application SQL restore lineage, projects, and other incomplete families
still block a restore permit. This checkpoint has no controlled enable success,
no complete source certificate, no deployment, activation or production migration.

## Prepared focused successor

The subsequent fixture corrects two preflight issues in the initial source
checkpoint: native appointment approval first creates actual team membership,
and scoped config cleanup uses the real native destructor instead of a
nonexistent endScopedEnv method. It also proves the owned audit trigger exists.

The fixed CLI phase `--recovery-organization-lifecycle-only` now invokes 27
prepared obligations: nine Service/approval/source probes and nine each for
Transfer and Handover proposal/approval/execution success, replay and genuine
audit-trigger rollback. Six bootstrap and three cleanup obligations make the
strict focused receipt exactly 36. All remain UNRUN. The reviewed migration list
now explicitly adds the new participant/index patch (35 source migrations,
1572 total patches, 543 tables expected); those counts require actual native
restore verification and are not accepted from a synthesized host template.
Host gate tests only prove consumer rejection behavior, including missing every
obligation and authority/type/source mutations. Existing historical gates and
receipts are not relabeled, and a current-main union is still required.

## Additional prepared boundaries

The final focused draft adds five storage boundaries: mismatched derived roles
are rejected by both native loading and source capture; a real legacy version-0
row blocks absence; a known synthetic row can be restored exactly from its own
existing binding without changing authority; oversized stored JSON is rejected
by length before decoding; and uint64 generation exhaustion rolls back the new
proposal without wrapping. The temporary malformed/overflow values are confined
to the owned fixture and restored in finally.

Two owned independent exec writers now use a fixed lifecycle proposal action.
They authenticate real native sessions, validate native CSRF/current admin, and
wait for an owner-bound continuation. The parent captures current sources while
holding organization_guard, proves the exact child requester waits on its own
PRIMARY row-1 lock with no early ticket, and releases by commit or rollback.
Afterward the real Service proposal must commit exactly one generation and a
matching persisted digest. Errors are never accepted as refusal or success.
Workers are stopped/reaped by their exact registered handles; the outer fixture
still owns all directory cleanup.

The new expectation is 36 component obligations plus six bootstrap and three
cleanup obligations, exactly 45 checks and two registered workers. This is a
prepared expectation, not measured evidence. It does not establish concurrent
arbitrary SQL/DDL exclusion, retained metadata privilege leases, full native
permission/source closure, or a restoration permit. The current-main union,
N00 binding, supported native map/pure execution and SQL proof remain pending.

## Actual initial-admission failure and narrow fixture successor

The signed 3d279 candidate actually passed 236 native pure methods, then failed
before the first lifecycle assertion: only the six bootstrap and three cleanup
checks completed. Recovery had been enabled before creating approved synthetic
reviewer/moving/responsible accounts, so the native initial-admission guard
correctly rejected the fixture. This is not a product authorization defect and
not 45 SQL checks passed.

This successor requires the original recovery flag to be false, prepares all
synthetic accounts and the genuine actor sessions/epoch initialization first,
then enables recovery once for the measured Service/Source/writer operations.
No protected write temporarily disables the guard. Production source and all
45 obligations remain unchanged. The static host phase contract is not a native
runtime proof; the successor still needs supported pure/SQL verification.
