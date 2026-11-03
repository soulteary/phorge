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
