# Restricted native Project approval execution

This is an incremental, disabled-by-default execution profile. It is not an
activation recommendation for the complete governance feature. The first
supported closure is one edit-policy transaction on an existing, active,
default-subtype flat-root Project. Primitive policies and direct user policies
are supported; named/custom policies, hierarchy, membership changes, mixed
batches, support/comment expansion and other object families remain blocked.
Low-risk original content edits retain their native editor path. A separate narrow
self-join/leave lease fences exact direct/inverse membership writes and native
materialization on the same verified Project writer. Administrator-driven member
changes, hierarchy mutations and general raw producer closure remain unsupported;
this partial profile is not a production activation recommendation.

The POST-only `/project/approval/` endpoint and API-token-only
`project.approval` endpoint submit/review tickets. Their actor comes from the
actual native authenticated web session or native token branch, never from a
payload actor ID. Submission returns only an opaque ticket locator. Full
sanitized history is restricted to a current system administrator who can
natively view the target. Durable records retain protected source bindings;
these credential digests are not included in history responses.

The executor retains original native Project and Space VIEW/EDIT eligibility.
The independent approver needs current native system-administrator eligibility
and native VIEW; approval adds neither EDIT nor a system-administrator bypass
of visibility. The original native editor validation, expansion, capability
checks and MFA are unchanged. In particular a native validator may reject a
planned policy transition even when a valid approval exists.

The native Project writer owns both approval consumption and actual native
Project mutation/transaction/audit writes. Identity/session/token/epoch sources
are locked cross-schema only after actual same-primary UUID and InnoDB/MDL
checks, through the live sealed Project scope. Existing caller transactions or
lock modes are refused. Locked current rows hydrate a fresh native actor and
member set; specific Space cache entries are replaced from current locked
Space rows and restored at scope cleanup. The bridge does not trust a cached
viewer `isAdmin`, old member attachment or old viewer-specific Space decision.
The target source fingerprint is compared before consume; the final target
must differ only by the approved edit policy and normal modification time.
The commit gate does not add a new EDIT check against the future policy.

A second, independent native-primary coordination transaction fences actual
Herald rule and webhook producers. It accepts no current rules and no firehose
hooks, and locks their entire primary-key ranges, including empty/supremum
gaps, through the real editor postcommit return. It is a READ WRITE transaction
exposing only fixed locking SELECT operations. It is not a SELECT-only database
principal or a server READ ONLY transaction. The primitive's runtime test
proves concurrent rule insertion, webhook firehose status transition and hook
insertion wait across a real Project commit and unblock only on release.
That primitive alone is not whole-bridge runtime certification.

The base editor still performs its own actual commit, native cache/Herald and
publish queuing; no preview, silent mode, outer fake commit or rollback-based
read-only claim is used. Native postcommit effects are not atomic with the
Project commit. A postcommit failure cannot undo already-committed approval,
Project records or external effects. No mail/network delivery worker is
started by the owned integration test.

Tickets bind exact target, operation, requested policy, reason, concrete
executor credential, target/source digests, issue/expiry times, and the
independent approver's actual current credential. Approval, consume and final
audit use fresh database time after lock acquisition. A consumed ticket is
not reused for a second native edit. Account/session revocation or source
changes refuse the ticket. Broad all-source recovery, other credential modes,
restore-generation protection, direct producer closure and additional native
families remain separate implementation and activation gates.

Tests in `support/governance/native-execution/` use only an explicitly owned
full disposable schema and real native credentials/editor calls. Source
implementation or local lint is not a passing runtime receipt; use matching
executed-source hashes and current supported-runtime checks for each claim.

## Mandatory native configuration generations

`20261103.project.configepoch.sql` is a real native storage prerequisite,
registered immediately after `db.config` rather than after historical PHP
migrations. This preserves normal fresh upgrades, `--no-quickstart` dependency
ordering and current-main upgrades: the Config database exists first, then the
generation table is created/seeded before historical ConfigEntry producers run.
There is no runtime DDL and no missing-table fallback. The schema must be
migrated before running this release's configuration writers.

`configurationKeys()` fixes the permission/application/MFA/routing keys on the
server. Each has an independently seeded positive unsigned generation.
`ConfigEntry::save()` dispatches through the original insert/update methods;
insert, update, replace and delete are wrapped once around the actual native
operation, including direct DAO calls, CLI database set/delete/migrate and the
native UI ConfigEditor. The original value and generation increment commit in
the same native Config transaction/savepoint. Existing external transaction and
lock levels belong to the caller and are retained. Native ephemeral-object
refusal is checked before any connection attempt. Ordinary nonlisted config
keys do not require the generation table.

