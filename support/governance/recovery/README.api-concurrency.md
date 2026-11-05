# Native API credential concurrency experiment

This test-only component starts from protected main `ae888333d9a24888f2ba7887904a2a7823fac8da`. Production PHP, SQL, native credential queries and the existing full native gate remain unchanged. The component is currently **source-only; supported PHP and real SQL have not run**. Host decoder and receipt mutation tests are not native authentication evidence.

The focused CLI entry is `support/governance/native-organization/test-native-organization-mysql.php --api-credential-concurrency`. It inherits the existing isolated owner-marker/grant/source checks and real paired file service. It requires `PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION` to equal the independently archived source revision. The existing full `all` profile does not call this experiment and remains 243 registered PHP methods / 897 SQL obligations. No new PHP classes or class-map entries are introduced.

The experiment has twelve attempts: native standard Conduit tokens (`api`), command-line tokens (`cli`), and OAuth access tokens, each with an ordinary native DAO INSERT or the actual product revocation storage operation under an owned transaction, followed by COMMIT or ROLLBACK. Each attempt first proves native Conduit authentication, observes an independent reader while the producer transaction is still open, then requires fresh native authentication and stored row state after the boundary. Conduit revocation exactly uses `setExpires(PhabricatorTime::getNow() - 60)->save()`, as `PhabricatorConduitTokenTerminateController` does. OAuth revocation deletes the actual `PhabricatorOAuthClientAuthorization`, as the native authorizations settings panel does; access tokens remain stored. Each OAuth attempt ensures a fresh real authorization before its baseline. The OAuth client, authorization and access tokens use the real native factories. The helper invokes the actual private native Conduit authentication method; this is a CLI native authentication proof, **not an HTTP request or handler deployment**.

For `api` and `cli`, the independent process runs the unmodified `NativeIdentitySource::lockCurrent` on its real User writer. The raw viewer PHID is not treated as token authentication: the source must validate the actual stored token/user/type/expiry binding. Epoch initialization is committed before the experiment to exclude initializer contention. The producer uses its ordinary Conduit writer; it is not forced onto a User connection. For OAuth the independent process performs actual native authentication on the read connection used by the native DAO. OAuth is explicitly not a supported governance actor-context/current-source profile.

The observer accepts only two measured states: a result produced before release, or a Performance Schema lock wait whose requester is the real reader thread, blocker is the actual producer thread, and table/schema are the exact owned credential table. A timeout, unknown SQL error, deadlock, malformed result or failed child fails the attempt; none is converted to denial. A failed child exposes only the existing bounded exception class/basename/line projection. Raw tokens, OAuth secrets, SQL and identities remain in owned private process files or memory, not receipts. Owned workers and the file service are reaped by the existing top-level finally path; failure does not skip fixture cleanup.

OAuth's ordinary nonlocking reads may see the committed version before an uncommitted authorization revocation is released. The receipt records that observation and separately requires a fresh post-commit native denial. It does not claim an OAuth current-reader fence. `api`/`cli` current-source outcomes must match the final committed credential state.

The focused gate `scripts/governance/check_api_credential_concurrency.py` requires exactly **46 checks**: six inherited source/grant checks, one real file-service readiness check, 36 per-attempt checks, and three inherited cleanup checks. It requires twelve normally finished workers, no remaining process/private directory, exact 55 schemas/two users/reader cleanup, the supplied actual file-binary digest and the current 543-table/1571-patch/34-reviewed-migration restore profile. Input revision and binary are independently supplied by the future signed archive runner. The JSON gate by itself does not authenticate a source archive or grant recovery authority.

This first bounded profile measures one real producer and one independent reader at a time. It can expose cross-row token-scan blocking and preserve an actual error; it cannot disprove a two-producer deadlock cycle or establish a universal API/OAuth revocation SLA. No production query is changed based on a guessed BINARY-token problem. Any production correction requires a reproduced failure, source review and a fresh runtime profile.

All thirteen source families remain incomplete. Restoration permits, automatic recovery, legacy certificate/session concurrency, arbitrary OAuth associations, HTTP/browser requests, deployment and online feature activation remain outside this component. Optional production flags remain off. A Root-issued unique COMMON lease, signed source/helper peer review and real run → whole-source hash → focused strict gate → complete owned cleanup are required before any runtime acceptance claim.


