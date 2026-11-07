# Selected native Conduit INSERT participant — development candidate

Default OFF calls the original parent DAO INSERT directly. Optional ON uses the native User and Conduit writer connections, without forced connections, new grants or native eligibility rules. Both writers must be idle/autocommit and on the same primary; existing outer transactions are refused. A User PRIMARY shared lock spans the actual Conduit DAO INSERT and its independently owned commit. Only then is the owned User transaction ended. This is not cross-connection atomicity: Conduit may be committed while User cleanup is unknown. Any unknown end retains connection references and quarantines reuse. Process exit can naturally close sockets and roll back; this does not promise a live lease forever.

The private borrowed-owner hook can capture up to64 Conduit rows/128KiB under the actual User owner, retains an internal source digest and rechecks before owner end. It neither adopts an arbitrary observer nor installs a production factory. No durable assessment, provider-completeness, API-wide/OAuth completeness or restoration permit is enabled.

`conduit-cases.php` supplies the physical SQL6 cases called by the fixed isolated Conduit phase below. It reuses independently owned observer provisioning and the real Store owner, but uses a new fixed Conduit worker action. For each parent commit/rollback: actual PRIMARY wait plus unchanged current Conduit binding; native token DAO resumes only after parent terminal; next real Store capture has a changed binding. Child administrator preflight is real and ends before parent starts. There is no continuous administrator lease claim. Existing Session producer bodies remain unchanged. The new native standard/cluster initializer host controls do not save to SQL.

Host evidence: actual new core executed against explicit native-connection SQL collaborators covers success order, external transactions, INSERT failure rollback, unknown commit retaining User lock, and primary+cleanup aggregation. Actual native standard/cluster factory and callable six-label declaration are run with DB/network functions disabled. Neither constitutes physical SQL proof.

The historical borrowed-owner v1 artifact is unchanged; its actual current source must reduce through exact pinned additions to immutable prior bytes. The new v2 artifact pins all four current core sources. Unrelated edits are refused even after re-pinning. The selected worker source maps, static source inventories and historical product checks keep exact named deltas rather than directory exemptions. A complete governance run passed652+1skip before the subsequent queued-isolation/reservation hardening; that earlier run does not certify the later bytes.

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

Receipt version `native-conduit-producer-v1` binds35 fixed source inputs,6 source-derived SQL obligations and16 total checks. The native profile is `isolated-borrowed-real-store-conduit-owner-v1`; batch/scope are `native-conduit-producer-owner` / `owned-native-conduit-producer-owner-no-permit`. A missing obligation, altered source, duplicate JSON, unknown field, false observer/worker/File cleanup, wrong binary bytes or changed source revision refuses acceptance. Root's physical SQL6 remains UNRUN.

The selected phase starts cleanup-blocked before any File/fixture work. Unknown observer/owner/producer state skips explicit generic rollback, pool-close, environment teardown and fixture DROP; the new child likewise skips generic teardown on failed or retained Conduit state. Only completed source cases plus exact observer lifetime/owner terminal and environment restoration permit generic cleanup. Natural mysqli/OS closure at process exit remains possible and is not a terminal proof. Default other phase bodies remain exact; the new driver additions reverse to the immutable prior driver SHA.
