# Native business publication transaction ordering probe

Prepared only. This fixture is not a runtime result and does not prove a product
bug before execution. Production code is unchanged from base `fdd2dfb6ad002a0dccba4651c7c3003aa262f99c`.

The actual Task and Project editors create native objects and a genuine comment,
then schedule their real publication task. Only that exact task class/object and
its persisted TaskData is executed in a new PHP process with separately owned
connections. The child does not synthesize the editor's saved mail-recipient state.

Three cases per family are exercised: normal physical commit, retained outer
native transaction then commit, and retained outer native transaction then
rollback. The independent child records actual object visibility. Missing object
must produce the exact native `PhabricatorWorkerPermanentFailureException` from
PublishWorker's loader; arbitrary SQL or validation errors cannot pass. No mail
in that branch means no callback is fabricated. A direct diagnostic reexecution
after commit does **not** claim the production daemon retries permanently failed
tasks. This distinction is part of the acceptance evidence.

Actual mail produced after commit is prepared and checked with its immutable
physical-authorization payload. Task owner and subscriber must have independent
single-recipient envelopes; native owner automatic access remains allowed while
a revoked ordinary subscriber is refused. No SMTP provider is run.

This first fixture deliberately selects the existing **native SQL queue** profile
for deterministic owned task lookup. Paired Gorge queue ingress/lease/finalization
is not covered by it. N05 exact guarded scopes reject inherited transactions and
commit before publication; this diagnostic tests ordinary native content editors,
not a substitute for testing those protected operations. No unsupported profile
may be enabled on the strength of these checks.

Twelve declared checks (Task7 and Project5) are obligations, not passed counts. All
child private configuration is synthetic and owner/inode/nonce scoped; private
stdout is parsed by the parent, and no object IDs, session tokens, mail payloads
or recipient values enter the public summary. Processes and 0700/0600 files are
cleaned in `finally` even when a native check fails.
