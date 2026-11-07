# Isolated Calendar revocation observation

This callable test producer adds real operation and per-call monotonic timestamps to the existing Calendar physical-policy seam. It does not install a production hook, add an authorization rule or enable any optional feature. It must be called explicitly from an owned isolated fixture after two different event mails have real persisted safe Gorge snapshots. The first event's invitee must currently be attending; both physical-policy positive controls must succeed.

Entry point: `governance_calendar_revocation_observe($fixture, $inviteeA, [$mailA, $mailB])`. The producer validates isolated environment, explicit source revision, Linux boot clock, native non-forced Calendar writer, no outer transaction, autocommit and exact owned Calendar/MetaMTA database namespaces. It measures the actual native invitee DAO save, verifies persisted uninvited state, then calls the actual reloaded mail's `authorizeGorgePhysicalDelivery` for A/B/A using the original bound snapshots. Only hashes, labels, timestamps and typed outcomes are exported. Exceptions during policy observation are `unknown`, never denial. This is a native method observation profile, not SMTP, HTTP or browser timing.

The transition boundary is a conservative native DAO operation call/return with a subsequent persisted-state check. No native transaction group exists for this DAO transition, so none is invented. The SQL COMMIT instant is not instrumented. A returned event is not itself an authenticated execution receipt; its `measurement_acceptance`, `SLA_proved`, `convergence_proved` and `physical_SMTP_proved` remain false. It changes only the supplied synthetic invitee in an owned fixture and does not restore it; caller owns normal fixture cleanup. No generic rollback, process termination or cleanup authority is added.

Replay the bounded observation with independently supplied source revision and producer digest:

```text
python3 support/governance/revocation-measurement/calendar-consumer.py --events PRIVATE_EVENT_JSON --source-revision EXPECTED_COMMIT --producer-sha256 EXPECTED_PRODUCER_SHA256
```

The descriptor requires serial same-domain timestamps, exactly A/B pre-controls and A/B/A post-observations, explicit persisted state and all unsupported claims false. It reports first denied-call bracket, later unknown/allow results and B's outcome. It never upgrades input shape to runtime attestation. Existing schema1/2/3 consumer behavior is unchanged; this separate narrow profile deliberately avoids mislabelling a DAO transition as a persisted native transaction record.

Validation: `python3 -B -m unittest discover -s tests/governance -p test_calendar_revocation_observer.py`. Host tests execute the actual producer body with explicitly substituted native DAO and Linux boot collaborators and a real host `hrtime` clock. Those tests prove code behavior, not native MySQL execution. Actual isolated fixture execution, independent full-source/dependency verification, exact paired binary provenance and strict outer cleanup are still required before any measurement can be accepted. The historical full SQL driver is unchanged; the independent selected entry below supplies new obligations and a versioned descriptor, followed by a required fresh actual run. This increment does not claim N12 or N13 complete.

## Fixed executable fixture and versioned source descriptor

The new independent entry is `php support/governance/native-organization/test-calendar-revocation-measurement.php --calendar-revocation-measurement`. It accepts only that flag, has no default full-suite path and preserves the original driver file unchanged. Its copied fixture lifecycle creates owned schemas/users, installs scoped native routes, starts the real paired File/queue services, runs the real Calendar scheduler, prepares two real persisted snapshots, invokes this observation producer and performs the original owned outer cleanup. No submit worker or SMTP send is started. Preexisting isolated fixture credentials, exact source revision and paired binary environment must be supplied by the authorized runner; the entry does not discover deployments or create runtime authorization.

`calendar-cases.php` supplies five explicit source-derived obligations. `scripts/governance/check_calendar_revocation_measurement.py` pins 17 source files, checks the exact selected obligations and original outer cleanup, then decodes the actual dynamic observation against the fixed producer SHA and externally supplied expected revision. It requires A denied twice and B allowed after the transition. Its successful output still says `measurement_acceptance=false` and `producer_attestation=external-execution-attestation-required`. Arbitrary JSON, even if source-shaped and cleanup-shaped, is not accepted as authenticated physical execution.

```text
python3 scripts/governance/check_calendar_revocation_measurement.py --receipt PRIVATE_RECEIPT --source-revision EXPECTED_COMMIT --file-binary PAIRED_FILE --file-binary-sha256 EXPECTED_FILE_SHA256 --queue-binary PAIRED_QUEUE --queue-binary-sha256 EXPECTED_QUEUE_SHA256
```

Before recording a commit-confirming return, the helper uses the reviewed MySQL `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` idle probe. It refuses a raw `START TRANSACTION` that native transaction-depth bookkeeping cannot see, without rolling it back or claiming a commit. The helper and CLI remain candidates until fresh supported PHP8.5.12 isolated execution, full executed-source/dependency provenance and independent outer cleanup review. No measured latency number is provided by this change.
