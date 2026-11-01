# B04/M07 read-only difference auditor

This contribution implements an offline comparison tool and a SELECT-only
snapshot collector. It adds no application route, configuration switch, policy
migration, edge repair, role, task status, notification or Gorge service.
The current native permission model and optional production controls remain
unchanged. D07 is not declared complete by this contribution.

## Operator sequence

Use a **restored, isolated snapshot**, never the live application database.
Keep its database server inaccessible to application workers and other writers.
Pin its archive hash, backup UTC watermark, native source revision, schema and
effective `policy.allow-public` value. These provenance values are supplied by
the restoration operator; the collector validates their shape, not the truth
of an arbitrary operator's claim about archive contents. Retain the independent
restoration receipt privately. A snapshot can be old: report its watermark and
do not generalize it to the current database.

Provision a new account with SELECT on only the restored project, user, spaces
and policy databases. No roles, mutation privileges, GRANT OPTION or application
credentials. The collector refuses broader grants and accepts only loopback TCP.
Its private stdin JSON contains `restoredSnapshot` (true), `host` (loopback),
`port`, `namespace`, `user`, `password`, `snapshotSHA256`, `snapshotWatermark`
(UTC ISO timestamp), `sourceCommit` (40 lowercase hexadecimal characters),
`allowPublic` (captured effective boolean), and optional `pageSize`, `maxRows`,
`seconds`. Keep this file, raw snapshot and HMAC key mode 0600.

```sh
umask 077
php scripts/permissions/collect.php < "$PRIVATE_CONFIG" > "$PRIVATE_SNAPSHOT"
# Generate once per audit; retain separately from both kinds of output.
python3 -c 'import json,secrets; print(json.dumps({"hmacKey":secrets.token_hex(32)}))' > "$PRIVATE_KEY"
python3 scripts/permissions/audit.py --snapshot "$PRIVATE_SNAPSHOT" \
  --key-file "$PRIVATE_KEY" --candidate independent-view-v1 --output "$NEW_OUTPUT"
```

Run the PHP adapter and auditor from a clean source checkout with **no local
deployment configuration**, using PHP 8.5.12 with JIT disabled and Python 3.
`collect.php` does not bootstrap the application. `native_rules.php` loads only
the bundled runtime and class library, not script environment/database config
sources. It reuses native global and explicit-user rule classes; the native
public-capability conversion uses the snapshot's captured setting. No opaque
custom/named/project rule is guessed from raw edges. There is no production
activation command and no generic dry-run flag that enables writes.

## Versioned comparison hypotheses

Each candidate is an independent hypothetical interpretation of the existing
snapshot. These identifiers do not enable a model or reproduce all of either
source PR. Creation-time behavior and future writes are outside their scope.

| ID | Ordinary child view | Automatic parent edit | Ordinary membership index |
| --- | --- | --- | --- |
| native-v1 (baseline only) | ancestor guard | retained | actual type-60 materialized edges |
| independent-view-v1 | independent | retained | actual materialized edges |
| explicit-edit-v1 | ancestor guard | removed | actual materialized edges |
| self-descendants-v1 | ancestor guard | retained | direct type-13 edges on self and ordinary descendants |
| direct-members-v1 | ancestor guard | retained | own direct edges only |
| combined-v1 | independent | removed | self and ordinary descendants |

Milestone policy/membership inheritance remains tied to its parent in every
candidate. Unknown/missing/cyclic/deeper-than-64 ancestry is bounded and unknown.
Ordinary parent direct membership missing from its old materialized index is an
explicit `member-index` delta, even if visible policy results happen to match.
Disabled/unapproved subjects retain their index data but receive no simulated
capabilities; this is the account-admission constraint, not a claim that the
low-level native policy filter alone rejects stale user objects.

`view`, `edit` and `join` are policy-layer capabilities. `leave` is a preview
combining view, membership, native parent/milestone editability and lock/editor
conditions. They do not establish route access or successful transactions.
For example, a raw edit capability need not imply object view. The report
separately marks direct URL, query/list, Conduit, search, feed/backlink and
board/history routes **not verified**. Application installation/access, session
admission, custom rule evaluation, object-policy edge semantics, Space/default
Space controls and transaction-editor guards are not certified. Space use and
unsupported policies produce unknown decisions, not a fabricated known denial.
Automatic native grants can still be known where they bypass a stored rule;
Space boundaries are checked before such grants. `migrationReady` is always
false. Route tests and owners' decisions remain a later prerequisite.

## Safety, bounds and output

The collector inventories required columns, requires InnoDB and reads all
selected tables under one REPEATABLE READ consistent **READ ONLY** transaction.
Session setup and transaction control are the only non-SELECT/SHOW statements.
No initialization, storage upgrade, ORM/editor/index hook or worker runs.
Projects/users use primary-key cursors; edges use `(src,type,dst)`, preserving
numeric type ordering. Default page 500, maximum 2,000; total row cap 100,000;
default 30-second wall/query/read budget, maximum 300 seconds. It reads the
entire bounded edge inventory, so unrelated edges can exhaust the budget and
cause a refusal rather than a partial all-clear. Schema drift or missing tables
fails closed; no guessed SQL schema or automatic repair is attempted.

The offline comparator has an independent cell/time budget and a 32-MiB private
input cap. Empty project inventories, unknown results, broken references and incomplete extraction produce
nonzero exit status (2), never a clean no-difference certificate. Collection
failures publish only a fixed redacted refusal. Existing output directories,
symlinks for private input and existing report files are refused. Reports are
new mode-0600 files within a mode-0700 directory. Unknown native evaluation
must never silently fall back to a Python approximation.

`summary.json` is an aggregate report with source/snapshot identities, domain
sizes, candidate axes, grants/denials/unknowns, integrity issues, ambiguities and
scope. `restricted-review.json` uses per-audit HMAC pseudonyms for project/user
identities; its key is stored separately. No names, raw PHIDs, email, deployment
hosts, credentials, raw policy bodies or capability links are published. Even
pseudonymous review data is locally restricted by default. Every old direct
edge has unknown historical origin: preserve it and ask its authorized owner;
never infer that it was automatically copied or should be deleted.

## Reproducible verification

```sh
python3 -m unittest discover -s tests/permissions -p 'test_*.py' -v
# Only with explicit disposable MySQL fixture environment, never live config:
php tests/permissions/restore_integration.php
```

The always-required atomic CI job runs both commands. Unit tests cover the
independent axes, hidden ancestry, explicit grants, missing old indexes,
milestones, lifecycle, graph defects, unknown policies/Spaces, budgets,
repeatability, privacy and file ownership. The integration driver creates
synthetic native-shaped schemas, logically dumps and restores them, then uses
a separate SELECT-only account for the actual collector and native rule adapter.
It proves INSERT/UPDATE/DELETE/ALTER/DROP/TRUNCATE denial, stable composite
pagination, all five model comparisons, deterministic reruns, incomplete exits,
privileged-account refusal and unchanged restored data. Setup/teardown are
privileged **only on owned disposable schemas**; no such privileges reach the
auditor. This synthetic roundtrip is separate from the operator's real archived
snapshot validation. Full native route and migration acceptance are not replaced.

## Recommendation rule

Retain native semantics until complete, representative differences and all
potential new grants, edit losses and ambiguous old membership have owner
review. Empty or hierarchy-free snapshots do not justify model changes.
Evaluate independent child view, explicit child edit and aggregation separately;
do not select the combined model merely because a tool can calculate it.
The restored-snapshot findings and remaining unknowns belong in the sanitized
acceptance record, alongside its age and scope. No migration is performed by
this contribution.
