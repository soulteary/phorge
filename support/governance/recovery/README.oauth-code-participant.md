# Native authorization-code source increment

This increment changes the existing retained `PhabricatorOAuthServerAuthorizationCode`
DAO INSERT path. It does not enable the OAuth application. With recovery OFF the
native parent INSERT is called unchanged; a process-local retained unknown owner
still rejects entry. The native server continues to generate the code and perform
its native protocol validation and consumption.

The selected fence owns the User PRIMARY read lock before opening the distinct
OAuth writer. Both owners acquire their own table MDL before InnoDB checks and
keep it through their own terminal operation. The actual DAO parent INSERT runs
inside that participant, and the complete code/client/secret/redirect projection
is checked before native commit. Two connections are not a cross-database atomic
transaction. Surfaced commit/owner cleanup uncertainty retains references and the participant
does not retry or unconditionally roll back. Quarantine is process-local, not
durable recovery. The public native database layer can itself retry a COMMIT
after decreasing managed depth; host exception controls do not prove physical
ACK-loss immunity. That inherited transport behavior remains a production/fault
qualification gate, separate from the selected known commit/rollback SQL cases.

The borrowed Store participant captures code rows on the existing User owner,
revalidates before owner end and rejects drift/budget/schema/route/observer
failures. Assessment schema 5 consumes four real selected readsets; readset schema
2 adds code. Stored schema4/readset1 remains separate and cannot smuggle the new
source. Every family and execution/provider permit remains incomplete/false.
Authorization scope/client mutations, code consumption/deletion, external identity,
SSH classification and other families remain explicit gaps.

## Local controls

From repository root, with the existing Python/PHP environment:

```
/Users/soulteary/miniforge3/bin/python3 -B -m unittest discover -s tests/governance -p 'test_recovery_oauth_code_*.py' -v
```

The actual native server/DAO default-OFF, real fixture includes and PHP methods
execute. SQL/connection/identity callbacks and performance-schema rows are explicit
collaborators. Two-ID marker and real owned child exit7/exit0 controls are not real
MySQL lock/transaction proof. Synthetic receipt mutation controls are not SQL
execution evidence. No physical SQL has been run for this increment.

## Fixed future SQL entry (Root only after review/admission)

The callable, single-selector entry is:

```
php support/governance/native-organization/test-oauth-code-owner-mysql.php --oauth-code-producer-owner
```

It retains the current isolated fixture preparation/ownership and starts one actual
paired File service for native avatar dependencies. It uses the native
`PhabricatorOAuthServer::generateAuthorizationCode`, a separate owned native worker,
the real Store transaction, current two measured User/OAuth thread IDs, and actual
observer queries. User is the PRIMARY waiter; OAuth is not mislabeled as requester.
Both threads must complete RC preflight before Store ownership and be independently
absent at terminal. A valid JSON result never substitutes for child known exit0.

Required existing environment inputs are `PHORGE_GOVERNANCE_ISOLATED_TEST=1`,
`PHORGE_GOVERNANCE_FIXTURE_DSN`, `PHORGE_GOVERNANCE_FIXTURE_USER`,
`PHORGE_GOVERNANCE_FIXTURE_PASSWORD`, `PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION`,
`PHORGE_GOVERNANCE_GORGE_FILE_BINARY`, and `PHORGE_GOVERNANCE_GORGE_FILE_SHA256`.
The DSN/profile must be newly owned disposable loopback and deployment selectors
must be absent. No grants or credentials are supplied by this document.
The runtime must satisfy the existing PHP8.5.12/MySQL/MDL profile and actual File
binary source/build admission. The original driver/Conduit/OAuth-access CLI remains
byteexact. This source does not authorize execution.

The new strict consumer is `scripts/governance/check_recovery_oauth_code_producer_v1.py`
with `--receipt`, `--source-revision`, `--file-binary`, `--file-binary-sha256`.
It binds 51 actual source inputs, eight SQL obligations across TWO commit/rollback
experiments, and 18 total checks, plus child/File/observer cleanup and 55 schemas /
two users / reader cleanup. Known unknown cleanup blocks DROP/close; retained
resources are not a successful receipt. It does not prove DDL races, transport
faults, all13 or production provider availability.

## Historical source coordination and current runtime admission

All old literal maps remain untouched. `recovery_oauth_code_source_delta.py`
strictly recognizes the complete six-file delta and restores exact public9bc
bytes. Historical consumers require the entire six-file current or baseline
cohort before applying that outer inverse, then retain their existing
Assessment/OAuth/Calendar/worker inverse layers and original literal hashes.
`recovery_oauth_code_coordinator_delta.py` separately reduces only exact reviewed
tooling/fixture compatibility changes. Missing/mixed/unknown cohorts and unrelated
byte changes are refused. Temporary source-test roots explicitly supply all six
files; corruption controls remain active. Existing three-source SQL labels remain
a subset claim; their current fixture explicitly checks schema5/four readsets and
four MDL tables. The new code-specific SQL phase has its own native code producer
and eight obligations.

The integration base is public main56483eebf91656102fe9bd1bc083a9993ce2988c,
including merged PR137. That current-runtime admission is a separate gate:
`media-session-ci/freshness.py` and `media-session-docker/snapshot.py` still reject
these changed runtime bytes against their reviewed9bc BASE. No historical inverse
is applied to that current-runtime predicate. After source review and an exact
source commit, Root must review a new BASE/ROOT_TREE binding, the exact eight-file
overlay and nineteen fixture pins, then perform a new actual native media matrix.
The previous97-check result does not qualify this runtime. No expected future
commit or runtime receipt is fabricated in this source snapshot.

No class/path/parent graph changed; src library-map bytes remain exact9bc. No
standard mapper execution is claimed for these new method bodies. Physical SQL,
current native-media runtime validation and independent review remain required;
production execution/provider/all13 are still blocked.
