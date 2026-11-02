# Native identity binding (in development)

These classes bind an actual native web credential to a current account and full
web session. A caller-supplied actor PHID, viewer object, or authorization Boolean
is insufficient. The context compares the request cookie-derived native session
digest to the attached session; the source subsequently proves that credential
against the actual locked database row. The context itself is not a grant.

The organization service owns the transaction. Sources require the exact native
user/session/UserTransaction writer connection object and an open transaction.
Current locking reads acquire shared row locks and table metadata locks, and
actual source tables must be InnoDB. Identity comparisons use byte equality,
including SQL predicates; collation names alone do not establish this property.
The source never opens or commits an independent transaction.

Snapshots bind the current account security-field digest, latest native user
transaction ID, exact session PHID, SHA256 of the native stored session key, and
session expiration. Native authentication digest material is not exposed by a
snapshot. `revalidate()` repeats current locked reads and reads database time
again. Media consumers must first validate their own persisted secret and
one-use state; public identity binding fields alone do not authorize redemption.

The account generation exposed to consumers is an opaque SHA256 binding of the
latest native user transaction ID, the independent legacy-role epoch and the
current security-row digest (including accountSecret). The underlying ID and
epoch are monotonic; the hash is not described as a monotonic integer. Native
disable/enable and empower/approve use user transactions. Existing legacy
system-agent and mailing-list editor methods increment their independent epoch
inside the existing native user transaction, regardless of optional feature
state, without adding Feed, mail, or permission semantics.

The new schema migration must run before the upgraded application serves role
edits. Missing epoch storage fails closed, never silently skips a generation.
For an existing locked native account, a first issuer explicitly inserts epoch
one if absent; no prior media credential exists for that baseline. The legacy
role hook upserts a changed account at epoch two and increments thereafter.
This does not add an epoch write dependency to emergency disable/enable paths.
Actual-runtime role ABA and transaction retention evidence remain required.

API/CLI Conduit tokens have a separate credential-bearing context, attached only
by the successful native token authentication branch when organization governance
is explicitly enabled. The current source locks the actual token through the user
writer after proving the configured token writer has the same actual server UUID
and the token table is InnoDB. OAuth, legacy certificate/session and cluster
credentials remain unsupported; they may not fall back to
`ConduitAPIRequest::getViewer()`. That object exposes a public setUser method.
An invented well-shaped token context is still rejected by actual source lookup.

No production route, optional-feature enable, permission migration, or native
permission-axis change is supplied by these classes. Tests currently include
pure negative construction/credential-shape checks. Matching native SQL fixtures
must establish actual current-account/session revocation, expiry waits,
disable-enable ABA, same-physical-source behavior and cleanup before this slice
can be accepted. Durable native approval consumption remains a separate required
adapter; synthetic organization tests are not its substitute.

## SQL compatibility boundary

Shared locks use `LOCK IN SHARE MODE`, supported by both the documented MariaDB
baseline and MySQL. The always-on legacy-role epoch hook does not query
`@@server_uuid` or `@@SESSION.transaction_isolation`. Its actual native editor
path requires a dedicated MariaDB regression; a source-string assertion is not
runtime proof. The optional organization/media backend is currently validated
against the deployed MySQL profile. API-token same-primary verification still
uses MySQL `@@server_uuid`, and the organization service reads MySQL's
`transaction_isolation` variable. Those optional paths must not be advertised as
fully MariaDB-compatible without explicit adapters and matching runtime evidence.
