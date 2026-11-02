# Native People directory candidate

This candidate uses the existing People application and native `user` database;
it does not restore a trimmed application or add an SQL-over-HTTP service. Native
`PhabricatorUserDAO` storage and Aphront writer connections retain the existing
Gorge topology/pooling/schema-management boundary.

`governance.organization.enabled` defaults to false and is locked. The schema
patch creates candidate storage only; it does not assign members, appoint
administrators, migrate native permissions or enable directory consumers. The
four native permission axes are unchanged, and no organization policy rule is
registered. The candidate People route and Conduit methods reject requests
while the feature is disabled.

`PhorgeOrganizationService::newForActor()` accepts only the sealed credential
context from the native authentication source. Mutation payloads have no actor,
admin or eligibility field. The service obtains its own native People writer,
rejects nesting in another transaction, locks native actor/subject identities in
canonical order, validates the credential, then locks the organization mutex.
Native identity and credential locks are revalidated after the mutex and before
commit. Candidate identity-epoch storage is not, by its existence, evidence that
all account mutation paths have an irreversible generation. Authentication
source completeness is owned and tested by the native identity adapter.

The first native backend supports system-administrator unit create/update,
unique primary-department assignment, and ordinary member mutations. Unit kind
is independent of projects; neither a parent team nor a project administrator
implicitly obtains directory authority. Membership update does not add a
project membership or native object permission. Team administrators require
current eligible native accounts plus a current member relationship and a
current exact-team appointment. Ordinary members, including the subject
looking at themself, gain no directory exception.

Appointment grant/revoke uses the durable native approval adapter on the same
writer transaction, with a separately authenticated current system administrator
approver. Requester, executor, subject and approver identities are planned before
the organization mutex and all sources are reread under locks. The old synthetic
approval store is not accepted.
No first-administrator bootstrap endpoint is provided. Isolated synthetic seeds
may establish existing appointments solely as test setup, not as an application
write path or proof of administrative approval.

Every member request has an operation ID and explicit unit/member/appointment
versions. Batches are bounded to 32 distinct subject/unit pairs. Native actor
and unit authorization precede operation replay, and a revoked actor cannot use
an old operation ID to retrieve a historical result. All member changes,
versions, protected audit rows and operation receipts commit together. Member
removal/suspension/shortening cannot invalidate a still-active or future
appointment prerequisite. Deactivation history is retained. Disabled targets
may be removed but cannot be newly added. The guard intentionally serializes
this first implementation; no production throughput claim is made.

`PhorgeOrganizationQuery` shares one native protected projection for lists,
selectors, counts and bounded export pages. Only system administrators or
explicit current team administrators can read it. Parent references are redacted
unless separately visible. Other team relationships are never joined into a
member response. Audit storage is not exposed through generic edit engines,
Feed, timelines, search, Conduit or export. No audit-history endpoint is installed.

Pure value-contract tests verify detached input and malformed requests; they do
not establish database rollback, credentials, authorization, or production
readiness. Native SQL acceptance must separately verify the actual credential
source, same physical primary and InnoDB, seeded exact roles, rollback, replay,
lock waits, protected projections and metadata. This backend is not full N03 or
full-goal completion. The People route `/people/organization/` provides a small directory and native
create-unit/add-member forms. Its JSON transport and `people.organization.query`
/ `people.organization.edit` use fixed dispatch and the same typed service. Web
mutations require CSRF; Conduit gets its sealed context from the actual
authentication controller, never from a user or actor parameter. Error responses
do not expose SQL, protected audit rows or approval bindings.

The bounded native SQL and direct-controller profile is verified in
`docs/governance/native-directory-evidence.json`. Full HTTP-server and browser
acceptance remain required; source presence is not completion evidence. Broader
lifecycle/handover, recovery, publication and deployment remain separate work
until evidenced.

The service sets READ COMMITTED for its next owned transaction only; it does not
change SESSION isolation on the shared native connection. This avoids freezing an
RR view during metadata/source inspection before a wait on the organization
mutex. Mutable organization sources remain serialized by that mutex, and native
account/credential sources remain current-read locked. A failed START restores
the queued isolation to the known SESSION default or fails closed. The normal
next native transaction must retain its original isolation semantics.

Directory responses track the actual authority and disclosed membership periods
used by that projection, including a visible parent reference. After native
credential revalidation, a fresh DB clock checks the earliest collected expiry
before commit. This is a statement-to-final-check boundary, not a promise that
network delivery happens before a period expires. The count expiry fixture uses
the real private capture and transaction-final gate as a staged test seam; it
must not be described as a full public count route interrupted mid-request.

## Directory completion candidate

The follow-up metadata patch records the fixed `restricted-admin-v1` directory
model and its version explicitly. It adds descriptive associated-department and
contact references. These links do not confer authority. A team administrator
receives no hidden department/parent reference; a contact outside the permitted
current team roster is redacted. Unknown visibility models are not interpreted
as ordinary readable units.

`previewUnitChange` computes the real stored ancestor chain. Level 8 has no soft
warning; level 9 warns but is allowed. The native editor offers a continuation
instead of treating 8 as a hard limit. The separate 64-node traversal/cycle
budget remains a defensive implementation bound, not the product soft limit or
a restriction on native project hierarchies. The preview is administrator-only
so depth/path metadata does not reveal hidden ancestors to team administrators.

Clearing a main department sets the current reference to null and increases its
version; it does not delete the row or reset its optimistic fence. System
administrators have a bounded unassigned/invalid-department worklist. This is not
a handover implementation. Direct changes from one non-null department to
another remain blocked for the transfer workflow. Explicit clearing is an
audited unassignment, not proof that native project access was removed.

Unit/member reads now use stable keyset cursors. Search treats `%` and `_` as
literal input, and filtered count uses the same protected search predicate. A
native organization tokenizer is served by its own authenticated controller;
a cached viewer passed to the generic datasource cannot substitute for the
sealed request credential context. Preselected tokens are independently
resolved through that protected projection.

The small native management surface adds ordinary membership period update,
end and suspend; administrator metadata, initial main-department assignment,
clear, hierarchy and archive preview forms. All mutation forms retain native
CSRF checks and expected versions. The archive preview retains relationships
and audit records, makes no native-project revocation claim, and does not open a
restore path. These candidate interfaces still need the matching native SQL,
CSRF, rendering and browser receipts before completion is declared.

No 365-day history deletion, transfer/handover state machine, dispatcher,
implicit membership/appointment compound, project permission migration or
production activation is included. O5–O8 remain separate dependent work.
