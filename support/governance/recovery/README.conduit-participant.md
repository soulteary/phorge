# Selected native Conduit INSERT participant — development candidate

Default OFF calls the original parent DAO INSERT directly. Optional ON uses the native User and Conduit writer connections, without forced connections, new grants or native eligibility rules. Both writers must be idle/autocommit and on the same primary; existing outer transactions are refused. A User PRIMARY shared lock spans the actual Conduit DAO INSERT and its independently owned commit. Only then is the owned User transaction ended. This is not cross-connection atomicity: Conduit may be committed while User cleanup is unknown. Any unknown end retains connection references and quarantines reuse. Process exit can naturally close sockets and roll back; this does not promise a live lease forever.

The private borrowed-owner hook can capture up to64 Conduit rows/128KiB under the actual User owner, retains an internal source digest and rechecks before owner end. It neither adopts an arbitrary observer nor installs a production factory. No durable assessment, provider-completeness, API-wide/OAuth completeness or restoration permit is enabled.

`conduit-cases.php` supplies the physical SQL6 cases called by the fixed isolated Conduit phase below. It reuses independently owned observer provisioning and the real Store owner, but uses a new fixed Conduit worker action. For each parent commit/rollback: actual PRIMARY wait plus unchanged current Conduit binding; native token DAO resumes only after parent terminal; next real Store capture has a changed binding. Child administrator preflight is real and ends before parent starts. There is no continuous administrator lease claim. Existing Session producer bodies remain unchanged. The new native standard/cluster initializer host controls do not save to SQL.

Host evidence: actual new core executed against explicit native-connection SQL collaborators covers success order, external transactions, INSERT failure rollback, unknown commit retaining User lock, and primary+cleanup aggregation. Actual native standard/cluster factory and callable six-label declaration are run with DB/network functions disabled. Neither constitutes physical SQL proof.

The historical borrowed-owner v1 artifact is unchanged; its actual current source must reduce through exact pinned additions to immutable prior bytes. The historical v2 artifact retains its four-source bytes and exact inverse. The new v3 artifact pins six current core sources, including selected Session storage and current User capture admission. Unrelated edits are refused even after re-pinning. The selected worker source maps, static source inventories and historical product checks keep exact named deltas rather than directory exemptions. A complete governance run passed652+1skip before the subsequent queued-isolation/reservation hardening; that earlier run does not certify the later bytes.

Root generated the classmap twice with supported PHP8.5.12 and the standard mapper; raw identical map bytes were imported after verifying exactly two new entries and the immutable prior-map inverse. The independent phase and strict consumer are implemented below. Remaining before runtime: final full governance, source signing and independent review, followed by Root-owned physical SQL6 and its fault/cleanup evidence. The callable SQL6 and actual grants/routing/P_S behavior remain UNRUN. Root is the only SQL/VM/CI execution owner. Do not launch this moving candidate.

## Fixed callable entry and receipt

The selected native CLI entry is now exactly:

```
php support/governance/native-organization/test-native-organization-mysql.php --conduit-producer-owner
```

Run only in the independently owned native fixture profile with the existing private control-account environment (`PHORGE_GOVERNANCE_FIXTURE_DSN`, `PHORGE_GOVERNANCE_FIXTURE_USER`, `PHORGE_GOVERNANCE_FIXTURE_PASSWORD`), isolated-test flag, actual source revision and independently pinned actual File binary/SHA environment. No literal credentials belong in commands or public records. It creates the original five synthetic native users/two administrators with native avatars and sessions, prewarms both identity epochs, and provisions the same separate minimal P_S/PROCESS observer without extending native writer grants. It never uses an eval entry or forces the business Store/Conduit DAO connection.

Capture stdout as a private receipt and retain the original exit status. The strict public consumer is:

```
python3 scripts/governance/check_recovery_conduit_producer.py --receipt RECEIPT --source-revision ACTUAL_SHA --file-binary ACTUAL_FILE_BINARY --file-binary-sha256 INDEPENDENT_SHA256
```

Receipt version `native-conduit-producer-v2` binds36 fixed source inputs,6 source-derived SQL obligations and16 total checks. The native profile is `isolated-borrowed-real-store-conduit-owner-v2`; batch/scope are `native-conduit-producer-owner` / `owned-native-conduit-producer-owner-no-permit`. A missing obligation, altered source, duplicate JSON, unknown field, false observer/worker/File cleanup, wrong binary bytes or changed source revision refuses acceptance. Root's physical SQL6 remains UNRUN.

The selected phase starts cleanup-blocked before any File/fixture work. Unknown observer/owner/producer state skips explicit generic rollback, pool-close, environment teardown and fixture DROP; the new child likewise skips generic teardown on failed or retained Conduit state. Only completed source cases plus exact observer lifetime/owner terminal and environment restoration permit generic cleanup. Natural mysqli/OS closure at process exit remains possible and is not a terminal proof. Default other phase bodies remain exact; the new driver additions reverse to the immutable prior driver SHA.


## Quarantine and DDL corrective boundaries

An actual retained Conduit reservation blocks the selected Session INSERT, User Store/planning, owned/borrowed lease and current-capture entries in the same PHP process. Storage INSERT hooks check an already loaded registry even after a config-scope reset to OFF; an empty/unloaded registry adds no SQL, grant or eligibility decision and preserves the original OFF parent call. Replacing the pool object cannot clear a retained reservation. This is process-local state, not a durable or cross-process fence, and does not claim interception of every raw User DAO writer.

Each producer table takes a `WHERE 1=0 LOCK IN SHARE MODE` metadata lock inside its own owned transaction before checking ENGINE and before reading/writing real rows. The borrowed current-reader takes Conduit MDL in the same User-owner transaction, then checks ENGINE and reads bounded rows. No ALTER privilege is requested. Missing SELECT, unsupported engine or unknown metadata is fail-closed. Host controls prove actual code ordering and refusal; independent physical DDL-wait/fault testing is still pending and is not one of the six already declared SQL obligations.


## Failure metadata before retained-scope destruction

The first hosted SQL6 attempt failed. Its visible `PhabricatorEnv` scoped-env
shutdown error is a secondary observation; the original SQL failure remains
unknown. An unknown owner keeps the flattened fixture environment and the prior
ScopedEnv object. PHP shutdown still destroys that object, whose native stack
key belongs to the saved pre-flatten stack. No change here restores an unknown
transaction, closes its writer or pretends that shutdown cleanup is proved.

The selected Conduit catch now emits bounded fixed stage/type/file/line/query-code
metadata before its finally and shutdown. A separate child reader validates the
actual Conduit handle and reads only the existing held-FD bounded owned marker
and result records. No message, SQL text, subject, credentials or raw child logs
are projected. The driver retains this projection in the failed receipt. The
failure-only CLI is `python3 -B scripts/governance/project_conduit_failure.py
--receipt PRIVATE_RECEIPT`; it compiles only captured SHA-checked projection
bytes. Missing/invalid input produces a fixed unavailable record. Runtime exit
remains failure and original unknown cleanup guards remain unchanged.

The current descriptor explicitly has37 pinned sources and reports
`native-conduit-producer-v3-diagnostic`; native SQL6/16 obligations and the v2
fixture semantics are unchanged. This diagnostic source is not physical SQL6,
MDL/DDL race, unknown-COMMIT fault, all13 or restoration-permit proof.