## Startup observation successor

The original `6b9b7518cb4e6461c86b3e54c59415f2559f9b5d` run passed 243 pure methods but failed after eleven focused checks at `native_worker_not_ready`; it did not pass the 46-check experiment. Its registered child was still running and the owned cleanup stopped it. No cause is inferred from the timeout alone.

This test-only successor records a private fixed phase/owner/thread manifest before and after bootstrap, default-config construction, real writer/reader connection registration, raw user loading, context creation, ready publication and the unchanged current read/native OAuth authentication. The actual User read connection is established in its normal `r` mode before loading the user; no connection is forced or reassigned. The ready barrier and eight-second limit are unchanged. On startup timeout, the parent validates the owned phase file and reports only the phase enum, liveness, fixed connection-role states and Performance Schema wait booleans for the owned User/Conduit/OAuth schemas. It never exports query text, identifiers, tokens, owner nonce or connection IDs. A diagnostic fault records unavailable and still rethrows the original timeout. Progress is never accepted as authentication, denial or successful completion. Production queries and all 46 success conditions remain unchanged. This successor has not run supported PHP/SQL yet.


## Current-read wait observation successor

The `02b2a2d06ebd3aa1b08d9a387d6571116cd52f3e` run passed 243 pure methods and eleven focused checks. This time the child reached Ready; the run failed at the five-second `recovery_api_observation_timeout`. It is a separate failure from the earlier startup timeout, and no 46-check acceptance is claimed. Its whole-source comparison and cleanup were independently recorded by the owner; this successor does not relabel those receipts.

A new failure-only collector reads the already owner-bound phase file before releasing the producer transaction. It preserves multiple connection roles sharing one actual MySQL connection, and examines their actual Performance Schema requester/blocker relations. Known table categories require both the exact fixture database and real DAO table name; other objects are labelled only `namespace-other` or `foreign-other`. It reports whether each blocker equals the producer connection or the parent's actor connection, index/lock classes, and bounded metadata-pending observations with **no inferred metadata blocker**. It also compares the parent's tracked User transaction state with actual `INNODB_TRX` presence. Connection IDs, database/table names, key values, SQL, credentials and raw errors are not emitted. Sixteen data waits/eight metadata waits bound the diagnostic; unknown or failed observation remains unavailable and the original timeout is still thrown. The existing accepted observation filter, timeout and all 46 success conditions are unchanged. The additional code has not run a database or real account interaction.


### Actual a4f6 observation and registry successor

The a4f6 isolated experiment passed all 243 pure methods and 11 focused checks,
then failed the original five-second observation timeout. Its bounded diagnostic
observed an actual Conduit PRIMARY shared-record wait; its blocker matched neither
the recorded producer nor parent User connection. That does not prove a producer
fence or token-scan deadlock. Cleanup and source hashing are separate owner records.

This successor adds only failure-observation metadata. It obtains the child's
actual native Conduit connection ID before the unmodified current-source call,
rather than incorrectly labelling its User writer as the credential connection.
The failure collector inspects actual parent producer/actor/control/setup objects
and already-cached native User/Conduit reader/writer connections (without creating
new parent connections). Every child role comes from the existing owned nonce
phase file. Multiple roles may match one actual connection; an unrecognized
blocker yields an empty role list, never an inferred owner. Only fixed role names
leave memory; no connection IDs, SQL, usernames, schema/table names or token values
are added to receipts. The original blocker matcher, timeout, 46 success checks,
12 workers and product queries are unchanged. Registry failures preserve the
original timeout and cannot count as a successful observation. SQL is unrun for
this successor, and no API stress, restoration permit or full-family completion
is claimed.


### Transaction-owner correction (prepared, not runtime acceptance)

The 581b registry experiment again passed 243 pure methods and 11 focused checks,
then timed out. Its P_S blocking thread matched the child waiter itself. That is
not proof of an InnoDB self-deadlock: MySQL can materialize an INSERT's implicit
lock from the waiting reader's thread, on behalf of the writer's transaction.
The creator thread/event and lock-owner transaction are different concepts.

