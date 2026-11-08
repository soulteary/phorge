# Known-zero owned worker terminal gate

This isolated fixture correction is based on main fc0369. It changes only the
shared `governance_native_finish_worker()` wait/reap block: a valid result file
cannot prove the native child exited successfully. The original handle must
produce an actual terminal status with integer exitcode zero, signaled/stopped
both false, within the unchanged fifteen-second deadline. The status is retained
before `proc_close()` can consume/cache it; close zero or minus-one is accepted
only after that positive terminal observation. Missing, nonzero, signaled or
unknown state refuses before JSON decoding.

A timeout keeps the original live process handle for the existing owned cleanup
path. No PID guessing, new signal target or retry is introduced. Result files
are not deleted by this gate. Existing action validators, decoder/policy logic,
worker ownership and native application/default behavior remain unchanged.
The historical Session diagnostic unit now supplies a real owned exit0 process
rather than a null process pretending to be terminal.

Actual host controls invoke the real shared function with owned PHP children:
zero, seven, fatal, SIGTERM and a full fifteen-second timeout against a live
child. Only the test's original Popen handle is terminated/reaped during cleanup.
The prewritten valid committed JSON is an explicit result collaborator, not a
native application or SQL success claim.

The exact terminal-block inverse recovers the immutable 11df workers source.
Existing Session, borrowed-owner and Conduit source pin maps retain every old
value; only their source-reading views admit this exact separately reviewed
strengthening. Unrelated edits still refuse even after a current hash repin.
This does not retroactively prove exit0 for historical receipts produced by the
old helper. Fresh exact-source CI/application/SQL acceptance remains separate.
No SSH slice, OAuth v2 private worker, production source, workflow, remote runtime
or database is changed in this independent branch.
