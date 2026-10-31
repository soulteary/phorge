# Organization teams and project access

This feature adds a separate organization directory, optional team policy rules,
and project member roles. It does not require the project progress or entry
sidecar extensions.

## Organization directory

The **Teams** application (`/organization/`) supports nested teams. Active site
administrators create, edit, move, archive, and assign members to teams. They can
also assign a user's teams through the team field on the People profile.

Each team has separate policies for the team's visibility and its roster. These
policies are independent at every level: a visible child does not disclose a
hidden parent, and a visible team does not necessarily disclose its members.
New teams are visible to their own direct members and administrators. Team
membership is independent of project membership. A user can belong to several
teams. Moving or archiving a team does not copy, remove, or invite project members.

The policy editor offers two distinct team rules:

- Direct members of selected teams.
- Members of selected teams and their descendant teams.

Ancestor membership never follows automatically from direct membership. Archived
teams do not grant access. Policy labels, selectors, profile fields, source
imports, and audit access all apply their own visibility checks.

## Project policies and roles

Projects retain separate **Can View**, **Can Edit**, and **Can Join** policies.
Their dropdowns include **Team Members**, which selects direct teams, teams with
descendants, or both. This is an allow source within the chosen policy. It does
not silently change the other two policies or add users as project members.

Project administrators can manage grants through the project access form:

| Operation | Behavior |
| --- | --- |
| Add | Union the selected users and team sources with the current policy. |
| Replace | Replace that capability's policy with the selected sources. |
| Revoke | Remove selected explicit sources from the composed policy. |

Direct-team and descendant-team sources remain distinct. Removing a team source
does not remove a separate user grant. Native application, space, and automatic
capabilities still apply. Revocation removes that grant; other valid grants may
continue to permit access.

For installations which need an additional restriction, the advanced project
access form can configure a strict team boundary. It intersects the native
policy and automatic capabilities. This boundary is optional and is preserved
until an administrator explicitly clears or replaces it. It is not shown as a
second ambiguous restriction when creating a project.

A direct member can have one of four project-local roles:

| Role | Behavior |
| --- | --- |
| Follow policy | Use the project's normal policies. |
| Read-only member | Grant view and cap edit permission, even when a broad edit policy allows it. |
| Editor | Grant view and edit. |
| Project administrator | Grant view/edit and allow member, role, and policy administration. |

New project creators receive a project administrator role without forcing them
into the selected initial membership. Existing projects retain their original
ACL behavior: their creator is recognized as a legacy administrator only while
those ACLs permit viewing and editing. Site administrators also need the
project's native view/edit access. Roles never promote the global user account.
Only project administrators may add/remove other members, reduce roles, or edit
access policies. The last effective administrator must hand over administration
before leaving or losing that role. Read-only members cannot evade their edit
ceiling by leaving and rejoining.

Ordinary child projects have independent direct members, policies, and roles.
Creating the first child does not copy the parent's members or replace the
child's explicit policies. Parent member lists aggregate their own direct
members and descendant members; edits at the parent affect its direct members.
Milestones retain native membership, role, and policy inheritance from their
owning project. Native ancestor visibility requirements are preserved.

## Initial member import

Project creation accepts individual users, teams, and projects as member sources.
Team imports expand direct members only. Project
imports expand the source project's current direct members. The caller must be
able to see a source and its roster. Hidden, archived, invalid, and oversized sources are rejected rather than
partially imported. Inactive, mailing-list, and inaccessible users are filtered
from otherwise readable rosters.

Expansion is a one-time snapshot, deduplicated with a maximum of 500 resulting
users. Later changes to source teams or projects do not synchronize the imported
membership or grant administrator roles. Dynamic access should use team policy
rules instead.

## Installation and existing data

The implementation uses native Phorge source paths and auto-loaded files in
`src/extensions/`; no bind-mounted controller overrides or private maintenance
endpoint are required. From the checkout used by your Phorge deployment:

```sh
./bin/storage upgrade --force
```

This applies `teamorganization:20261027.organization.sql` after the native user
database exists. It creates the team, member, audit, and serialization-lock tables
in the configured user namespace. It is idempotent and preserves existing table
names, project properties, role values, and PHIDs from earlier deployments of
this feature. Follow the normal Phorge deployment procedure to restart workers
and clear opcode caches after replacing source.

Before rebuilding project membership indexes on an existing installation, run
the read-only audit **with the new sources and existing database**:

```sh
./scripts/project/audit-membership.php
```

An exit status of 2 reports old direct parent-member edges missing from the
previous materialized membership index. Rebuilding with the new semantics would
reactivate those direct memberships. Review each reported PHID and remove any
unwanted direct membership through the administrator interface before rebuilding.
The tool never edits edges or rebuilds indexes. Historical automatic child-member
copies cannot reliably be distinguished from intentional invitations and are
left intact. No migration deletes users, memberships, projects, or existing ACLs.

Membership and access forms carry state tokens. Team mutations use an
organization serialization lock and expected versions; project mutations use the
native project row lock, freshly loaded actors, and native transactions. Stale
forms and revoked/disabled actors are rejected, and mutations retain audit
records. Restore both source and database from a matched backup when rolling
back a deployment that has changed policies or roles.

## Verification

Use a disposable database with the native Phorge test configuration. These
storage-fixture tests must not point at production data:

```sh
php bin/unit --no-coverage \
  src/applications/project/__tests__/TeamSubprojectPermissionsTestCase.php \
  src/applications/project/__tests__/TeamOrganizationPermissionsTestCase.php \
  src/applications/project/__tests__/TeamProjectAccessTestCase.php \
  src/applications/project/__tests__/TeamProjectMembershipTestCase.php \
  src/applications/project/__tests__/TeamProjectMemberImportTestCase.php \
  src/applications/project/__tests__/TeamProjectTeamPolicyTestCase.php \
  src/applications/project/__tests__/PhabricatorProjectCoreTestCase.php
```

Coverage includes hierarchy/roster privacy, direct and descendant policy rules,
union/replace/revoke behavior, stale state and actor revocation, read-only and
last-admin protections, child membership isolation, milestone inheritance, and
native Conduit create/edit and initial-member imports. Cross-feature feed tests
belong with the optional project history implementation; this suite does not
load or call it.
