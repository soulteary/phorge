# Session-bound private media exchange

This is an internal backend, disabled unless the exact boolean
`governance.media-exchange.enabled` is true. No public controller, cookie writer,
file authorization bypass, or anonymous fallback is registered by this component.
Its native source must prove the actual complete Phorge web session, current
account, and irreversible native generation binding on the same People database writer
connection. An arbitrary caller boolean or attached viewer is insufficient.
Native source implementation and actual SQL tests are required before acceptance.

The file origin first prepares a browser transaction. Its browser secret must
remain in a host-only Secure HttpOnly cookie at that origin. The public transaction
identifier grants no identity. The application issues a short-lived exchange code
only from an actual POST with native CSRF validation and native session context.
The transport must send the code in a POST body, never a reusable URL credential.
Redemption requires both this code and the browser secret, plus the exact audience,
purpose, and restricted relative return path. The return path deliberately supports
only a bounded subset of native file data/transform paths; unsupported paths fail
closed pending controller integration. It never accepts a return origin or query.

Only SHA-256 digests of random 256-bit browser, transaction, code, and credential
values are persisted. The native stored session key is never persisted in these
tables: its digest and indexed session PHID bind the credential to the native
source. The opaque 64-hex account-generation binding covers the current native
transaction generation, independent legacy-role monotonic generation, and native
security-row digest; it is never parsed or stored as an integer. Secret return values use opaque envelopes and must not enter receipts,
logs, referrer-bearing URLs, or JSON debugging. Browser secret is an antifixation
binding, not file authority. Media identity must still pass per-object authorization.

All mutations acquire the browser row before exchange/credential rows and then
native source locks. Code consumption and credential insertion commit together.
The browser generation advances atomically upon successful authenticated code
issuance, revoking earlier credentials and outstanding transactions for that
browser. The newest issued account intent wins even before redemption: an old
account code cannot redeem first and invalidate the new account code. A failed
issuance rolls back both generation advancement and credential revocation.
Redemption consumes the single code in its current generation. Two concurrent
redemptions cannot both succeed. Unknown, expired, partial, disabled, changed
account, deleted session, or stale generation sources fail closed. Disable/enable
without an intervening media request must still invalidate the old credential;
this requires the native monotonic generation source, not a cache observation.

The proposed 300-second preparation, 120-second code, one-hour credential, and
one-day browser bounds limit secret lifetime; they are not a revocation SLA.
Every authentication revalidates the native source. Source snapshot rollback or
history truncation requires operational issuer-key/generation invalidation before
restoring access; that recovery gate remains open until independently tested.

Remaining package work includes the origin-bound HTTP handoff, host-only cookie
attributes and clearing, concrete file/read/transform guards, cache and active
content controls, native session lifecycle tests, browser tests, and all mail
producer integration. This engine alone is not N09/N10/N11 completion, production
enablement, or a migration of any native project permission axis.
