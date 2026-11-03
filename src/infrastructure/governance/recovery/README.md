# Recovery account capture: R02.0/R02.1

This package adds an **unregistered, isolated CLI-only account capture**, not an
account recovery operation. All thirteen R01 families remain `uncovered`,
`all_sources_complete=false`, and `can_execute=false`. No route, feature option,
credential revocation, native User write hook, or source-complete permit is added.

The exact sealed native actor context is validated against the actual current
native session or API token on the physical People writer. Current sysadmin is
required to inspect another account. The actor's legacy issuer epoch must already
exist; missing actor metadata is an explicit refusal. Initialization through the
existing writer source is a separate fixture setup transaction, never hidden
inside capture. Missing **subject** epoch is versioned observed absence, not an
inferred epoch, eligibility decision or restore approval. Fixed native User flags,
latest native User transaction ID, existing legacy-role generation and hashed
security fields are read using canonical identity bytes and shared locks.

The factory requires CLI isolated opt-in, no deployment selector (including `0`),
no local deployment files, fixed native loader, a loopback disposable namespace,
the fixture writer identity, actual quickstart marker and source hash, and a
single unclustered native SQL route. The same People writer, transactional source
engines and MDL/current locks are checked by the real NativeIdentitySource.
Outer native or physical transactions and existing Lisk locking scopes are
refused without taking ownership of them. Fixed server-side length preflight
precedes transferring selected secret columns; selected account identities and
source rows are bounded. Missing source tables or malformed data fail closed.
The 10-second completion deadline rejects late results; it is **not** a proof of
a hard MySQL execution-time/memory bound or a lock-wait timeout.

The owned observation transaction is rolled back on finish/error. Capture emits
no source DML and never calls the missing-issuer initializer. This does not claim
that the writer principal has SELECT-only grants, that the server transaction is
READ ONLY, or that unrelated future effects would roll back. Locks cease before
return; the returned data is not fresh authority for any later write.

R01 is integrated by copying its single new inventory class, with its existing
fixed-loader and all-family-uncovered interpretation. No whole-file User or
Guard overlay is applied to the current Core baseline. The separate R00/Guard
central enable refusal remains an independently reviewed, unmerged package;
this package does not claim its production User entrypoints are installed.

`support/governance/recovery/account-cases.php` contains prepared real native
fixture cases: actor missing epoch/no initialization, actual current capture,
subject observed absence, source row preservation, outer transaction ownership,
real native disable/enable generation ABA and missing-table cleanup. It must be
called inside the owned full native fixture after `governance_native_request`
is loaded. The fixture uses actual native session/editor/DAO code. These SQL
cases are **not yet executed**. Pure tests and supported-runtime/class-map checks
are also pending; host `php -l` only verifies syntax.

Remaining account closure includes all status/role writers under independent
contention and restoration lineage. Web/API/media/organization enumeration,
object automatic capabilities, policy/Space/project/configuration/extension
sources and full source recovery decisions are not supplied by R02.1.
