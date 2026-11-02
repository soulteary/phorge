# Isolated native authorization-source observation (N07)

This is a real-table reader, not a renamed synthetic epoch fixture. It reads
selected native columns from the repository's quickstart table definitions using
one private MySQL connection across the native application schemas. It does not
bootstrap the application, load deployment configuration, execute a transaction
editor, write membership edges, register a policy or authorize a target write.
The original four project axes and optional-feature state are unchanged.

## Guarded entry point

`PhorgeGovernanceNativeSourceReader::newFixture(config)` requires supported PHP
8.5.x (at least 8.5.12), CLI, explicit isolated-test opt-in, loopback, and a
`governance_native_<16 random hex>` namespace. It refuses application/deployment
selectors and local deployment files at construction, every begin and every
finish. Opt-in withdrawal after begin therefore refuses finish and rolls back.
No caller-provided connection or transaction is accepted. Limits can only be
reduced: 64 rows per ordinary table, 256 per membership edge table, 262144 bytes
of selected row data in total, and a five-second inter-statement budget. SQL
also has a SELECT timeout reduced to the remaining budget and a read timeout
no greater than the requested budget. Budget is rechecked after each read and
before/after capture construction; these are bounded
observation safeguards, not a new production revocation SLA.

The factory checks actual `SHOW GRANTS`. Only USAGE plus SELECT grants on each
of the eight exact owned application/guard schemas are accepted. Database
underscores must be escaped to avoid wildcard grants. Global SELECT/ALL, role
membership, mutation rights, GRANT OPTION, additional schemas or missing read
grants are refused. A Boolean read-only flag is not a privilege proof.
The actual test principal's failed INSERT/UPDATE/DELETE/ALTER/DROP/TRUNCATE checks
belong in the native fixture harness, separately from this static grant check.

An owned guard table must contain the native-source marker and the SHA-256 of
this checkout's `resources/sql/quickstart.sql`. The harness extracts original
DDL, replacing namespace/charset only, and records that source hash. The reader
also checks required native columns/types and InnoDB engines. A marker is
accident prevention and fixture lineage, not proof against a hostile setup
administrator. No production schema patch is registered.

## Actual native sources

`getNativeTableContract()` is the fixed table/column allowlist:

- `user.user`: immutable identity and current admin/approval/disabled/system/
  mailing-list flags, modification time, and an SQL digest of account secret.
- `user.phabricator_session`: user binding, kind, start/expiry, partial state and
  SQL digest of the stored session key. No browser session token is read.
- `user.edge` and `project.edge`: native forward/inverse/materialized membership
  constants 13/14/60, endpoints and metadata references. Historical edge origin
  is not inferred and no materialization is performed.
- `project.project`: current policy, parent/milestone/member-index settings,
  Space, status, bounded properties and the actual query indexes
  `projectPath`, `projectDepth`, `projectPathKey`. Binary path/key bytes only
  participate through SQL digests. Their observation does not certify the
  native ancestry evaluator or index materialization. Descriptions/names are
  not exported.
- `maniphest.maniphest_task`: policy, owner/author/Space and modification fields,
  SQL digests of title/body, and bounded properties.
- `phriction.phriction_document`: actual slug/depth/policy/Space/content pointer
  and version fields; no Wiki body or title is returned.
- `policy.policy`, `policy.policy_namedpolicy`: ordered rule input, defaults,
  effective-policy references and version fields, without names/descriptions.
- `spaces.spaces_namespace`: policy, default/archive state and version fields.
- `config.config_entry`: only application settings, policy locks/public access
  and account-approval settings. Local/static precedence is not certified.

The adapter deliberately observes bounded shared tables conservatively. Updates
to unrelated observed rows can invalidate a fingerprint. This is an initial
bounded observation scope, not a production-wide scalable dependency algorithm.
A missing row, unsupported policy rule, malformed rule set, missing policy
reference or project ancestry cycle is reported explicitly. Over-budget, missing
schema/columns or non-InnoDB storage refuses the entire capture. Long policy/
configuration/property values are SQL length-guarded before fetch. Titles,
descriptions and account/session secrets are only selected through SQL SHA2.
Other string columns must have a bounded varchar/varbinary type. Engines are
checked after SELECT, while its metadata lock is still held, so a table cannot
be accepted after a precheck-to-read nontransactional conversion race.

## Consistent observations, not a writer lock

`capture(binding)` returns `PhorgeGovernanceNativeSourceCapture`. The binding is
the existing operation/actor/executor/approver/target/parameter-digest/session
contract, with real native PHIDs and 64-hex SHA-256 digests of stored session keys.
The binding does not carry a raw stored key or browser cookie. The SQL-selected
SHA2(sessionKey, 256) is compared directly to that digest (no double hashing).

