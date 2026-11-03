# Portable private-mail CI candidate (test code only, not yet wire-validated)

The local Linux entry removes the prebuilt Podman pod and private developer
workspace dependency. It uses CI's actual PHP 8.5.12, Python, existing disposable
MySQL service and explicitly hashed executables. It creates no database server,
container, public port, production route or fake callback. Native full-schema
fixtures alone create/drop their random owned databases and scoped users.

`run_pending.py` prepares a genuine native People transaction in a hold process,
starts independent loopback PHP HTTP and the real paired Go mailer/observer, and
uses the native helper's real standalone Owlmail capture. It prepares an actual
immutable snapshot, checks a source-pending callback cannot send/quarantine,
then commits, rolls back/ages, or revokes the recipient/admin. Retry must invoke
the real native callback. Commit checks exact native MIME and one-send replay;
permanent denial must produce no SMTP/provider fallback. Owner signals do not
execute SQL in the parent. It checks native schema/user/reader plus queue,
Owlmail and file-storage cleanup and stops only its process groups.

## Explicit dependencies

The source checkout must be the committed **main95 + N11 + additive wire helper**
union, not this base branch by itself. Do not copy whole historical fixture/CLI
files over main. The source revision, tracked-clean check and required source
hashes bind the candidate. Missing pending mode/source or native runtime failure
is a test failure, never a skip. The harness never loads old result artifacts.

Five binary roles are required: `file`, `taskqueue`, `mailer`, `observer`, and
`owlmail`. The first three must share the immutable paired Gorge source revision;
Owlmail may use the explicitly approved v0.14.0-alpha.1 source revision. Each
manifest entry has `path`, `sha256`, `source_revision`. Observer revision must match the Phorge helper checkout.
These are CI build inputs
and expected hashes, not a claim that an unexamined binary proves its source.
Build from pinned reviewed checkouts and retain build provenance. The observer
source here is byte-identical to the separately reviewed strict native-response
observer; its 11 classifier subcases do not substitute for real wire execution.

Source/binary hashes and tracked-clean source revision are checked before and
after the wire case. These are bounded integrity observations, not an atomic
filesystem snapshot or binary-signature attestation.

All new service listeners bind `127.0.0.1`, with four ephemeral parent ports plus
native-helper-owned file/queue/Owlmail ports. HTTP refuses redirects, proxies,
foreign hosts and unbounded bodies. The existing CI MySQL service is reached
only through 127.0.0.1; this harness does not attest or change its preexisting
Docker publishing/firewall. No `-p` or engine API is used.

## Invocation after source and binary builds

Create a mode-0600 binary manifest from the actual pinned builds. Run
`make_config.py --source-root <committed-source> --source-revision <sha>
--binary-manifest <private-manifest> --gorge-revision <paired-sha> --php <php>
--expected-pure-methods <exact-current-count> --output <new-private-config>`.
No database password is placed in that config; the selected environment key is
read only for the fixture process. Set `ATOMIC_TEST_DISPOSABLE=1`, then run
`run_pending.py --config <private-config> --case commit --output <new-receipt>`
and separately `rollback`, `recipient-revoked`, `admin-revoked`.

Only the sanitized final receipt is suitable as a CI artifact. Do not upload
owner directories, ready/router/control files, raw logs, MIME, codes, passwords
or tokens. Success removes its owner directory after native cleanup is proved.
Failure retains private evidence if cleanup is unproved and cannot pass.

## Current evidence and remaining gates

Eleven Python tests actually passed with zero skips in immutable official
Python image `python@sha256:7b58e4266b99c869d2225c050345f7c7fec255d9e3baeca19ea7de9411411c91`
(Linux, Python 3.13.18, non-root, no capabilities, no network, read-only root).
They include real exited-leader/live-orphan cleanup, unknown orphan refusal and
TERM-resistant process KILL/reap. Linux signals/reaps use held pidfds after
SID/UID/starttime/private-nonce ownership checks; a reused numeric PGID is never
a signaling authority. Unknown groups or unreaped zombies fail and retain
private evidence. The observer's existing 11 classifier subcases also passed
with Go 1.27.3 on the local host. The final portable entry also ran four genuine Linux-local PHP/MySQL/Go/Owlmail
wire cases against committed Phorge 9c9cdf455b22575cd357de2b8c592cb38875513e
and clean paired Gorge 7cd64becdad83177a3d4f6568a2de5eb0736aac6: commit25,
rollback27, recipient-revoked20, admin-revoked20 assertions. Each case verified
the actual197-method PHP8.5.12 pure suite, source/HTTP callback classifications,
SMTP capture behavior and native cleanup. All8525 tracked standalone-source
files matched the read-only runtime volume. Four owner directories were removed
with zero live children; the outer disposable container and two owned volumes
were removed. No browser or deployment is proved. Previous Podman107/139
receipts are separate evidence and are not reused as this entry’s result.
The original139 security/unknown-outcome/provider-fallback business matrix is
now ported and independently executed as141 assertions in the full matrix entry.
The four-case entry retains its narrower scope and does not claim that full matrix. CI workflow registration must wait
for final independent review and integration against the selected current
source/binary builds. No new workflow is enabled by this candidate.

