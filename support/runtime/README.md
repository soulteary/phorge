# Project PHP compatibility runtime

This package supplies the historical `arcanist` PHP library APIs used by
Phorge. It is maintained in this repository. Deployment, tests and maintenance
tools do not use an independently checked-out Arcanist repository or `arc`.
Client entry points (`bin/arc`, `scripts/arcanist.php`) and upstream test
products/fixtures are excluded. Testing base classes required by Phorge remain.
Repository-backed diff parsing, binary conversion and disk/client bundle loading
have been removed. A conservative file closure preserves the remaining APIs.

`manifest.json` records every maintained file, its content hash, original
source hash and reason for retention. The downloaded source had no Git
metadata: `upstreamRevision` is deliberately null. Its complete source snapshot
is identified by `upstreamSnapshotSHA256`. Neither identity is a claimed Git
commit. LICENSE and NOTICE apply to upstream-derived files.

The content identity is SHA-256 of sorted records:
`relative-path + NUL + lowercase-file-sha256 + newline`. `manifest.json` itself
is omitted to avoid a recursive digest; generated build/cache paths are
declared separately. `php scripts/runtime/verify.php` checks the recorded
identity, unexpected files, library map and current project symbol coverage.
After a reviewed library change, update its patch record and run
`php scripts/runtime/verify.php --refresh-manifest` explicitly.

Use `support/runtime/bootstrap.php` for library loading and
`bootstrap-cli.php` for the established error, timezone and signal setup.
Both paths reject mixing another registered copy of the library into the same
process. The internal registration name remains `arcanist` to preserve resource
lookups and interfaces.

Use `php bin/rebuild-library-map` to rebuild both runtime and Phorge maps.
Optional library root arguments limit the operation to specific maps. This
updates only the generated runtime map hash in the manifest; all other files
must still match their reviewed hashes. It uses the bundled XHPAST tool by
default and never downloads build dependencies
at runtime. `--alternative-parser` requires a previously prepared PHP-Parser.
Use `php support/runtime/support/xhpast/build-xhpast.php` and
`php support/runtime/support/php-parser/build-php-parser.php` to prepare these
tools explicitly during development or image construction. PHP-Parser is a
separate pinned dependency, not another Arcanist checkout.

`php bin/unit --no-coverage src/infrastructure/cluster/` executes real PHP test
cases with the retained Phutil unit engine. `--everything` runs Phorge test
classes. It does not advertise or run excluded upstream runtime test suites.
Failures, broken tests and an empty selection return a failing exit status.
Some module selections also include ancestor tests which build storage
fixtures. Configure the test database before running those selections, as in
the database consumer CI. The runner does not silently skip required storage
tests when a database is unavailable.

To inspect a reviewed local upstream snapshot, use
`php scripts/runtime/import.php /path/to/snapshot --destination /tmp/runtime-candidate`
with an empty destination. The importer refuses to overwrite a maintained
runtime or any nonempty directory. This candidate contains upstream-derived
source/resources and source identity; it is not a ready-to-install release.
Review it against the current package, port every manifest patch (including
the server diff API cuts), and preserve project bootstraps and maintenance
metadata. Then explicitly replace reviewed managed files, run
`php scripts/runtime/prune.php`, rebuild maps, refresh the manifest and run
the runtime contracts and project acceptance suite. Diff baseline changes
must be investigated rather than regenerated automatically. Import and prune
are maintenance operations, never required to build or run a checkout.

## Maintenance and PHP versions

The container and complete paired acceptance currently use PHP 8.3. The
`runtime-compatibility` CI job additionally checks the retained runtime,
maintenance tools and response safety contracts on PHP 8.4 and 8.5. Passing
these narrower checks does not claim full application or migration support on
those versions. Promote a new production baseline only after running the
complete paired acceptance and a representative application workload on it.

When evaluating upstream fixes, record the inspected source location and its
commit if available, or preserve the original snapshot and its SHA-256 when
Git metadata is absent. `upstreamRevision: null` must not be replaced with an
inferred commit. Keep a reviewed decision for each relevant fix: applied,
already covered by a project patch, or not applicable to a removed API.
Changes to HTTP/TLS, serialization, error handling, process execution and
parser behavior require regression cases for the retained behavior, including
failure paths, on every runtime-matrix version.

For each imported candidate, reconcile every `manifest.json` patch explicitly:
retain it, port it, or document why it is superseded. Update the patch record
and original source hash before refreshing maintained content hashes. Run
`verify.php`, both parser/map maintenance checks, response safety contracts and
the full paired acceptance; publish their exact source identities together.
Refreshing a manifest approves file identities, not compatibility or security.
