# Isolated organization notice to protected history browser slice

This candidate adds an application-browser entry to the existing real
organization producer and native mail pipeline. It preserves the previously
accepted native-admin grant allow/revoke proofs at revision 9442; those are
not new execution evidence for this source.

The new explicit fixture phase is:

    php support/governance/native-organization/test-native-organization-mysql.php --governance-notice-history-wire-hold

It retains all ordinary isolated-source, owned-directory, Gorge file/queue,
stable Owlmail v0.14.0, callback and mailer inputs from README.notice-wire.md.
Use `notice-history-browser-router.php` in place of its service-only router.
The two service callback URLs still dispatch through that original router
with its original service-token restrictions. Other browser requests use the
real application configuration, native authentication/session engine,
controllers, policy and CSRF pipeline. A service token grants no browser read.

The fixture creates a password for the single verified administrator mail
recipient and a separate ordinary user with a verified synthetic address who
cannot enter the current-administrator mail audience. It enables
the native password provider only in the disposable database, with registration
disabled. Credentials and the event ID are only in the owned private
`history-browser.json`; they are never a public receipt. The producer still
uses its original real native session. No browser cookie is injected, and
the fixture does not claim that the producer has no session.

The original OrganizationService action and committed audit/outbox path are
unchanged. Preparation does not prepare mail, lease a queue task, submit SMTP,
simulate a callback, click a link or claim delivery. Notifications and private
mail remain off in production defaults. No production class or policy changes.

## Required actual parent exercise

Use the original actual relay/daemon/SMTP procedure and exact owned cleanup.
Record the committed source/event identity, the same event ID at queue,
mapping, prepared mail and SMTP attempts, and the actual generic MIME link.
Do not construct a link and call that a mail observation.

1. At the native browser entry, open the received history link anonymously;
   establish that the native login flow is required and no history rows leak.
2. Log in with the private recipient credentials through the actual password
   form; open the received link and verify the actual committed audit appears.
3. Log out using native CSRF, log in as the ordinary user, and request the same
   known URI. Require the current native denial and no audit/name/body leakage.
4. In a separate fixed revoke fixture, use the existing native Editor control
   between preparation and delivery. Require current recipient refusal, no new
   SMTP, and a denied current protected read. Keep the successful first run
   separate from the revoked run; do not reuse its binding as authority.
5. Exercise the original same-event relay retry and provider ACK-loss boundary.
   Record actual callback/SMTP counts and stable IDs. This is at-least-once
   submission: no exactly-once visibility, remote-ACK certainty, recovery permit,
   general N11 completion or production activation is inferred.

The browser/daemon parent implementation and actual SQL, SMTP, browser and
retry timing runs are still pending. Host controls exercise the actual route
function and native route registration plus explicit rejection cases; they
are not native login or physical delivery proof. New primary-email, SSH,
registration queue and unsupported Legalpad producer gaps remain separate.

This independent lifecycle successor closes the exact owned fixture writer
before exposing the new browser-ready manifest. The original null callback
does not enter this branch. Once that boundary is attempted, database cleanup
remains blocked on errors, timeout or unknown parent state. Only an owned
release file plus a second exact-writer SQL-zero check permits cleanup.
Closing a host handle or the former fixture writer alone proves neither all
writer sessions absent nor a successful browser run.
