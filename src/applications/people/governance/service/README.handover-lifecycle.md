# Native handover lifecycle candidate

This successor to the separately frozen initial-transfer checkpoint adds a fixed
People-service backend. It is off with `governance.organization.enabled` by
default. It registers no dispatcher and changes no native project policy,
membership, hierarchy, Space, or join semantics.

## Mutations

`extend`, `terminate`, and `responsible` each create a new durable transfer ticket.
The requester/executor is the actual authenticated current system administrator.
A different current system administrator approves with a separately locked native
credential. Planning PHIDs are only a bounded lock plan, never authorization.
Execution re-reads sources under the existing account-before-organization-guard
lock order and consumes the ticket in the same People transaction as its handover
CAS, audit, operation ledger, and minimal pending outbox row. The legacy operation
ledger's primary key supplies replay protection; `transfer.operationID` does not.
A committed replay still requires the bound current administrator, and cannot
consume again. Fresh execution requires unexpired requester and approver sources.

An extension is calculated from the stored previous local deadline in its stored
IANA timezone. The original start instant/local time/offset and current deadline
must agree with TZDB; cumulative days are re-derived from those original local
dates and must equal the stored total. Caller totals are not accepted. Each
extension is 1–30 days, and a cumulative total above 30 needs a separate exception
reason as well as independent approval. Ambiguous offsets need explicit resolution;
nonexistent times fail. An overdue deadline does not silently extend itself: the
new approved deadline must be future at final validation. The audit records the
overdue interval, stored timezone, and current site timezone. Site configuration
changes after proposal make its approval stale.

Termination does not clear the current primary department or remove history.
Replacing a responsible person is descriptive only; it never adds membership or
admin status. The former responsible person and the subject may be disabled for
termination/replacement. The new responsible person must currently be eligible.
An extension requires both the subject and current responsible person eligible.
Native disable remains independent and is never blocked by these records.

## Manual project receipts

At most 32 persisted receipts are accepted per handover. These record `keep`,
`remove`, or `review`, with `pending` or `acknowledged` manual status. They do not
perform the named project action. Every write, replay, and output resolves the
project again with the current native viewer's `PhabricatorProjectQuery`.
Invisible references and counts are omitted, and the result is explicitly
nonexhaustive. This native project query is a separately sampled consumer, not a
cross-database atomic permission grant. A project becoming invisible after the
last query remains subject to the recorded original native revocation boundary.

## Due work

The read-only worklist uses the `(state, deadlineAt, id)` index with a bounded
keyset page. A separate explicit administrator POST may record one deterministic
`handover.due` event per actual handover/deadline. It neither revokes access nor
extends/terminates a record. Repeating the same operation uses the durable ledger;
different operations cannot multiply the same due-event identity. The pending
outbox has zero attempts and no dispatcher or external delivery.

## Evidence and remaining gates

This T4 source is **not yet runtime verified**. Added pure tests and real-native
SQL fixture are drafts until the parent runs them in the exclusive isolated
PHP/MySQL environment and binds their source hashes. The prior 159-method,
329-check receipt belongs only to signed initial-transfer checkpoint `a1d6ad7a72`.
It must not be reused as proof of these methods. No production deployment or
migration is authorized by this source.

Still required: real expiry/ABA/lock-order concurrency for each new action;
source/credential/config changes; disabled former-owner replacement; positive
due-event historical-state and bounded pagination checks; rollback at consume,
handover, event, audit and ledger writes; complete native management forms and
Chinese messages; current-viewer privacy in route/Conduit surfaces; strict N00,
source inventory, CI, independent review, and actual final source binding.

There is also a known cross-slice availability regression to test: an unissued
subject's legacy role epoch is absent, then first credential use initializes it
to 1 and invalidates an otherwise unchanged pending source snapshot. T3 correctly
rejected the changed source. The fixture initializes the subject before proposal
only as a test precondition, not a production fix. Normalizing this first-use
transition must be implemented and verified with the native identity-source owner;
requester/approver credential validation must not be weakened or skipped.