This metadata is always on across optional-feature-off windows; it adds no
permission, MFA, Feed or mail semantics. The native compatibility path uses
common InnoDB metadata checks and `LOCK IN SHARE MODE`, not MySQL8 UUID syntax.
The optional execution bridge still requires its separately proved MySQL8
same-primary profile. A focused MariaDB result is evidence only for these
native metadata paths, not the complete optional backend.

The Project source binds both current effective configuration and these
mandatory generations; missing rows are unknown, never revision zero. Relevant
current Config entries and missing-key ranges are locked before generation
rows. Native cache/stack/database source disagreement is refused, and native
Project application VIEW is evaluated against the freshly locked actor.
Current web enrollment follows the native global MFA-enrollment setting;
native API tokens retain their existing enrollment semantics. Native factor
challenge evidence remains separate. Local/deployment file replacement,
physical administrator SQL and whole-database restoration need their own
N08/N13 issuer/deployment-generation treatment and are not certified by this
native ConfigEntry metadata seam.

The writable fixture also injects a fixed `Error` from the native service
profiler only after a real Project UPDATE completed inside the native nested
savepoint. No native query/result is substituted. It verifies that the already
changed row and consumed ticket roll back, actual nested/server rollback calls
occur, and all exclusively owned transaction/lock levels are drained. The
profiler listener is restored exactly in finally. This is fault-injection
cleanup evidence, not a claim that the native pipeline naturally raises that
particular Error or that postcommit effects can be rolled back.

Direct native REPLACE additionally locks all possible native unique-collision
victims (id, namespace/key tuple and PHID). Its actual unique-index profile must
match the native three constraints, including complete non-prefix columns;
unknown additional uniqueness is refused. The fixed native column collations
are consulted when attributing configuration aliases to generation keys; no
ad-hoc lowercase or trailing-space normalization is treated as proof. The
metadata DAO explicitly rejects inherited ORM writes, so normal model APIs
cannot reset a generation. Only the internal fixed increment path and the
versioned storage patch write these rows.

## Configured native MFA evidence

The owned current-source fixture exercises actual configured TOTP enrollment,
challenge refusal, valid one-use workflow-bound tokens, replay refusal, role
revocation and mutation rollback. Native editor MFA uses a high-security token
without upgrading the session. A completed authentication challenge can persist
after the Project transaction rolls back; the fixture does not claim security
token revival or transactional rollback of authentication effects. Other factor
types, browser enrollment and full hierarchy closure remain separate gates.

## Bounded version-2 approved member profile

The next isolated slice uses an exact `{version:2, operation, members}` descriptor
for one `project.members.add` or `project.members.remove` native edge transaction.
Up to 16 unique existing User PHIDs are canonicalized. Addition requires a
currently eligible account; removal can reduce a disabled account's membership. The current flat project
remains bounded to 128 direct/materialized members; unsupported hierarchy, data
or mixed transaction envelopes fail closed. The matching supported PHP 8.5.12 fixture passed 161 pure methods and 413
native checks, with 5903 runtime source files matched. This bounded evidence
is not an activation recommendation or full hierarchy/source-closure proof.

Existing edit-policy tickets retain their version-1 interpretation. A member
ticket binds the whole requested descriptor, current row/edges, subject identity
and native transaction generations, concrete executor and independent current
system-administrator approver credentials. Native EDIT, object/Space VIEW and
native MFA remain prerequisites. Self-join/leave retains its ticket-free native
path. Approval consumption, exact direct/inverse/derived changes, native record
and audit use the real Project writer; native postcommit effects stay outside
that transaction. The raw edge lease never accepts caller approval booleans.

For noncredential subject facts, first issuer metadata initialization at generation
1 is not treated as a role change. Native USER transaction IDs, current security
fields, and the legacy role generation remain bound; first role changes advance
to 2. The actual executor and approver credential bindings are not normalized
or weakened. Physical metadata deletion and restoration remain recovery gates.

Historical member-add records remain readable to a current system administrator
with native Project VIEW after the subject is disabled. Execution eligibility
is not reapplied as a historical-read prerequisite; history still stays inside
this tested flat native profile.
Historical reads check actual current source bounds, without hypothetically
replaying an old addition against the current membership capacity. New mutations
still enforce the planned-result member limit.

The explicit union with main media HTTP support passed 169 pure methods and
482 native checks, using the paired image service. The matched 5918-file runtime
inventory also covers the media behavior and static resource manifest. This
backend regression is not a browser or production-activation certificate.
