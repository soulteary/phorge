# Opt-in project hierarchy navigation (D04)

This package extracts N01–N06 from PR #50
`47f9cd049c1a436e6a5ac377123627c4d2f010b0` and the independent ancestor
privacy checks from PR #47 `53f319fdff64d880699e127db3c3ffa7b6ae9739`.
It does not adopt either PR's proposed membership or permission model.
Native ordinary-child ancestor visibility, parent edit behavior, membership
indexes and milestone inheritance remain unchanged.

## Default and explicit navigation

`projects.default-navigation` is a locked enum configuration value:

- `flat` is the default and retains native query selection, including saved or
  pinned queries. It does not force a hierarchy directory on existing installs.
  The builtin hierarchy entry is appended after native queries, so the search
  engine's unpinned fallback stays Joined for signed-in users and Active for
  anonymous users. Returning a null navigation key alone is insufficient to
  preserve this behavior: the delegated search engine resolves that fallback.
- `hierarchy` makes an unfiltered GET to `/project/` select root projects,
  ordered by name. Archive filtering is `all`, so an archived root does not
  strand its active children. Pagination counts root projects, not a flattened
  subtree. Each child directory is loaded separately with native viewer policy.

Explicit queries, filter parameters and form submissions retain the ordinary
search behavior. Pagination parameters do not turn a hierarchy directory back
into a flat query. `/project/query/hierarchy/` remains a stable explicit query
in either configuration, so disabling the default mode does not break links.

Project item headers continue to open their overview. Hierarchy lists offer a
separate **Browse Subprojects** link for projects which support subprojects,
including leaves. Link presence never uses `hasSubprojects` as proof that the
viewer can see a child. A leaf or a project with only hidden children opens an
empty authorized child list. Existing milestone presentation is retained.

## Parent context and privacy

When hierarchy navigation is the default, project crumbs include a back action
to the visible direct parent's child directory, or to the root directory if
that parent is unavailable. It does not jump to an arbitrary visible ancestor.
During child/milestone creation the held object is the parent, so navigation
returns to that object rather than skipping a level. Existing edit forms append
the current project once; their context load requires view only, preserving
native view-only action dispatch without adding project-edit permission.

Ancestor names and PHIDs are filtered for the current viewer before crumbs,
list paths, Conduit parent fields or ancestor attachments are rendered. These
privacy checks remain active in flat mode. Hierarchy constraints only use
visible project identifiers. Logical ancestor tokens validate every original
constraint before simplifying redundant ancestors. This prevents a hidden
constraint from disappearing during simplification and broadening results.
The native ancestor guard still rejects a child when its parent is hidden;
synthetic raw-attachment tests verify defensive presentation without changing
that authorization behavior. Strict unresolved Web slug migration (M09) remains
in the later membership package.

## Validation

The six-method pure suite covers default selection, anonymous native-engine
fallback, explicit queries/filters, pagination,
POST handling, builtin query contracts and English/Chinese messages. The
12-method storage suite additionally exercises the real signed-in engine
fallback through the project-list request/controller context and verifies
hierarchy opt-in for both signed-in and anonymous viewers. It covers real root
pagination with archived parents and descendants,
unchanged ancestor permissions in both modes, Conduit constraints and reused
queries, token validation, raw attached-ancestor privacy, direct-parent fallback,
creation context, view-only context at the native method gate and edit-form crumb duplication.

After the D01 foundation is available:

```bash
python3 tests/atomic/run.py --package D04 --output /tmp/phorge-d04-evidence
```

Use its disposable MySQL and paired Gorge file/image/render fixtures. Missing
backends and skipped mandatory cases fail the gate. The maintained application
cuts, bundled PHP runtime and Gorge service/cleanup responsibilities are
unchanged. No schema migration or data rewrite is required; switch the enum
back to `flat` to restore the native default presentation.
