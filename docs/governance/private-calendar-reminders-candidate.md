# Private Calendar reminder destinations

The native reminder engine groups a recipient's upcoming events into one digest,
marks their native occurrence keys, and binds the message to its first event.
With private-mail selected, the generic link projection removes the digest body.
Only that first event link remains, so the other marked reminders lose their
own navigable destination.

This candidate emits one native single-event message per already selected
notification view only when `governance.private-mail.enabled` is true. The
original single digest builder remains the entire disabled path. Native
attendee selection, time windows, occurrence notification keys and native
mark-before-submission ordering are unchanged. There is no new PHID-only deduplication
which could suppress recurring occurrences.

Each message keeps its own native `relatedPHID` and original recipient. The
existing private-mail egress policy independently queries the current recipient
and related object before submission and each physical attempt. This change
does not add recipients, grant Calendar VIEW, permit anonymous links, enable a
feature, or introduce a new permission model. Unknown or unresolvable related
objects continue to fail the existing egress gate.

Seven host controls invoke the actual Calendar engine, native Event/View,
MetaMTA mail and private projection classes. They cover two navigable links,
private content removal, exact disabled-path digest comparison, recipient and
producer binding, singleton/empty selection, preservation of repeated native
views, and nonboolean flag refusal. They do not execute the scheduler, SQL,
physical mail or browser. Current production egress authorization is unchanged.

Required before activation: real attendee and occurrence selection; repeated
scheduler run/recurrence and ghost-instance cases; separate current-policy
revocation for either event; recipient disablement and no credential revival;
and exact real SMTP MIME with independently navigable authenticated event
links. Native notification marking precedes mail submission as before; no new
atomicity or exactly-once delivery claim is made.

## Isolated SQL entry and mandatory CI gate

The source-only native profile is `php support/governance/native-organization/test-native-organization-mysql.php --calendar-reminders`. It reuses the owned full-source restore, restricted
writer/reader, actual paired File/Taskqueue binaries, write guard and cleanup.
It invokes the native scheduler body through reflection, creates real
Calendar events and attendee rows, persists original occurrence marks and
mail/outbox rows, then manually relays each stored preparation event to the
actual queue twice to verify inbox deduplication and queued native mail IDs.
This is neither a daemon/global-lock nor an automatic outbox-relay test.

The 21 Calendar obligations include independent event destinations, original
attendee automatic VIEW despite a no-one policy, loss of both invitation and
explicit VIEW, per-event current physical-callback checks, disabled recipients,
quarantine after restored access, original disabled-flag digest behavior,
repeated scheduling and a persisted recurring child's parent/child mark keys.
An unpersisted ghost remains fail-closed with no invented related-object PHID;
full ghost reminder delivery is a separate unresolved functional gate.

The strict consumer `check_private_calendar_reminders.py` requires all 32
checks, exact 12 source inputs, the current measurement revision, independent
File/Taskqueue byte digests, supported PHP and full process/storage/schema/
reader cleanup. Atomic CI runs this profile as a required job step and only
uploads its bounded receipt bundle after success. Eighteen host construction
and receipt-mutation controls pass; this source candidate has not yet run the
new SQL profile. No actual SQL, SMTP or browser pass is claimed here.

High-volume per-recipient reminder sets require a bounded-load acceptance
profile before activation; this candidate does not silently cap selected events
or claim that a multi-message submission is atomic.

The Calendar fixture explicitly disables both media-exchange core and HTTP
hooks within its own scoped environment. An actual native scoped-configuration
control begins with both parent flags enabled, verifies both are false during
Calendar setup and verifies the full prior values and stack depth are restored.
Only private-mail and the owned queue are selected for this focused profile;
this changes no production default or permission semantics.

The N00 scan retains all existing candidate IDs, dispositions and eight blockers;
its source snapshot changes only for the exact Calendar engine bytes. Existing
historical product/driver closure checks allow this one named additive change
through exact source hashes and reversible deltas anchored to the prior source.
Unrelated changes still reject even if the candidate pin is refreshed.

## Failure-only diagnostic boundary

If the required Calendar driver exits nonzero, the workflow retains that exact
exit status. A trusted Python projector reads the owned, bounded receipt through
a stable descriptor and strict JSON parser. Only this failed profile can yield
fixed failure class/location, known completed case IDs, and typed cleanup
booleans/counts. SQL text, message/body data, email, arguments and credentials
are never copied. Unknown metadata becomes null or an explicitly unavailable
diagnostic; it never becomes a passing SQL receipt.

The safe JSON is written to a new private exclusive file with held-directory
and held-file identity, mode, link-count and readback checks. The same captured
safe bytes go to stderr without reopening the path. Only that single owned
safe file is uploaded on failure, while the original full receipt remains
private and the success consumer/artifact gates remain unchanged. Source,
filesystem and shell-sequence controls do not establish the failed run's cause
or a successful Calendar SQL execution.

The diagnostic-owned step marker is also an explicit conditional failure path.
A missing, empty, directory or unwritable `GITHUB_OUTPUT` sink cannot replace
the original native exit status. Without a successfully written owned marker,
no diagnostic artifact is selected; the safe stderr projection remains useful.

The owned SQL fixture now uses the actual native
`PhabricatorCalendarEvent::initializeNewCalendarEvent` entry point. The earlier
`initializeNewEvent` name is absent from the native class and throws before
persistence. A fresh-process host regression derives the selected method from
the real fixture, reflects its native definition and invokes the complete real
factory. It explicitly supplies only an empty Spaces request-cache and User UTC
settings as collaborators, with mysqli initialization and network entry functions
disabled. This proves native method compatibility and an unsaved native event,
not physical SQL, policy authority or a successful scheduler execution. The
actual application query/default policies, actor/date/Space/timezone initialization
remain native; no substitute constructor or synthetic persisted event is used.

The current egress diagnostic is observation only. The two original event probes
record an exact bounded boolean shape for object-query presence/identity,
preparation status/cached snapshot/quarantine, snapshot shape and attempted/exact
physical allow. There are no event IDs, addresses, URLs, message bytes, hashes,
SQL or credentials in that projection. Query/prepare/callback order, short-circuit
calls and all existing assertions remain unchanged. Native getter reads add no
query or permission decision. A failed egress attempt retains its existing case
label across cleanup; the original error class/file/line and cleanup evidence
remain intact. The strict projector drops unknown fields/types or more than two
rows. It does not turn a diagnostic into successful SQL evidence or identify an
unproven root cause. Host controls execute the actual old/new fixture loops with
explicit native-query/mail collaborators; actual native mail getter and failure
handoff controls additionally run with mysqli/network bootstrap functions disabled.
