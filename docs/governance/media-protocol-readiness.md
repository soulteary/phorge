# N09–N12 readiness after the native foundation

This is a protocol-readiness inventory, not activation approval. It carries the
selected ADR v1.2 rules forward. No production routing, cookie policy, permission
model, migration or service is changed. Older proposed session endpoints are not
statements about current Stargate, Warden or other service capabilities.

## Decisions already fixed

- V06-A: a media credential belongs to a concrete originating Phorge application
  session and must have a reliable irreversible invalidation state/generation.
  Logout, expiry and account disable must be covered. Disable then enable must
  not revive an old credential, including when no media request occurred while
  disabled. Checking only the user's current enabled flag is insufficient.
- Built-in static assets and login icons remain usable through an exact source
  allowlist. Ordinary avatars and private task/document files require login.
  Possession of an old attachment URL is not a new anonymous authorization.
- Private email content moves to protected links under current recipient/object
  authorization. Already sent attachment copies cannot be recalled. Stargate
  admission does not grant access to every application object.
- V04-B/V05-A cover outbound subjects, bodies, status interfaces and link targets.
  Non-system administrators do not receive approval accepted/rejected outcomes
  or hidden history. A generic received-submission acknowledgement is distinct.
- Four project permission axes remain native. Optional features stay off. D15
  and a global eligibility service have not been selected. There is no agreed
  numeric revocation SLA. New public hostnames require separate confirmation.

## N09: contracts that can be implemented first

A transport-independent media decision contract can bind issuer, viewer,
originating application session, audience, purpose, issued/expiry time and
invalidation generation. Unknown identity, session state, invalidation source or
object policy must block; the contract cannot manufacture a verified identity
from a caller-supplied boolean or header. One-use exchange and replay state
machines, expiry boundaries and disabled/reenabled histories can be tested in
owned isolated storage without exposing an endpoint.

Source classification can separately inventory built-in assets, ordinary avatars,
uploaded content, transforms, downloads and instance routes. File extension or
user-controlled metadata cannot turn active content into a built-in resource.
Active HTML/SVG must not be moved inline onto the application origin. Cookie
scope, origin separation, capability stripping and cache decisions belong to
this contract and its negative tests.

A cache contract can already require authorization before conditional responses,
no shared private-file caching, no 304 bypass, and no fallback from failed media
identity to anonymous bearer access. A controller's historical cache default is
source evidence only, not a measurement of deployed cache behavior. Cached or
already delivered bytes cannot be promised revocable by a new server check.

### Selected protocol direction and remaining gates

1. **Resolved by the 2026-11-02 confirmation: browser handoff and issuer.** Selected direction:
   Phorge validates its own application session, then performs a one-use exchange
   for a dedicated host-only media credential on the isolated file origin.
   Do not forward the application cookie to a parent domain or put a reusable
   viewer bearer token in links. This requires a real handshake, audience/CSRF/
   replay controls and an explicit gateway cookie/header allowlist. It is not
   an existing promised service API. A gateway-issued identity alternative
   would need a reviewed Stargate/Warden protocol and would expand their role
   beyond the already chosen admission-only responsibility.
2. **Engineering proof required for irreversible invalidation.** Selected baseline:
   authoritative application-session state plus persisted monotonic media
   invalidation, rather than a new global D15 authority. Actual logout/expiry/
   disable and enable entry points must be audited before selecting adapters.
   If the deployed services do not provide the required source, keep the route
   blocked; do not assume an old candidate protocol exists. The V06-A outcome
   is already chosen; the unproved lifecycle mechanism is the remaining gate.
3. **Confirm any expanded public surface.** Existing authorized file-origin
   scope is the starting boundary. A new hostname, broader origin sharing or
   public authentication endpoint outside that scope requires confirmation.
   Local fixture endpoints do not grant production publication authority.

## N10: implementable after N09, not independently enabled

Pure route/purpose validators and source classifiers can be implemented first.
Actual `data`, `download`, `xform` and instance guards require the selected real
N09 handoff and lifecycle adapter. Every path must evaluate the current viewer
and native object/Space policy; administrator, subscription, filename, a
transform identifier or link possession are not implicit exemptions.

