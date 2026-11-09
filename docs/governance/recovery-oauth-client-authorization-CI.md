# Focused native OAuth client authorization SQL qualification

This additional path-scoped pull-request and manual workflow runs the fixed
`--oauth-authorization-producer-owner` entry. It preserves required Atomic,
OAuthCode, OAuthAccess, Conduit, Assessment and current native-media CI.

The fixture uses the real native `authorizeClient(array())` INSERT and native
`PhabricatorOAuthClientAuthorization::setScope(array())->save()` UPDATE. The
native scope registry stays empty; an empty-scope timestamp change is not a new
OAuth scope grant. Four experiments (INSERT/UPDATE, owner commit/rollback)
contain sixteen physical SQL obligations; four durable Store obligations bring
the total to twenty, with thirty strict receipt checks and fifty-six raw source
pins. The workflow builds only the existing paired File binary, uses disposable
MySQL 8.4.13 at the fixed official index digest and PHP 8.5.12, verifies both deterministic library maps, and preserves
actual native exit status. It provisions isolated PyYAML 6.0.3 before offline
controls and performs committed current-source admission before the build.

Success artifacts contain the accepted fixture receipt and fixed public build
provenance. Failed native output and stderr stay private; only the bounded,
allowlisted diagnostic JSON may be uploaded. Unknown worker/owner state is
retained and never authorizes TERM/KILL or cleanup.

Source and host controls are not physical SQL evidence. This candidate still
requires fresh public CI for both Authz20 and the current protected media source.
Previous OAuthCode SQL8 or media97 receipts do not qualify the changed candidate.
Client authorization DELETE, client secret/disabled/redirect/trust changes and
other uncovered families remain blocking. Defaults stay OFF; no production
factory, complete-source claim, restore permit, activation or deployment is added.

The final qualification requires two actual read-only VERSION()/server UUID
observations against the same fixed disposable loopback endpoint, bracketing the
native fixture. Both must report exactly 8.4.13, equal UUID digests and the
workflow-observed fixed service image reference. A missing/old/changed runtime
record cannot qualify even a valid native receipt. These are two observations,
not a continuous or future immutability guarantee. The image-reference check is
Docker metadata equality, not independent registry signature verification.
No 8.0 compatibility or qualification is asserted by this workflow.
