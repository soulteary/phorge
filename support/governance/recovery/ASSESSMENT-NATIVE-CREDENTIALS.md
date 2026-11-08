# Native credential facts in restoration assessment

The real `PhorgeRecoveryRestoreAssessment::capture()` now asks the Store for
Session, Conduit token, and OAuth access-token source facts. The Store uses its
private owner token, exact native User writer, owned observer, and final borrowed
participant. This calls the existing bounded native readers, including current
RC/P_S event, routing, same-primary, MDL/ENGINE, PRIMARY User-lock, grant and row
budget checks. The participant never owns the caller's transaction. Store
recapture and terminal observation retain their existing ownership rules.

The schema-4 Assessment binds the three actual source digests, fixed scopes,
provider schemas and borrowed-current-User-writer mode into its durable binding.
A subsequent source change invalidates the existing review through the original
Store comparison. A failure in an installed owner or reader propagates and
poisons the participant; it cannot become an unavailable/empty-source result.
Missing production observer provenance instead produces an explicitly unavailable
readset with null digests. This is not evidence that credentials are absent.

There is no public observer setter, new observer factory, fixture dependency in
core, permission change, or native execution implementation. Production observer
ownership/provisioning remains unavailable. All thirteen families stay incomplete;
`requireCompleteForExecution()` and the native-transition refusal remain intact.
SSH, temporary credentials, OAuth refresh/client grants, other producers, physical
restore lineage, and the other uncovered catalog sources are not made complete.
Separate Task/File RR observations are not composed into this RC readset. Default
feature flags are unchanged and off. These facts are not a continuous authority
lease or cross-database atomicity proof.

## Versioned source controls

The source-only v5 manifest binds nine selected native source files. Its exact
three-file inverse recovers immutable 1722 bytes before the existing OAuth and
Conduit inverses. Old v1-v4 source manifests retain their original pins. OAuth
SQL consumers/manifests v1 and v2 remain byte-for-byte historical artifacts; v3
binds fifty current sources, including Assessment and Request. Its eight SQL
obligations/eighteen checks remain the selected OAuth two-owner-ending fixture,
not new proof of durable restoration. The existing partial phase is explicitly
versioned to Assessment schema 4 and retains all 21 SQL obligations/31 checks.
Borrowed Session and Conduit consumers retain their obligations and source counts
with the exact new Store hash. No historical runtime receipt becomes proof for
this source revision.

## Host evidence and physical SQL gate still required

The host harness executes actual Store, Assessment, Request and final participant
code with explicitly declared SQL/native collaborators. It verifies all three
readers are called; each source independently changes the next owner Assessment;
the real Store review recapture rejects each stale digest; same-owner changes,
unsupported/budget failures and caught failures cannot commit; unknown observer
states remain unproved and retain the owner. Native User/context/final connection
classes also load without SQL, and a native connection alone cannot mint an owner
or observer. This does not authenticate a real database actor or prove MySQL locks.

Root's next isolated physical integration stage must provision the existing owned
observer in support code, authenticate two real independent admins, create native
credential rows before the native stop transaction, and keep the stopped subject
distinct from both admins. Use the real durable submit/preview/review functions
inside that observer lifetime, not a fabricated descriptor or forced connection.
Required meaningful checks are:

1. Initial blocked ticket contains three non-null schema-4 digests from actual
   Session/Conduit/OAuth rows; repeated preview is stable and remains blocked.
2. Between owner transactions, remove/change each selected row through its native
   DAO and prove the existing review fails `sources_stale`, with all seven durable
   tables unchanged by the refused review. Test each source independently.
3. An unavailable observer produces only unavailable metadata, never a permit;
   installed observer/grant/route/budget/P_S failure refuses rather than downgrades.
4. Commit and rollback owner endings are physically observed. Unknown terminal
   events retain private fixtures/accounts and prohibit explicit generic cleanup;
   PHP process shutdown is not a promise of a live lease forever.

The existing fixed `--recovery-partial-composition` phase exercises the unchanged
bounded partial family obligations under schema 4; the fixed OAuth owner CLI and
v3 consumer exercise selected native OAuth ownership. Neither replaces this new
physical durable-source integration stage. Its callable fixture/receipt wiring,
DDL contention and real unknown-COMMIT fault injection remain future gates; no
SQL case has been run by this package's author.
