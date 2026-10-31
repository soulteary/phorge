# Project records

Project records are append-only native project transactions. Nodes describe
results, decisions, and participants; work comments add discussion. An optional
completion percentage is a manual project assessment, not a count of completed
tasks. The native task engine remains the only execution-state source.

## Enable and stop publication

`project.history-state` defaults to `off`:

| State | Project menu | New records | Existing authorized records |
| --- | --- | --- | --- |
| `off` | Hidden | Rejected | Readable at their stable URLs |
| `active` | Visible | Current approved project editors only | Readable |
| `read-only` | Visible | Rejected | Readable |

An unknown state fails closed. Both the publishing service and native project
transaction types enforce the publication gate, including direct native-editor
calls. Hiding a menu does not disable stored transaction types, project policy
checks, Feed filtering, references, or old record links. To stop new records,
use `read-only`; do not remove the classes or transaction readers.

Publication reloads the account and project instead of trusting objects loaded
before a disable, approval change, or project-policy change. Participants must
be current users who can view the project; they need not be direct project
members. Empty participant input uses the publisher. The raw input is limited
to 50 entries before deduplication.

Project `view` and `edit` remain distinct. Revoking only edit still allows
authorized reading but stops publication. Revoking view hides old records,
Feed stories, and private backlinks. Public history requires both an explicitly
public project and `policy.allow-public`; anonymous users cannot publish.

## History compatibility

These native types remain registered in every state:

- `team:project-node`
- `team:project-progress`
- `team:project-keynode`
- `team:project-comment`

Historical six-field payloads, authors, timestamps, and transaction IDs are not
migrated. New non-comment records use server-controlled `version: 2` and
`status: null`, even through legacy transaction types. Known historical status
input is accepted and discarded on new writes; unknown status input is
rejected. A version-2 record displays "Recorded", while an old record without
a saved status displays "Unannotated". Neither inherits the current summary's
status. Comments do not change the completion percentage.

The original transaction supplies identity and time. Caller-supplied authors,
dates, IDs, and arbitrary properties are discarded. Published records cannot
be edited or removed through the record UI. Identical text may intentionally
be appended as two distinct events. This is a business history, not a
cryptographically tamper-proof audit log.

`/project/progress/<project-id>/?entry=<transaction-id>#entry-<transaction-id>`
reads the requested entry independently of list pagination and binds it to the
current project. `kind=node` includes legacy progress/key-node types;
`compose` chooses a publication form independently of the list filter.

Remarkup, mentions, Feed publication, and task/file references use retained
native mechanisms. The target task/file's own policy remains authoritative;
mentioning a private object or adding a project tag does not share it.

## Deployment and rollback

No new SQL table or destructive migration is required. Deploy all transaction
readers and matching worker code before enabling writes. Keep publication off
until every serving node can interpret these types and version-2 payloads.
Mixed old/new application or worker versions must not receive newly enabled
write traffic. Code rollback must retain readers for any records already
written; changing the state to `read-only` is the supported immediate stop.

This package does not install organization roles, alter parent/child project
semantics, extend global task status defaults, or bring file/image processing,
background execution, or cleanup back from Gorge into PHP. File/image fixtures
used by tests must use the paired Gorge services.

English source messages use `pht()`. The project-scoped Simplified Chinese
translation includes the publication, visibility, history, and validation
messages; language selection never changes stored keys or payloads.

## Validation

`tests/atomic/packages/D05.json` declares the exact native test classes and
methods. Run it using the D01 isolated regression runner; absent MySQL or
paired Gorge dependencies are failures, not successful skips. Storage cases
cover native service/editor publication, append-only attribution, legacy
payloads, privacy, disabled/withdrawn-approval stale actors, raw participant
bounds, current view/edit revocation, public anonymous reading, and Feed and
reference behavior. The state/i18n class also runs without storage fixtures.

## Source and scope

Extracted from PR #49 at
`4051759aedb701c46b0b859185d08b6873fbd066`, with publication controls, fresh
account authorization, English source messages, Chinese translations, and
additional regression cases. Workspace layout, task badges, shortcuts, and
record-to-task drafts are a separate D06 package. Global `active`/`blocked`
task defaults remain deferred to D13.
