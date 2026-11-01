# P1 project records and workspace delivery ledger

D05/D06 source feature contribution: `soulteary/phorge#49` at
`4051759aedb701c46b0b859185d08b6873fbd066`. Implementation baseline:
`a6d18ba72c805bc54e703ba2c02a53d5ba3d2290`.

## Current merged checkpoint

All six packages merged through #52–#57. Follow-ups #58–#61 added source
provenance, the sole PHP 8.5.12 baseline, authentication-navigation coverage
and notification fixes. Current native inventory is 141 methods plus a separate
30-assertion HTTP gate, paired to formal Gorge `2026.11.01-r3` via
`tests/atomic/paired-gorge.json`. Optional controls remain off/native/false.
The branch integration narrative below is historical, not pending merge work.
Current design-anchor and browser evidence boundaries are documented in
`atomic-required-gates-and-evidence.md`.

## Review and dependency boundaries

| Package | Review reference | Dependency | Included capability |
| --- | --- | --- | --- |
| D01 | [PR #52](https://github.com/soulteary/phorge/pull/52) | main | Disposable regression gates, paired Gorge contract checks, modular Chinese catalog loading, explicit preflight discovery. |
| D02 | [PR #53](https://github.com/soulteary/phorge/pull/53) | D01 | File transform visibility; a separate feature package. |
| D03 | [PR #54](https://github.com/soulteary/phorge/pull/54) | D01 | Safe policy presentation, explicit replacement editing, and historical rule preservation; a separate feature package. |
| D04 | [PR #55](https://github.com/soulteary/phorge/pull/55) | D01 | Opt-in hierarchy navigation and native ancestor privacy; a separate feature package. |
| D05 | [PR #56](https://github.com/soulteary/phorge/pull/56) | D01 | H01–H08, H13, H14: append-only native records, permission checks, historical readers, Feed/backlink filtering, English and Chinese text. |
| D06 | [PR #57](https://github.com/soulteary/phorge/pull/57) | D05 | W01–W04, W06–W08, H10–H12: optional native workspace, scoped shortcuts, native status presentation, authorized zero-write task drafts and explicit native Save. |

D02–D04 are independent feature reviews and are not prerequisites for
the records/workspace dependency chain. Foundation updates are merged normally
into D05 and then D05 is merged normally into D06.

Main `509e2754eb` contains merged PRs #52–55. D05 incorporated this current main
through the normal merge `69075fae3c`; its only conflict was the generated source
library map, which was regenerated and checked for deterministic output. D06
normally merges that D05 update and therefore includes all six packages.

## Activation and retained ownership

- `project.history-state` defaults to `off`; `active` allows publication and
  `read-only` retains authorized readers while rejecting all new records.
- `project.workspace-mode` defaults to `native`; only explicit `unified`
  enables the shared shell. Native task data, columns, filtering, ordering,
  drag metadata, and the existing status dictionary remain authoritative.
- `project.history-task-drafts` defaults to `false`. Direct routes enforce the
  flag, active publication, current account validity, source/project access,
  and native Maniphest creation capability. GET performs no task write; native
  Save uses the current author and native status, priority, and policies.
- Disabling controls does not delete records or task data. Historical native
  record types remain readable, and new record payloads do not create another
  execution-state system.
- Existing retired-application cuts stay in place. Organization/member/role
  expansion and global task status additions remain deferred. File, image,
  rendering, background worker, and cleanup ownership stays with the existing
  Phorge/Gorge division.

## Reproduced evidence and final gate requirements

Focused package coverage comprises D01 49, D05 24, and D06 11 methods. Clean
focused runs reproduced 73/73 D01+D05 and 84/84 D01+D05+D06; these are not the
complete gate for the integrated current-main source. With D02–D04 now on main,
the all-package native gate is 123 methods for D05 and 134 methods for D06.
Run `python3 tests/atomic/run.py --output <new-directory>` without package
filters for that complete gate. No skips are allowed. Exact reports record the tested commit and
worktree state; a report from an older commit or dirty tree does not complete
the release gate. CI retains the native results in `atomic-native-php-*` and
the separate HTTP results in `atomic-workspace-http-php-*` artifacts.

The separate real HTTP driver has
reproduced 30/30 assertions with successful owned configuration/database cleanup.
Those assertions cover actual native sessions, valid-CSRF denied writes,
private history/title filtering, a real explicit task Save, zero-write draft
GET, direct-route switch enforcement, and native/unified transitions.

The functional HTTP fixture explicitly launches PHP with `opcache.jit=disable`
while retaining OPcache and all 30 assertions. CI PHP 8.4/8.5 with function JIT
miscompiled the retained SQL formatter before web startup; the same failure was
reproduced without storage, and a real-server regression verifies exact query
outputs, invalid argument rejection, and the selected functional mode. CI runs
that regression before HTTP acceptance. Evidence includes effective server PHP,
SAPI, OPcache enable/CLI settings, JIT mode, and buffer size. This fixture-only
setting does not change the production PHP 8.3 baseline or production settings.
PHP documents the default JIT mode as `disable` since 8.4:
https://www.php.net/manual/en/opcache.configuration.php#ini.opcache.jit

Actual browser validation separately exercised long-column/cross-column mouse
drags with reload and database persistence, retention of both open and resolved
native task status, Chinese task draft Save, desktop layout, and a 390 px Chinese
viewport. Screenshots and database snapshots are independent evidence, and are
not added to either automated count. Only the driver-owned app was stopped;
shared disposable dependency services remain under the integration owner's
control.

The HTTP fixture preserves the existing optional builtin-image composition
mode. A pre-existing Gorge recipe gap for 76/120/152 px favicon outputs remains
documented in D06; this package does not widen the external API, change the
production default, or add a PHP fallback.
