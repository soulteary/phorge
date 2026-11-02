# Owned full-native completion fixture

`fixture-run.php` is a CLI-only, explicit-opt-in integration runner. It refuses
all deployment selectors, including the string `0`, local configuration,
inherited SQL pools and inherited/global unguarded write contexts. Use the same
private `PHORGE_GOVERNANCE_FIXTURE_*` environment interface as earlier fixtures;
never commit or publish its values. Only owned real paired file/taskqueue processes bind disposable loopback ports;
no delivery daemon or public port is started by this harness. The caller must use a disposable network-none service
container; the fixture never connects to a production instance.

The full source quickstart restores 54 native application schemas and 500
initial tables. The separate owned governance schema holds lineage and a private
per-invocation nonce. Only successful CREATE operations acquire cleanup
ownership. A fixed reviewed set of pending current-source patches is applied
through the actual native StorageManagementAPI, in dependency order. SQL/PHP
patches execute before their ledger entries are marked applied. Unreviewed
pending keys or source paths fail closed; no fabricated ledger inserts substitute
for migrations. Quickstart table count and post-migration table inventory are
reported separately.

Control credentials stay private to the fixture. A separate setup account has
DDL only on exact owned native schemas; a distinct runtime writer has CRUD;
the reader has SELECT on the eight exact N07 schemas. Runtime roles cannot alter
the nonce/lineage schema. `getAdmin()` means the restricted setup principal,
not the server-control account. Native writes use owned AphrontWriteGuard
callbacks, never a global dangerous-write switch. Marker/nonce checks precede
native writes and cleanup. All created schemas and principals are removed.

The actual native pipeline intentionally commits synthetic changes and queues
publication with silent mode false. The explicit Gorge queue profile also verifies actual HTTP queueing and exactly selected
owned publish workers, including the native no-mail create case and a mailable
comment update producing mail/feed outboxes. No external delivery is
executed. The N07
phase closes only the writer pool this invocation acquired after an initial
empty-pool proof, then uses the private SELECT-only read-view scope. Concurrent
setup commits demonstrate snapshot A versus fresh B, ABA and group-membership
revocation. Guarded query and SQL-mode negatives must remain blocked. Writer
routing and caches are restored after each reader scope.

Stdout is one strict JSON receipt. Native console output is captured rather than
prepended to JSON; only its byte count/hash are reported. Primary failures and
cleanup failures are separate, with sanitized class/file/line evidence. No raw
SQL, identities, credentials or configuration values are printed.

`fixture-coverage.json` lists implemented, pending and unproved gates. A passing
finite fixture never proves arbitrary extension/external-effect closure, external worker
delivery, every caller, execution freshness or a
production executor. Keeping optional features off is a user constraint, not a
substitute for implementing or validating those missing capabilities. Prior
85/54/66 native SQL regression receipts remain independent and must rerun when
shared Reader/observer code changes. Final acceptance also requires matching
executed source hashes, hosted CI/review and merged-main evidence.

## Strict receipt acceptance and diagnostics

Default CLI invocation runs phase `all`. The explicit
`PHORGE_GOVERNANCE_COMPLETION_QUEUE_PROFILE=gorge` profile is required for full
acceptance; it first exercises native publication, then the real paired HTTP
queue and selected publishing workers. Run the strict gate on the unmodified
stdout JSON receipt:

```sh
python3 scripts/governance/check_completion.py --receipt "$completion_receipt"
```

The gate rejects failed runs, cleanup errors, nonzero terminal recovery,
partial/diagnostic phases, native-only queue profiles, duplicate check names,
incorrect counts, missing required runtime checks, duplicate JSON keys, unknown
receipt fields and source patch/hash drift. All 24 matrix rows must have their
own check IDs in the receipt. The matrix includes one intentionally blocked
historical-member contract; it does not mean 24 new enabled production features.
Positive and negative publication cases, rollback/support evidence, current
Gorge file roundtrip and private service cleanup are required independently.

The sanitized checker result certifies only structural acceptance of this fixed
N05/N07 integration evidence, including nine existing operation vocabularies.
It does not claim N03, global development completion, an execution token, hosted
CI/review or production acceptance. Executed-source hashes and final-commit
review remain separate requirements.

`php support/governance/completion/fixture-run.php --readview-only` is a strict
diagnostic entry point sharing the full source restore, privileges and safety
bootstrap. It skips the file service, native writing pipeline and queue service,
then runs actual read-view experiments. Its receipt explicitly says
`phase=readview-only` and `diagnostic-readview-only-not-full-acceptance` and is
always rejected by the full completion checker, even when its own tests pass.
Unknown CLI arguments are refused. CI acceptance must continue to invoke the
default all phase and must never strip an output prefix or rewrite diagnostic
fields into a full receipt.
