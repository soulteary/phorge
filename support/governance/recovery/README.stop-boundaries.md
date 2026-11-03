# Stop-ledger boundary candidate (unrun)

This independent successor is based on signed `0b579a01abc0b44e9cd3ecd5395888d5979701c8`.
That earlier source passed 214 native pure methods and 725 SQL assertions. Those
results do not certify this changed worker/fixture successor. Its pending
obligations are 214 pure methods, 740 SQL assertions and 32 registered exec
worker cleanups. No new production route, permission or activation is added.

Two fixed fixture-only actions, `recovery.stop.record` and
`recovery.stop.history`, authenticate real web-session credentials independently
in new processes and call the existing sealed-context service. Their result
contracts accept only exact action-specific fields and two current-authority
refusal reasons. The general worker reason matcher is unchanged. Unknown
exceptions, SQL failures or malformed results fail the fixture; they never count
as authorization denial. These methods are not public HTTP entrypoint evidence.

The paired race uses two distinct actual sessions of the same administrator.
The parent locks one native User row through its primary key, rather than an
unindexed query. The subject is created before the actor and has the smaller
PHID, matching the service's first canonical account lock. Both child SQL
queries must be observed in SHOW FULL PROCESSLIST and in actual
performance_schema.data_lock_waits for the owned User table before release.
Exactly one ledger/audit pair and one new/one replay response are required.

While a further record call is actually blocked, the existing native
UserTransactionEditor commits administrator removal, with the real request,
cancel URI and independently checked native no-factor precondition. Its native
transaction and current account flag are verified. Releasing the subject lock
must yield the exact current-admin refusal without ledger changes; an
independent fresh history call must also refuse. A positive fresh-worker history
control precedes revocation.

Record and history expiry cases use separate real native sessions, committed
native sessionExpires values and the actual database clock. Each worker first loads its real native session and is observed waiting. Only
then does the separate autocommit native parent connection commit the shorter
expiry, avoiding the genuine SessionEngine login refresh. The test proves the
credential is still live at that point. The parent holds its one
account-row lock until database time reaches expiry. Releasing it must produce
the exact native credential refusal, not an unknown exception. No clock or
permission boolean is injected.

Storage cases temporarily rename or convert only the owned audit table, restore
it in finally, and require exact missing-table/engine refusals with unchanged
ledger/audit fingerprints. An unsupported cluster configuration must refuse
before ledger access; this does not establish distributed or foreign-primary
support. An actual raw START TRANSACTION and caller-owned name change must
survive the service's exact native 1568 rejection until the caller rolls back.
The previous tracked-outer case is retained unchanged.

All worker processes/directories remain registered for the outer owner cleanup.
The helper releases its own parent row locks and restores its owned table/env
changes on failure; it does not clean unrelated workers. Fifteen new strict
assertions and seven additional workers are mandatory. The narrow runtime
summary can describe the two-session race only after these assertions execute.
The original broader concurrency summary, all source-family completeness and
recovery authorization remain false, and adoption remains blocked pending
fresh exact-source runtime/cleanup proof and the remaining recovery work.

Four host PHP result-contract tests and pure receipt mutations are not SQL,
CSRF/HTTP or concurrency evidence. Generated map, full native execution, source
hashes and the new current-source SQL result remain unrun at this checkpoint.

## Failed first boundary checkpoint

Signed `6bc84b827d01c263601c7df57616a0cb10efe991` passed214 pure methods and
725 total assertions before failing at the missing-audit-table fixture. Ten of
this successor's fifteen boundary assertions had run successfully, including
both real waiters, one-new/one-replay, native administrator removal, and both
post-login real expiry waits. This partial725 is not the prior0b full725 result,
and is not a successful740 run. All6787 runtime files matched that signed
source; all26 workers started so far, owned schemas/users/reader/image/stage
were cleaned. The remaining five boundary assertions and the later N12 probes
were not reached. Immutable failed receipts are retained separately.

The native MySQL error mapper constructs its final SchemaQueryException with
`#1146` in the message and the default Exception code0. The first fixture
incorrectly expected getCode1146. The correction requires the exact native
exception class, code0 and exact1146 message for the owned audit table, plus an
independent Admin information_schema observation that the real table is absent
and its owned renamed backup exists. Restoration is separately checked. Generic
backend errors, other schema errors or other table names cannot satisfy this
fixture. New host tests instantiate the original native exception classes;
they do not replace the still-pending real missing-table/whole740 execution.
