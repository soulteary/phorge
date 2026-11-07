# Isolated native OAuth access-token SQL phase

This source adds a separate physical compatibility experiment to the e804 OAuth
fence candidate. It does not complete OAuth authorization, all thirteen recovery
sources, a producer lease, restoration, or a permit. Production defaults and
native authorization semantics are unchanged. No database execution is claimed
by the source artifact or host tests.

The only entry is:

```
php support/governance/native-organization/test-oauth-access-owner-mysql.php --oauth-access-producer-owner
```

Root/CI must supply the existing isolated fixture DSN/control credentials and
`PHORGE_GOVERNANCE_ISOLATED_TEST=1`, exact candidate revision in
`PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION`, and the reviewed real Gorge File
binary environment. This entry creates only its fresh native fixture namespace;
it refuses ordinary invocation and cannot adopt a production observer. Its fixed
child action is `recovery.oauth-access.borrowed-owner.issue`, executed with a
fresh process, never a fork or inherited SQL socket. Existing Conduit driver,
workers, evidence decoder and inverse contracts remain byte-for-byte unchanged.

For each **User owner ending** (commit and intentional rollback), the fixture:

1. Prepares real sorted native identities, native credentials, an actual native
   OAuth client, and separate User/OAuth writers before the Store owner begins.
   Both actual User and OAuth connection IDs are owned marker/handle fields.
   Each independently requires an observed committed RC event, enabled
   transaction instrumentation, zero active transaction and zero record locks.
2. Uses the actual Store owner and borrowed OAuth participant, whose fixed source
   acquires table MDL before checking InnoDB and reading bounded token rows.
   The observer additionally proves granted transaction MDL on the exact table;
   the already-owned writer checks InnoDB. No extra observer table grant is used.
3. Continues the child into actual
   `PhabricatorOAuthServer::generateAccessToken()` → native DAO INSERT → selected
   OAuth fence. The observer must see the **User owner connection** blocked in a shared User PRIMARY record-lock wait
   against the Store owner for the exact subject ID. The actual fence acquires
   this lock before beginning its separate OAuth writer transaction. Repeated borrowed facts
   must remain equal while the owner is held.
4. Ends the owner in the selected way, requires the original rollback Throwable
   when requested, requires the actual owned child terminal exit code zero (JSON alone is
   insufficient), proves both User and OAuth SQL sessions absent,
   and captures changed OAuth facts in a new Store event.

The eight SQL obligations plus ten bootstrap/preparation/cleanup obligations
produce eighteen checks. The separate strict consumer is
`scripts/governance/check_recovery_oauth_access_producer_v2.py`; it pins 48 actual
runtime source files and requires the independently hashed File binary, two
workers ended, owned observer removed without widening native grants, all 55
schemas/two runtime users/reader removed, and native environment restored.
The current source-only v2 manifest preserves `physical_SQL_cases_executed=false`.

A failed begin/commit, retained fence state, invalid owner, unknown observer or
unproved end prevents generic driver rollback/DDL and configuration teardown.
Owned child termination is not proof of a transaction result. PHP process exit
may naturally close sockets; the fixture does not call this a live retained
lease. Unknown-state host controls exercise actual driver/worker cleanup blocks
with explicit connection collaborators; they are not a physical unknown-COMMIT
fault injection experiment. Actual SQL waits, MDL instrumentation availability,
commit/rollback and final fixture cleanup remain Root/CI acceptance gates.

The frozen v1 source/consumer remain historical and blocked by the independently
reproduced wrong-connection wait and ignored child-exit findings. They are not
current SQL evidence or an execution approval. The v2 consumer pins the corrected
source; old Conduit consumers and immutable inverses remain unchanged.
