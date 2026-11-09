# Native directory transport matrix

This test-only phase exercises the current native Web controller and real
`ConduitCall` dispatch for `people.organization.query` / `.edit`. It changes no
production class, permission, route, map or default feature setting.

Root/CI may run `support/governance/native-organization/test-directory-transports-v1-mysql.php` with the single fixed argument
`--directory-transports`, using the driver's existing explicit isolated-test and
private fixture DSN/credential environment. The driver retains its PHP 8.5.12,
loopback, no deployment selectors/config, owned full source restore, principals,
writer routing and complete fixture cleanup checks. This successor keeps media exchange,
notification delivery, uploads, deletion outbox and all non-File Gorge routes
disabled. It starts exactly one owned loopback core Gorge File storage service
for real native avatars; no application HTTP listener, worker or browser starts. The original driver is byte-exact Git149 and its default/all phases are unchanged. This v1 entry rejects empty arguments and every other selector. Its existing full restore/cleanup lifecycle is copied from149 with an explicit inverse; the old literal source maps need no repinning.
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

`tests/governance/native-directory-transports-source-v1.json` fixes 58 exact
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
then invokes only the fixed new CLI. It uses only the exact paired File fixture described below; no image backend or application listener runs.
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


## Safe failure observability successor

PR135's initial candidate703 exited nonzero in the actual SQL step; its
success-only artifact contained no failure detail. The cause is not inferred
from that exit. This successor does not change matrix behavior or make the
failed run pass. The fixed CLI catch and existing cleanup-error callback emit a
separate bounded diagnostic marker containing only an allowlisted exception
class, repository-relative PHP source path, positive line number and fixed
progress label. Unknown classes/stages and outside paths use explicit fixed
fallbacks. No message, stack, arguments, SQL, DSN, username, session or token is
emitted by this helper. Its optional diagnostic failure cannot replace the
original exception/failure state.

The failure projector reads at most1MiB from a held, regular, single-link,
NOFOLLOW/nonblocking stderr FD with a final identity/stamp check. It ignores
unmarked raw lines without copying them, then strictly validates every marker's
six fields, literal class/stage allowlists, current Git-tracked regular PHP path
and actual public file line bound. Escapes, absolute/private/untracked paths,
extra/missing/duplicate fields, duplicate markers, trailing payload and oversized
input fail. At most eight distinct primary/cleanup clues can be projected. A
source-unknown clue has line0. These are debugging clues, not independently
attested causes or SQL acceptance.

Only after the projector's current-source admission and validation succeeds
does the workflow publish `native-directory-safe-failure-v1/diagnostic.json` on
failure. Raw native stderr and failed integration.json are never uploaded or
printed; no `cat` of stderr exists. The original native nonzero exit is retained.
An absent/invalid marker yields no diagnostic artifact, not a guessed result.
The old success consumer and acceptance criteria are unchanged. The source
inventory is versioned with the four added diagnostic roles (36total); old
maps/consumers and the original driver stay immutable.

Eight additional local tests include a real native request-normalizer exception
through the PHP diagnostic helper and Python projector, message/canary refusal,
path/key/type/stage injection negatives and actual FIFO/symlink/hardlink/size
refusals. These plus the existing15 local methods are source/host evidence only;
no SQL/remote execution or root-cause claim is made by this change.

### Preserve the native exit if workflow-output metadata cannot be appended

The first diagnostic successor had an unguarded `GITHUB_OUTPUT` append under
Bash errexit. A failed append could replace native exit37 with exit1. This narrow
successor guards the optional append (including an unset/empty variable) so the
handler always reaches its original `exit "$native_status"`. It neither changes
the SQL fixture nor treats a missing diagnostic output as native success.

Five pure-shell tests extract the actual workflow handler. A successful
projector is an explicit test collaborator; native37, Bash `set -euo pipefail`,
mkdir/mv and the output append are real own-filesystem operations. The old
unguarded line is reconstructed only in the red control and actually returns1;
new unset/empty/directory/valid output cases all return37. No SQL/projector
runtime acceptance is inferred from the shell collaborator. The previous
source and test freeze stays unchanged.


