# Isolated native recovery account capture (R02.0/R02.1)

This candidate appends an actual current account-source capture to the owned
full native fixture. It does not restore an account, grant permission, revoke
credentials, install a native User enable guard, or register a public endpoint.
All thirteen recovery families remain uncovered and `can_execute` remains false.
The captured account adapter is partial even though its selected storage reads
are real. Object automatic grants, complete credential inventories, configuration,
extension producers and restoration lineage are still separate requirements.

The source baseline is `2b1db269cfdba1bfaa612e68767fec85eef8e2c7`, including
the protected native membership baseline. Existing native permission axes,
self-join/leave, optional defaults and the private-mail/HTTP/member evidence gates
are retained. No whole-file native User or old Guard overlay is applied. The
single R01 inventory class is imported explicitly without promoting its static
metadata into recovery authority.

The account scope uses the real sealed native actor context and physical People
writer, actual current credential validation, sorted account locks, native source
MDL/InnoDB checks, fixed bounded storage projections and final freshness checks.
It requires an already initialized actor epoch, records missing subject metadata
as versioned observed absence, and emits no source DML. Only its own observation
transaction is rolled back; inherited native or raw transactions are refused.
There is no server READ ONLY or SELECT-only-principal claim.

## Evidence and obligations

An independent supported PHP 8.5.12 run executed **203 pure methods** with the
exact baseline plus the six frozen source files and a fixed pure-test driver.
The mapped source hash was
`64b62bd21d35dad38148e0b2ca4963f9e0ccc1389efb7cbdb61d67a5421d1f06`.
Both owned staging directories were removed. This establishes pure/class-load
behavior only. The README inside the six-file source checkpoint was authored
before that run; this record supplies the later execution status without
rewriting the historical checkpoint.

The integrated all-mode driver adds ten actual SQL obligations to the existing
659, giving **669 expected checks**, not 669 observed successes. Required cases
include missing actor epoch without initialization, real account capture and
subject absence, unchanged source rows, preservation of a caller's native outer
transaction, raw physical transaction refusal with the caller's write preserved,
real disable/enable generation ABA, current nonadmin refusal, and missing-source
failure with transaction cleanup. All full-fixture ownership, worker, file-service,
source-restore and schema/principal cleanup requirements remain unchanged.

The strict receipt gate requires the exact partial, non-executable summary and
all ten named obligations. Mutation tests reject missing source cases, integer
booleans, a falsely complete source family, execution promotion and unknown permit
fields. JSON acceptance alone is not proof of native execution: an independently
bound current-source runtime artifact is still required.

**Current SQL status: not executed.** Hosted CI, production activation, browser
acceptance, all-source recovery completion and deployment are not established.

## First full-fixture attempt

The exact signed first candidate completed 203 pure methods and all 659 existing
SQL assertions, then **failed before any new R02 assertion**. The strict account
scope correctly rejected a namespace override left by the schema-comparison
fixture. That fixture used the native query's uncached StorageManagementAPI
factory, which pushes a namespace on each lookup. The correction explicitly
owns/caches those APIs and restores only the exact namespace pushes and raw
connections it created. The account scope's empty-namespace guard is retained.
Setup DDL also now uses the existing narrowly scoped strict-mysqli helper, so
native missing-source exceptions are not changed by an ambient test report mode.

The failed run's source hashes matched all 6771 PHP/SQL and public-suffix files;
55 schemas, two runtime users, the reader, nineteen worker directories/processes,
and the paired image service were cleaned. This is a failed checkpoint, not SQL
acceptance. The corrected 669-check candidate still requires a fresh actual run.

A second exact candidate (`b746b9ebafe49942676a27b6136005cd0249055d`)
again completed 203 pure methods and the original 659 SQL assertions, then failed
before the new account checks: the production scope correctly rejected an extra
configuration dictionary. Native `PhabricatorEnv::overrideConfig()` retains its
own override-source layer after a test restores individual values. The fixture
now creates an explicit temporary two-source subcontext from the current
resolved values of only known dictionary/default sources, after proving the
owned writer routing. It preserves the current rotated media issuer settings
and restores the exact original stack, cache and override/repair references in
`finally`. The production scope's exact two-source constraint is unchanged.
This second failed checkpoint also matched all 6771 runtime source files and
fully cleaned the fixture, workers, file/image services and own staging data.
The scoped-fixture correction is not yet SQL-verified.
