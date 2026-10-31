# Optional project workspace and task drafts

The workspace presents native tasks and project records together. Tasks remain
the sole source of execution status, responsibility, discussion, ordering,
filtering, columns, and persisted board movement. Project records retain their
independent manual project assessment and append-only history.

## Controls and native defaults

| Configuration | Default | Effect |
| --- | --- | --- |
| `project.workspace-mode` | `native` | `unified` adds workspace tabs, status badges, and shared native controls. Unknown values retain native behavior. |
| `project.history-task-drafts` | `false` | Allows record-to-task drafts only while project history is `active`. |
| `project.history-state` | `off` | D05 controls record publication and history-menu visibility. Existing authorized record URLs remain readable. |

The workspace and draft controls are independent. Turning the workspace off
does not remove tasks, columns, records, status keys, policies, or existing
source links. Turning drafts off hides the record action and rejects direct
`projectNode` requests; ordinary native task creation continues unchanged.
History `off` or `read-only` also prevents record-to-task drafting.

This package does not extend the global task-status dictionary. Existing
custom dictionaries remain authoritative. The extra `active`/`blocked`
defaults from the source contribution remain deferred to D13.

## Unified display

Unified mode adds a shared workspace shell above the native board and history.
The task-status badge reads the task's actual native status, including custom
statuses; it does not infer status from the column or a record percentage.
Native filters, ordering, hidden-column controls/counts, workflow links,
dropdown metadata, resources, and drag sigils remain in use. The board is
placed in document flow below the optional shell; native mode retains the
existing native board container and toolbar locations.

Three shortcuts have separate destinations and contracts:

- All Project Activity: `/feed/?projectPHIDs=<project-phid>`.
- Advanced Task Search: `/maniphest/?projectPHIDs=<project-phid>`, shown only
  when Maniphest is available to the viewer.
- Project Change History: `/project/manage/<id>/`, the native project
  transaction view, distinct from the appended development records.

Target routes retain their own application, Space, and object policies. A
shortcut is never an authorization grant. With Maniphest unavailable, the
history menu remains the usable fallback. An existing menu default pointing
at the old standalone history entry resolves to the workboard in unified mode
without rewriting stored menu configurations.

## Unsaved task drafts

Opening `/maniphest/task/edit/?projectNode=<record-id>` checks the enabled
draft control, the freshly loaded current approved account, current project
view/edit policy, Maniphest availability, and native task-creation capability.
Only canonical nodes and the two legacy node transaction types can be used.
Missing, private, comment, or revoked sources receive the same controlled
denial; an ID does not disclose its source project or type.

The GET creates no task or relation. It fills an unsaved native task with the
record title/body, source link, original author and UTC timestamp, and the
intended project tag. Historical status may be included as explanatory text,
but it never becomes the task's execution status. Caller permissions,
participants, completion percentage, original author, and historical time are
not copied as task grants, assignee, current creator, or task metadata.

Explicit Save continues through the native edit engine, its fields, defaults,
locked fields, policies, and transaction editor. A copied description belongs
to the new task: the creator should review the new task's visibility before
saving. Project tags and source links do not share private objects.

English source messages use `pht()` and the project-scoped Chinese translation
map. Switching language changes presentation, not native status keys or
authorization semantics.

## Validation and source

`tests/atomic/packages/D06.json` declares state/i18n and real storage tests.
`tests/project-workspace/README.md` documents the guarded native HTTP harness
and the additional browser checks. Native tests are not presented as proof of
layout or actual dragging. Browser evidence must identify the tested commit
and record the persistence check after reload.

This package extracts the workspace/draft portion of PR #49 at
`4051759aedb701c46b0b859185d08b6873fbd066`, on D05 and D01. It keeps the
existing product cuts, member/ancestor semantics, and Gorge ownership. No SQL
migration, external runtime checkout, or new PHP image/worker/cleanup fallback
is introduced.
