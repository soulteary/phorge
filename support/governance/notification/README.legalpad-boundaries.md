# Legalpad boundary and concurrent-consumption fixture

This successor is based on signed `57669e809c0d88a9c27cffe0cdae45acb149f25b`.
Production Legalpad/auth/mail sources are unchanged. The new fixtures are prepared,
not accepted until PHP 8.5.12 and actual owned MySQL/Gorge execution passes.

The existing `governance_private_mail_legalpad_cases($fixture, $check)` entry now
calls the boundary helper and then the concurrent helper. Nine additional checks
are declared (seven source/fault/logout checks and two concurrency checks).
The earlier ten-check baseline is preserved except its last label now explicitly
identifies sequential repeat *after* the independently proved concurrent CAS.
Expected 157 pure / 369 SQL is an obligation, not an actual result.

Concurrency uses exactly two exec-created PHP processes via the existing owned
worker registry. Only fixed `legalpad.verify` is added. The private 0700 directory
contains synthetic fixture-session data in 0600 files; no real user credential is
copied. Each child validates owned nonce/lineage, exact scoped CRUD grants, and
builds a separate actual native web-session request with a native CSRF token.
It calls the unchanged verification service, which checks CSRF/current identity
and actual document VIEW. No shared PHP process or inherited database handle is
used.

The parent holds the actual signer `user` row on its independent owned setup
connection. Both child MySQL thread IDs must simultaneously appear as real
`performance_schema.data_lock_waits` waiters on that setup connection, with their
actual native source-lock SQL visible. A sleep is never accepted as concurrency
proof. After release, the expected outcomes are exactly one initial verification
and one current `already_verified` observation. A setup-only transactional trigger
counts the real UNVERIFIED-to-VERIFIED UPDATE: exactly one must occur. Native mail
and worker counts must remain unchanged. The trigger/counter are removed and the
existing inode/nonce worker cleanup checks zero remaining process/directory.

Seven other cases use actual owned source rows: contact unverified, document
version changed, historical unbound corporate actor, mismatched contact data,
actual MyISAM metadata refusal, exact owned SIGNAL 1644 update fault with marker
and rollback, and real native logout/session deletion. Source edits are explicitly
fixture setup, not assertions that all production mutation paths were exercised.

Still blocked: independent in-flight secret rotation, project/named-policy
revocation profile, latest-main integration, portable CI/source catalog, Legalpad
SMTP and trusted browser acceptance. Legalpad has no document SpacesInterface;
this fixture does not invent a document Space policy. Native transitive-policy
query-to-commit revocation boundaries remain unchanged and are not atomic closure.
