# Genuine Gorge application-publication diagnostic profile

This successor is separate from the twelve SQL-profile business checks. It uses
real synthetic native users/sessions, Task and Project editors, and the enclosing
owned paired taskqueue. It requires a recorded actual HTTP enqueue service event
and the exact task read back from that service. No task is manufactured directly.

The service only offers class-filtered leasing. The fixture bounds the owned
PublishWorker set at 64, leases that class through authenticated HTTP, and yields
non-target owned tasks without running them. It never expands the production API
or sets lease columns with setup SQL. A larger class set is an explicit refusal,
not a skipped success. All database schemas are disposable and owned; no daemon
is running. Non-target leases may have their yield windows changed by this
fixture, which does not certify untouched production scheduling semantics.

An independent PHP process verifies its exact current task/lease and payload,
then invokes the actual token-checked versioned `worker.execute` Conduit handler.
The handler is not replaced with a Boolean or a synthetic worker. Its actual
followups go to Gorge's fenced finalization endpoint; missing rolled-back native
objects produce the actual permanent result and are archived through resolve.
The normal case additionally yields, awakens and leases the same task through
real service endpoints before executing it. This is an explicit scheduling retry,
not a claim that a permanent source denial will automatically retry.

For outer transactions, prepare is observed while the object is not yet visible
to the independent child, then execute happens after the actual parent commit or
rollback. Prepare does not inspect business authorization and is not called a
permission grant. Committed mail envelopes still pass actual fresh recipient and
object authorization; rollback must create no envelopes. All child processes and
private owner-bound directories must be gone before the enclosing service stops.

The eighteen required new checks, strict typed summary and mutation tests are
source obligations until supported PHP 8.5.12 / real MySQL / paired Gorge runs.
The historical component cf313 requires 207 pure / 735 SQL checks, and was not
runtime certified. The explicit union with main98-derived 91a6 preserves all
740 root checks and adds eighteen Gorge obligations: source-derived 215 pure /
758 SQL, still unrun. The root SQL profile and new Gorge profile both use native
Project description custom fields; independent children must read the stored
private marker before accepting a Project positive. No Project comment support
is invented. No production source differs from the root union.

This does not prove HTTP Conduit gateway routing, the Go worker daemon, SMTP,
legacy SQL/Gorge failover, or cross-schema atomic commit. Existing SQL-profile and
physical-mail evidence remain independent and cannot certify this new source.

## Intermediate native creation envelope correction

The successor now includes the genuine core:create transaction emitted by native
Web and Conduit create paths. Three additional Project checks require exactly one
visible create event, exactly two hidden initial name/description fields, and no
other visible transaction. Nonzero safe mail and independently stored description
remain mandatory. This adds three source obligations to the frozen bcf component:
215 pure / 761 SQL, unrun. The analogous root SQL-profile creation correction is
not yet merged into this intermediate checkpoint. It is not a runtime-ready main
union and must not be accepted until that root delta and its three mandatory
checks are explicitly integrated and the final set is recomputed and run.
