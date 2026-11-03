# Native source transactions and bounded delivery retry

This default-off candidate retains native account and permission semantics. The
independent-process regression reproduced a legitimate People transaction whose
Meta mail was already visible before the People COMMIT: preparation permanently
quarantined that mail, and the quarantine continued to deny delivery after the
exact source event became visible. The signed bug checkpoint preserves that
behavior as evidence; it is not an accepted production behavior.

The successor distinguishes a missing USER event from an invalid, oversized,
wrong-subject/type or hash-mismatched event. Current account, administrator,
recipient, native primary-email and destination checks remain immediate vetoes.
Only an otherwise-valid missing event may raise a transient source-pending error
while the persisted mail is younger than 300 seconds by the fresh primary DB
clock. Future, malformed or expired timestamps deny. This is a retry budget,
not permission or a revocation grace period.

Pending never returns allowed=true, never writes a quarantine/status/snapshot,
and never calls a physical adapter. Existing durable workers may retry and
revalidate the same immutable delivery identity after the physical COMMIT.
After rollback and budget expiry, the missing event is permanently denied.
Previously quarantined rows are never automatically repaired or unquarantined;
no existing immutable message is rewritten or migrated.

Supported PHP 8.5.12 passed 151 pure methods and 350 native SQL checks. The new
cases use real UserEditor, independent native ORM processes and source-event
hashes. They cover precommit preparation and physical-authorization methods,
commit retry, rollback expiry, current-primary revocation, current-admin
revocation, present-event hash mismatch and historical quarantine nonrevival.
They do not execute SMTP, prove cross-database atomicity, or prove live queue
retry through the paired Go delivery ledger. The paired Gorge dispatcher needs
its own stop-dispatch source-error type: a retryable source error must defer the
whole delivery rather than traverse another provider. Live native-to-Gorge-to-
SMTP competition remains a separate required validation.

### Invitation capability regression candidate

The current candidate authorizes invitation authors through the native People
`people.create.users` capability. Other administrator-only security flows retain
their existing checks. The legacy invitation `admin` binding must equal its
actual author/subject; it is not a separate source of authority.

Nine additive SQL obligations cover a genuine non-administrator InviteWorker,
current capability removal, persistent callback quarantine after regrant,
missing capability, default administrator behavior, exact native custom-policy
behavior and separation from administrator-only welcome mail. The required SQL
set is 668; the current pure-method expectation is 201. These are pending
runtime obligations for this candidate, not results inherited from an old tree.

The physical driver preserves all original sixteen security cases and four
large-message cases. Two additional invitation cases use a scoped native grant
only while preparing their snapshots. Baseline administrator policy is restored
before the parent becomes ready. A fixed owned control grants the capability
only after the original sixteen physical cases complete; it proves the inviter
was not an administrator and lacked the capability before the native grant.
The added allow/revoke paths contribute twelve driver assertions, including
exact native MIME, current callback denial, zero denied SMTP deliveries and no
resend on accepted replay. The full matrix expectation is 180 assertions, plus
unchanged pending-source cases of 25/27/20/20. Neither SQL nor physical execution
of this newly integrated candidate has been recorded yet.
