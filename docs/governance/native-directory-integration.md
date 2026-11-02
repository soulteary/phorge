# Native directory and session-bound media integration

Status: native isolated candidate verified; full project goal remains active.
This extends ADR v1.2 and MEDIA-EXCHANGE-001. It does not activate a feature or
change the four native permission axes.

## Implemented boundary

The People application owns the new directory tables. A current native account
and its specific web session or API/CLI token are verified through the actual
native primary connection; a caller-supplied viewer or administrative Boolean
is insufficient. The sealed context is attached by the existing Conduit
authentication branch. Unsupported authentication modes fail closed.

Organization units distinguish departments and business teams from projects.
A user has one versioned primary department and may have multiple team
relationships. Native project membership and project policies are not changed.
System administrators create units and independently approve administrator
appointments. Current exact-team administrators may maintain ordinary members;
parent-team and project administration do not imply access to other rosters.

List, selector, count and bounded export share the protected directory
projection. Hidden parent references and unrelated team relationships are
redacted. Writes use explicit versions and operation IDs. Native account locks
precede the organization guard, and approval consumption, relationship changes,
operation receipt and protected audit rows share one native transaction.
Approval freshness uses current native account/credential sources and a fresh
database clock. No audit-history search, timeline or export surface is installed.

The media backend implements browser preparation, actual CSRF-protected native
web-session issuance, one-use redemption and per-read source validation. Only
credential/code digests are stored. Successful authenticated issuance advances
the browser generation and invalidates previous credentials and pending codes;
a failed issuance rolls back generation, revocation and code changes together.
Redemption does not restore an older browser identity. The backend identity
snapshot grants no file permission by itself.

Native user transactions provide irreversible account-generation input. Two
legacy direct role writers advance an additional epoch in the same native
transaction. This metadata exists independently of feature activation so an
off-window role change cannot silently revive a previously issued binding.
Metadata initialization is a write, not a SELECT-only audit. The historical
read-only audit bridge remains separate. Database-snapshot restoration still
requires a future issuer-incarnation rotation; this batch does not claim
backup rollback is safe for old credentials.

## Release and activation gates

Both `governance.organization.enabled` and `governance.media-exchange.enabled`
default to false. The schema patches create storage without creating directory
relationships or changing native project authorization. These patches must be
applied before running the new legacy role-writer hooks; missing metadata is a
hard failure, not a silent generation-zero fallback. Schema upgrade and rollback
must be rehearsed before any test-machine release update.

The native People route and two fixed Conduit methods are implemented, but
actual HTTP-server and browser acceptance remain required. The media backend
has no public route, cookie writer or file policy integration. Required follow-up
includes host-only secure cookies, issuer/origin checks, bounded preparation and
cleanup, safe active-content handling, native file/object checks for every data,
transform, conditional response and avatar path, and private notification links.
No fallback from failed media identity to an anonymous file capability is allowed.

N04 handover, N08 all-source restoration approval, N11 notification producers,
N12 operational revocation measurements and N13 release/deployment integration
remain incomplete. Archive-to-active restoration is blocked. A newly enabled
native account can obtain a new native session under existing native semantics;
that is not evidence of the optional restoration workflow. Fixture TTL constants
are technical test bounds, not production revocation SLAs.

## Verification design

The isolated integration restores the current quickstart and applies the
reviewed source migrations to owned databases. It creates synthetic people
using real People DAOs, establishes sessions through the native session engine,
and calls actual service, approval, controller and authentication code. It
never uses the old synthetic `org_user` projection as native authority.
Direct controller invocation is identified separately from an HTTP server or
browser test. Seeded pre-existing appointments only establish privacy fixtures;
the durable grant workflow has its own independently authenticated proof.

CI retains the earlier synthetic organization integration and full native
pipeline regressions. A separate native-directory source manifest covers the
new People sources, the Conduit authentication integration and media runtime,
including additions under the historically excluded isolated-governance path.
Source hashes and static call links are drift evidence, not authorization proof.
The new bounded receipt gate rejects missing/duplicate scenarios, source-patch
hash drift, untyped activation flags, failed cleanup and expanded evidence scope.
Raw accounts, session tokens, mail codes and deployment details are excluded
from published artifacts.

## Current isolated evidence

The supported PHP 8.5.12 run passed 132 native test methods and 156 native
integration checks. All 12 candidate tables matched the actual native schema
comparison without running adjustments. Separate processes proved guard-wait
revocation for both lists and counts, list-period expiry, and competing one-use
redemption. Count expiry during a request uses the documented fixture-only
real capture/final-gate seam; it is not a full HTTP mid-request interruption.
The shared connection kept its SESSION default and its next native transaction
retained repeatable-read behavior.