For deterministic fixture interleaving, `beginCapture(binding)` opens a new
REPEATABLE READ consistent snapshot with READ ONLY, then reads `user.user`.
`finishCapture()` reads the other schemas on the **same connection and same
snapshot**, builds immutable observations and rolls back. A second privileged
fixture connection may commit changes between those methods; the open snapshot
must remain internally consistent, and the next capture must observe the new
committed state. There is no arbitrary callback or public raw SQL/connection
handle. No lock is held across a business write, and a snapshot does not prove
that authorization remains current at some future execution time.

The capture's restricted `getSnapshot()` / `getSources()` contain principal and
session references for internal comparison only. Do not publish them. Public
`getSummary()` contains logical table row counts and aggregate fingerprints,
schema/quickstart hashes, observed/unobserved counts, and fixed unknown codes;
no raw native rows, namespace, principal/session ID, policy value, credentials or
SQL exception text is included. SQL failures become fixed safe reason codes with
no previous exception attached. Cleanup failure refuses the result. The factory
and capture use strict mysqli reporting in their explicitly isolated CLI process.

## Eligibility and remaining gaps

Account/session eligibility is a **row-level observation only**. A matching
stored user/session reference and live expiry do not validate the account-secret,
session-key/HMAC protocol, full identity entry admission, browser credential,
legal agreement, MFA or effective native object authorization. Those contexts
remain unverified. Session values are not turned into a new authentication token.

Actual required-source revisions come from selected native rows, never caller
revision strings. Organization runtime sources remain explicit unknowns. Native
rule execution, automatic capability evaluation, full transitive closure,
application/default/local-configuration precedence and cross-component session
semantics are not proven by this reader. Consequently every capture is
`native-observed-blocked`, `native_closure_complete=false`,
`execution_atomicity_proved=false`, `can_execute=false`. Even a stable native
fingerprint is not complete authorization or a grant. The old validator bridge
must stay blocked on the unknown sources. Capture value objects reject attempts
to claim complete closure or execution.

Pure native tests cover input/budget constraints, reference detachment, real
session table naming, pre-connection deployment-scope rejection and non-executable
capture values. They are not database acceptance. The separate harness must use
actual quickstart DDL, actual SELECT-only grants and genuine second-connection
commits, including missing table, MyISAM, schema/byte/row overflow, unknown rules,
scope withdrawal and consistent-snapshot/fresh-capture tests. No executor,
production deployment, new domain, permission migration or feature activation
is implemented here.

## Batch 4: selected native rule and hierarchy observations

`PhorgeGovernanceNativeRuleHierarchyVerifier::observe(binding, viewer, object,
ruleClass, value)` is an isolated fixture observation, not a policy evaluator or
executor. It accepts exactly Users, Projects, ProjectsAll and ProjectMembers
native rule classes, at most 16 distinct typed PHIDs (ProjectMembers uses an
empty value), and an existing exact project/task/wiki target bound to the actor.
It verifies the existing owned Context, captures the bounded native sources,
and blocks unknown/ineligible account/session rows, unsupported policy rules,
missing references and parent cycles. Native PeopleQuery/ProjectQuery must find
all requested subjects: missing or filtered dependencies are unknown, never a
fabricated deny. ProjectQuery actually attaches parent, milestone and native
member sources; native `willApplyRules()` and `applyRule()` compute the reported
selected-rule result. Fresh native-loaded project targets replace caller
attachments. The observed parent chain is limited to nine nodes and membership
summaries to 256 rows per node. Public results contain only counts and digests.

An empty all-projects value may produce native `allow`, which is the actual
rule method's vacuous result. It never grants access. The selected rule/value
are explicit observation inputs, not proof that a stored object's entire
ordered allow/deny policy is represented. Non-project targets and the viewer
are provided native objects, not a fresh authenticated request. Row eligibility
does not validate the native session protocol. Existing global unknown reasons
remain: arbitrary rules, full policy composition, runtime configuration,
organization dependencies and execution remain unsupported.

The bounded source capture and native ORM rule/query calls use distinct
connections/read views. They are explicitly **not atomic**. A concurrent change
can alter the selected native result or grow dependencies after preflight;
post-query cardinality checks do not certify an execution-time memory or SQL
resource bound. This is a disposable, bounded-fixture test instrument, not a
production source adapter or proof that native rule closure is complete.
All results retain `native_closure_complete=false`, `risk_complete=false`,
`can_execute=false`, `execution_atomicity_proved=false`, and
`source_and_native_observation_atomic=false`. No application registration,
router, schema migration, policy mutation or native transaction executor is
introduced. The prior Reader's unknown reasons are intentionally retained.

The Projects/ProjectsAll rule membership query uses materialized-member edges
on the selected project's own PHID. It is distinct from the milestone object's
automatic parent-member capability and from ProjectMembers' inverse-edge rule.
These observations preserve differences instead of normalizing them into a new
permission model. Structural checks accept the canonical decimal string form
of native Lisk depth hydration, while rejecting malformed numeric strings.
