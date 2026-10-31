# Project workspaces and development history

A project's workboard is the shared entry for executable task cards and its
append-only development history. Tasks retain native Maniphest permissions,
transactions, columns, filters, sorting and drag-and-drop behavior. Each task
card shows its native execution state; the default states include open, active,
blocked and resolved. Existing installations with a customized status dictionary
keep their configured values.

Project records describe decisions, completed stages and work comments. A node
records a title, Remarkup body, participants and an optional reported project
completion percentage. A comment records discussion without replacing that
percentage. Neither record has an independently editable task status. Native task
completion and a project's reported completion percentage remain separate facts.
The project overview, workboard and project lists link to the same history.

## Permissions and attribution

Publishing requires the project's current native `CAN_EDIT` capability, checked
again when the transaction is applied. Viewing history requires `CAN_VIEW`.
Permission changes therefore take effect for both publication and old links.
The actor and transaction creation time come from the authenticated transaction;
submitted author IDs, timestamps and extra payload keys cannot override them.
Published entries are immutable project transactions. Remarkup references,
mentions, notifications and feed visibility use native policy-aware rendering.

A project tag does not grant access to a private task. History references and
feed items also respect the referenced object's own policies. If the optional
team-access feature is installed, its policies participate through the same
native project capabilities; this feature does not depend on team classes.

## Task drafts from historical records

An editor may open a native task draft from a visible node. The draft contains
the record's title and body, a source link with attribution, and its project tag.
Opening or cancelling it writes no task. The editor must explicitly save the
native form, at which point normal task defaults and permissions apply. Source
participants, author, dates, completion percentage and task permissions are not
copied. The source project's current policy is rechecked when opening the draft.
Comments cannot be used as task-draft sources.

## Existing installations

No SQL data migration or history rewrite is required. The existing transaction
types `team:project-progress`, `team:project-keynode`, `team:project-node` and
`team:project-comment`, transaction IDs, author/time attribution and
`/project/progress/<project-id>/?entry=<transaction-id>#entry-<transaction-id>`
links remain readable. Historical state fields still describe the old record;
new stage records store payload version 2 and a null status. This applies even
when an older transaction type is used by an API client.

The old project progress menu item resolves to the native workboard when
Maniphest is installed, without rewriting saved profile menu configuration. If
Maniphest is not installed, the historical entry still opens the project record
page. Workboards retain upstream hidden-column counts and controls.

## Regression verification

Run the native storage-fixture suite in a development installation:

```sh
php bin/unit --no-coverage src/applications/project/__tests__/TeamProjectProgressTestCase.php
```

The database user must be allowed to create and drop the temporary unit-test
fixture databases. The 18 cases cover edit revocation, append-only history,
legacy payloads and paging, native task states and drafts, independent private
object policies, mentions/backlinks and native background feed publication.
Publication tests force the local native task queue and do not run mail workers.
The same class is discoverable by `arc unit`.

Browser acceptance instructions live in `tests/project-workspace/README.md`.
The disposable runner creates synthetic users, records and sessions in an
isolated database. It covers form publication, read-only/outsider access,
private task references, legacy links, native task drafts, filters/sorting,
column dragging and desktop/mobile rendering. It does not use a deployed site's
configuration or sessions.
