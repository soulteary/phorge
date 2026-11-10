# Explicit Project description grant preparation

This disabled-by-default increment adds durable **metadata preparation**, not an
ordinary user's content editor. It adds no route, bypass, native EDIT capability,
policy change, department inheritance, Project membership inheritance, or parent
team inheritance. `executionStatus()` always returns `can_execute:false`.

The first operation is only `project.description`, whose native transaction is
`core:customfield` with `customfield:key=std:project:internal:description`. There
is no Project DAO description column. This package does not write either that
field or any Project policy/member/Space transaction. Task/Wiki, renewal, an
ordinary editor's current-source lease, typed editor dispatch, transaction
expansion/side-effect closure and postcommit handling remain blocking work.

## Real native sources, not a boolean provider

The native ActorContext carries an actual web session or supported native API
credential. The existing sealed Project history scope rereads and locks actual
accounts, exact credentials, configuration generations, Project, native member
sources and Spaces on the same physical primary. Its current administrator
check never bypasses Project, Space or application VIEW; native web MFA
requirements remain unchanged. This initial profile inherits the existing
flat-root/default-subtype/primitive-policy and empty-Herald/firehose-source restrictions and requires both the
new flag and the existing Project approval flag. It is not a recommendation to
enable either in production.

A new sealed, maximum-two-identity **planning** value allows only history scopes
to lock the stored requester and explicit user recipient in canonical order.
It provides neither actor nor authorization. Stored-approval planning can only
be constructed by the real ticket reader. All existing modes without a plan
retain their existing checks; a plan refuses execution/proposal/member modes.

User recipients come from actual native account rows. Team recipients refer
only to People `organization_unit.kind=team`, with the restricted visibility
model, an active state, real organization guard and same-primary InnoDB/MDL
checks. `organization_member` observations use only that precise team and user,
current account eligibility and the direct active validity interval. They never
traverse departments, parent teams or Project edges. The low-level period
method is explicitly just a row decoder; an array is not a source certificate.
No ordinary non-administrator grant-evaluation provider is claimed here.

The new content flag and organization flag are included in the existing actual
Config epoch/key source set. The migration seeds both generations. Schema and
configuration changes must be migrated and revalidated in an isolated current
fixture before this profile is exercised. No runtime DDL or missing-schema
fallback exists. Migration source is not a production migration authorization.

## Durable grant and revoke approvals

`PhorgeProjectContentGrantService::call()` is a typed server-side interface,
currently without Web/Conduit registration. A submitted request contains exactly
an existing Project locator, explicit `user` or `business-team` recipient,
`grant`/`revoke`, reason, and bounded validity inputs. It never accepts a payload
actor, role, capability, department scope, parent scope, or arbitrary operation.

Submission requires a current native system administrator with native VIEW.
Approval requires a second distinct current administrator with native VIEW.
The original requester's **specific current credential**, administrator state,
MFA requirements and VIEW are revalidated, rather than using an identity header
or old viewer object. Target/configuration/recipient source changes refuse the
pending ticket. Pending tickets last five minutes; the initial conservative
profile allows explicit grants lasting at most24hours from submission. Longer
validity and renewal are not silently inferred; renewal remains a separately
versioned future implementation.

The grant, approval transition and append-only audit records commit together on
the live Project writer. An already-applied ticket cannot apply again. Unknown
commit outcomes must be inspected separately; the service does not auto-resume,
retry approval, or promise physical transaction fault reconciliation. Native
account/session expiry or revocation refuses a still-pending approval.

Revoke binds the exact persisted grant and current version/digest, but **does
not require the original recipient still to be eligible, active, present in a
team, or present in directory/account storage**. This allows removal of authority
after departure or archival while retaining history. Both acting administrators
still need all current native checks. Membership changes do not delete historical
grant rows; a future executor must reread current native eligibility and exact
ordinary membership for every grant use. That executor is still blocked.

## Evidence and next runtime gates

Host tests execute real current PHP classes for strict request values, sealed
plan admission, membership-period decoding, class-map/default option registration
and permanent execution refusal. Static checks cover metadata-only SQL and the
revoke path's absence of a recipient-eligibility dependency. These tests do not
claim durable SQL behavior, two real admins, a native transaction, or an ordinary
user's permission evaluation.

Before a PR is merge-ready, an independent source review and an owned current
native SQL fixture must prove user/team grants, independent approval, requester
logout/expiry/admin loss, target VIEW/Space/config changes, direct membership and
disabled-account observations, departed-recipient revocation, stale ticket/grant
rejection, duplicate/rollback/audit behavior and unchanged native editing. Root
runs that fixture; no Docker/SQL/network deployment was performed by this author.
Existing signed0436 media/source inventories deliberately reject this new runtime
cohort until a separately reviewed current-source binding is prepared.
