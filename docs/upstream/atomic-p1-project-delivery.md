# P1 project records and workspace delivery ledger

D05/D06 source feature contribution: `soulteary/phorge#49` at
`4051759aedb701c46b0b859185d08b6873fbd066`. Implementation baseline:
`a6d18ba72c805bc54e703ba2c02a53d5ba3d2290`.

## Review and dependency boundaries

| Package | Review reference | Dependency | Included capability |
| --- | --- | --- | --- |
| D01 | [PR #52](https://github.com/soulteary/phorge/pull/52) | main | Disposable regression gates, paired Gorge contract checks, modular Chinese catalog loading, explicit preflight discovery. |
| D02 | [PR #53](https://github.com/soulteary/phorge/pull/53) | D01 | File transform visibility; a separate feature package. |
| D03 | [PR #54](https://github.com/soulteary/phorge/pull/54) | D01 | Safe policy presentation, explicit replacement editing, and historical rule preservation; a separate feature package. |
| D04 | [PR #55](https://github.com/soulteary/phorge/pull/55) | D01 | Opt-in hierarchy navigation and native ancestor privacy; a separate feature package. |
| D05 | `feat/atomic-d05-project-records` | D01 | H01–H08, H13, H14: append-only native records, permission checks, historical readers, Feed/backlink filtering, English and Chinese text. |
| D06 | `feat/atomic-d06-project-workspace` | D05 | W01–W04, W06–W08, H10–H12: optional native workspace, scoped shortcuts, native status presentation, authorized zero-write task drafts and explicit native Save. |

The D05 and D06 references intentionally use branch names until review URLs
exist. D02–D04 are independent feature reviews and are not prerequisites for
the records/workspace dependency chain. Foundation updates are merged normally
into D05 and then D05 is merged normally into D06.

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

The final registered counts are D01 49, D05 24, and D06 11. D05 requires a clean
73-method native run; the stacked D06 branch requires a clean 84-method native
run. No skips are allowed. Exact result reports record the tested commit and
worktree state; a report from an older commit or dirty tree does not complete
the release gate. CI retains the native results in `atomic-native-php-*` and
the separate HTTP results in `atomic-workspace-http-php-*` artifacts.

Before the two new foundation discovery regressions, clean native runs
reproduced 71/71 D01+D05 and 82/82 D01+D05+D06. The separate real HTTP driver has
reproduced 30/30 assertions with successful owned configuration/database cleanup.
Those assertions cover actual native sessions, valid-CSRF denied writes,
private history/title filtering, a real explicit task Save, zero-write draft
GET, direct-route switch enforcement, and native/unified transitions.

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
