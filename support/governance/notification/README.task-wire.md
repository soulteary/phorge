# N11 Task wire acceptance — queued source window

This is an **unexecuted source package**, based on the native main snapshot.
Pure tests are not SQL, worker, SMTP or browser evidence. New source/runtime
admission remains blocked until the complete candidate is reviewed and signed.
No production code, permission semantics or production flags are changed.

## Existing interfaces and precise extensions

- Existing `wire-hold.php`, `OwnedRuntime`, `load_config`, `preflight`, the native
  fixture driver and `owlmail-sink.php` own the isolation boundary. Existing
  `run_hosted.py` retains its five cases and its five binary roles byte for byte.
- New `--task-mail-wire-hold` is a separate native driver branch. It goes through
  the existing native runtime/source and fixture admission before creating the
  Task hold. Existing modes retain their original branch behavior.
- `task-wire-producer.php` has distinct queued and manual entry points. QUEUED
  mode creates a synthetic attachment and real Task create/title/comment/
  subscriber transactions, then binds the unique native publish task, actor,
  TaskData and xactions to its actual Gorge queue row. It does not publish.
  `governance_task_wire_assert_no_inherited_writes()` checks Task, File, Worker
  and MetaMTA writer handles **before attachment or Task writes**.
- The explicit MANUAL helper is retained for staged inspection. The queued hold
  never calls it. Enum modes prevent a manual return record being used as a
  queued record by the inspection/revocation functions. Neither mode is a
  substitute for fixture/source admission.
- The recipient is a subscriber; author is assignee. Native assignees have
  automatic VIEW/EDIT, so policy-only revocation of an assignee would be a false
  test. Revocation uses a real VIEW transaction and a fresh native query cache.
- `task-wire-protocol.php` adds bounded owner/sequence/action files. It reuses
  the reviewed Calendar held-FD, atomic no-overwrite publication algorithm.
  Only inspect/revoke are accepted; no arbitrary IDs, SQL or callback code.
- `task-wire-router.php` is a separate loopback authenticated fixture router.
  It exposes only existing mail.delivery and worker.execute; delegated worker
  calls must be capabilities or prepare/execute for the unique owned native
  publication task/class. Production worker and queue semantics are unchanged.

## Parent executable and source/binary contracts

`tests/governance/private_mail_ci/run_task.py` reuses the tracked hosted runtime,
not any old private W script or its state. `make_config.py --task-wire` pins the
additional closed helper set; default configuration generation is unchanged.
Task admission also requires a completely clean tracked/untracked Git view and
requires every helper to match the exact recipe revision. This dirty development
worktree therefore refuses execution. No future signature or target is invented.

The worker uses an additional private manifest with exactly `path`, `sha256`,
`source_revision`, bound to the same Gorge revision as the existing services.
It does not add a worker role to the old five-role runtime configuration. Root
must build/verify the actual `gorge-worker` from that paired revision; a manifest
alone does not prove how a binary was built.

After complete source/profile admission, the isolated Linux/PHP 8.5.12 parent:

1. Runs the existing full native pure obligation and starts only owned fixture
   services. All runtime configuration is synthetic and loopback. Organization
   and media features stay OFF; private-mail and recipient splitting are ON only
   in the isolated test profile. The private void address is synthetic.
2. Starts the actual pinned standalone Gorge worker, with one consumer and only
   `PhabricatorApplicationTransactionPublishWorker` in its class filter. The Go
   worker leases the real task and invokes native worker.execute. The parent
   requires the same native publish task to archive successfully.
3. Starts a separate class-filtered preparation phase, using the existing
   SELECT/UPDATE-only relay principal for the mail outbox. It requires native
   prepare task/data/archive lineage, actual immutable snapshot and the native
   submit follow-up for that exact mail/delivery identifier.
4. Starts only `GorgeMailSubmitWorker`; requires successful native submit archive
   lineage, the corresponding mailer terminal state, authenticated Owlmail sink
   count and actual raw MIME. No parent manually calls the publication worker or
   prepares its snapshot. Post-ack duplicate execute is only a dedup negative.
