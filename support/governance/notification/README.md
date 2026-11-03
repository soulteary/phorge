# Private notification egress candidate

This isolated slice is disabled by the locked `governance.private-mail.enabled`
option. It requires native Gorge email delivery and a paired mailer callback;
legacy PHP-worker email egress is refused when selected. SMS retains its separate
native challenge/delivery path and is not certified by this email slice.

Explicit source producer IDs distinguish private business mail from account and
security flows. Unknown producers and raw-address business notifications fail
closed. Business mail is persisted and serialized with a generic subject and an
authenticated application link, without private title, action, body, HTML,
attachments, personnel display names, stamps or custom headers. This deliberately
drops business reply/threading metadata rather than carrying unreviewed private
headers. Account verification/reset/invitation/SSH/contact/security producers keep
their native challenge/address semantics; their full runtime matrix is still a
release gate, not proved by the producer catalog.

Every business attempt uses fresh native People and actual viewer ObjectQuery,
including query-level ancestor/Space behavior. Recipient disable or loss of
object visibility blocks the whole immutable message, including shared direct-producer envelopes. Native reply-handler targets are forced to individual-recipient mail; other multi-recipient business producers remain blocked until explicitly split and tested.
A quarantined message cannot resume after access is restored. Existing unsafe
Gorge snapshots are not rewritten under the same delivery ID and cannot be
submitted by the new gate. Already accepted and unknown outcomes retain the
existing ledger semantics; delivered copies cannot be recalled.

The paired Gorge mailer requires deployment-owned
`GORGE_MAILER_AUTHORIZATION_CONDUIT_URI` and
`GORGE_MAILER_AUTHORIZATION_CONDUIT_TOKEN` to submit a versioned authorized
snapshot. It calls authenticated `mail.delivery` / `authorize-attempt` immediately
before each physical `adapter.Send`, including backend retries/fallback. The
callback is bounded, follows no redirects, and compares the actual message with
the saved immutable snapshot. No native user/API/session credential is forwarded.
A missing/unknown authorization source sends no bytes. This check is not an atomic
transaction with SMTP acceptance and promises no new seconds-level revocation SLA.

`native-cases.php` plugs into the existing owned full-native fixture. It starts an
owned queue process, performs native DAO/query and immutable serialization tests,
and never starts delivery workers or SMTP. A configured mailer endpoint is used
only to construct the native adapter and serialize; it is not evidence of a live
mailer. The paired callback/SMTP/Owlmail integration and all producer/security
runtime cases remain explicit gates. Existing media/TLS checkpoints do not prove
these notification changes.
