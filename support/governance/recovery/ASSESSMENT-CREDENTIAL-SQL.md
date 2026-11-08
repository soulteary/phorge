# Isolated durable credential readset integration

This fixed CLI exercises the real schema-4 RestoreService submit, repeated
preview, independent review, stale-review rejection and incomplete execution
refusal. It is a new selected proof fixture, not a production observer factory.
No production class, native permission, default feature or existing driver is
changed. It must run only against a disposable full native fixture owned by Root.

Entry (supported PHP 8.5.12 and isolated MySQL prerequisite required):

```
PHORGE_GOVERNANCE_ISOLATED_TEST=1 \
PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$FIXTURE_SOURCE_REVISION" \
PHORGE_GOVERNANCE_GORGE_FILE_BINARY="$ROOT_REVIEWED_FILE_BINARY" \
PHORGE_GOVERNANCE_GORGE_FILE_SHA256="$ROOT_INDEPENDENT_FILE_SHA256" \
php support/governance/native-organization/test-assessment-credential-readset-mysql.php \
  --assessment-credential-readset > "$PRIVATE_RECEIPT"

python3 -B scripts/governance/check_recovery_assessment_credentials_v2.py \
  --receipt "$PRIVATE_RECEIPT" --source-revision "$FIXTURE_SOURCE_REVISION" \
  --file-binary "$ROOT_REVIEWED_FILE_BINARY" \
  --file-binary-sha256 "$ROOT_INDEPENDENT_FILE_SHA256"
```

`PHORGE_GOVERNANCE_FIXTURE_DSN`, `PHORGE_GOVERNANCE_FIXTURE_USER` and
`PHORGE_GOVERNANCE_FIXTURE_PASSWORD` must already be supplied through Root's
private owned-fixture environment. Do not echo them. The copied fixed CLI retains
all existing supported-PHP/deployment-config/source restoration/grant/owned File
startup and cleanup prerequisites. Its only accepted selector is the one above.
The File digest is independently supplied from real binary bytes and Root's
reviewed pairing/provenance, never accepted from the receipt itself.

The fixture reuses the real five-user preparation, avatars, sorted distinct
administrator roles, native sessions and both real identity-epoch prewarms.
It creates subject Session, Conduit and OAuth access-token rows through their
native constructors before the native User disable transaction, then records the
real native stop. It explicitly verifies all three seed rows remain present.
The stopped subject is neither administrator. The support-only owned observer
wrapper independently provisions/validates its observer; the private Store owner
still mints its own opaque context and uses the real default User writer.

Eight source-derived SQL obligations verify actual owner-held MDL/InnoDB on all
three source tables; a persisted ticket's three actual digests; stable write-free
repeated preview; a real independent `reviewed-blocked` record; each source's
native DAO deletion followed by a changed selected digest and exact old-review
`sources_stale` rejection with the seven domain-table fingerprint unchanged; and
finally the unchanged `requireCompleteForExecution` refusal on a fresh reviewed
ticket. These are one sequential isolated integration experiment, not eight
independent scenarios. Native DAO deletions are explicit fixture revocations;
this does not assert all native DELETE producers implement INSERT fencing.

The current v2 strict consumer retains the historical 220 exact source pins and
adds two pinned source adapters (222 inputs) (a conservative inherited
native/governance dependency set, not a coverage percentage), derives eight SQL
labels from the captured case source, and expects eighteen total obligations.
It verifies standard map/manifest source pins, real File stop/storage removal,
55 schema/two runtime-principal/reader cleanup, independently owned observer
removal and unchanged native-writer grants, and the real empty native-worker
registry. It emits no raw bindings, session/token values, passwords or row data.

Unknown owner/observer state is checked before generic rollback, pool closure,
environment teardown or database disposal. Private contexts/resources remain
retained instead of claiming cleanup. Retained references cannot prevent natural
mysqli/OS close or implicit rollback when PHP exits. Primary and cleanup handling
comes from the reviewed observer and focused-owner lifecycle. Fixed safe PHP
failure projection is inherited; raw failed receipts remain private and are not
success artifacts. The additional `assessment-credential-focused.yml` invokes this fixed CLI and
strict v2 consumer on disposable PHP8.5.12/MySQL. It preserves paired File build,
actual binary/provenance, standard mapper double verification and success-only
artifacts. Failure uploads contain only the fixed reprojected safe diagnostic;
raw receipt/stderr remain private. It does not replace required Atomic CI or
provide execution approval.

Host controls load the actual fresh-process factory/observer/case declarations
and real empty registry, execute the actual refusal/fingerprint helper and whole
driver cleanup block with explicit collaborators, exercise the actual public
consumer with DATA-only binary bytes, and reject source/receipt tampering. They
do not run MySQL, File binaries, the fixed SQL CLI, SMTP or a browser. The new
physical experiment, unknown-COMMIT faults, DDL contention, continuous authority,
production observer factory, complete family coverage and restoration permit
remain unproved/false until their separate gates are satisfied.

The current-main source view accepts only the already reviewed exact Calendar
driver inverse and exact known-zero-terminal worker inverse before checking the
unchanged historical pins. A missing, unrelated or corrupted change is refused,
even when a current-helper hash is repinned. The v1 consumer remains historical
and intentionally refuses this newer current source. No production observer
factory is introduced, and native restoration remains incomplete/blocked.
