# Isolated native project-description execution qualification

This is **executable test source, not an observed SQL result**. It extends the
frozen default-off description executor candidate. PR141 native metadata/grant
qualification is an independent prerequisite and does not qualify this executor.
No HTTP route is registered or tested. No production deployment, migration or
permission change is performed by authoring these files.

## Admission and fixture construction

`native.php` deliberately has a null Root profile SHA and exits before native
bootstrap or file/database mutation. Root must review a new source successor,
record the complete current native/helper source inventory and exact source
revision, independently check the isolated database container/network/runtime,
and pin the exact private profile bytes before any execution. Do not change the
null pin via an environment override or accept an arbitrary caller profile.
No runner, CI workflow, source cohort or production source admission is changed.

Use a fresh disposable `n05_grants_<32-hex-owner>` namespace bootstrapped by the
standard native storage upgrade and the existing real prerequisite provisioning
pattern. Consume two distinct approved system administrators, their real native
API tokens, one approved ordinary nonadmin recipient, an active business team
with that recipient as a current **direct** member, and one flat active default
Project. Create/restrict the Project via native setup transactions **before**
enabling test-only flags: its VIEW must admit the ordinary recipient, its EDIT
must deny that recipient, and its actual materialized/direct members must match.
The fixture entry refuses unsuitable policy setup; it does not rewrite policies
or make the recipient an administrator/editor. Preserve native application and
Space visibility. The target must satisfy the executor's restricted runtime,
configuration, empty-Herald and source-producer fences. Do not relax these to make
the test run. No concurrent daemon/queue consumer or other fixture writer may
operate on this disposable namespace; Root must prove isolation independently.

A reviewed profile supplies: schema `N05-native-description-qualification-v1`,
owner, namespace, source_root, exact complete source_sha256 inventory,
server_uuid, target_phid, subjects (`user`, `business-team`), private
admin_actors_path/admin_actors_sha256, exclusive recipient_actors_output, and
owned_db_receipt_path/owned_db_receipt_sha256 from the actual Root Docker+SQL
observation. `isolated_features_authorized=true`, `HTTP_authorized=false`, and
`worker_delivery_authorized=false` describe this reviewed isolated profile only.
Native config must bind the actual loopback MySQL endpoint, no cluster/load
libraries, PHP exactly 8.5.12, JIT disabled/buffer0/CLI0, MySQL exactly 8.4.13.
All three governance flags may be enabled only in this isolated fixture. They
remain default OFF in the candidate and must remain OFF on deployed services.
The exact Root-owned profile pin and runtime observation, not caller booleans,
are admission authority. Future execution must be journaled with actual terminal
status and source/runtime provenance; do not infer success from this design.

The entry uses `PhabricatorConduitToken::initializeNewToken` for the ordinary
recipient, writes the three real actor token bindings to a new mode0600 private
file, and uses `newFromAuthenticatedAPIToken` for every actor. No fake context,
permission result injection, omnipotent executor actor or native EDIT grant is
used. Existing native editor internals retain their own unchanged implementation
(e.g. native `willPublish` reload behavior). Tokens and account identifiers are
never included in the public summary. Failures retain fixture data/private token
file for Root inspection; there is no automatic retry or namespace deletion.

## Actual native cases (nine outcomes)

1. Missing grant refuses with exact `description_current_grant_required` and no
   target/security/customfield/transaction/audit/queue changes. Complete original
   customfield storage rows (including description row IDs and raw fieldValue)
   remain in the private snapshot; decoded equality cannot hide delete/reinsert
   or raw storage rewrites.
2. Real independent administrator submit+approve creates a user grant; the
   ordinary API credential calls the sealed executor and real `applyTransactions`.
3. A fixed test-owned SQL trigger rejects the actual description use-audit
   INSERT with MySQL error1644. The test requires that exact native query error
   and proves description, transaction, audit and target queue rollback together.
   The trigger is always dropped in `finally`; its name must be absent initially.
4. An ordinary direct modular transaction submitted without the sealed dispatch
   scope refuses with the exact scope error, even while test flags are ON.
5. Real independent administrator submit+approve revokes the user grant; the old
   grant can no longer execute, with exact refusal and unchanged target snapshot.
6. A separately approved business-team grant admits the same ordinary recipient
   through the actual current direct membership source and native executor.
7. A real ten-second user grant is required to be initially active, then expires
   using actual database time (bounded wait12seconds, no host clock substitution).
   The expired grant refuses with the same strict current-grant error.
8. The exact native Conduit token DAO is deleted; the already-created actor
   context must fail native credential recapture with unchanged target state.
9. A replacement authentic native token is created, then a native user-disable
   transaction is applied by the real administrator. Its previously-created
   context must fail current-account recapture. The replacement token is deleted
   in `finally`. The recipient remains disabled in the disposable fixture.

Successful execution proves the persisted semantic description, one new native
transaction by the actual recipient, one new `description-updated` audit for the
exact grant, unchanged full Project row except native dateModified, unchanged
other fields/edges and all three native account rows, and unchanged historical
transaction/audit/task bytes. The fixed trigger failure verifies the description
and use-audit commit boundary rather than merely checking their later existence.

The actual native publish worker task must be persisted with exact target,
actor, transaction PHID, native mail/feed intent and recipient. Workers are not
run in this qualifier: **feed delivery, search indexing, email delivery, webhook
side effects, worker retry/crash and HTTP ingress remain separate gates**. No
claim is made that queue admission is delivery or that postcommit effects share
the description transaction. The use-audit SQL failpoint occurs before native
queuePublishing. Root must separately capture post-stop isolation/cleanup and
remove the entire disposable namespace/private files after authorized inspection;
never apply fixture account changes or trigger DDL to production.

## Pure host controls and remaining gates

`test_project_description_qualification.py` uses real native token factory and
worker-data methods without saving, and exercises the detached delta validator
against exact success and corrupted security/audit/queue observations. Those
vectors have no authorization or SQL provenance. A library-only read adapter
exercises the actual dstate snapshot path with the real native description
field decoder; equal decoded descriptions with different raw storage row IDs
must compare unequal. The production entry/cases/executor never accept that
adapter. Original SQL reads and raw PHP values remain binary safe and stable
ORDER BY fieldIndex; raw snapshots are not exported as JSON. The entry refusal is tested
with its null Root pin. Existing executor tests exercise real native field
storage decode, modular dispatch denial and default flags. None is positive SQL.

Before promotion: independent source review, current signed native source and
complete inventory/cohort coordination, exact profile/runtime observations,
actual positive native SQL cases with retained raw private provenance and public
sanitized results, actual default-off behavior in the packaged PHP8.5.12 runtime,
and separate HTTP/worker/mail/feed/search/notification acceptance are mandatory.
No candidate feature activation is authorized by passing this qualifier.
