# Isolated durable administrator approvals

This adapter performs real writes only inside the exact owned synthetic
OrganizationStore transaction. It is not registered as a production executor,
a native identity adapter, or a replacement for native project permissions.
Ordinary team-member maintenance does not use this approval protocol.

## Binding and state

Each approval binds one team administrator grant or revoke, its exact subject
and period, requester, executor, future business operation ID, team version,
current identity rows, current member row, and current appointment row.
Canonical bounded values detach caller-held PHP references. Missing or changed
sources fail closed. A batch may contain several approvals, but each approval
has its own binding and can be consumed only once. The business operation ledger
provides batch idempotency; it does not authorize a different mutation.

The durable sequence is submitted -> approved -> consumed. The approver must be
a currently eligible system administrator distinct from both requester and
executor. Requester and executor may be the same person: this is a two-person
control, not a requirement for three people. No caller Boolean or pure-state
`canExecute` result is accepted as authority. Disabled subjects may be revoked;
they cannot receive a new administrator appointment. A grant requires current
ordinary membership whose validity covers the appointment.

## Transaction and time boundary

The adapter borrows the store's private connection and transaction nonce through
an exact-class factory. It checks the actual selected database, disabled
autocommit, immutable owned schema marker and nonce, and retains the guard row
lock. Identity rows use FOR SHARE so the writer needs SELECT rather than UPDATE
on the synthetic identity source. Mutable organization rows use FOR UPDATE.
All local writers serialize on the same guard. This deliberately favors an
inspectable correctness boundary over a throughput claim.

All source locks are acquired before a fresh database clock validates approval
expiry immediately before approval or consumption. The store also invokes a current-approval gate after proposal/approval audit
writes and the consumed-approval gate after target, audit, and operation-ledger
writes.
It rechecks TTL, current identities, independent approver and membership, without
mistaking legitimate target changes in this transaction for stale input.
TTL is an explicit isolated-test parameter (1–86400 seconds); this ceiling does
not adopt an unconfirmed production default.

The store owns commit, rollback, replay handling, and audit inserts. The adapter
never commits independently and does not insert a duplicate audit event.
A late mutation failure rolls back all earlier mutations, approval consumption,
audit events, and the operation ledger in this same database. This statement
covers these local transactional tables, not unrelated external effects or
cross-database native account freshness. Replay still requires current authority
before an existing result is disclosed.

## Evidence and remaining work

Pure tests exercise value shapes, reference detachment, bounded canonicalization,
explicit TTL/version rules, and inability to construct a scope from caller proof
objects. Only matching real MySQL fixture receipts establish durable locking,
expiry waits, replay, consume, and batch rollback behavior. No pure test is a
substitute for that evidence.

Receipts explicitly retain `production_execution=false` and
`native_account_freshness_proved=false`. The synthetic source is a useful
isolated implementation boundary, not completion of N03/N07 or authorization to
turn on optional governance features.

N08 recovery remains dependent on a real native identity/source adapter,
complete Web/Conduit/CLI enable-entry coverage, current direct/project/automatic/
Space/named-policy source enumeration, and a proven transaction/lock boundary
for those actual sources. Unknown sources must block activation. Recovery must
present the current all-source difference and require explicit confirmation;
it must not silently revive ended appointments or historical memberships.
Audit/history access remains restricted to current eligible system administrators.
These are concrete next implementation gates; this adapter does not claim that
recovery, production execution, or native-account migration is complete.
