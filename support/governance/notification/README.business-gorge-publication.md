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

The twenty-one required Gorge checks, strict typed summary and mutation tests are
source obligations until supported PHP 8.5.12 / real MySQL / paired Gorge runs.
The historical cf313, bcf02 and d629 source-only checkpoints remain unrun.
The explicit union with signed root6c155 preserves every root743 mandatory check
and adds twenty-one Gorge checks: source-derived215 pure /764 SQL, still unrun.
The root SQL and new Gorge profiles both include native core:create and native
Project description fields, with independent storage-marker verification. No
Project comment support or native visibility/preference change is invented.
No production source differs from the root union.

This does not prove HTTP Conduit gateway routing, the Go worker daemon, SMTP,
legacy SQL/Gorge failover, or cross-schema atomic commit. Existing SQL-profile and
physical-mail evidence remain independent and cannot certify this new source.

## Native creation envelope and final root union

The genuine core:create transaction comes from native Web and Conduit creation.
The three additional Gorge Project checks require exactly one visible create,
exactly two hidden initial name/description fields, and no other visible event.
Nonzero safe mail and independently stored description remain mandatory. The
former intermediate d629 source-only215/761 checkpoint was not runtime-ready;
its required root SQL creation correction is now explicitly merged from6c155.
The union is still awaiting its own supported runtime, source hashes and cleanup.
