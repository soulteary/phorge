# Private media qualification on the current baseline

Status: preparation only. No positive SQL, TLS or browser run is claimed by this
checklist. Existing instance configuration remains unchanged, with both media
options OFF. This record contains no deployment addresses or credentials.

## Baseline and evidence boundary

The initial baseline is main `6e5f7f2ecf1ad4f9b92c5edbee91c13c4d33f8c4`,
paired with the reviewed 2026.11.21-r2 Gorge release and PHP 8.5.13. A later
candidate must identify its own exact commit and immutable runtime images.

The November 18 disposable PHP 8.5.12 cohort passed trusted HTTPS browser and
independent HTTP checks. Those results remain historical evidence; they do not
qualify a changed candidate or the PHP 8.5.13 release automatically. November 21
native/OFF acceptance passed login, account switching, disable, logout, original
files and notifications. Another signed-in holder could read a native raw-file
capability URL. That observation is retained native behavior, not strict media
identity isolation.

Current safe controls run against the baseline:

- `python3 -B tests/governance/test_media_task_runtime_v2.py`: 20 passed.
- `node tests/governance/js/test_private_media_bootstrap.cjs`: 9 passed.
- Pure native governance baseline on PHP 8.5.13: 276 methods passed.
- Application media-session early-refusal controls on PHP 8.5.13: 4 passed.
- Current archive-owner controls: 12 passed.
- Native response contract on PHP 8.5.13: 1 test with 28 assertions passed,
  including MIME disagreement, 302/304 response hardening and observed OFF defaults.
  These constructed responses do not establish an authenticated HTTP 304 hit.

These are source/lifecycle and JS unit controls, not authentication, SQL, TLS or
browser acceptance. Historical conditional HTTP requests returned 200 without an
ETag; a genuine 304 response was not established. GD was exercised; no unused
ImageMagick dependency is required for that covered profile.

## Local admission checks

Before allocating any resource, record and independently review:

1. The exact clean candidate commit, complete source inventory and accepted
   provenance. The selected source operation rejects modified tracked bytes.
2. The literal local CLI and endpoint. The runner admits `/usr/bin/docker`,
   `/usr/local/bin/docker` or `/opt/homebrew/bin/docker`, but always passes its
   fixed local Unix endpoint and a fresh empty Docker configuration directory.
3. The actual engine behind that endpoint. On the inspected Mac, the admitted
   Homebrew Docker client exists, while the fixed socket resolves to a Podman
   machine socket. This does not by itself prove Docker-profile compatibility.
4. Exact local `sha256:` PHP and MySQL image IDs. No runner pull is permitted.
   PHP must measure 8.5.13, mysqli/mbstring, JIT disabled, buffer zero, CLI
   opcache zero, pcntl/posix/fsync and the complete capability profile.
5. The MySQL fixture must measure its required 8.4.13 profile, UUID,
   performance_schema and metadata-lock instrumentation on a fresh database.

A direct project-signed development commit and a GitHub merge commit have
separate external tooling admission paths. The existing merge-proof tag for the
baseline is present locally, but the external fallback verifier uses
`/usr/bin/gpg`, which is absent on the inspected Mac. A Homebrew GPG executable
exists at a different path. Do not modify a protected system path, replace the
trusted key, or disable verification to continue. GPG belongs only to external
source/CI tooling; it is not added to Phorge's application runtime.

The current candidate must first be reviewed and committed before genuine source
admission. A dirty source tree is an expected refusal, not a SQL failure.

## Execution sequence

The following commands describe the accepted entry points. Placeholders must be
replaced with independently checked local inputs. They are not ready-to-run
instructions and do not authorize feature activation.

```sh
python3 -B scripts/governance/run_media_task_selected_v2.py \
  --execute \
  --root <exact-clean-checkout> \
  --expected-checkpoint <exact-reviewed-commit> \
  --config <private-image-profile> \
  --output <fresh-private-directory>

python3 -B scripts/governance/project_media_task_selected_v2.py \
  --input <private-driver-result> \
  --expected-checkpoint <exact-reviewed-commit>
```

The profile is a canonical owner-owned, single-link mode-0600 file with exactly
`docker`, `mysql_image` and `php_image`. Keep it and raw captures private. Use
`.github/workflows/native-media-session-positive-sql.yml` as the exact current
source-owned sequence, including prerequisite controls, source proof and the safe
projector. Do not call the historical runner directly and label that result a
current-source execution.

Selected SQL is only the first integration layer. It must retain the exact
strict case counts, native schema and identity checks, independent zero-session
and metadata-lock observations, owned cleanup and normal source-operation exit.
A failed or unknown run is not retried or silently cleaned; preserve its state
for inspection. Broader native file/Gorge fixtures and genuine browser tests
remain independent gates.

## Positive acceptance matrix

Positive testing requires a fresh disposable instance with separately confirmed
permission to enable only its two media options. Existing instances stay OFF.
Do not infer that a previous round's temporary authorization applies to a new
instance.

- Real native login and ordinary A/B accounts; no injected browser session.
- Application-origin authorization and CSRF-protected issue, file-origin
  preparation and one-use redemption; replay, wrong-browser and wrong-origin
  refusal.
- Owner original text/image success; anonymous and other-account refusal for
  raw downloads and derived images, including HEAD and Range.
- Account switch, native logout, actual session expiry and disabled account
  refusal. Disable/reenable without an intervening media request must not revive
  an old credential.
- Current object/Space/application visibility revalidation. Withdrawal of the
  last native visible source denies the next request; an independently valid
  native source is not incorrectly revoked.
- Active HTML/SVG response containment and owner download headers. Browser
  execution behavior is separate from HTTP header assertions.
- Cache and conditional behavior: verify authorization before serving bytes or
  metadata. Never claim 304 coverage if the runtime emits no matching validator.
- Paired Gorge storage and the actually selected renderer; independently verify
  response bytes and output dimensions where applicable.
- Native flag-OFF regressions, default configuration, audit retention and exact
  cleanup. No native project permission axis is changed.

## Open gates

- Confirm disposable-only positive feature scope; until then do not run it.
- Resolve the actual local engine/profile qualification without weakening
  existing checks or changing the default socket/context.
- Resolve external provenance execution on the chosen host without expanding
  key trust. A reviewed clean project-signed candidate may use its existing
  direct-signature path; this does not qualify the baseline merge-proof path.
- Qualify the exact candidate under PHP 8.5.13, then complete real TLS/browser,
  file policy and session lifecycle together.
- Obtain CI and independent final review before merge. No deployment or
  permission migration follows from this checklist.
