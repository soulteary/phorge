# Native Wiki retirement

Status: implementation in progress; not approved for release or deployment.

The native Phriction application is being removed. A separate product will
provide Wiki functionality. This change does not select that product, migrate
content, or rewrite links to an external service.

## Runtime scope

The application, controllers, Conduit methods, search engines, typeahead,
Herald adapter, transaction editors, markup rule, help page and stylesheet are
removed. Shared Remarkup and governance code no longer constructs Wiki objects
or advertises Wiki content grants. Task and project permission semantics are
unchanged. Optional governance features remain disabled.

Historical Wiki mail is rejected at authorization, including when the optional
private-mail policy is disabled. Existing messages already delivered to a mail
recipient cannot be recalled. Queue and transport integration still require
verification; the unit fixture is not evidence of completed queue acceptance.

Ponder's answer-Wiki transaction is unrelated to Phriction and remains.

## Data and rollback

No database, document, attachment, audit row or backup is deleted. Historical
SQL patch identifiers remain. The seven PHP patches use fixed SQL table names
instead of removed application classes, retaining the original transformations.
They need fresh-schema and restored-schema integration tests before release.

The existing schema checker may report preserved Wiki tables as surplus. Do
not remove those tables to silence the warning. The release procedure must
verify the preserved schema and attachment references before any deployment.

A rollback must restore the matched application and worker versions as well as
configuration. Retain a consistent database and attachment backup. This branch
does not execute a rollback, reset a patch ledger or change active services.

## Evidence and remaining gates

`wiki.json` records removed paths, original hashes and retired native unit cases.
Retired cases are not counted as passing tests. The renamed mixed task-policy
case retains its task assertions; the task/project cases remain active.

Local checks completed:

- 56 affected native unit cases passed in an isolated PHP 8.5.12 container.
- Runtime discovery found no Wiki application or Wiki Conduit methods; task and
  project applications and `maniphest.search` remained available.
- 47 CI policy tests passed, including the explicit retirement inventory,
  rejection of restored retired paths, and historical hash checks.
- 91 operations tests passed, including five retirement tests. The mail fixture
  checks both values of the optional policy flag without sending mail.
- Modified PHP files passed syntax checks and `git diff --check` passed.

These results do not establish full regression or deployment readiness. Merge
remains blocked on:

1. Real SourceOperation entry and exit with the explicit retirement inventory.
   DATA-only tests pass; this does not certify the signed runtime lifecycle.
   Original hashes remain fixed, and unknown additions, reintroduced Wiki files
   and unexpected missing files remain rejected.
2. Updating mixed Completion, native SQL, source-operation and notification
   fixtures so retained cases execute and retired cases are reported separately.
   Do not execute archived Wiki code as current-source evidence.
3. Fresh and restored database validation, including the PHP patches and
   preservation of documents, attachment references and transaction history.
4. HTTP routes, search results, old links, historical jobs, task/project editing
   and final independent review on the exact PR head.

All required CI gates remain in place. No failed or unexecuted gate is waived.

## Integration progress

The current retirement implementation has additionally passed:

- the complete pure native governance suite (276 methods, PHP 8.5.12);
- 81 native-source and 43 native-policy SQL checks;
- 27 read-set and 66 native-pipeline SQL checks;
- 109 retained Completion checks using the pinned Gorge file and queue services;
- six real HTTP requests with a native synthetic session: three retired paths
  return 404, and task detail, task list and project list return 200;
- persisted historical Wiki mail quarantine with the private-mail policy off
  and on, retaining the original stored delivery snapshot;
- all seven historical PHP migration patches against synthetic nonempty tables,
  preserving two documents, two content rows and two mail-property records.

Each SQL run used disposable storage with no public listener. These are scoped
results, not a substitute for the final exact-head CI and source-owner checks.
