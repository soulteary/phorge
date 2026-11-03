# R02.2 direct web-session and temporary-token observation

This successor is based on signed R02.1 checkpoint
`361f5b50781e70f89816b502af100927ce42dfda`; that queued account-only candidate
is unchanged. This successor also incorporates the separately signed,
fixture-only context correction `b746b9ebafe49942676a27b6136005cd0249055d`;
its SQL retry is still pending. The new code is isolated, unregistered and non-executable. Every
R01 family remains `uncovered`; neither a successful observation nor an empty
credential set permits recovery, grants native AuthSession VIEW, or authorizes a
credential-management action. No global revoker is called by the adapter.

`captureWebCredentials()` uses the existing sealed-actor account scope and its
physical People writer transaction. Real DAO routing identifies
`user.phabricator_session` and `auth.auth_temporarytoken`; cross-schema reads
require the configured primary UUID to match, fixed database names, MDL, InnoDB,
and the bounded native column profile. Target-owned sessions and temporary rows
whose direct resource or owner equals the target are current-read locked.

Each source has a 64-row limit. Server-side length preflight bounds selected
variable fields, properties to 4096 bytes and combined selected rows to 65536
bytes before retrieving secrets. Only those checked, lock-held row IDs are then
fetched. A new READ COMMITTED phantom cannot introduce an unchecked large payload
between preflight and materialization. These are selected-result bounds, not a
claim about arbitrary MySQL CPU, lock-wait time, internal memory or all producers.

Raw session keys, token codes, resources, email addresses and properties do not
leave the capture. Results contain opaque whole-row bindings and fixed storage
states. An unexpired full/partial session or token is **not** declared usable.
The initial method returns one sample; `finish()` compares the storage-set digest
with a second current sample, refreshes temporal states, and rechecks the actor.
Any source/budget exception closes this scope instead of allowing account-only
fallback. Selected locks end before the report is returned.

This is not an atomic/exhaustive family snapshot. A complete insert/delete ABA
between observations, future inserts, external-account sessions, email/contact
key-hash associations, provider-namespaced OAuth resources, legacy credentials,
unknown token types and restore lineage remain unknown. Native one-time login
stores USER as the resource even when its private key hash also binds an email;
that contact binding is not recoverable from the token row alone. OAuth1 stores
`oauth1:request:secret:<provider-id>` with an arbitrary handshake resource. Direct
USER-resource `login:onetime`, `login:password` and `mfa.sync` rows are recognized
only as storage kinds, with foreign/ambiguous owners explicitly unresolved.

The producer inventory JSON records inspected DAO and producer source hashes;
it is static bookkeeping, not a proof of all entrypoints. The prepared SQL
fixture invokes the actual SessionEngine, single-session logout, one-time login
producer and single-token revocation, plus native account disable/enable. Setup
DAOs create only bounded synthetic foreign/unknown and budget rows. It tests
partial/expired/foreign sessions, redaction, delete/reissue binding changes,
unknown ownership/resources, and row/per-field/aggregate payload refusal.
Password controller, MFA enrollment and OAuth HTTP workflows are not claimed
executed by this fixture.

Five new pure methods and thirteen new SQL obligations are prepared. The
successor expects 208 pure methods and 682 full-native checks (existing 669 are
retained). **Neither current supported-runtime pure tests nor SQL have run.**
Earlier 203 pure evidence and any account-only SQL evidence remain scoped to
R02.1. Production activation, all-source completion, browser proof and deployment
remain false.
