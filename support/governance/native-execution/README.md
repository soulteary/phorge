# Owned native Project execution fixture

`producer-fence-cases.php` exposes
`governance_project_producer_fence_cases($fixture, $check, $actor)` and independently
checks three real producer waits. Its receipt deliberately excludes full bridge
execution claims.

`project-policy-cases.php` exposes
`governance_project_policy_execution_cases($fixture, $check)`. It requires the
existing native-organization fixture's `governance_native_request` real session
factory, a fully restored owned `PhorgeGovernanceCompletionFixture`, current
Project approval schema patch and actual native source/bootstrap configuration.
It creates independent synthetic executor/reviewer accounts and real sessions;
the reviewer can VIEW but cannot EDIT the proposal target. The first positive
case calls the actual native Project editor and binds its persisted transaction
group to durable ticket consumption and audit. No delivery worker runs. The
feature override is scoped to this owned fixture and restored in finally.

This is writable isolated integration, not read-only observation. The source
and flag are not a claim that all Project producers are gated, that N05/N07 are
complete, or that postcommit side effects roll back with the Project writer.

The inherited native-organization runner uses the native SQL task-queue profile
and real Gorge file service. SQL queue evidence is not evidence for the deployed
Gorge HTTP queue/outbox publication path; that paired-service profile remains a
separate required deployment gate. An actual native commit does not make later
publication/mail/webhook delivery atomic or retract an already delivered copy.

### Fresh native storage bootstrap

`bootstrap-wrapper.py --profile default` and `--profile no-quickstart` execute
`PhabricatorStorageManagementUpgradeWorkflow` against a new owned, empty
namespace with setup privileges restricted to exact source database names.
They run synchronization and schema adjustment, require the complete native
patch ledger and all 16 generation rows, and clean their schemas/principal.
The supervisor captures actual native console output in memory; the
no-quickstart proof requires the generation patch to run before the historical
20140722 config producer and requires no quickstart load. Only console hashes
and fixed observations enter receipts. This does not certify arbitrary
historical external archives or the optional MariaDB execution bridge.

The retired PHID database needs an exact schema grant because the historical
native `phiddrop.sql` intentionally drops it. Its source statement is checked;
there is no wildcard grant. Cleanup counts native-dropped owned schemas
separately.

`--profile push-groups-regression` exercises the actual 2014 PHP migration on
three synthetic log rows, including two differing rows in one group and one
existing event. The old `SELECT * GROUP BY transactionKey` actually fails with
1055 under `ONLY_FULL_GROUP_BY`. The repaired query selects `MIN(id)` per group,
then orders those real rows by ID, making the representative metadata
explicit. The test checks the earliest row's metadata, shared event linkage,
and preservation of the existing event. SQL mode is not relaxed. The change
only affects this historical one-time migration, not native runtime policy.

The next flat-root source slice additionally reads and fences the actual native
`parentProjectPHID` index range; cached `hasSubprojects`/`hasMilestones` flags do
not prove child absence. For an actual child-free root, direct member edges
(type 13) and materialized members (type 60) must agree. Inconsistent source
state is refused without repairing permissions. Its real-editor negative cases
and independent child-producer wait cases have matching bounded runtime evidence;
they do not certify all hierarchy mutations or general raw membership producers.

Mandatory installation regression cases run after both actual fresh workflow
profiles: exact generation keys must match the runtime source catalog, then an
actual missing generation row and actual missing table must each reject native
ConfigEntry writes while optional features are off. Native configuration data
and transaction/lock levels must remain unchanged; the isolated fixture restores
the deliberately removed metadata before cleanup. These tests supplement the
focused MariaDB 10.11 compatibility receipt for the unchanged always-on config
and legacy-role paths; they do not claim optional execution support on MariaDB.

### Native self-membership lease slice

Self-join and self-leave retain the native capability distinction: joining
requires current native JOIN, while leaving a membership-locked project still
requires native EDIT. They do not create or consume a fictitious high-risk
approval. Real credential/account/config/Space sources are locked and the
original editor's capability and MFA checks still run. A private editor effects
nonce, created only at the actual post-validation phase, binds the narrow
membership lease.

The raw edge editor validates membership specs before `writeEdgeData`. Only the
exact direct/inverse pair for the acting user and target project, with no edge
data or mixed specs, may borrow the live Project writer. The qualified native
People edge table must be on the verified same primary and InnoDB; this keeps
13/14 and native derived 60 in one actual transaction. Other edge operations and
feature-off behavior retain their original route. No public connection getter
or caller `approved` boolean grants this lease.

The native materializer may change only the expected acting-user delta inside
that lease. Without it, only a current, locked, child-free root with no derived
permission delta and no hierarchy-flag transition may run. A repair that would
change permissions is refused. This does not implement administrator-driven
membership approvals, subproject/milestone mutations, all native raw SQL
producers, or the full native permission model. Native postcommit effects remain outside
the main mutation's transaction; no whole-pipeline atomicity is claimed.

The isolated native-editor cases cover join without EDIT, leave, raw source
rejection before edge-data writes, actual inverse and derived SQL-trigger
failures, and recovery. Fault comparisons include direct/inverse/derived rows,
native records, publishing rows, approval rows, and audit rows. The 225-check checkpoint covers this lease. The later matching 238-check fixture
adds 13 actual configured-TOTP checks; it does not retroactively broaden the
older MariaDB or bootstrap receipts. See the private source-bound receipt index.

`project-mfa-cases.php` uses actual native configured TOTP and real authenticated
requests. It covers cold challenge, incorrect code, workflow/session binding,
valid one-use token, replay, native write-fault rollback and current-role refusal.
This is not browser enrollment or certification of every factor type.
