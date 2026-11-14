# Task description grants: isolated T1 source preparation

This package writes only independent Task grant, approval and audit metadata.
It has no HTTP/Conduit route and no Task content execution authority. The Task
feature is locked and OFF by default. Enabling Project grants never enables it.
Existing Project grant classes, Project execution scope and generic Editor are
unchanged. Native Task assignee automatic capabilities remain native.

The target is one saved Maniphest Task, and the only operation is
`task.description`. The exact typed request accepts an explicit eligible user or
an active independent business team. It cannot grant Task title, owner, policy,
member, administrator or native EDIT capabilities. No Project, department or
parent-team inheritance is introduced. A value decoder or membership observation
is not a permission receipt.

A Task-specific sealed metadata plan owns its native writer transaction. The
writer relocks real identity/credential generations, the Task, Spaces, Maniphest
application VIEW and effective configuration. Two distinct current system-admin
credentials must submit and approve the same canonical request and shared source
facts; actor-specific credentials are revalidated separately. A missing pre-existing issuer/account epoch is refused before shared
credential locking; the shared initializer independently refuses an active Task
writer before any INSERT. Task grants never initialize the People epoch table.
Metadata uses three
new InnoDB tables in the Maniphest database, with approval/use audit on the same
physical writer. Revocation never depends on the departed recipient remaining
active. Expiry is checked with database time before persistence and commit.

Only API-token metadata calls with the native required-MFA setting exactly false
are implemented. Web/required-MFA/unknown profiles hard-refuse; enrollment is not
substituted for fresh MFA. There is no public API transport adapter in this stage.

The native identity producer adds one sealed Task-writer routing branch, leaving
its Project branch intact. Native config producers track the exact closed union
of existing keys plus the independent Task feature key; Project config capture
retains its existing domain. This is necessary to fence effective configuration
changes, including aliases under native column collations.

Writer cleanup checks exact registry owner, native transaction-state identity,
monotonic generation, depth and lock modes. Successful commit keeps its reservation
until close; close cannot roll back a later foreign transaction or overwrite a new
cache frame. A commit/rollback acknowledgement failure or replaced frame is
unknown and locally quarantined, never retried. This is a process-local guard,
not a physical transaction lease or cross-request disposal guarantee.

## Evidence and remaining gates

Pure/native-no-DB tests exercise typed requests, source/value refusal, caller
seals, default OFF, MFA refusal, inherited visibility and actual native transaction
stack lifecycle. Reflection/query observers explicitly supply unit bookkeeping;
they do not establish real admin, SQL or application authority.

No migration, SQL, server, container or browser was run. The migration and
SchemaSpec are source only. Standard PHP mapper verification, complete source
registry/inventory/profile admission, a new signed native anchor and recipe/media
bindings are still required. Existing schema/patch counts must be derived from
the new actual migration; old source or fixture totals must not be relabelled.

Real isolated SQL must prove schema agreement, two current distinct admins,
user/team grant and revoke readback, replay/stale binding refusal, all atomic
approval/grant/audit rollback boundaries and simultaneous native identity/team/
configuration changes. T2 must separately implement and verify a sealed native
Maniphest description editor with current actor VIEW, no EDIT elevation, actual
expansion/side-effect closure and rollback. Task/Space/application VIEW and direct
team membership must be rechecked at actual content use; a persisted T1 grant
cannot substitute for them. Wiki is unimplemented and remains blocked.
