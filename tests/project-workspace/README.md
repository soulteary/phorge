# Isolated native workspace HTTP and browser acceptance

Run the D01/D05/D06 native gates first. This harness then starts the actual PHP
front controller with a fresh database namespace, synthetic users/sessions,
native project/task transactions, and the same explicit MySQL and paired Gorge
file/image/render services used by D01. It never reads a deployed configuration
or reuses a production data volume, session, user, or database namespace.

The driver requires all D01 disposable fixture environment variables, including
`ATOMIC_TEST_DISPOSABLE=1` and the exact `GORGE_CONTRACT_REF`. It refuses an
existing `conf/local/local.json`, generates a private temporary configuration,
verifies schema issues against the native surplus-only rule for trimmed apps,
and removes only its own unchanged configuration and namespace at shutdown.
An external edit makes ownership verification fail instead of deleting it.

```sh
python3 tests/project-workspace/serve.py \
  --output /tmp/phorge-workspace-http \
  --exit-after-http
```

The output directory must be new. Omit `--exit-after-http` to keep the disposable
app available for browser checks. The driver prints the path to a private
`handoff.json` containing synthetic login links and the bounded state-control
endpoint. Ordinary evidence contains no session values or control secret.

CI adds `--require-clean-worktree`, shared with the native gate. This checks
Phorge HEAD and actual tracked/untracked status before any storage work and
after cleanup. Dirty source or a changed HEAD makes the report fail even when
all HTTP assertions passed. Both source snapshots are retained. Local TDD may
omit the flag, with dirty status explicit in the report; such evidence is not
a clean release gate. Keep paired Gorge source and output outside Phorge's
worktree. An existing output directory is refused without overwriting its
files, including another run's successful report.

Every run uses a unique `*.localhost` hostname and a random loopback port.
Chromium resolves this hostname to loopback; synthetic `phsid`/`phusr` cookies
therefore cannot overwrite another localhost application's cookies. Python
HTTP clients connect only to the selected loopback address and never use an
environment proxy. For a local curl diagnostic, use the private handoff's
`curlResolve` value with `--resolve`.

The router exists only for explicitly launched PHP `cli-server` tests. No test
route is registered in the production application or web document root. The
session and state endpoints require the private run key. State changes allow
only the three documented feature controls, verify configuration ownership,
and update the tracked digest; they cannot target another file or account.

HTTP validation preserves the production-default builtin-composition mode.
Mandatory D01 probes separately exercise actual Gorge image composition.
Neither the driver nor the feature adds a production PHP rendering fallback.

The functional HTTP server explicitly uses `opcache.jit=disable`, retaining
OPcache and all HTTP assertions. Function JIT reproduces an incorrect retained
SQL-formatter result before web startup on PHP 8.4/8.5 CI runtimes; a DB-free
real-server regression simulates that host configuration, checks exact formatter
outputs and rejected argument counts, and verifies the launcher's selected mode.
CI runs this regression before HTTP acceptance. The report records the actual
server PHP version, SAPI, OPcache enable/CLI settings, JIT mode, and buffer size.
This is an isolated functional-test setting; production PHP/runtime settings
are unchanged. PHP documents `disable` as its default JIT mode since 8.4:
https://www.php.net/manual/en/opcache.configuration.php#ini.opcache.jit

## Automated HTTP coverage

- Real native sessions for an editor, reader, observer, and Chinese editor.
- Real form publication and exact one-transaction persistence.
- Authorized old entry lookup beyond the first list page.
- Reader/observer history access, valid-CSRF direct-POST denial, and private
  project denial without title leakage.
- Chinese publication form labels.
- Unsaved native task draft GET with zero task writes; explicit native Save
  creates exactly one task.
- Read-only transition: old records remain, publication UI disappears, and an
  old valid form cannot append a record.
- Disabled direct draft entry produces a controlled denial and no task.
- Native/unified display transition preserves native board access and adds
  task-status badges only in unified mode.

The report records every assertion and cleanup outcome. A failed check exits
nonzero. Native fixtures, HTTP flows, and browser layout are distinct evidence;
the HTTP report does not claim browser validation.

## Browser checks

Use the private handoff to open a synthetic session. The public
`browser-fixture.json` contains projects, task titles/IDs, columns, draft URL,
and an old entry ID. Keep all browser traffic on this disposable hostname.

| ID | Action | Required evidence |
| --- | --- | --- |
| UI01 | Open the main workboard as owner. | Native tasks, unified tabs, and visible toolbar labels; no duplicate task-state system. |
| UI02 | Choose native All Tasks. | Completed Long Column 12 remains completed and visible. |
| UI03 | Scroll to a long column's bottom and across to the fourth column; drag Long Column 12. | Real mouse drag; reload; destination persisted; task status still resolved. |
| UI04 | Change native status/time/author ordering and task filters. | Menu works, query/URL changes, visible results remain scoped to the current project. |
| UI05 | Open a record-to-task draft, cancel it, and inspect task count. | Zero task writes for GET/cancel. |
| UI06 | Open a draft and explicitly Save a distinct task title. | Exactly one saved task, current author/default status, source note retained. |
| UI07 | Open as reader/observer. | History readable, publication hidden, private source absent. |
| UI08 | Open as Chinese editor. | Chinese headings, captions, validation, and task-draft denial render; stored keys unchanged. |
| UI09 | Toggle history read-only and reopen an old record link. | Authorized content readable; old form POST denied. |
| UI10 | Toggle drafts off and visit the direct `projectNode` URL. | Controlled denial; ordinary task creation still works. |
| UI11 | Toggle workspace native/unified. | Native layout returns without deleting tasks, columns, or records. |
| UI12 | Check fullscreen, narrow viewport, hidden-column control, and horizontal/vertical scrolling. | Labels and native controls usable; no workspace overlay obscures cards. |

Capture screenshots of the actual tested UI and record the database persistence
assertions separately. The upstream author's 255 checks are not this run's
result. Stop the driver after the browser checks to destroy its namespace,
synthetic sessions, private handoff, and owned configuration. The ordinary
reports/screenshots remain for review.
