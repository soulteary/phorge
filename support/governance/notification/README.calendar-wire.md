# Isolated Calendar producer, mail and browser fixture candidate

This test-only successor is based on reviewed a20b0e80/tree601c. It adds the
explicit `--calendar-reminder-wire-hold` selector. It is a source/host-review
candidate: actual SQL, automatic relay/worker, SMTP, native login and complete
owned process cleanup remain unproved for this new phase. Production defaults,
permission axes and the existing Calendar SQL21/32 phase are unchanged.

## Native source and private interface

The driver starts one owned File service, explicitly initializes the existing
native worker registry, then calls the Calendar hold. The hold starts the real
owned taskqueue and stable Owlmail v0.14.0 capture using existing helpers. It
creates a host, a non-admin recipient and a non-admin ordinary user. Only the
recipient attends two real native events; both physical-test events have NOONE
VIEW so the ordinary user is denied. The older SQL different-policy sample is
not changed.

The native scheduler body produces the original real mail/outbox and occurrence
marks. Exact per-event recipient, related object, native mark, mail and relay
keys are checked. Preparation never manually enqueues, prepares delivery,
authorizes a physical attempt or manufactures SMTP success. Reflective scheduler
body invocation is not proof of its daemon/global lock.

Password bootstrap uses the native Password/PasswordEngine/PasswordProvider
storage APIs already used by accepted native fixtures. Registration stays off.
Both users must have zero real native sessions after password verification;
no session row, cookie or browser storage is fabricated.

All files below are private owner-bound local fixture data, never public logs:

- `router.json`: existing strict native callback/database/service configuration.
- `calendar-runtime.json`: exact `owned-calendar-wire-runtime` scope, owner,
  source_revision and actual queue URI/token. This avoids inventing a notice
  producer/config or enabling organization/notification features.
- `calendar-browser.json`: exact `owned-calendar-native-browser` scope,
  owner/source_revision; recipient+ordinary `{phid,username,password}`;
  login_path; browser_cookie_injected=false; zero_preseeded_sessions=true;
  exact A/B `{label,event_phid,path}` from native getURI; and the actual native
  fixture-writer-close proof. Passwords and private thread identity stay private.
- `calendar-control.json`: owner, recipient_phid, bindings, invitee_ids,
  event_ids and nativepaths. Controls remain fixed CLI operations owned by the
  parent; no new HTTP mutation endpoint is added.
- `ready.json`: original bindings/marks/queue/mailer+relay database/capture
  fields, native primary expected_destination, stable capture requirement and
  native_source_only. SMTP/browser/preparation success are separate facts.

All native Lisk connections and the exact fixture writer are closed before the
browser and ready manifests are published. The writer-close proof explicitly
does not claim all writer sessions zero.

## Fixed routing and native configuration

The original `notice-history-browser-router.php` pathname is retained for the
existing fixed process-owner helper. Its default history path remains the
original behavior. A Calendar branch is selected only by the typed server-owned
private profile, never by a request query, cookie, header or posted parameter.
The original four-argument `governance_notice_history_route` itself reads that
profile, so offline guest checks and the actual router enforce the same exact
A/B native event and corresponding received `/object/PHID/` destinations.
Unrelated event/history/service routes are denied by the Calendar browser path.
The original native authentication/CSRF/controller/VIEW pipeline is retained.

The existing `governance_notice_wire_config/install_env` signatures delegate the
Calendar profile to its fixed private config. Calendar explicitly keeps
organization, notifications and both media flags OFF, selects the native
prototype only in the isolated fixture, uses UTC and the actual required queue
and callback services. Default notice/history behavior is preserved.

## Stop, quiesce, independent SQL-zero, release

The parent must first stop/reap its actual Go workers, native HTTP server and
observer children. It writes the fixed owner-bearing `history-queue-quiesce`
request. The keeper reuses the reviewed history quiesce helper to stop its real
retained taskqueue/storage and publishes `history-queue-quiesced.json` only after
that succeeds. Calendar ACK scope is `owned-calendar-wire-queue-quiescence`;
owner/source_revision/queue_cleanup shape is retained and
independent_SQL_zero_proved=false. The default history ACK scope is unchanged.

Only after that ACK does the parent independently prove PROCESS-visible same-
primary all-writer SQL-zero under its existing remaining deadline. Then it may
write owner-exact release. The keeper refuses release before quiescence and
reuses the native exact-writer SQL-zero check before permitting driver cleanup.
Unknown/failed parent state keeps the driver's Calendar cleanup gate shut and
retains schemas/principals for owner reconciliation. The ordinary history gate
and all other driver phases are byte-preserved by an exact additive inverse.
The empty native registry's six zero fields do not prove external workers stopped.

The held-FD role reader, complete x+b byte-readback/no-replace publisher and
primary-plus-cleanup failure retention come from the separately closed source
candidate. The new driver delta has an exact inverse back to the immutable a20
908a driver, followed by the original strict inverse to the59b baseline. Four
maps update only the driver pin;73 entries/12 Calendar sources/21cases/32total
remain unchanged.

## Host evidence and remaining gates

Run the discoverable `test_private_calendar_wire.py` host suite. Its explicit
collaborators cover native storage, SQL and service stop; actual filesystem,
source-loop, native initializer and empty-registry controls are distinguished.
These do not certify real SQL or physical delivery. Existing default history,
notice config, source-inverse and native lifecycle regressions must still pass.

Before actual execution: independent source review, exact signed source and
whole-SOURCE closure, strict parent terminal/keeper obligations, actual paired
binary/profile provenance, independent SQL-observer authority and full owned
child/queue/File/capture cleanup must all be bound and accepted. No new domain,
production feature activation, online permission migration or deployment occurs
from this candidate. All-producer, ACK-loss and exactly-once visibility remain
outside this selected Calendar proof.
