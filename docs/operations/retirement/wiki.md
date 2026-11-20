# Native Wiki retirement

Status: implemented. Deployment is a separate operation and is not authorized
by this change. Exact-head CI and review are required before merge.

The native Phriction application is being removed. A separate product will
provide Wiki functionality. This change does not select that product, migrate
content, or rewrite links to an external service.

## Runtime scope

The application, controllers, Conduit methods, search engines, typeahead,
Herald adapter, transaction editors, markup rule, help page and stylesheet are
removed. Shared Remarkup and governance code no longer constructs Wiki objects
or advertises Wiki content grants. Task and project permission semantics are
unchanged. Optional governance features remain disabled.

Historical Wiki mail is rejected at authorization, including when the optional
private-mail policy is disabled. Existing messages already delivered to a mail
recipient cannot be recalled. Prepared-delivery quarantine is covered by the isolated SQL/HTTP fixture
with the optional policy off and on. This does not prove external SMTP delivery.

Ponder's answer-Wiki transaction is unrelated to Phriction and remains.

## Data and rollback

No database, document, attachment, audit row or backup is deleted. Historical
SQL patch identifiers remain. The seven PHP patches use fixed SQL table names
instead of removed application classes, retaining the original transformations.
The isolated restored-schema fixture executes all seven against nonempty
synthetic tables; final exact-head integration remains a merge gate.

The existing schema checker may report preserved Wiki tables as surplus. Do
not remove those tables to silence the warning. The release procedure must
verify the preserved schema and attachment references before any deployment.

A rollback must restore the matched application and worker versions as well as
configuration. Retain a consistent database and attachment backup. This branch
does not execute a rollback, reset a patch ledger or change active services.

## Evidence and remaining gates

`wiki.json` records removed paths, original hashes and retired native unit cases.
Retired cases are not counted as passing tests. The renamed mixed task-policy
case retains its task assertions; the task/project cases remain active.

Historical checks on the retired PHP 8.5.12 baseline (superseded; not current
release evidence):

- 56 affected native unit cases passed in an isolated PHP 8.5.12 container.
- Runtime discovery found no Wiki application or Wiki Conduit methods; task and
  project applications and `maniphest.search` remained available.
- 47 CI policy tests passed, including the explicit retirement inventory,
  rejection of restored retired paths, and historical hash checks.
- 91 operations tests passed, including five retirement tests. The mail fixture
  checks both values of the optional policy flag without sending mail.
- Modified PHP files passed syntax checks and `git diff --check` passed.

These local results do not replace exact-head CI. The merge gate requires the
complete native regression, paired runtime contracts, operations checks and
resolution of all actionable review findings. Retired cases are not reported as
passing coverage.

The strict source inventory retains original hashes and rejects unexpected
missing files, additions and restored Wiki runtime files. Historical source text
is available only to isolated static DATA inventory tests. The retirement
inverse is bidirectional and does not extend key trust or execute old Wiki code.

## Integration progress

Before the security-baseline update, scoped PHP 8.5.12 runs passed the
following checks. These records remain historical and do not qualify 8.5.13:

- the complete pure native governance suite (276 methods, PHP 8.5.12);
- 81 native-source and 43 native-policy SQL checks;
- 27 read-set and 66 native-pipeline SQL checks;
- 109 retained Completion checks using the pinned Gorge file and queue services;
- six real HTTP requests with a native synthetic session: three retired paths
  return 404, and task detail, task list and project list return 200;
- persisted historical Wiki mail quarantine with the private-mail policy off
  and on, retaining the original stored delivery snapshot;
- all seven historical PHP migration patches against synthetic nonempty tables,
  preserving two documents, two content rows and two mail-property records.

Each SQL run used disposable storage with no public listener. These are scoped
results, not a substitute for the final exact-head CI and source-owner checks.

The merged Almanac fix changes one existing caller candidate hash. The caller
snapshot is updated to those reviewed bytes; candidate identities, static-only
triage, blockers and activation constraints are unchanged.

## Current security-baseline evidence

The pinned PHP 8.5.13 runtime has passed 276 native governance unit methods,
109 retained Completion SQL checks, six HTTP checks, prepared-mail quarantine
with the optional policy off and on, and all seven historical PHP migration
patches against synthetic data. The migration checks retained both documents,
both content rows and both mail-property records. The runtime rejects 8.5.12;
JIT is disabled. The 47 CI policy checks also passed.

Workflow composition checks bind the current workflow hashes and reconstruct
historical YAML only as inert comparison data. The four inherited media
fixtures are checked against their actual admitted Task predecessor rather
than an unrelated older OAuth baseline. Current source admission and all
negative tests remain required. Full exact-head CI and final review are still
pending; this record does not claim merge readiness or deployment.

The retired-schema manifest is version 2. It explicitly retains the ten Wiki
tables present in the tracked quickstart schema. Fresh storage initialization
and restored storage must pass the ordinary schema verifier; unknown tables
and current schema requirements remain errors. Document, content, search,
transaction and edge tables are retained as historical data.

Local PHP 8.5.13 storage verification returned zero for fresh initialization,
repeated upgrade with populated historical Wiki tables, and upgrade after a
logical restore. The document, content and audit rows remained byte-identical.
The retirement native runner passed 66 methods, including the closed table
manifest, rejection of unknown Wiki tables and active-schema precedence.

A real CLI negative check also created one unknown table in the disposable
retired Wiki database. Ordinary `storage upgrade` reported that table as
surplus and returned 2. Removing that test-only table restored exit 0, with
all historical sample rows unchanged. The planner now propagates unknown
table errors even when the retired database has no current SchemaSpec.