## Full provider and security matrix (new candidate, not yet executed)

`run_matrix.py --config <private-config> --fault-manifest <private-manifest>
--output <new-private-receipt>` ports the real business/security assertions from
the separately validated parent matrix. It uses the same owned local runtime
and committed-source preflight; it does not invoke Podman or replay receipts.
The additional fault manifest has exactly path/sha256/source_revision/source_sha256,
is mode0600, and binds the actual executable and committed `smtp-fault.go`.

Coverage includes ordinary MIME/replay/source revocation/message mutation,
451 envelope refusal followed by a second current native authorization before
provider fallback, DATA final-ack loss entering terminal unknown without retry
or failover, 503/redirect/missing-allowed/missing callback configuration, and
seven real security producer positives plus nine source mutation negatives.
Positive MIME is compared to native subject/body/To/Cc with no Bcc, added HTML
or attachment; challenge routes must survive. No fake callback is used.
All stages use real HTTP status/response classification and actual capture counts.

The full matrix actually passed141 assertions against committed Phorge
`60d4c107bf8fef04cead67650eddc08fffeed0dd` and paired clean Gorge
`7cd64becdad83177a3d4f6568a2de5eb0736aac6`. All16 native security flow cases
passed, as did current authorization before fallback, DATA-ack terminal unknown,
transport/configuration refusal and exact decoded MIME/To/Cc checks. The four
pending scenarios were also freshly rerun on that commit (25/27/20/20 assertions).
Each case verified the actual197-method PHP8.5.12 pure suite. All8535 tracked
standalone source files matched the read-only runtime. All27 registered child
processes were handled, no live/private owner directories remained, all five
native cleanup receipts passed, and the outer container and two owned volumes
were removed. The final Linux nonroot/no-cap/no-network unit run passed14/14
with zero skips. These are current isolated results, not a replay of old9c evidence.

Hosted CI registration/execution, final integrated source review, browser and
deployment acceptance remain separate. Historical139 is a coverage reference,
not a constant substituted for executed assertion IDs. No workflow is enabled.

## Hosted workflow candidate (not yet executed)

`.github/workflows/private-mail-wire.yml` runs on pull-request changes to the
native source, SQL/runtime dependencies, governance tests or this workflow;
manual dispatch remains available once the workflow is discoverable. Draft PRs
are included, so evidence can be obtained before marking a PR ready. A per-PR
concurrency group cancels superseded runs, and the job has a 40-minute ceiling.
Only the latest completed run can provide evidence; a cancelled run is never a
pass or a cleanup attestation. GitHub owns teardown of the disposable runner and
service after cancellation.
It pins the publicly fetchable Gorge candidate
`b58fe520085f4a81a39518e6bc3c408fe7fc4e21`; testing that commit does not authorize
its merge or release. The historical local `7cd64...` build is not used.
Owlmail is pinned to the already tested immutable source commit. The workflow
builds the three Gorge services, Owlmail, the native observer and fault server
from those checked-out sources. It prepares a standalone current-commit clone,
compiles native dependencies there and rejects tracked map drift.

`run_hosted.py` serially executes four pending cases and the provider/security
matrix. It uploads only five sanitized final JSON receipts. Private binary
manifests, owner directories, raw stdout/stderr, MIME and callback tokens are
outside the artifact glob. Failed subprocesses keep their owned diagnostics
private and fail the job; missing receipts cannot promote the job to success.
This workflow has not run on hosted CI. It adds no required branch-protection
check and does not make the draft or any production feature ready.

The pull-request workflow uses only `contents: read`, no deployment secrets,
no `pull_request_target`, and no write token persistence. Forks use the same
read-only profile and public immutable dependency checkouts; GitHub's first-time
contributor approval policy still applies. The database password is a fixed
public disposable-test value, and its service is explicitly published only on
127.0.0.1. A fresh private standalone clone contains the actual checked-out PR
merge commit, not a caller-specified production source directory. No completed
fork or main run has yet been observed for this candidate.
