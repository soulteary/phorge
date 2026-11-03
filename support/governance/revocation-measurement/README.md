# N12 observation consumer foundation

This test-only component fixes the event contract and inventories actual native
seams. It does not change sessions, permission axes, cache settings or production
features. Four inventory adapters remain **unsupported/uninstrumented**. The narrow
application-session Query producer has a separate c419 runtime checkpoint;
that is not proof of this changed API-token successor. Both current-source
profiles require fresh execution. No SLA is adopted. The eleven tests use
synthetic event data only to verify consumer behavior. Earlier
SQL/HTTP receipts cannot be converted into timing evidence.

An actual producer must record the native transaction operation invocation and
successful return with no outer transaction, using a monotonic clock and an
opaque hash of the persisted native transaction/transition identity. These
fields are `operation_start_ns` and `commit_confirmed_return_ns`, explicitly
labelled `native-transaction-operation-call-return`. The fixed t0 is the native
operation return confirming commit. This is a conservative editor-call bracket,
not actual SQL COMMIT-send/ack instrumentation or an exact database timestamp.
The actual commit lies inside that operation bracket. Per-attempt observations use the
same monotonic clock domain; cross-process producers must establish the same
boot identity and publish only its hash, never raw host or boot identifiers.
Cross-host observations need a different demonstrated clock protocol and are
refused by this contract. A success boolean or wall clock alone is insufficient.

`consumer.py --events <bounded-json>` rejects unknown fields, duplicate keys,
missing commit binding, noninteger/negative/backwards clocks, mixed transition
identities and mismatched boot domains. It excludes requests started before t0
from postcommit evidence. It describes the first explicit denied request's
start/finish bracket, distinguishing unknown/transport failures from denial.
This initial profile requires serial non-overlapping attempts: each start is
at or after the previous completion. Overlapping requests are rejected rather
than ordering observations by their request-start positions. A later allowed
result is reported, never discarded. Re-enable requires a new
transition identity, not extension of a previous revocation timeline.

The pure consumer cannot authenticate arbitrary JSON as a native event. Its
output permanently states `producer_attestation=unverified-input`,
`measurement_acceptance=false`, `native_authority_proved=false`,
`convergence_proved=false` and `SLA_proved=false`. Source hashes in the inventory
identify review seams; they are not producer-hook or runtime attestations.
Actual adapter integration must add supported isolated native commit/request
probes with current source binding and reviewed receipt provenance before a
separate acceptance tool can certify measurements. No importer may upgrade
these descriptive outputs merely because their JSON shape validates.

Gateway admission, native application sessions, API tokens, media credentials,
background jobs and persistent connections remain separate profiles. Preserve
accepted native boundaries. Future media credentials must not revive after
logout/disable/re-enable, while native web/API policies need their own explicit
review; this component never installs a global irreversible revocation policy.
In-flight responses and already sent/downloaded copies are outside recall.

## Narrow actual producer candidate (unrun)

`native-organization/revocation-measurement-cases.php` creates an owned synthetic
Project and native session, verifies an independent authenticated Query can see
it, then applies an actual native VIEW transaction. Its fixed t0 brackets the
real editor call returning with no outer transaction. A persisted native record
and group are checked, then two independently bootstrapped authenticated Query
processes must observe both the committed source digest and denial. All timing
comes from the parent PHP `hrtime(true)` around serial exec requests, including
spawn/authentication/reap overhead. Children prove the same hashed Linux boot.
No raw PHIDs, transaction group, cookie or token enter the returned events.

The fixture requires `PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION` equal to the
actual executed source commit; no default revision is substituted. It adds five
mandatory native assertions and three strictly registered Query workers, whose
owned directories are left for the full outer cleanup. The source hook and
worker action have only passed syntax review, not supported SQL/runtime tests.
`describe_native_query_receipt()` validates the exact narrow envelope and source
binding but still demands an independent execution/artifact attestation; an
arbitrary source-bound JSON document is not authenticated runtime evidence.

The candidate explicitly incorporates protected main
`7c38bf7b4da36e0523aa23f85ec4d576d3d9bdb4`, preserving approved membership and
all 584 prior main obligations. Its five new obligations make 589, with 22
registered workers and an expected 193-method native suite. These are pending
runtime obligations, not observed passing counts. Static governance tests (102)
and permission tests (84) passed after integration. The CLI strict gate requires
an externally supplied `--measurement-source-revision`; CI supplies the checked
out `GITHUB_SHA` both to the producer and verifier. It never trusts the event's
own revision as its expected value. The dynamic measurement block is validated
field by field before joining the otherwise exact native receipt tree.

