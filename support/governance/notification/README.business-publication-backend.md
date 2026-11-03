# Business publication diagnostic backend profiles

This fixture-only successor preserves the parent AppEditor SQL publication queue
(owner `phorge`) and all twelve business publication assertions. Each independent
PublishWorker child receives a 0600 owner-bound queue configuration for the real
paired taskqueue already owned by the enclosing native fixture. The child checks
an authenticated taskqueue HTTP stats response before executing the native worker.
Native email adoption and its persisted Gorge outbox remain production behavior;
no production requirement is bypassed and no extra service or worker is started.

The child-only backend proof does not certify Gorge publication queue scheduling,
daemon retry, SMTP delivery, or cross-schema atomicity. Existing mailer and callback
serialization endpoints are not invoked as physical services by this diagnostic.
`paired_gorge_queue_profile_proved` therefore stays false. All ten child processes
and their owner-bound configuration directories must be reaped and removed before
the enclosing fixture stops its taskqueue. Secrets and service addresses are never
included in the public receipt.

Run through the existing full native organization runner with pinned taskqueue and
file binaries in the isolated parent harness. This source successor requires a new
supported PHP 8.5.12 / real MySQL / Gorge runtime receipt; the earlier failure at
check 473 and all older passes remain historical and do not certify this change.

The real child backend profile passed as part of signed70a215/774 with full source
hash matching and cleanup. Earlier473/480 failures remain historical. Any later
API/main source union is unproved until its own supported run is accepted.
