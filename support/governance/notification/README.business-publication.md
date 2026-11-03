# Native business publication transaction ordering probe

The SQL profile executed successfully at signed70a445b77d60d821d69e3424da7edea03f4c4e66
within the actual215-pure/774-SQL isolated component. This does not certify later
source unions or a general product bug. The publication additions are fixtures,
not production scheduling changes.

The actual Task and Project editors create native objects with native create
transactions, a Task comment or a Project description custom field,
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

The twelve original checks (Task7 and Project5) and three Project create-visibility
checks passed in that exact component. Later source unions require new proof. All
child private configuration is synthetic and owner/inode/nonce scoped; private
stdout is parsed by the parent, and no object IDs, session tokens, mail payloads
or recipient values enter the public summary. Processes and 0700/0600 files are
cleaned in `finally` even when a native check fails.

Project private content uses the existing description custom field
`std:project:internal:description`; native Project transactions do not expose
comment objects. Task fixtures retain native comments. The independent worker
reads committed Project description storage before accepting a positive case,
so absence of private bytes from the envelope is checked against genuine source
content. This does not add a Project comment capability or change permissions.

Direct Editor creation fixtures also include `TYPE_CREATE`, as native Web and
Conduit create callers do. The Project cases inspect actual applied transactions:
creation is mailable while initial name and description fields are hidden.
Nonzero authorized mail and committed private-source-content requirements remain;
no native hide or self-mail preference is changed.
