# B04/M07 v4: isolated XLSX, permission concurrency and crash recovery

All four permission axes retain native semantics. Production candidate models
and optional features are not enabled. No live configuration, database, image,
permission migration or deployment is involved.

## Optional XLSX dependency

The isolated fixture pins PhpSpreadsheet 5.10.0 and all six Composer packages in
`tests/permissions/xlsx/composer.lock`. CI installs them without plugins/scripts
outside the source checkout and audits the lock. Neither the production image
nor the application root gains a Composer dependency or an autoloader.

The Excel formatter supports an explicitly preloaded modern Spreadsheet class
and retains the legacy PHPExcel fallback. It does not search for, install or
autoload the modern dependency itself. Existing field hooks retain their names;
OOXML string/numeric/date codes replace dependency-specific constant references.
Without an explicit preload the baseline remains unavailable, and the prior
negative-format test still runs in ordinary PHP processes.

Two real viewer-filtered workbooks are uploaded/read back through paired Gorge
file storage. Independent Python ZIP/XML parsing checks rows, title positions,
blank-column coordinates, numeric date typing and formula-like input as text,
with private metadata exclusion and file author/policy controls. The expected
column layout is viewer-specific because native Space export fields vary by
visibility. Sparse XLSX cells are valid; tests compare coordinates rather than
requiring empty XML cells. This is positive file/engine coverage, not a new
production-browser or office-application compatibility claim.

## Member and Space concurrency

Separate PHP processes pause an editor before capability checks, commit the
independent revocation, then release it. The native observed boundaries are:

- Member-based edit permission is denied after member removal; public project
  query admission remains allowed. Denial leaves business state/history intact.
- A loaded Space policy can admit the in-flight edit; a fresh edit and a fresh
  viewer-scoped query are denied after Space visibility revocation.

These characterize native caching and admission. They do not implement global
retroactive cancellation, an alternative membership model or new grants.

## Versioned bulk creation and interrupted items

Creation now commits native item rows, RUNNING job status, native child queue
rows and `nativeQueueBatchVersion=1` in one worker-database transaction. The
existing native SQL followup-flush primitive is used even under Gorge leasing,
because these tables share SQL and separate HTTP enqueue/completion cannot make
a batch atomic. This is an intentional atomic path, not service-failure fallback.

If the batch committed but parent acknowledgement was lost, a repeated creator
with the version marker acknowledges it without re-creating/enqueueing it.
Unmarked RUNNING/COMPLETE creator jobs keep the prior explicit rejection; no
legacy job is migrated or inferred to be safe. Creator replay under v4 therefore
acknowledges the committed batch, unlike the historical v3 permanent-failure
expectation. Business-item replay still rejects completed items.

The per-item lock spans business execution and terminal item status. A reclaimed
versioned RUNNING item has an indeterminate cross-database business result: it
is marked FAIL without replaying mutations, and aggregate completion is updated
before permanent queue failure. Before-commit and after-commit cases must retain
zero and one business transaction respectively. Unversioned RUNNING items are
left untouched with an explicit manual-reconciliation failure. Operator warnings
have Chinese translations. This is conservative recovery, not automatic repair
or a global exactly-once guarantee. Mixed old/new worker rollouts are not certified.

## Actual process crashes and Gorge ownership

Eight cases are the Cartesian product of native SQL / Gorge queue ownership
and creator-before-commit, creator-after-batch-commit, item-before-business-commit
and item-after-business-commit. A scoped SQL trigger pauses at the boundary;
MySQL process state is observed without blocking on a trigger-owned table.
The PHP worker is SIGKILLed. Only its verified namespace-owned sleeping database
connection is closed, removing the artificial pause and allowing deterministic
rollback. The next process reclaims that exact synthetic task and checks durable
job/item/business/queue results. Raw triggers, connections and payloads stay private.

The queue binary is built from the same immutable paired Gorge source/build
bases as the other fixtures. It listens only on a random loopback port, uses the
owned restored namespace and has its scheduler disabled. `beginScopedEnv()`
selects Gorge ownership plus required service policy only inside helper PHP
processes. No persistent ownership setting is written. A real Gorge transient
failure additionally checks a single failure increment, backoff, zero initial
business writes and successful retry. Missing authentication is rejected, the
service is stopped, and a fresh baseline process verifies native ownership,
unavailable optional XLSX and disabled project controls.

The paired legacy lease API is queue-wide. The fixture parks all other synthetic
active tasks in its own namespace before selecting a target; it never runs a
generic daemon or leases unrelated work. This does not certify ID-filtered
multi-consumer scheduling, stale-lease fencing, Redis ownership or later Gorge
execution APIs. The legacy complete/fail contract is not presented as a fenced
lease protocol. HTTP outage/partition recovery and mixed-version workers remain
separate obligations.

## Evidence and remaining scope

The v4 finite receipt requires the locked dependency hash, two positive workbooks,
exact member/Space outcomes, eight crash boundaries, both queue owners, Gorge
transient retry, cleanup and the explicit legacy/manual-reconciliation policy.
The source-only catalog remains bounded, with 75 reviewed files (43 literal
membership-reference files and 32 selected boundaries). No historical invitation
provenance is inferred. The broader 32 ADR designs and B04/M07/D07 remain partial.

Remaining obligations include arbitrary member/Space hierarchy mutations, live
mid-export revocation, HTTP XLSX downloads and broader office-reader matrices,
network partition/lease fencing, Redis and mixed-version deployments. Native
in-flight Space/ancestor caching is retained, not silently hardened into a new
permission model. Shared records contain aliases/counts/booleans and source
identity, never raw XLSX, SQL, task payloads, deployment information or credentials.