## Real paired File and native-avatar dependency successor

The actual safe failure on bb5 identified `PhabricatorFile.php:369` after the
admin projection. Source order puts the first real Conduit call before the Web
call: native token authentication loads its owner via ObjectQuery, the User PHID
type requests profile images, and a fresh user's image cache generates/persists a
native default avatar. With `gorge.file.uri` null, no production engine can write;
historical PHP blob/local-disk/S3 engines correctly remain read-only. This is a
missing fixture dependency, not a directory authorization change. The safe clue
does not include a full runtime trace; the chain is derived from exact149 source.

The new scope is `owned-native-directory-transport-matrix-with-paired-file` and
its native schema is `native-directory-transports-v1-file-dependency`. Old
no-File receipts cannot satisfy it. Only this isolated phase binds one fresh
paired Gorge File service using the existing core fixture start/stop helper.
The File-only builder reuses exact-pair/clean-checkout checks and the unchanged
locked-base inspector, but issues only `SERVICE=gorge-file-storage`; it does not
build the ImageMagick/image service. CI extracts from the inspected immutable
local File image without starting that extraction container, hashes the actual
binary, and removes that exact extraction container. Build/pair/bases/image/hash
provenance is retained. The old general builder and its consumers are unchanged.

All seven synthetic users receive real `updateUser()` avatars. Fresh User and
File DAOs must agree on the stored profile/version and `gorge` engine. A fresh
File DAO reads the actual Gorge bytes back; byte size, native content hash and
actual 400x400 PNG decode must pass. No fake cache, made-up PHID or legacy writable
engine is used. Native GD remains the existing `legacy` builtin compose mode;
no ImageMagick/image backend, media exchange, uploads or delivery option is enabled.
Native authentication may write profile/cache data: these route checks are not a
claim of globally read-only SQL.

The strict consumer requires all seven avatar witnesses plus 130 actual checks,
exactly one File startup, actual hashed Linux File binary input and successful
process/storage cleanup, in addition to the unchanged 45 read observations,
five sensitive-action denials, two ordinary additions and full SQL cleanup.
The binary parser's header-only ownFS unit fixture is data admission only, not a
usable Go program or runtime/source attestation. CI's actual native command and
paired builder are mandatory for real evidence. Safe failure diagnostics and
native-exit preservation are retained unchanged apart from the explicit new
fixed progress labels/source roles.

The static Calendar/Conduit preservation test admits exactly this one new
workflow path in its diff-set. Its original three workflow checks, production
source assertions and inverse checks are retained; no wildcard exclusion or
runtime Calendar phase is used by this change. Actual SQL remains pending the
next public CI run; the prior failed artifact is preserved and never relabeled.


## Safe terminal diagnostics after the File-dependency run

The f9d0 public CI log establishes successful File build/extraction and a
nonzero selected step, but does not identify which terminal check failed.
Captured runtime-warning rejection occurs after the Throwable catch; it now
emits a fixed `RuntimeWarning` / `unexpected-native-runtime-output` marker with
unknown source and line zero. This is a classification, not attribution of the
original warning. No warning message or buffered native output is copied.

Three explicit terminal paths (`native-exit`, `native-stderr`, `strict-consumer`)
now use the same guarded safe projection before returning their original exit
status. When no marker exists, only that fixed path and bounded held-file byte
counts/digests of private stderr/receipt/consumer output are published. Malformed
or extra markers still fail strict projection; they cannot become hash-only
fallback success. Every diagnostic remains non-accepting and cannot replace
source, matrix, storage, permission or cleanup checks. Raw failed receipts and
stderr remain outside uploaded artifacts. The prior failed run is retained;
its actual failure cause and selected SQL result are still unknown.

Local controls execute the exact PHP warning branch and all three extracted
Bash terminal blocks using explicit synthetic native/consumer exits and real
private files/readers/projector. Current Git source admission is a stated
collaborator in that shell control; the production CLI still enforces it.
These controls perform no SQL, File service, Docker, SSH or application network
execution and cannot stand in for the next public CI result.
