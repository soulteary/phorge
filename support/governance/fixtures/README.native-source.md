# Batch 3: source-derived native DDL and SELECT-only observations

This is a one-shot, explicitly disposable PHP/MySQL fixture. It is not a complete
restored Phorge instance, a migration, a production SQL patch, a route, a member
writer or a transaction executor. No deployed connection settings are loaded.
The existing four native axes and disabled optional-feature state remain intact.

## Dependencies and command

Use the retained runtime from this checkout under PHP 8.5.12, with `mysqli`, a
fresh library map, and isolated MySQL/InnoDB. The disposable runner must have no
public ports and must only be reachable within its owned test namespace. The
setup account needs create/drop database and create/drop user/grant rights on
that disposable server; its credentials must come from private environment,
never committed or emitted as receipts.

```sh
PHORGE_GOVERNANCE_ISOLATED_TEST=1 \
PHORGE_GOVERNANCE_FIXTURE_DSN='mysql:host=127.0.0.1;port=3306;dbname=governance_fixture_bootstrap;charset=utf8mb4' \
PHORGE_GOVERNANCE_FIXTURE_USER="$ISOLATED_MYSQL_ADMIN" \
PHORGE_GOVERNANCE_FIXTURE_PASSWORD="$ISOLATED_MYSQL_PASSWORD" \
php support/governance/fixtures/test-native-source-mysql.php
```

The bootstrap database name only validates the explicit loopback test input.
The fixture creates its own random `governance_native_*` namespace; it does not
use or alter the bootstrap schema. Local/deployment config files and deployment
selector environment variables cause refusal. Unsupported PHP is refused,
including for the SQL fixture. Do not bypass the version guard with a desktop
runtime merely to obtain a green test.

## Source-derived schema and synthetic facts

`PhorgeGovernanceNativeFixture.php` extracts the exact current `CREATE TABLE`
definitions from `resources/sql/quickstart.sql`, substituting only the owned
namespace and charset/collation placeholders. It creates twelve source-derived
tables over the user/project/maniphest/phriction/policy/spaces/config schemas;
a separate governance schema holds the ownership contract marker and the
quickstart SHA-256. Each source definition hash is returned in the safe receipt.

There is no hand-written replacement for native user, session, project, edge,
task, Wiki, custom/named policy, Space or configuration columns. The test also
compares ten model `getSchemaColumns()` sets to the actual SQL column sets.
User/project edge tables use their source definitions. This is bounded current
DDL/model consistency, not proof that every historical patch or whole-instance
upgrade has been restored or replayed.

Synthetic rows include ordinary users, an administrator and a disabled user;
parent/child/milestone projects; native direct/materialized and inverse member
edges; an owner-held task; Wiki document/content; explicit Spaces and a custom
policy. Privileged setup writes only these newly owned databases. The fixture
never calls the production membership editor or native materializer to mutate
an existing instance. Old production relationships are untouched.

## Actual native methods, bounded context

For N05, SQL rows are hydrated into real native objects. Project member/parent
attachments come from the explicit fixture rows, not an invented permission
flag. The observation class calls the actual `PhabricatorPolicyFilter` and
native automatic-capability methods. Space/custom-policy queries use a separate
SELECT-only native connection.

Only an in-memory test configuration stack (native defaults plus the private
fixture dictionary) is injected with reflection. Normal environment bootstrap is
not invoked, so it cannot connect to an inherited default database before an
override. Policy/filter code and results are not substituted. Before each capability
observation, the dedicated context verifies the exact effective configuration,
actual scoped SELECT grants and fixture marker; it refuses inherited native
connection/namespace pools and isolates DatabaseRef/Space request caches. The
fixture includes stale underlying routing and poisoned-cache controls, plus
restoration of the original cache object and mysqli report mode. The prior native
environment static properties and request cache are saved and restored in
`finally`, including on failure; new native Lisk connections are closed. This
script is still one-shot and does not claim full application bootstrap, query
attachment completeness or arbitrary same-process application test isolation.

N07 consumes the real native rows through the independent SELECT-only reader.
Its public receipt has fixed reasons, counts and fingerprints, not raw data.
Its closure remains incomplete and `can_execute=false`. Custom-rule observation
is distinct from implementing native rule evaluation. Cross-schema consistent
snapshots are read-only observations, never locks retained through a native
business write or proof of cross-server atomicity.

## Assertions and negative paths

- The reader must reject INSERT, UPDATE, DELETE, ALTER, DROP and TRUNCATE with
  actual MySQL permission errors. A connection or syntax error is not counted
  as write rejection.
