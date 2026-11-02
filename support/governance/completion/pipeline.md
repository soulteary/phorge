# Writable native pipeline integration scope

`governance_completion_pipeline($fixture, $check)` deliberately executes real
Project, Maniphest and Phriction editors against a disposable, owned, complete
native schema. It is not a read-only observer and does not expose a production
executor. Native business rows, transactions, comments, subscriber edges, file
attachments, Herald transcripts and publishing tasks are expected writes.

The full fixture must prove ownership before providing a writer environment.
The function refuses an external Gorge task queue and in-process worker
execution. Editors are not put in preview or silent mode. The native queue is
populated but no worker or network mail delivery is started. Runtime failures
must be investigated rather than bypassing native stages.

The scenarios exercise creation and update commits for the three editors,
Project parent/milestone membership rejection, Task reassignment subscriptions
and comment implicit CC, native file-reference extraction and attachment, Wiki
content-version final effects, no-rule Herald processing, persisted transaction
records and publishing queue entries. A real paired Gorge file service is required even for native builtin avatars.
The test additionally writes and reads a synthetic file through the current
Gorge engine before native file-reference extraction/attachment; historical
PHP storage engines remain read-only.

A temporary trigger on the owned native task-transaction table raises a real
SQL exception after the editor has saved the business object. The test checks
that native rollback restores the task row and does not leave transaction rows
or a new publishing task. The trigger is removed in `finally`. Existing-object
validation rejection separately checks business state, transaction records and
publishing count. These assertions do not claim rollback of global counters,
filesystem changes, already delivered messages or arbitrary external effects.

This is scoped to the current source cut and fixed isolated configuration with
optional features disabled and no configured external transports. Arbitrary
plugin extensions, nonempty user-defined Herald rule/action sets, external
services and subsequent worker delivery require separate evidence. Executing
this scenario does not authorize changes to production permission semantics,
activate optional governance models or migrate live permissions.

The full-pipeline fixture uses the same canonical PHID-to-PHID fused membership
lists as native project member controllers. A generic edge-spec map may pass a
local expansion observation yet fail the Project editor's membership policy
hint preparation; that local result was never complete payload validation.
Writable tests exercise the additional native phase rather than changing it to
accept a fixture-only representation.

## Required paired-service profile

The current Gorge profile requires real paired file-storage and taskqueue
binaries, each verified against the provided SHA-256 before starting. The test
starts only private loopback processes in the disposable pod, with an
allowlisted environment, random service tokens and owned temporary storage.
It does not inherit S3, SMTP, proxy or production endpoints. Service shutdown
and nonce-checked private-storage cleanup run in `finally`.

The file service is a prerequisite for normal native builtin avatars as well
as the explicit byte roundtrip. `PHORGE_GOVERNANCE_COMPLETION_QUEUE_PROFILE=gorge`
additionally requires a real editor-created publishing task to traverse Gorge
HTTP. The service policy is required, native fallback counters must not change,
and authenticated HTTP reads verify the resulting owned task. Native SQL queue
results alone remain fallback evidence, never Gorge-profile completion.

The fixture manually executes two exactly bound owned publishing tasks: the
native title-only creation remains hidden for mail and produces no mail outbox;
a real visible comment update is the positive case which generates local Gorge
mail and feed outbox records. No outbox pump or delivery worker is
run; pending delivery identifiers remain unset. This proves publication
preparation, not email delivery or browser notification delivery. Both queue
and file processes are stopped before fixture teardown. Receipts contain only
binary hashes and cleanup booleans, never service tokens, private paths or
endpoint addresses.

Custom-field edits follow the native EditEngine contract: load the builtin
application-transaction field from actual storage and bind its provided old
value. Two description edits prove that the second transaction reads the first
persisted value, rather than using a literal null. Wiki child setup similarly
creates the required root ancestor through the native editor; it does not skip
ancestry validation. These prerequisites were exposed by executing phases that
prior local observation tests intentionally did not certify.

Space creation and a later object's Space edit are separate request lifecycles
in this fixture. Native Space discovery caches its initial result per request;
a newly saved namespace can exist in SQL while absent from that cache. The
fixture starts a fresh native request cache after namespace setup and verifies
the actor's actual active-Space list before editing objects. It does not change
Space visibility or claim mid-request cache invalidation.