This test-only successor attributes ownership through actual InnoDB metadata.
The waiter is identified by INNODB_TRX.TRX_REQUESTED_LOCK_ID matching the waiting
lock, with actual LOCK WAIT state and the owned reader connection; it does not
interpret the possibly internal read-only REQUESTING_ENGINE_TRANSACTION_ID.
The blocking writer is identified by BLOCKING_ENGINE_TRANSACTION_ID joined to
INNODB_TRX.TRX_ID, then actual TRX_MYSQL_THREAD_ID equals the owned producer.
Lock joins include ENGINE and require INNODB, plus the unchanged waiting status
and exact owned schema/table. Diagnostic owner roles and event-creator roles are
separate; absent owner metadata stays unknown. The product token query, timeout,
46 obligations and permission semantics remain unchanged. No SQL acceptance,
product deadlock or production-query fix is claimed for this successor.

Primary references: [MySQL8.4 data_locks](https://dev.mysql.com/doc/refman/8.4/en/performance-schema-data-locks-table.html),
[INNODB_TRX](https://dev.mysql.com/doc/refman/8.4/en/information-schema-innodb-trx-table.html),
and [MySQL engineer explanation of implicit-lock materialization](https://bugs.mysql.com/bug.php?id=104431).
The [official8.4 P_S implementation](https://github.com/mysql/mysql-server/blob/8.4/storage/innobase/handler/p_s.cc)
reports the blocking transaction ID separately from its lock-creation event.

### Explicit protected-main107 integration (unexecuted candidate)

This working-tree merge combines protected main
`ae13ad894b2fc10aa9c8683ce18953850fddaf8e` with the isolated transaction-owner
fixture source `ff2f444a9a12587149961aea91e8cba87c6ee8be`. It retains main's
notice producer/control phases, organization lifecycle/policy producer actions,
recovery/session/stop result decoders and security branches while adding the two
fixed API/OAuth actions, their owned startup diagnostics and focused CLI.

The earlier 243-method pure and partial 46-check observations belong to their
historical source revisions. They are not evidence for this merged candidate.
The current main pure driver and normal SQL gate remain byte-identical to main
(261 methods / 43 classes and 934 normal SQL obligations); this is source
preservation, not execution. The API-focused profile still requires its separate
46 actual checks and 12 owned workers, and is not added to or claimed by the
normal 934 profile. The old 897 normal count is historical. Supported native map,
pure, normal/focused SQL, current-source strict sealing and independent review
must be established for the eventual signed union head. Reflection of the native
authentication method is not HTTP/gateway evidence. Source families, restoration
permission, production activation and deployment remain unproved/default off.

### Main107 failure-only split P_S / I_S sampling candidate

The signed 9cf main107 integration completed its normal 261-method / 934-check
profile with exact source hashing and cleanup. Its separate focused run reached
14 checks and timed out during the second API insert attempt's observation;
the last successful check label was its baseline, not the throwing operation.
That run did not complete 46 checks and remains historical failure evidence.

This successor changes no product query, success matcher, five-second deadline
or 46-check contract. The old startup flags are renamed to creator references,
with an explicit false transaction-owner proof marker and an ENGINE-qualified
P_S lock join. Lisk may reuse the User writer for its reader role, so role aliases
are not evidence of two independently blocked connections. The actual token
current read remains on the supplied native User writer; the separate Conduit
connection is not automatically its reader.

Only after the original observation times out, the collector reads bounded P_S
wait rows without any INNODB_TRX join, then separately reads the owned connection
rows in INNODB_TRX. It emits fixed roles/states and opaque-ID equality booleans,
not IDs, SQL, credentials, account names or lock data. Two samples are separated
by a measured local pause of at least 250 milliseconds with no I_S read issued
by this collector during the pause. Other sessions' monitoring reads are not
controlled. Empty samples never prove a persistent wait. Event creators are not
reinterpreted as transaction owners, and all ownership/cache-causality proof
flags remain false. A separate real observation is required to determine whether
cached I_S rows, requester identity, lock-ID matching, or another factor caused
the original mismatch. The primary timeout is always preserved if diagnostics
are unavailable. No SQL execution, current-source map/pure completion, producer
fence fix or recovery permission is claimed by host tests.