5. Stops consumers, HTTP and mailer before releasing the SQL fixture, then
   requires exact native schema/principal and owned file/queue/sink cleanup.
   Unknown delivery, worker timeout or any cleanup uncertainty stays failed.

Source-stage implementation covers three isolated invocations:

- `allow`: exactly one accepted MIME, then acknowledged duplicate sends none.
- `revoke-before-publication`: native Task policy revoke before publication;
  no native recipient mail and zero physical MIME.
- `revoke-after-prepare`: real worker prepares first, native policy revoke next,
  then submit must fail and sink remains empty.

Root execution shape, only once admitted:

```
make_config.py ... --task-wire --task-source-manifest <private-task-source-manifest> --output <private-config>
run_task.py --task-source-manifest <private-task-source-manifest> --config <private-config> --worker-manifest <private-worker-manifest> \
  --case <allow|revoke-before-publication|revoke-after-prepare> --output <new-safe-receipt>
```

The current source is not signed/admitted, and no such command has been run.
No workflow is enabled automatically by this package.

## MIME and native/media boundary

Offline MIME checks require the single synthetic recipient (native subscriber
Cc may retain an exact synthetic void To), no other roster, no HTML/attachment,
no private canaries or identity headers, and the exact native
`/object/<same-Task-PHID>/` login route. The native Task ID is bound to `/T<ID>`
in the producer. Real redirect and browser policy results remain unproved.
Never rewrite production link projection to make a test pass.

The private attachment remains a separate media boundary. No capability file
link is declared login-required. N09 is not completed by Task mail filtering.
Already-sent copies cannot be recalled by later revocation.

Raw MIME, addresses, PHIDs, credentials and origins remain private. Public
receipts include only revisions/digests, named checks, counts, cleanup and
explicit limitations. Offline validators cannot attest evidence provenance;
only the actual owned parent chain supplies it.

## Remaining gates and coverage

Independent combined review, full source/registry/profile admission and actual
SQL/worker/SMTP execution remain required. The existing full source gates may
reject this driver delta until a reviewed exact successor is integrated; do not
weaken those guards or substitute historical source bytes at runtime.

Outer-rollback, nonrecipient/disabled-account, worker crash/unknown-SMTP cases and
browser/attachment behavior still need separate Task execution packages. Existing
SSH/Legalpad and generic File mail wire evidence remain separate and unchanged.
Pure tests cover actual PHP binding/transaction guard with explicit storage
collaborators, real private-FS protocol, strict manifest/class/source admission
rejections and actual MIME parsing. They certify none of the runtime gates.

## Optional same-Task browser hold (source preparation only)

The default three-case parent still performs immediate complete cleanup. The new
explicit `make_config.py --task-wire --task-browser` plus `run_task.py
--browser-hold --browser-ready-output <new-private-control> --browser-seconds
<120..600>` selects a separate, source-pinned test mode. It is not admitted by
this uncommitted source window. No live SQL, queue, SMTP or browser run has been
performed for this successor.

Only after the existing worker and physical-mail assertions does the parent stop
and reap its standalone workers and mailer. The same native Task keeper then
creates fresh disposable recipient/ordinary passwords and a registration-disabled
native password provider. The recipient remains a non-admin subscriber, not an
automatic-view assignee. Before exposing the private browser manifest, native
transaction guards must be empty, the native DAO pool closes, and the existing
physical owned-writer close/observer proof must succeed. This closes only the
identified fixture writer; it does not claim every database session is absent.

The existing loopback HTTP port is reused, with exact Host and owned fixture
checks. Browser requests permit only the same Task, its exact object redirect,
native login/logout and static assets. They cannot submit a service token,
worker callback, arbitrary Conduit action, another Task, attachment capability or
Task edit. Native authentication, VIEW, response and CSRF processing remain in
the original application. Organization and media features remain OFF. Browser
success is never inferred from preparing the provider or holding a route.

The external control receipt is private0600 under a private0700 directory. It
contains same-Task route/lineage, source identity and an owner digest, not the raw
owner, callback token, username or password. Fresh login credentials are in the
separate keeper-owned `task-browser-native.json` only. Do not upload either file
or raw MIME. Root browser observations remain an independent private result.

