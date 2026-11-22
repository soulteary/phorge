# PHP 8.6 isolated compatibility

The production baseline remains PHP 8.5.13. This change adds an explicit
`php86-isolated-v1` profile for PHP 8.6.0 compatibility tests. It does not
qualify PHP 8.6 for deployment or change native permissions.

The production version predicate continues to reject PHP 8.6. The candidate
bootstrap requires both `PHORGE_PHP_COMPATIBILITY_PROFILE=php86-isolated-v1`
and `PHORGE_GOVERNANCE_ISOLATED_TEST=1`, the actual stable PHP 8.6 interpreter,
and absence of deployment selectors and local deployment configuration. Unknown
profiles, prereleases and dangling configuration links are refused. These
checks do not themselves prove container or network isolation; the fixture
runner supplies and verifies that boundary.

## Changes

- Temporary directory names use random bytes without lossy numeric conversion.
  Atomic `mkdir` decides ownership; an existing path is never adopted or chmodded.
- Pure and full-native receipt validators keep their production defaults. An
  explicit candidate profile selects the exact PHP 8.6.0 target. The actual
  runtime field is never rewritten to PHP 8.5.13. All original scenario IDs,
  schema, binary, source, ownership, cleanup and failure checks remain required.
- The candidate Dockerfile is rendered from the production recipe. Its
  base image, explicit isolated environment and APCu build differ; the production Dockerfile
  and release policy are unchanged. Images are local test inputs, not published
  release artifacts. JIT remains disabled.
- Source-admission compatibility uses exact inverse data and negative tests.
  Historical bytes are metadata only; the current signed tree is still executed.
  The trusted key set is unchanged.

APCu 5.1.28 fails to build against PHP 8.6 because removed internal APIs are
still referenced. The candidate pins upstream commit
`95f9ab828f95c6ca5877e584ead109189d0cec61` and verifies the archive SHA-256
`f9b5a106ad0e6ab942d8f73eca209d3e2892689a9b6653b4b10d702388605a1a`.
It builds with phpize because the PHP 8.6 image has no pecl executable.
This unreleased dependency is a production-adoption blocker, not a change to
the production APCu package.

## Validation

The candidate workflow executes complete Python unit discovery, the existing
pure native PHP suite, default-OFF boundaries, full native SQL with the paired
Gorge source, notification-source validation and directory SQL/browser tests.
The existing production CI is required separately and is not replaced.

To render a local recipe:

```sh
python3 -B scripts/runtime/php86-candidate-dockerfile.py --output /tmp/php86.Dockerfile
docker build -f /tmp/php86.Dockerfile -t localhost/phorge-php86-isolated:test .
```

Use the candidate image only with disposable schemas, synthetic identities and
loopback-only fixture access. `tests/governance/directory_product/run.py` accepts
`--runtime-profile php86-isolated-v1`; its default remains production PHP 8.5.13.
In browser mode the runner requires separate successful SQL and Chromium results,
verifies unchanged source and removes only exact owned resources.

Synthetic browser sessions do not prove enterprise OTP delivery or production
authentication. Candidate acceptance does not authorize optional features,
deployment or permission migration. No production addresses, credentials or
personnel data belong in shared receipts.
