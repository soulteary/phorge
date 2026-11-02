# Native durable appointment approvals

The adapter borrows only the exact live PhorgeOrganizationService transaction.
It validates the sealed actor snapshot against actual native user and full web
session or API/CLI token sources. There is no synthetic account projection,
caller authorization Boolean, independent commit, or production execution flag
used as a grant. The organization feature remains disabled by default.

The service plans all requester, executor, subject and approver identities and
locks those native accounts in canonical order before its organization guard.
Planning is not authorization. The adapter rereads the ticket under the guard;
a newly added unplanned identity blocks the request rather than adding a lock
out of order. Requester and executor are the same actual actor in the current
proposal endpoint. A different current native system administrator approves.
No first-team-administrator bootstrap exemption exists.

Each ticket binds the exact future operation ID, team, action, subject, period,
requester/executor credential bindings and current target/source rows. Full
credential bindings contain only locator/digest/generation data, not raw cookie
or API token secrets. Approved credentials are rechecked against their real
current native rows, so logout, token revocation, account disable or security
generation changes invalidate use. Long-lived API tokens are still bounded by
the explicit ticket TTL. Unsupported OAuth, legacy certificate and cluster modes
do not fall back to a viewer identity.

State is submitted -> approved -> consumed. Each approval ID consumes once.
A bounded batch may consume several individually bound tickets atomically; the
service's operation ledger handles whole-batch replay only after current actor
privileges are checked. The adapter does not authorize a different mutation from
an existing operation ID. Grant requires an eligible native subject and current
ordinary membership that covers the appointment. Revocation may target an
existing disabled account. All local source versions and shapes are validated.

Database time is read after source/credential locks and again before writes.
After all audit, target and operation-ledger writes, the service invokes the
commit gate. A consumed-ticket gate rechecks identities, credentials, generation,
membership validity and TTL, without treating this transaction's legitimate team
or appointment version changes as stale pre-execution input. Transaction errors
roll back ticket consumption and local target/audit/ledger writes together.
These statements require matching real InnoDB/native connection test evidence;
syntax and pure value tests do not establish them.

The service owns append-only audit writes and returns narrow receipts. This is
not a general history API. Any audit/history endpoint remains restricted to
current native system administrators and must independently enforce that rule.
Pure tests cover explicit intent mapping and inability to construct the adapter
from caller proof objects. Actual native session/token, independent approval,
expiry waits, role/account ABA, stale sources, replay and multiple-ticket rollback
must be exercised by the owned native fixture before acceptance.
