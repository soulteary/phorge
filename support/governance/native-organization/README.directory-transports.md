# Native directory transport matrix

This test-only phase exercises the current native Web controller and real
`ConduitCall` dispatch for `people.organization.query` / `.edit`. It changes no
production class, permission, route, map or default feature setting.

Root/CI may run `support/governance/native-organization/test-directory-transports-v1-mysql.php` with the single fixed argument
`--directory-transports`, using the driver's existing explicit isolated-test and
private fixture DSN/credential environment. The driver retains its PHP 8.5.12,
loopback, no deployment selectors/config, owned full source restore, principals,
writer routing and complete fixture cleanup checks. This phase keeps media
exchange, notification delivery and all Gorge routes disabled and starts no
listeners, workers or browser. The original driver is byte-exact Git149 and its default/all phases are unchanged. This v1 entry rejects empty arguments and every other selector. Its existing full restore/cleanup lifecycle is copied from149 with an explicit inverse; the old literal source maps need no repinning.
No credential belongs in an argument, checked-in file, test output or public log.

The matrix uses five freshly created native accounts and actual native web
sessions/API tokens: system administrator, ordinary member, parent-only manager,
child-only manager and separately appointed manager of both teams. Membership and
existing-appointment seeds reuse the reviewed privacy fixture helpers; those
seeds do not prove the appointment approval workflow. The query and transport
results are compared with explicit team IDs, parent redaction and counts, not
only with each other. Read modes cover list, selector, bounded export, count,
other-user teams, each team's members and member count. Hidden roster reads must
produce the generic Web 403 / Conduit error without a result.

Conduit authentication invokes the actual native controller's private
`authenticateUser` branch on a real token and request, then executes the real
`ConduitCall`; `setUser` alone never supplies authority. These are in-process
native calls, not an HTTP server or browser receipt. Web calls use the real
controller and the existing owned request write/CSRF guard. Expired/deleted API
tokens and unknown modes are explicitly refused.

Team-admin appointment grant/revoke, approval proposal and compound proposal /
execution are rejected through both transports, with all six organization
business tables fingerprinted before and after. Submitted tickets are real
system-admin proposals so missing ticket planning is not mistaken for an actor
refusal. Ordinary member additions succeed through each transport without an
appointment or extra sensitive-action approval. No native project permission
changes are performed.

## Local evidence and pending execution

`python3 -B -m unittest discover -s tests/governance -p test_directory_transports.py -v`
runs six real native class/dispatch admission tests without SQL. Only default
and memory configuration sources supply a synthetic cache namespace; Query,
Service, authentication, SQL and authorization are not replaced. The tests call
real query/edit methods, real ConduitCall parameter validation and the actual Web
feature-off and enabled-but-uncredentialed routes. They prove those refusal paths only. They do **not** run the
positive five-account matrix or establish SQL cleanup/transport privacy.

The first local run retained one failure because the test had omitted native
cache initialization before Conduit class-map lookup. The corrected test supplies
only in-memory defaults and a synthetic cache namespace. No production code or
assertion was removed to pass it.

The full matrix, exact signed candidate/full-source binding, actual PHP 8.5.12
and owned SQL cleanup remain unproved until Root/CI executes this phase. Existing
strict old receipt consumers are unchanged and must not accept this new scope as
old full-suite evidence. Source inventories/CI integration require a separate
reviewed successor; no source hash is blindly repinned here. This is not full
N01–N04, browser, performance, all-source or activation approval.

## Versioned source and CI binding

`tests/governance/native-directory-transports-source-v1.json` fixes thirty-two exact
source/dependency roles and the entire Git149 `src`/`resources` inventory. The v1
consumer requires its literal keyset, file lengths/digests, unchanged production
Git inventory and current worktree. In CLI mode every selected file and the
manifest must also exactly match the supplied current Git revision. A signed
candidate may be accepted before merge; this gate does not require main/merged
status or claim signature verification. Old source maps and all old receipt
consumers remain byte-for-byte unchanged.

The new workflow `native-directory-transports-v1.yml` runs on every PR and manual
invocation, uses the existing PHP 8.5.12 / disposable MySQL8 mechanism and two
standard mapper passes, performs source-only admission **before** the matrix,
then invokes only the fixed new CLI. It uses no Gorge binary/build or listener.
The strict consumer requires all 45 read observations, all five sensitive-action
negative results, both ordinary-add results, every actual check, the existing
full restore/migration proof, empty captured native output and completed
55-schema/two-runtime-user/reader cleanup. Duplicate/extra/missing/raw fields and
integer-as-boolean substitutions fail. JSON templates in unit tests are consumer
mutation fixtures, never actual SQL evidence. The pure `validate()` return marks
source binding false; only the CLI can set it true after actual Git checks.

Only successful selected runs upload integration/accepted/source/map evidence.
Native stderr is outside that upload tree; failure is nonzero and does not
publish an accepted artifact. No production credentials or deployment input are
used. The workflow is source-only until Root publishes the candidate and actual
CI completes. Its MySQL service and runtime setup are not executed locally here.