An explicit finalize request uses the same admitted source/config/worker inputs:

```
run_task.py --task-source-manifest <same-private-task-source-manifest> --config <same-private-task-config> --worker-manifest <same-worker-manifest> \
  --finalize-browser <private-control> --owner-file <owned-runtime-owner-file> \
  --output <new-private-finalize-receipt>
```

The request has a fixed one-shot sequence and resource-identity digest, bound to
source, owner, Task hold and the live original Linux
parent process identity. Wrong owner, unknown fields, duplicate finalization or
recycled/lost parent identity refuses. A timeout in a still-live parent is a
failed test; it can perform controlled cleanup. After stopping/reaping HTTP and
all consumers, cleanup requires the existing keeper-owned queue/storage shutdown
ACK and an independent exact-writer SQL0 observation. The keeper repeats SQL0
before its cleanup permit. The explicit browser-mode keeper fences cleanup from its entry; an early failed
setup uses the same owned writer close/quiesce before release, not a new password
provider or browser claim. Any missing/unknown stop, ACK or SQL result blocks
release and retains private evidence rather than reporting successful cleanup.
The queue ACK reuses the existing queue-only helper; its historical notice scope
is not Organization notice or Task history acceptance.

A crashed parent cannot run Python `finally`. The private receipt explicitly
requires reconciliation after parent loss. Finalize then returns
`recovery_required`, grants no release/cleanup/restart permission and does not
kill a possibly recycled PID. The keeper's separate bounded lease and cleanup
fence do not establish complete crash cleanup. Root must reconcile owned
processes, SQL sessions and storage before any reuse. No new invocation may
blindly reuse that namespace, owner or credentials.

This window does not add Task history assertions, browser automation, a revoke
control after the browser seal, physical ACK-loss proof, or N09 attachment
identity. The existing three SMTP cases remain separate. All actual source,
SQL, browser, queue and cleanup gates remain pending until the reviewed package
has its own real source/native/recipe admission.

Owned Task source sidecar and history scope
-----------------------------------------

`make_config.py --task-wire` additionally requires `--task-source-manifest` with
an exclusive private output path. The config schema and default hosted mode are
unchanged. `run_task.py` requires that sidecar and validates the exact current
Git revision/tree, regular 100644 mode, blob, size, SHA256 and physical source
bytes at entry and again before publishing a passing result. Parent Python
modules must physically originate from the configured source root. The source
set includes the parent-control dependencies and every native pure class
explicitly selected by `tests/governance/run.php`. This is source provenance,
not a substitute for signed recipe admission, runtime profiles, SQL, live
binary provenance or actual worker delivery. Unbound source is blocked.

The keeper's observation reads only the owned Task and recipient notification
row, its exact chronological key and matching FeedStoryData object. It then
runs the native PhabricatorNotificationQuery as that recipient. Retention and
current visibility are separate assertions: a retained row does not authorize
VIEW after revocation. The browser route additionally admits exactly
`/notification/individual/?key=<owned-key>`; notification lists, foreign keys,
extra query fields and alternative spellings are refused. Preparation and
component tests never prove browser, SMTP or real history SQL acceptance.

Runtime admission successor
---------------------------

The typed Task source set is the exact union of Task/browser sources, all
existing default transport SOURCE_FILES, gate sources and current native pure
class sources. The count is diagnostic; the path set and typed pins authorize
nothing on their own. Native pure results must match the exact method ID set.

The parent now surrounds owned resource creation and cleanup with real
current_source/native-perimeter and full-source CLI checks, strict protected
freshness, and a genuinely issued SourceOperation with its prepared-source
ledger. A successful result is returned only after that operation exits and
source gates are checked again. The default/source-only unbound candidate is
expected to refuse before launching services. Unit collaborators and synthetic
source fixtures do not demonstrate an admitted Task operation, SQL, SMTP or
browser success. Signed native/current metadata/registry/media successors and
actual isolated Linux runtime acceptance remain separate requirements.