Five worker private directories were removed, with no remaining workers or
private directories; 55 owned schemas, two runtime principals and the reader
were removed. One real paired Gorge file service was started and stopped, and
its private storage removed. The delivery-disabled flags refer to application
worker/notification delivery, not an absence of this storage-service process.
The retained full native/Gorge integration also passed its separate 125 checks.

The two direct role-writer fault cases each retained one native search-indexing
task, while the user role and epoch rolled back. This is measured existing
indexing enqueue behavior, not an assertion of cross-database side-effect
rollback. No delivery workers were run. The administrator-revocation test used
the actual native no-factor MFA path; it does not prove a TOTP challenge.

All 5,847 files in the copied application/governance PHP and migration
inventory matched the test instance. This content match does not expand scenario coverage.
Governance evidence gates passed 75 Python tests; permission-runner unit
regressions are separate from hosted route acceptance. Three independent
source reviews found no remaining material issue. Signed commit binding,
hosted CI and final-head review still gate merge; release, HTTP/browser
media guards and test-machine acceptance remain separate required work.

The sanitized runtime receipt is `native-directory-evidence.json`. It contains
no deployment address, token, raw account record or private worker pathname.

## Remote review corrections

The real request CSRF write guard is now exercised by the controller fixture.
Only the five read-only directory entry points permit their transaction and
first-use identity-metadata bookkeeping in an automatically restored scope.
Mutation entry points retain the normal guard. Media identity authentication
permits only its fixed transaction bookkeeping; preparation, issuance and
redemption are not unguarded by this correction. Tokenless first-use GET, invalid
credentials, normal-write guard restoration and CSRF-less mutation refusal were
verified with the actual request callback. These are direct native controller/
service checks, not yet HTTP-server or browser acceptance.

Shared locking reads use `LOCK IN SHARE MODE`, which retains compatibility in
the always-on legacy role hooks. A separate owned MariaDB 10.11.19 instance
passed 17 focused checks using the actual native role editors while both
optional features were disabled. It restored the full native source schema and
current migrations, verified restricted writer/reader denial and removed all
55 schemas and temporary principals. The sanitized receipt is
`native-legacy-mariadb-evidence.json`. This does not certify the optional
directory/media backends or the entire historical database-version matrix on
MariaDB; the optional integration profile remains the tested MySQL 8.4 profile.
The shared-lock syntax is documented by both
[MariaDB](https://mariadb.com/docs/server/reference/sql-statements/data-manipulation/selecting-data/lock-in-share-mode)
and [MySQL](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html).

## External media issuer and narrow restore verification

The media-only issuer key and incarnation must come from an external configuration
source. A database configuration entry is rejected even if it contains a valid
key. Native application sessions and API credentials are not rotated or replaced.
Browser, transaction, code and credential digests use distinct HMAC purposes.
The new migration deliberately leaves prior candidate rows without an issuer;
normal issuance never upgrades those rows.

The isolated run passed 135 native pure methods and 173 real MySQL/Gorge checks.
It reconstructed the previous media schema with an old SHA-only row, applied the
actual source ALTER migration and verified rejection without promotion. It also
restored an exact logical snapshot of the three media tables through real SQL
while rotating the externally held media issuer. Old media credentials, pending
codes and browser slots remained unusable. The underlying synthetic native web
session and API credential were retained, still authenticated, and their security
metadata was unchanged. Fresh media access required a new browser transaction.

This is table-level media recovery evidence, not complete disaster recovery or
authorization to revoke existing native sessions/API credentials. HTTP/cookie
handoff, structured targets, quotas/cleanup, file policy/cache/active-content
guards and notification integration remain separate work. Public routes and
production optional features stay disabled.

Issuer `assertCurrent` checks only the effective configuration stack in the current process. Recovery requires reloading every serving worker with the new external key/incarnation and preventing those external values from rolling back with the database. The current process check is not a fleet-wide reload certificate. The incarnation-only SQL case passed with a matching runtime receipt: it retains the key and native credentials while rejecting old browser slots, codes and credentials, then successfully establishes fresh media authority.

## Prepared targets and bounded admission candidate

The dependent target slice passed 138 native pure methods and 209 actual MySQL/Gorge checks, including real preparation races, the 100,000-row total bound, current counts after capacity-mutex waits, database-clock quota transitions and cleanup SQL-fault rollback. All nine workers and owned database resources were cleaned. Canonical data/download/xform targets and client nonce digests are fixed at preparation and rechecked at issuance and redemption. The pooled SESSION default and the next ordinary native RR transaction remain unchanged. See `media-targets-and-maintenance.md` for the exact scope.

This source snapshot does not include the separate directory-completion branch and does not certify their future combination. No public HTTP handoff or production activation is included.
