# Native organization recovery source closure development

This isolated successor follows the genuinely measured 29ce policy component;
its changed organization producer source has not run supported pure or SQL.
The policy result does not prove this code or a complete recovery profile.

The only fence switch is governance.account-recovery.enabled, default false.
Directory, transfer, notification and dispatcher flags do not disable the
recovery producer fence. When recovery is off the four DAOs call their native
parent implementations directly, including existing REPLACE behavior. When on,
only the final native Unit/Member/Appointment/PrimaryDepartment classes use the
same evidenced People writer and organization_guard mutex as the directory and
transfer services. This is serialization, not a permission or eligibility grant.
INSERT/UPDATE/DELETE are supported; REPLACE or manual INSERT identities refuse.

Existing service ordering is account locks followed by organization_guard.
The new DAO scope takes no User, credential or other account row lock after the
mutex. Fixed classes are final, timestamps are false, auxiliary PHIDs disabled,
and save/write/delete hooks must still be the native Lisk implementations. No
application serializer is accepted. Raw model fields must be bounded scalars,
so object conversion cannot run arbitrary callbacks while holding the mutex.
The parent hooks were read directly: scalar serialization and no-op post/delete
hooks do not query User. Unexpected hook/config changes refuse. Database trigger
or foreign-key producer behavior is a separate metadata/lineage obligation;
PHP hook checks do not prove their absence. Unknown deployment producers remain
blocked, not implicitly trusted because a feature flag is off.

The scope takes one owned native transaction/savepoint, checks MDL/InnoDB and
requires the existing guard row (never initializes it). No inherited raw START
is adopted: SET next isolation refuses before ownership. A nested finish releases
only the scope depth, retaining outer locks until caller commit/rollback.
Native SQL/deadlock/timeout/cleanup errors propagate, never count as denial or
successful closure. Global/session isolation is not changed; next defaults are
restored if START fails before consuming queued isolation.

Required actual evidence before adoption includes two real DAO writers blocked
at the guard (actual process IDs and P_S wait relation), release on commit and
rollback, INSERT/UPDATE/DELETE and unsupported REPLACE, flag-off native positive
control, nested caller rollback, raw START errno1568 with caller bytes preserved,
missing guard without auto-initialization and wrong-engine/route failures. These
cases are not yet run. The planned current lifecycle read-set must include
related transfer/handover/responsibility, pending approvals, manual project
receipts and outbox references, with native object visibility and bounded LOB
preflights; their prior omission cannot be called family-complete.

No source family is removed. No all-thirteen closure, restoration lease, native
restore success, production scale, HTTP, deployment or activation is claimed.
The next provider must bind these new sources with an explicit version; old
v1/v2 reviews must retain their original scope and never gain authority from
source upgrades. The final target still requires a real complete supported
profile and controlled two-administrator restore success.