## Second profile: owned API token deletion (new, unrun)

This successor preserves the queued Query component `c41997e0...` unchanged in
its own branch. That checkpoint subsequently passed 193 native methods and 589
SQL checks, with 5,937 runtime/dependency files matching. Those results do not
certify this changed successor. Its current obligations are 193 native methods,
594 SQL checks and 25 registered worker cleanups, all requiring fresh execution.

The new fixed `measurement.api-token-auth` worker uses actual native Conduit
`authenticateUser` on an anonymous request and an owned standard API token.
Success must authenticate the expected real user. Denial requires both actual
absence of that token row and the native `ERR-INVALID-AUTH` branch. Other
exceptions or results are unknown/failure, never substituted for denial. It is
an authenticator-method profile, not full HTTP or gateway evidence.

The parent measures its own native token DAO deletion inside an owned Conduit
writer transaction, checks exactly one affected row and commit return, then
requires two independently bootstrapped authentication processes to observe the
same absent source. Only this ephemeral fixture token is deleted; no existing
session, other token, restoration or mass revocation is performed. All public
bindings are hashes; raw native error descriptions containing tokens are
ignored and never included in receipts.

Schema 2 is intentionally separate from Query schema 1. Token DAO deletion has
no native User transaction group, so its transition uses an
`operation_binding_sha256` and `native_transaction_record_available=false`.
The operation binding covers the owned token identity, pre/post source facts
and a one-use fixture operation nonce, not a fabricated database transaction ID.
Its clock boundary is `native-token-dao-delete-own-transaction`. Schema1 Query
records retain their actual persisted native transaction-group binding.

The frozen c419 Query source is not modified by this new worktree. No current
API execution, propagation SLA, convergence, HTTP or production proof is claimed.

### API candidate execution and cleanup contract

Run only from an exact committed, tracked-clean Linux checkout with the existing
owned full-native fixture environment and actual paired binaries. The selected
PHP must be 8.5.12 or a supported later version. Generate the library map in the
isolated staging copy, compare its intended delta, then execute:

```sh
REVISION=$(git rev-parse HEAD)
php tests/governance/run.php > "$NEW_PRIVATE_OUTPUT/native.json"
PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$REVISION" \
  php support/governance/native-organization/test-native-organization-mysql.php \
  > "$NEW_PRIVATE_OUTPUT/integration.json"
python3 scripts/governance/check_native_organization.py \
  --receipt "$NEW_PRIVATE_OUTPUT/integration.json" \
  --file-binary "$ACTUAL_PAIRED_FILE_BINARY" \
  --measurement-source-revision "$REVISION" \
  > "$NEW_PRIVATE_OUTPUT/accepted.json"
```

The output directory must be a new private directory owned by the runner; the
commands intentionally do not provision credentials, start database services,
claim a shared test slot or enable production features. The preexisting fixture
configuration supplies its disposable database and native service environment.
A caller must not replace the expected revision with one read from the receipt.
Both Query and API dynamic blocks must pass the full strict receipt validator.
Before accepting, compare every executed source/dependency file against the
committed archive and separately validate the producer's SHA and pinned PSL.

The API case deletes only its newly created standard token. Its `finally` rolls
back only its own open Conduit transaction and removes a surviving token only
after exact owner and secret comparison. It never recreates the token. The three
independent child handles are registered before spawn and remain owned by the
outer fixture registry; a local helper does not clean unrelated handles or
silently remove their directories. Acceptance requires the full outer cleanup:
25 registered workers/directories, zero live processes or remaining private
directories, all owned schemas/users/reader removed and the actual file/image
services cleaned. Any cleanup failure is a failed run. A response timeout does
not authorize restarting an existing run or treating an unknown result as deny.

The next integration should preserve the tested c419 Query producer bytes and
its schema1 decoder while merging protected main explicitly. At protected main
26f291add780672e41fea8afbe39a5c29da242a8, the union would require the existing
593 SQL obligations plus Query5 and API5, totaling603, with25 workers and an
expected194-method native suite. These are source-derived **pending** counts,
not runtime results. If the private-mail candidate is merged first, recalculate
from its actual accepted main gate; do not copy603 or historical594 over newer
obligations. The isolated API branch itself remains based on c419 and has not
been merged with that newer main or executed.

## Protected main26f integration candidate (unrun)

This separate successor explicitly retains all protected main26f VIEW source
and nine VIEW obligations. Its required SQL set is603 (593 main+5 Query+5 API),
with25 registered workers and194 expected native methods. These are pending
obligations, not reused c419 execution evidence. The original Query producer
and API producer bytes are unchanged from their component checkpoints.
