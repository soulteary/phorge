# Owned pending-source physical experiment

This is a separate explicit overlay on signed native checkpoint
`8bf760a11d3aeb870a4ef656398db960477d4993`. It does not modify the frozen
139-case security fixture or its parent runner. The copied parent helpers and
header serializer come only from its recorded explicit source hashes; the
source-pending and integer-header pure regressions are both retained.

Run the ordinary disposable native bootstrap with the existing private-wire
environment, plus `PHORGE_GOVERNANCE_PRIVATE_MAIL_PENDING_CASE` equal to exactly
`commit`, `rollback`, `recipient-revoked`, or `admin-revoked`:

    php support/governance/native-organization/test-native-organization-mysql.php --private-mail-pending-hold

The parent still owns the namespace, private 0700 directory, owner marker,
loopback HTTP/mailer ports and synthetic service tokens. It starts the same
native HTTP router and paired Go mailer; no new HTTP control endpoint exists.

The hold creates real accounts and native sessions, opens a real outer People
transaction, applies the actual UserEditor username change (or approval for the
admin-revoked case), and selects exactly the resulting native producer mail.
It prepares the genuine immutable snapshot in that transaction. This is a
worst-case cached snapshot: independent HTTP prepare and physical authorization
must still refuse transiently while the source USER event is uncommitted.

`ready.json` is private and has scope
`private-owned-native-pending-physical-fixture`. It contains one
`pending_snapshot`, `pending_mail_id`, `pending_flow`, `pending_case`, and
`source_transaction_open=true`, plus the existing private capture/mailer fields.
It is not a public receipt and contains synthetic challenge material.

A separate CLI only queues an owner signal; it cannot commit another process's
connection or execute arbitrary SQL:

    php support/governance/notification/pending-control.php commit
    php support/governance/notification/pending-control.php rollback
    php support/governance/notification/pending-control.php aged
    php support/governance/notification/pending-control.php revoke

The transaction-owning hold consumes and validates that signal, performs the
fixed operation and atomically updates private `pending-state.json`. The command
file is removed before acknowledging state, so the next action cannot race an
old command file. State transitions are strict:

- commit: holding -> committed;
- rollback: holding -> rolled-back -> aged (two separate signals);
- recipient/admin revocation: holding -> revoked (rollback, then actual native
  email-primary or current-admin source mutation).

The aged action changes only that synthetic mail's persisted creation time to
fresh DB time minus 301 seconds. It is an expiry fault fixture, never production
sleep-based correctness or a caller-provided authorization clock. Recipient
revocation retains the old email row and changes the actual native primary
resolver, exercising destination freshness; disabled-account alerts are not
misrepresented as forbidden username notices.

The parent should assert: independent native HTTP prepare reports pending with
no quarantine; two configured Go providers get exactly one failed native source
callback, zero SMTP messages and a retry_wait ledger; after commit, a later
same-delivery retry reauthorizes and produces exactly the immutable expected
MIME once. Rollback remains pending while young, then aged missing source is a
permanent refusal; current recipient/admin revocation is an immediate refusal.
Source/transport errors must not traverse another provider. These claims require
actual parent execution; this helper's readiness alone proves none of them.

At release the hold requires its terminal state (committed, aged or revoked).
A lease timeout, incomplete sequence or malformed signal fails. Its finally
rolls back any owned still-open transaction and stops/cleans owned services.
The outer bootstrap removes all schemas and all three runtime principals.
The parent must add `pending-state.json` and an unconsumed
`pending-command.json` to its exact owned-private cleanup allowlist. No raw
snapshot, code, address, owner, DSN, token or path belongs in the public receipt.
