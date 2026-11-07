# Isolated Calendar revocation observation

This callable test producer adds real operation and per-call monotonic timestamps to the existing Calendar physical-policy seam. It does not install a production hook, add an authorization rule or enable any optional feature. It must be called explicitly from an owned isolated fixture after two different event mails have real persisted safe Gorge snapshots. The first event's invitee must currently be attending; both physical-policy positive controls must succeed.

Entry point: `governance_calendar_revocation_observe($fixture, $inviteeA, [$mailA, $mailB])`. The producer validates isolated environment, explicit source revision, Linux boot clock, native non-forced Calendar writer, no outer transaction, autocommit and exact owned Calendar/MetaMTA database namespaces. It measures the actual native invitee DAO save, verifies persisted uninvited state, then calls the actual reloaded mail's `authorizeGorgePhysicalDelivery` for A/B/A using the original bound snapshots. Only hashes, labels, timestamps and typed outcomes are exported. Exceptions during policy observation are `unknown`, never denial. This is a native method observation profile, not SMTP, HTTP or browser timing.

The transition boundary is a conservative native DAO operation call/return with a subsequent persisted-state check. No native transaction group exists for this DAO transition, so none is invented. The SQL COMMIT instant is not instrumented. A returned event is not itself an authenticated execution receipt; its `measurement_acceptance`, `SLA_proved`, `convergence_proved` and `physical_SMTP_proved` remain false. It changes only the supplied synthetic invitee in an owned fixture and does not restore it; caller owns normal fixture cleanup. No generic rollback, process termination or cleanup authority is added.

Replay the bounded observation with independently supplied source revision and producer digest:

```text
python3 support/governance/revocation-measurement/calendar-consumer.py --events PRIVATE_EVENT_JSON --source-revision EXPECTED_COMMIT --producer-sha256 EXPECTED_PRODUCER_SHA256
```

The descriptor requires serial same-domain timestamps, exactly A/B pre-controls and A/B/A post-observations, explicit persisted state and all unsupported claims false. It reports first denied-call bracket, later unknown/allow results and B's outcome. It never upgrades input shape to runtime attestation. Existing schema1/2/3 consumer behavior is unchanged; this separate narrow profile deliberately avoids mislabelling a DAO transition as a persisted native transaction record.

Validation: `python3 -B -m unittest discover -s tests/governance -p test_calendar_revocation_observer.py`. Host tests execute the actual producer body with explicitly substituted native DAO and Linux boot collaborators and a real host `hrtime` clock. Those tests prove code behavior, not native MySQL execution. Actual isolated fixture execution, independent full-source/dependency verification, exact paired binary provenance and strict outer cleanup are still required before any measurement can be accepted. The module is not wired into the full SQL driver yet; integration must add explicit source-derived obligations and a new versioned receipt gate, followed by a fresh actual run. This increment does not claim N12 or N13 complete.
