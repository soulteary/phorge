# Isolated governance notice physical pipeline candidate

This successor includes main `ae888333d9a24888f2ba7887904a2a7823fac8da`
and the separately verified notice component `83c8d04c30a0c2fc4071f1c5c08670044ff35df7`.
Their source sets contain 248 pure methods in 40 classes, 933 SQL obligations,
41 registered global workers, 20 local business children and six local Wiki
children. Neither parent receipt proves this combined source. The new wire
fixture and all transport results are unverified until an actual parent run.
Production source is unchanged by the wire fixture. No optional feature or
production route is enabled by this candidate.

## Native fixture interface

Use the existing owned fixture entry point with the single explicit phase:

```
php support/governance/native-organization/test-native-organization-mysql.php --governance-notice-wire-hold
```

The parent must own an empty `0700` directory named
`phorge-private-mail-wire-<24 lowercase hex>` immediately inside the native
system temporary directory, with an exact `0600` `owner` file containing its
64 lowercase hex nonce. This is a private fixture lease, not authentication.
The ordinary isolated DB, native source and Gorge file/taskqueue binary inputs
remain mandatory. Additional fixed inputs are:

- `PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR`, `..._OWNER`, `..._HTTP_PORT`,
  `..._MAILER_PORT`, `..._MAILER_TOKEN`, `..._CALLBACK_TOKEN`,
  `..._HOLD_SECONDS` (120–1800).
- `PHORGE_GOVERNANCE_NOTICE_WIRE_CASE`: one fixed case below.
- `PHORGE_GOVERNANCE_OWLMAIL_RELEASE=v0.14.0` and the ordinary
  `PHORGE_GOVERNANCE_OWLMAIL_BINARY` / `..._SHA256` inputs.

The release string alone is not provenance. The parent must verify its actual
stable v0.14.0 binary against the already verified OCI/source/signature chain
and save that chain with this new run. The old alpha binary and old 20-case
upgrade result are not a new notice transport proof.

The hold process creates a real approved native administrator with a verified
primary address and a real native web session. It uses native CSRF and sealed
ActorContext to call OrganizationService. Only the actual audit hook writes
the qualified MetaMTA outbox. It checks committed source replay and creates
no notice mail, snapshot, worker success, provider receipt or SMTP result.
The existing baseline administrator has no primary email and is used only as
the real native operator for controlled account changes. No raw address is
inferred to be an actor.

`ready.json` is `0600`, owner-bound, and private. It contains the real queue,
mailer ledger principal, limited relay principal, capture credentials,
synthetic expected destination and opaque event ID. It is never stdout or a
public response. The relay principal has SELECT/UPDATE only on exact
`metamta_gorgeoutbox` and `gorge_mail_delivery` tables; it cannot read native
user, audit, notice or recipient tables. The mailer principal keeps its
existing two-table CRUD scope. Four disposable runtime principals are now
expected for this phase, rather than the full SQL profile's two.

## Actual parent pipeline, not a manual success replacement

Start the fixed native server on loopback using `notice-wire-router.php`.
It retains the existing native configuration/request/Conduit driver and only
admits authenticated POST `/api/worker.execute` and `/api/mail.delivery`.
Worker requests permit the actual capabilities handshake and fixed
PhorgeGovernanceNoticeWorker prepare/execute class; they do not admit another
caller-selected PHP worker. No control command is an HTTP route.

Use current source-pinned paired Gorge worker and mailer binaries, with actual
queue and native service capability readiness. The Go registry's native
Conduit delegate is the NoticeWorker handler; this must be proved with the
actual binary, not assumed from an old worktree. The worker's real MetaMTA
relay first enqueues the committed event, leases it, executes the native
worker over HTTP and atomically finalizes its fenced lease. The native worker
commits mail, recipient edges, recipient mapping and the second durable mail
relay. Its prepared retry must not duplicate any of these.