Tests must include private/avatar reads, private transforms, conditional requests,
active content, exact built-in positives, wrong audience/purpose, expired/replayed
exchanges, logout, disable/enable without an intervening media hit, and recovery
with old versus freshly issued credentials. Returning to anonymous bearer access
would weaken security and needs separate approval, not automatic rollback.

## N11: split contract work from actual dispatch

N11-A can proceed now: a complete producer/consumer inventory, event
classification, restricted-recipient and minimal-payload envelopes, generic
acknowledgements, idempotency/retry contracts and protected-link requirements.
Include current project/task/wiki producers as well as new directory,
appointment, handover, approval and account-recovery events. Inventory gaps must
remain visible; changing one mail template is not complete coverage.

Render/send-time authorization must cover recipient eligibility and the exact
current visible relation or object. Hidden names, department/team links, counts,
status and approval history must not leak through subjects, previews, text,
HTML, inline data or result endpoints. A submission, subscription or ownership
role is not read authorization. Retry must repeat authorization rather than
reusing an old eligibility boolean.

N11-B actual protected-link sending waits for N09/N10 and each real producer.
An isolated restricted outbox can be implemented while dispatcher remains
blocked, but that is not real delivery acceptance. Legacy sends/copies are not
retroactively revoked. Anonymous inline private images, a new external-sharing
mode or exceptions to login require an explicit new direction decision; they
are not implied by the existing email behavior decision.

## N12: measurement can precede a new revocation architecture

A sanitized measurement schema and isolated harness can be implemented now.
Record separate commit/acknowledgement origins for gateway logout, application
session expiry/revocation, account disable and API-token revocation. Distinguish
new requests, already open connections, streamed/in-flight bytes, worker retries
and previously cached/downloaded copies. Include clock source, monotonic elapsed
time, cross-clock uncertainty and explicit unsupported channels. Do not turn
"no sample observed" into zero-delay success.

Measurements should compare the actual selected service versions and transports,
using synthetic accounts and private trace identifiers. They may establish a
baseline; they do not select D15, declare a numeric SLA or authorize production
session invalidation. Recovery scope includes native sessions, APIs and future
media credentials. Unknown coverage blocks mechanical recovery; it must not
silently shrink the scope or batch-revoke/reissue every API token.

### User decisions only after concrete evidence

- A new numeric maximum revocation delay, forced termination of existing streams
  or session/API invalidation beyond the accepted native boundary needs an
  explicit product/security decision after N12 measurements.
- Adopting D15 or another cross-service authority is a separate architecture
  choice. Recommended default is to retain native application authority until a
  measured gap justifies the added state, failure modes and operational burden.
- Any exception allowing anonymous private media, public avatars, reusable
  external share links or additional public hostnames must be confirmed.

Protocol-neutral test harness choices, digest serialization, synthetic fixture
layout, bounded sampling cadence, private evidence retention and test naming can
follow the recommended implementation without repeatedly requesting decisions.
Concrete TTL/cache limits can be proposed from measurements, but must not be
presented as an already accepted revocation SLA or a current service promise.

## Confirmed selection — 2026-11-02

The user selected the recommended N09 direction for isolated implementation and
validation: Phorge validates a concrete originating application session; a
one-use exchange at the existing file origin issues an independent media
credential tied to irreversible session invalidation. Parent-domain cookies and
reusable bearer tokens in links are excluded. Issuer and browser handoff are no
longer pending user choices. No new hostname, production deployment or feature
activation is authorized by this selection.

Native session identity and lifecycle sources must be observed and verified in
the isolated harness. Where the actual native source or invalidation coverage
is unproved, the candidate remains blocked; a caller-provided eligibility
boolean is not a substitute. Choosing session-generation storage, serialization
and bounded test mechanics is implementation work within this direction. D15,
new public surfaces, changed permission semantics and latency promises remain
separate decisions. This dated selection updates this working readiness record;
it does not rewrite the older versioned ADR snapshots.
