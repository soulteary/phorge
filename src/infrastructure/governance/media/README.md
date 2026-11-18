# Session-bound private media exchange

This is an internal backend, disabled unless the exact boolean
`governance.media-exchange.enabled` is true. The exchange backend alone does not activate the HTTP controllers, cookie
writer, file guards, or an anonymous fallback. The separately locked
`governance.media-exchange.http-enabled` option also defaults to false.
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

The source now includes the origin-bound HTTP handoff, host-only cookie
attributes, native file/read/transform guards, and cache/active-content controls.
Their presence and unit tests are not proof of complete N09/N10 qualification.
Acceptance still requires the exact candidate revision to pass real TLS and
browser exchange, cross-account and session lifecycle, all retained file routes,
and cleanup/recovery tests together. Private-mail producer integration has its
own N11 qualification. This engine alone is not N09/N10/N11 completion,
production enablement, or a migration of any native project permission axis.

With both options disabled, the existing native file capability-link semantics
remain. In particular, rejecting another account at a file details page does not
prove that account cannot read a secret raw-file URL. Ordinary application login
or gateway admission must not be reported as session-bound media authorization.

Authentication grants a narrow scoped CSRF write-guard exception only for native
START/COMMIT/ROLLBACK bookkeeping. Credential and identity-source reads execute
after that short scope ends. Browser preparation, issuance, and redemption retain
the request write guard; the identity-read exception does not authorize them.
The shared metadata-lock spelling is portable to the focused legacy-role database
profile, but this does not claim complete optional-directory support on every
MariaDB version or topology.