For a deterministic between-stage mutation, first start the real worker with
`GORGE_WORKER_TASK_CLASS_FILTER=PhorgeGovernanceNoticeWorker`. After native
`status` reports prepared, stop/reap that worker and restart the same actual
worker with the mail preparation/submission classes. Do not consume tasks or
fabricate results in the parent. The existing stable queue inbox retains the
mail tasks. The Go mail preparation and submit handlers drive actual native
mail preparation, immutable binding and physical callbacks. Native result
projection must use the same real service endpoint and scoped ledger.

Relevant actual worker configuration keys are `GORGE_WORKER_TASK_QUEUE_URL`,
`GORGE_WORKER_TASK_QUEUE_TOKEN`, `GORGE_WORKER_CONDUIT_URL`,
`GORGE_WORKER_CONDUIT_TOKEN`, `GORGE_WORKER_MAIL_OUTBOX_DSN`,
`GORGE_WORKER_MAILER_URL`, `GORGE_WORKER_MAILER_TOKEN`,
`GORGE_WORKER_TASK_CLASS_FILTER` and `GORGE_WORKER_FEED_POLICY_FILE`. Use one actual worker and one lease at a time (`GORGE_WORKER_MAX_WORKERS=1`, `GORGE_WORKER_LEASE_LIMIT=1`) for deterministic barriers.
A private `silent=false, uris=[]` policy permits mail submission without
adding any feed destination. All service listeners remain loopback/internal;
no new public port, domain, parent cookie or deployment is required.

The mailer physical authorization observer should forward to the native mail
endpoint. The worker's Conduit endpoint stays the actual native router. Record
every real physical callback, actual SMTP provider behavior and authenticated
Owlmail raw MIME/counts. Never substitute a callback mock returning allowed.
Expected MIME is generic link-only, with the synthetic primary destination;
no unit name, original audit fields, HTML, attachments or private headers.

## Fixed CLI controls and physical cases

`notice-wire-control.php status|mutate|restore|relay-retry` uses the same owned
configuration. It emits bounded state/count/hash JSON, never credentials,
mail bodies, addresses, actor IDs or raw SQL. The case and target are taken
only from the owned private files, never from CLI target arguments.

Cases are `allow`, `admin-revoked`, `disabled`, `disable-enable`,
`primary-changed`, `source-changed`, `between-attempts`,
`config-notice-error` and `config-private-mail-error`.

Account changes use the actual native UserTransactionEditor, authenticated
operator session, native CSRF and actual transaction rows. Notifications stay
on while account recovery and stop-ledger flags are explicitly off. The ABA
case performs disable then enable without an intermediate callback. Primary
address and audit mutations are explicitly fixture-only source changes.
`restore` never clears quarantine or rewrites immutable mail. Configuration
fault cases install a real targeted configuration source exception, not a
synthetic authorization boolean. `relay-retry` only resets the fixture's one
already delivered relay row so the real queue inbox must deduplicate it.

The parent must prove successful one-time capture, no extra capture on relay
replay, fresh denial before send, denial after source regrant/ABA, actual
first-provider451 -> native mutation -> second callback false with no accepted
DATA, and configuration-source errors causing zero egress with no native
fallback. Missing service/redirect/malformed callback negatives remain useful
separate transport controls. Do not classify a generic HTTP failure as a
specific permanent source denial or pending source state.

## Cleanup and remaining scope

Stop/reap Go worker, mailer, observer/fault and native server before writing
owner-exact `release`. The hold process then stops its actual taskqueue and
stable capture services, removes private service storage, and the existing
fixture drops all owned schemas/readers/principals. The parent owns and must
verify removal of every lease file and its own directory, including
`notice.json`, `notice-control.json` and optional `notice-fault.json`.

The first wire producer is a real organization unit event. Project approval,
administrator appointment, transfer/handover, recovery classification and all
future business recipient audiences remain separate required physical cases;
they are not completed by this first event. Native full-SQL hook coverage,
current bounded admin audience, automatic daemon/HTTP/SMTP, in-app delivery,
browser, restoration and production activation are distinct gates. Nothing
here authorizes a recovery action or weakens native actor/VIEW/EDIT policy.
