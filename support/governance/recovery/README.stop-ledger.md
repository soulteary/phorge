# R03.0: classification of an already committed native stop

This independent candidate records an administrator attestation about a real
committed `user.disable` transaction. It does not disable or enable accounts,
restore relationships, revoke credentials, or grant a recovery permit. There is
no web, Conduit, CLI or notification dispatcher registration. The locked option
`governance.recovery-stop-ledger.enabled` defaults to false.

`recordAlreadyStopped()` accepts a sealed concrete native credential context,
subject PHID, native disable transaction ID, one of `technical-pause`,
`offboarding`, or `security`, a bounded reason, and an idempotency operation ID.
Unknown historical causes remain unknown. Neither an old enabled/disabled bit
nor an email match can substitute for a real latest native disable event.

The service owns one actual People MySQL writer transaction. It locks the
actual account rows in canonical PHID order before credentials and event rows,
requires a currently eligible system administrator, validates InnoDB tables,
and checks that the target is disabled and that the supplied event is its latest
native enable/disable transaction. The ledger and audit append commit together.
The exact event, target identity and actor credential are checked again after
audit writes and immediately before commit. Existing outer transactions and
unknown routing are refused. Arbitrary privileged SQL is outside this service
boundary; append-only here does not claim protection from a database operator.

One stop event has one immutable classification. The same operation and exact
actor/request can replay without another audit row. Conflicting operations or
attempts to silently replace an existing classification are rejected. A future
correction workflow must preserve the original attestation and receive its own
review; this package has no correction or deletion route. History reads require
a current administrator every time, including replays, and are bounded pages.

Crucially, this service runs **after** the original native stop has committed.
A classification or audit failure must never roll back or prevent emergency
disable. Missing/unclassified history does not become a technical pause and
does not authorize future recovery.

## Required evidence before adoption

This is an unexecuted implementation candidate. No migration has been run.
API source capture remains a dependency gate. Required real SQL tests include:

- Native disable and ledger/audit commit, then exact idempotent replay.
- Wrong subject/event, stale stop after re-enable/re-disable, active target,
  ordinary actor and current administrator credential revocation.
- Audit failure and delayed post-audit authority expiry roll back only the
  ledger transaction; prior native stop and its transaction remain committed.
- Two independent native-session writers with observed real row-lock waits,
  exactly one ledger and one audit, plus conflicting operation rejection.
- Missing/nontransactional tables, unknown routing, untracked raw transaction,
  nested transaction and read-only scope refusal.
- Current-role history denial, bounded pagination, no raw credential or reason
  in public evidence, and exact-source/cleanup verification.

Pure request/DAO tests are not substitutes for these storage and concurrency
requirements. Full-source recovery, restoration permits and recovery entry
guards remain unimplemented and blocked. Four native project axes are unchanged.

## First SQL successor (prepared, unrun)

The first successor registers fifteen mandatory actual-SQL checks. It retains
all 710 main99 checks and requires 725 total, with 214 registered pure methods.
Its reviewed migration adds two User tables: the strict full-native schema
count therefore becomes 540 tables and the source patch count becomes 1570.
The migration filename and digest join the existing exact reviewed patch set;
no old migration, source pin, caller candidate or required check is removed.

The prepared fixture uses native session issuance and UserTransactionEditor
stops, actual persisted event values, separate ledger/audit commits, exact
replay, ordinary-user refusal, bounded history, native enable/disable ABA,
an audit INSERT SIGNAL1644, caller transaction preservation and real logout.
These fifteen cases have not run yet. Their strict summary explicitly retains
`concurrent_writer_proof=false` and `recovery_authorized=false`. Independent
concurrent writers, current role loss while waiting, schema/routing fault
profiles and a raw physical transaction test remain adoption gates.

### Recorded restore failure

The first signed `b16b5de1` attempt passed all214 pure methods and matched6786
runtime files exactly, but failed during native schema restoration before the
stop cases ran. All55 owned schemas, two runtime users, reader, image and source
stage were removed. The migration's line comment contained a semicolon; the
existing native `applyPatchSQL()` reader splits at semicolon-plus-whitespace
without recognizing comments. The resulting comment-only and malformed SQL
chunks were reproduced independently. The successor changes only that comment
punctuation and adds a native-reader compatibility regression. It does not
change the native SQL parser or reduce the725 required checks. No successful
725-check database result is claimed for the failed revision.
