Dedicated Project description execution: isolated source candidate

PhorgeProjectDescriptionExecutor::execute(actualNativeAPIContext, strictPayload)
contains a real native Project editor call. It is not a route, Conduit method,
production enablement, ordinary Web UI, SQL result or blanket content permission.
The existing content-grants and Project-approval flags must both be true; both
remain false by default. Native web requests are explicitly refused pending real
Request/CSRF/MFA wiring, rather than being dispatched as console requests. Only
supported native API-token contexts enter this first profile. No token is created,
stored in a payload, sent via headers or logged by this package.

Payload is exactly existing target_phid, grant_id, new UTF-8 description and
expected_description_digest (ProjectExecutionValue digest of native old nullable
value). This is an optimistic stale-write guard, not authority. No actor, role,
capability, administrator, membership, policy, department or parent identifier is
accepted. Task and Wiki are unsupported. No-effect edits refuse before execution.

The executor creates the exact final native Project Editor, Project Transaction
and object. A private WeakMap binds their object identities and actual context to
one request; ordinary Project editors and Conduit/handbuilt submissions of the
new modular transaction cannot create that map. The native transaction type is
governance:project-description. The existing core:customfield transaction and
its normal native EDIT check are unchanged. The new modular type requires a
sealed live grant-use scope as well as native VIEW; it never grants native EDIT,
changes the actor to omnipotent, shadows core:customfield or removes generic
native editor capabilities. No missing scope, source or table fallback exists.

The existing native Project scope adds a content-description mode. This reuses
its actual primary transaction, native account/exact credential source locks,
configuration epochs, actual flat-root/default-subtype/primitive policy Project,
Spaces, direct/materialized Project member observations and native application /
Space / Project VIEW checks. It is the existing sealed source connection, so no
new caller boolean makes IdentitySource accept an arbitrary writer. A description
request cannot enter policy/member modes. Existing source helpers admit this mode
only to read the precise current ordinary team/account sources; grant preparation
and approval remain administrator/history-only.

The grant is read from the actual locked native Project table. It must match the
exact existing Project and project.description action, be version1, unrevoked and
currently valid using database time. A user grant must match the actual actor;
a business-team grant requires exact direct active membership and eligible native
account under actual organization guard/unit/member observations. Departments,
parent teams and Project membership never grant editing. Real stored applied
approval and append-only granted audit are reread: canonical binding/digest,
independent administrator identities, grant recipient/target/expiry, applied time
and real grant audit actor must agree. This is persisted grant provenance, not a
requirement that the original approvers stay logged in throughout grant lifetime.
The two administrators' actual current eligibility/credentials were checked by
the existing native grant application transaction, unchanged here.

The exact native built-in description field is resolved under the scope. Actual
storage uses ProjectCustomFieldStorage, never a fabricated Project.description
column. Bounded actual storage rows are locked and decoded with native
setValueFromStorage + didSetValueFromStorage. The modular adapter calls native old
and new value, validation, internal and external effect APIs. Native external
customfield handling performs the actual storage write. Other field rows, all
security/other Project columns (except native dateModified), and native direct /
materialized member rows must remain unchanged at commit. Exactly the original
one transaction object/description/metadata/author is accepted after native
expansion. Any additional policies, members, edges, admins, comments, subscribers,
MFA transaction or other effects refuse. The original native MFA metadata form is
bounded. A grant-use audit joins the same actual native Project commit.

Native generic Editor source is byte-unchanged. Its existing scope hooks run
before effects and before native commit; the grant, current credential and selected
configuration are revalidated. The existing producer fence rejects nonempty
Herald and unsupported webhook/libraries profile and remains across native
postcommit work. Existing native customfield search indexing, cache updates,
mail/Feed and queuePublishing are retained; they are not replaced by a synthetic
success receipt. Revocation after native commit cannot undo completed changes,
already delivered messages or asynchronous native side effects. This source does
not promise a stronger postcommit revocation boundary or physical ACK-loss proof.

Meaningful host evidence uses real current PHP classes for request bounds, stored
grant row decoding, default-OFF refusal, flag-ON no-scope refusal at native entry /
capability / old-value / internal / external stages, native built-in description
field storage decoding and unchanged generic Editor bytes. Pure row/vector tests
are not source authority or a real SQL transaction. Host PHP is8.5.8; required
runtime qualification remains exact8.5.12. No SQL, SSH, VM, container, credential
provisioning or deployment was executed.

Remaining technical gates: independent whole-source review; exact current-source
cohort/map/inverse and signed media rebind; default flag-OFF real native regression;
real dual-admin grant + ordinary API user/team description edit on current owned
MySQL8.4.13; no native EDIT before/after; exact native customfield write and complete
transaction effects/audit; stale body, expired/revoked grant, logout/token expiry,
account disable, team changes, VIEW/Space/config revocation and expanded effects;
actual standard maps; native search/cache/mail/Feed and queue evidence; rollback /
unknown commit boundaries. Web UI/CSRF/MFA and publication routes are still blocked
and must be separately implemented and tested. None of this source enables online
features or migrates permissions.