- Actual native methods show member view, ancestor edit, milestone behavior,
  task-owner automatic view/edit, restricted Space priority over automatic
  membership, and no universal administrator bypass. Custom and named project
  policy member/nonmember controls execute real native SQL policy queries.
  Native project paths use actual PHID-derived four-byte keys; parent membership
  is materialized only, without an impossible direct parent edge.
- Baseline fingerprints show that observers did not write native rows.
- A held private snapshot starts before another connection commits policy and
  owner changes; its finish remains internally consistent while the next fresh
  capture detects those commits. This does not imply execution-time revocation.
- The three bound native session sources must be eligible under SQL-digested
  stored session keys. The overall blocked result alone cannot hide a broken
  identity binding.
- Native path/depth/key changes affect observed hierarchy source revisions even
  if the parent PHID and policy values stay unchanged. A held-phase elapsed
  budget is rejected; no per-nanosecond completion guarantee is implied.
- Unknown native rules stay unsupported. Missing tables, non-InnoDB tables and
  native column-type drift, revoked isolation scope and lower row-budget overflow
  refuse the observation rather than shrink coverage.
- Connection settings, namespace, raw PHIDs and session keys must not appear in
  the public source-summary receipt.

## Ownership, cleanup and receipts

A database is recorded as owned only after successful CREATE; a failed create
cannot add a cleanup target. A random reader is recorded as owned only after
successful CREATE USER. Cleanup removes only those exact successfully created
schemas and that user, including when setup or assertions fail. The observation
adapters never receive setup credentials. Cleanup failure makes the run fail.

Output fields include `passed`, the executed check-name list and count,
`readerWritesPerformed`, `sourceDefinitionHashes`, `quickstartSHA256`, cleanup
counts and a sanitized failure stage/type/file/line. No password, SQL exception
message, raw rows or complete capability links are emitted. A failed or missing
check cannot be a pass; local fixture success is not hosted CI, production
acceptance or full native-source closure.

## Fourth-batch selected native branches

Run `support/governance/fixtures/test-native-rule-branches-mysql.php` in a
separate invocation with the same explicit isolated environment and disposable
MySQL principal as the third-batch runner. Its JSON receipt has
`batch: 4-native-rule-branches`; do not substitute it for the separate
third-batch receipt. Both runners must pass after the content PHID fixture was
corrected to the actual `PhrictionContentPHIDType::TYPECONST` (`WRDS`).

This runner extracts the same twelve source-derived quickstart table definitions;
it is a bounded subset, not a full restored deployment. Additional synthetic
projects distinguish type-60 materialized reads, type-14 inverse reads and
one-sided type-13 historical diagnostics. One-sided edges are deliberately
inconsistent diagnostic samples, not recommendations for membership writes.
The ten-node synthetic hierarchy tests this observer's stricter depth budget,
not a change to native project hierarchy semantics.

The selected native Users, Projects-any, Projects-all and object-members rules
are exercised through their actual `willApplyRules` and `applyRule` methods,
with real native queries. Empty Projects-all locally returns true; this does
not establish `ruleHasEffect`, ordered-policy evaluation or an authorization
result. Missing users/projects, unsupported classes/values, duplicate values,
value budgets, each role's expired session, malformed parent paths/keys/depth,
missing parents and cycles must remain blocked. Parent rows remain present
in the three independent path/key/depth corruption cases.

Wiki observations hydrate a real content row, attach it to the matching native
document and call the real editor batch expansion. Empty content adds delete;
move plus empty content preserves native expansion ordering. Missing or
mismatched content is blocked. No transaction is applied and no persistence,
notification, authorization validation or executor is provided.

Receipts include check names, exact source-definition hashes, quickstart hash,
SELECT-only write-refusal count and owned cleanup result. They deliberately
exclude raw PHIDs, content, session data and connection configuration. Source
capture and later native rule SQL do not share one atomic read view. Full native
internal-query resource limits, concurrent growth, complete ordered policies,
organization sources and execution atomicity remain unverified; all closure
and execution flags remain false. No production routing, configuration, schema,
permission model or feature activation is changed.

The milestone rule sample deliberately has no materialized membership row on the
milestone itself. The native Projects-any rule returns deny for that PHID, even
though native milestone automatic capability observation can use its parent's
members. These are distinct native consumers; this fixture preserves the
difference instead of synthesizing membership or changing policy semantics.

The Wiki fixture also loads `PhrictionContent` through the actual native Lisk
`loadOneWhere` text-protocol SQL path inside a proven owned SELECT-only
`NativeCapabilityContext`. It asserts the real stored version hydrates as the
canonical string `"1"`, while the prepared-mysqli control hydrates as integer
`1`. Both attached-content batches must succeed and report normalized integer
version `1`. The native context closes in `finally`; inherited pools are
refused before ownership and never cleared by this fixture.
