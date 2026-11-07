# Borrowed current writer observation: staged compatibility proof

This independent successor starts from reviewed PR123 routing586. It adds a
borrowed Session read-set participant to the actual `RestoreService` transaction
owner without adding a production observer factory, permission rule, enable
provider, or complete family. Public submit/review/execute operations retain
their existing blocked behavior. Task/File RR scopes and separate policy-reader
observations are not composed.

The real store privately mints an opaque owner token before its BEGIN attempt. Only that exact writer, observer and token may register a participant.
The participant verifies the final native connection classes, separate thread,
same server, native scoped writer/independent observer grants and current RC
P_S transaction event. It uses the existing bounded actual Session source
reader, binds stable source bytes rather than event IDs as the source digest,
and verifies the captured read set before the owner ends. It never begins,
commits, rolls back, kills, reconnects or changes isolation on the caller.

Only the store ends its own transaction. The participant then observes the
original physical event through the observer, without issuing a new writer
query which could overwrite that event. Unknown begin/end, changed ownership
and missing physical events fail closed. The owner keeps primary and cleanup
exceptions together and leaves cleanup blocked/owner retained on unknown.
A known committed physical event after a lost commit ACK proves cleanup only;
it does not convert the primary failure into operation success.

## Observer ownership and remaining production blocker

Core has no fixture/control/nonce/USER_ATTRIBUTES dependency, no public observer
setter, and no descriptor or authority boolean which installs a provider.
The private observer seam remains null in business execution. A genuine
production-owned/preprovisioned observer factory, credential origin and
lifetime policy remain unimplemented and must be separately designed/reviewed.
These hooks do not certify business submit/review integration or all sources.

`borrowed-session-observer.php` is an explicitly isolated support wrapper. It
uses the existing owned fixture/control channel to create only the separate
minimal P_S SELECT + global PROCESS observer, validates its actual account
attributes, and exercises the private core seam with reflection. That seam is
a test technique, not an adopted production authority. Native writer grants
are never expanded. The wrapper closes/drops the observer only after the real
store proves physical terminal cleanup; unknown state or failed close retains
its owner/account and reports both primary and cleanup failures.

## Tests and physical SQL cases

Host controls run actual store/participant source with explicitly labeled SQL,
identity and routing collaborators. A separate actual support-wrapper/opaque
owner control covers successful cleanup, primary preservation, unknown parent
terminal and failed close. They prove no physical SQL, real grant or lock wait.
The strict source-artifact CLI validates exactly two fixed candidate source
hashes and rejects runtime/production/permit claims and unknown fields:

    python3 -B scripts/governance/check_recovery_borrowed_owner_source.py \
      --source-artifact docs/governance/recovery-borrowed-owner-source-v1.json

`governance_recovery_borrowed_session_cases()` authors six callable physical
assertions (three for each actual store commit/rollback ending). It requires
an already owned native fixture, real actor context, two fresh actual User
subjects and real Gorge file/native worker prerequisites. The real private
store owner is invoked directly; this is not a public restoration approval.
Native SessionEngine INSERT workers must wait on the actual store User PRIMARY
lock, resume only after an independently observed owner terminal event, and
change the next real store event's selected source binding. Worker SQL sessions
and observer account cleanup must be independently absent. No schema cleanup
may run unless BOTH `governance_recovery_borrowed_cleanup_proved` and
`governance_recovery_borrowed_fixture_cleanup.cleanup_proved` are exactly true.

Physical SQL6 is authored but UNRUN. The independent successor adds the exact
`--session-borrowed-owner` phase and a mandatory Atomic step after the existing
Session lease step. The public receipt checker requires six source-derived SQL
obligations and ten shared lifecycle obligations, independently supplied real
Gorge file binary bytes/hash, current source revision and all 29 fixed source
pins. Neither the selector nor an accepted receipt authorizes production use.

Preparation creates five native Users with native Gorge-backed avatars while
recovery is disabled. Four eligible Users are PHID-sorted into distinct issuer,
owner and two subjects; only the first two become administrators. The issuer
is outside the parent lock set and precedes both subjects. Both actual native
identity epochs and separate native sessions are initialized before the parent.
Prewarm preserves the original Throwable. An active preparation failure may
roll back only its successfully opened, unchanged native depth-one transaction
before any commit attempt; rollback failure aggregates the exact primary and
cleanup objects. Failed BEGIN or COMMIT remains unknown and does not authorize
a speculative transaction end. Fixture cleanup stays blocked on preparation
failure, including when native bookkeeping reports depth zero.
The runtime checks default new User and AuthSession connections share the same
idle native writer, with no forced connection or namespace override. Host
isolated-DAO preparation controls are explicitly not default-writer SQL proof.

Before any generic pool rollback/close, configuration teardown or schema DROP,
the new phase requires both strict cleanup flags and empty private core owner
and observer state. Unknown state retains accounts, fixture and context for
operator reconciliation, while exact owned child/file processes may be stopped.
This only forbids explicit cleanup: PHP process shutdown can still close mysqli
or OS resources and implicitly roll back. It does not promise a live lease after
CLI exit. Existing phases retain their original cleanup behavior. No mapper entries change. Full Session
mutation/temporary-token producer coverage, grant/DDL lease, physical fault
matrix, restore lineage, File/Task shared snapshot, all13 completion and native
enable remain blocked.
