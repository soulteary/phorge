# MEDIA-EXCHANGE-001 v1: originating-session file credentials

Status: protocol direction explicitly selected by the user on 2026-11-02;
implementation and activation evidence pending. This supplements ADR v1.2
without changing its historical snapshot or the four native permission axes.

## Selected decision

Phorge validates its own concrete authenticated application session. A one-use
exchange establishes a separate credential on the existing isolated file origin.
The credential remains subordinate to the originating session and irreversible
invalidation state. Logout, expiry and account disable invalidate subsequent
reads; disable then enable cannot revive an old credential, even with no media
request during the disabled interval. Identity-service admission is not an
object permission grant.

Application cookies are not shared on a parent domain. Reusable viewer identity
tokens do not appear in attachment URLs. The file credential must be host-only,
Secure and HttpOnly, with `SameSite=Strict`. Application and file origins must
use distinct HTTPS hostnames within the same registrable site. The pinned
Public Suffix List includes PRIVATE rules: sibling names under separate
`github.io` tenants, for example, are separate sites and are refused. Cross-site
file origins are unsupported; do not relax cookies to `SameSite=None` to bypass
this gate. This validation supplements origin isolation, not object permission.
The exchange
must bind issuer, audience, purpose, actual session, viewer, expiry and a
browser transaction/CSRF challenge. Codes are one-use under real concurrent
redemption; unknown or stale identity/invalidation/object sources fail closed.
Concrete production TTL and revocation SLA remain separate from fixture values.

## Required implementation evidence

- Actual native session authentication and current account qualification; a
  synthetic row, arbitrary viewer header or caller Boolean is insufficient.
- Real disable/logout/expiry sources and monotonic invalidation history; current
  enabled status or a coarse timestamp cannot prove non-revival after ABA.
- Exact origin/return-path validation, login-CSRF and account-switch resistance,
  replay refusal and no parent-domain cookie widening.
- Current native file/object policy before response, including transforms,
  downloads, avatars, conditional responses and cached private content.
- Precisely sourced built-in static assets/login icons remain available without
  treating attacker-selected filenames, MIME types or SVG as built-ins.
- Private bytes retain origin isolation and bounded streaming through the
  retained Gorge file/image ownership. Failed media authentication never falls
  back to anonymous bearer access. Already downloaded copies are not revocable.
- Browser and cross-service acceptance on the authorized test machine after
  signed paired release and applicable activation review.

## Authorization and rollout

The user authorized isolated implementation/verification and eventual existing
test-machine deployment/browser testing. This decision does not add a public
hostname, enable optional features, migrate project permissions, select D15,
authorize arbitrary session/API revocation, or declare production readiness.
New public scope, permission-semantic changes and concrete feature activation
remain explicit gates. The ongoing project goal includes completing real
adapters and entry points rather than leaving synthetic boundaries as the final
feature. Public records contain no deployment addresses or credentials.
