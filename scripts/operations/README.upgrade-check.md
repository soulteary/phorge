# Read-only upgrade inventory

`upgrade_check.py` compares a Phorge/Gorge inventory with the
[dated component catalog](../../docs/operations/2026.11.19-r3-components.json).
It is separate from the existing production preflight and backup adapters. It
does not add support for their rejected Redis, bind-mount or custom-DB topologies.
See [upgrade and rollback](../../docs/operations/upgrade-and-rollback.md) for the
remaining manual gates.

Create a private bindings JSON outside the repository. Its keys are `phorge`,
`phorge-daemon` and the nine `gorge-*` component names in the catalog. Each value
is the selected container's full 64-character ID, not a name or address. An
optional `preserved-app` key protects an unrelated application's exact image ID.
Resolve IDs using the deployment's existing administrative access; do not upload
bindings, snapshots or raw Docker output to a PR.

Run collection on the machine/context whose Docker daemon owns those containers.
Use an existing user-owned directory with mode 0700; each new snapshot is created
exclusively with mode 0600. The paths below are placeholders for private files.

```sh
python3 scripts/operations/upgrade_check.py collect \
  --bindings "$PRIVATE_BINDINGS" --output "$PRIVATE_BEFORE"
python3 scripts/operations/upgrade_check.py preflight \
  --catalog docs/operations/2026.11.19-r3-components.json \
  --snapshot "$PRIVATE_BEFORE"

# After a separately authorized upgrade, resolve any recreated container IDs
# into a new private bindings file. This utility does not perform that upgrade.
python3 scripts/operations/upgrade_check.py collect \
  --bindings "$PRIVATE_AFTER_BINDINGS" --output "$PRIVATE_AFTER"
python3 scripts/operations/upgrade_check.py recheck \
  --catalog docs/operations/2026.11.19-r3-components.json \
  --baseline "$PRIVATE_BEFORE" --snapshot "$PRIVATE_AFTER"
```

Collection only calls Docker container/image inspection and a fixed PHP
configuration read in both Phorge processes. It does not pull images, contact a
registry, open SSH connections, start/stop containers or alter configuration.
It discards environment variables, mounts, container names and Docker stderr.
Snapshots still contain operational metadata and must remain private. Reports
use fixed role names and check codes rather than input values or paths.

Preflight checks the observed runtime health and disabled governance flags; it
does **not** assert that the old images match the target release. Recheck also
requires immutable image references, actual repository digests, Linux platform,
source/version labels, and the preserved application's unchanged image ID. It
checks web and daemon separately. Missing health probes remain `not_verified`;
an external direct-health check must be retained separately, not converted into a
fabricated Docker health result. Unknown or missing governance enablement flags
fail the check, including the media HTTP switch.

Current observations may be at most one hour old; a recheck baseline may be at
most 24 hours old and must precede the current observation. Collection refuses
container identity, restart or health changes detected between its two reads.
It is a bounded observation, not an atomic snapshot or continuous guarantee.
Offline JSON is operator-supplied evidence, not a signed attestation.

Exit codes:

- `collect`: 0 means a private snapshot was written, not acceptance.
- `preflight`/`recheck`: 1 means invalid input or a failed/unknown technical check;
  2 means these technical checks passed but external acceptance is incomplete.
  Neither assessment returns 0.

Database/Redis image verification, real-provider OTP login, backup/business
restore, image signatures/provenance and authenticated business/attachment checks
remain `not_verified` in this tool. It cannot discharge those gates, certify all
supporting authentication versions, or approve optional media identity activation.
The historical observation's passed checks are not imported as fresh evidence.

Tests use synthetic snapshots and a mocked Docker command boundary:

```sh
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests/operations -v
```
