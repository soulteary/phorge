# Existing Wiki content publication fixture (not runtime verified)

This independent successor is based on PR101's signed documentation head
`4395d3102e16def28f1d080c911f2168cb83d4f8`. It adds 13 mandatory checks to
its 789-check baseline: source-derived obligations are 219 pure / 802 SQL,
31 registered workers and a separate set of five Wiki probe children. No
supported-runtime result is claimed. Production PHP and resources are unchanged.

Each sample first creates a genuine existing Phriction document, nonempty content
and subscriber through the native editor with a real session/request. It reloads
that document with the author's native VIEW and EDIT requirements, then edits
its content with the real content version. No invented create transaction or
move-mail event is added. The effective content edit must be visible for native
mail. A real enqueue HTTP observation, exact paired-service task and lease are
required before an independent process invokes the actual versioned native
Conduit worker handler and finalizes its fenced outcome.

Normal and committed outer-transaction samples require nonzero safe mail for the
actual subscriber, independently stored edited content, current native policy
checks and successful archival. The rolled-back sample still has its existing
document, but the new transaction rows are absent: only the exact native
missing-transaction permanent result, zero mail and failure archival qualify.

The two before-commit child calls are **prepare only**. They must see the prior
content and produce no mail. They do not prove a real publish attempt racing an
uncommitted caller, automatic recovery from a permanent result, or atomicity
between the native source and external queue. Those remain explicit work.

MoveAway and MoveTo hide mail natively; this fixture makes no move-mail claim.
Retained move/search publication, worker.execute HTTP gateway routing, the Go
worker daemon, SMTP and browser acceptance are not proved here. Paired Gorge
services stay owned and isolated. Production features remain default off.

All five children must exit, owned private directories must be removed and all
profiler listeners and parent transactions must be restored. The queue's existing
64-PublishWorker hard lease budget is retained: backlog exhaustion fails the run
rather than silently raising the bound or skipping a sample.
