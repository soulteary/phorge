# Almanac inventory acceptance — 2026-11-20

## Scope

Native infrastructure inventory only. No Drydock restoration, cluster service
permission changes, governance feature activation, or production permission
migration. All test inventory uses synthetic names and documentation-only
network addresses. No deployment locations, credentials, or member records are
included here.

## Observed installation behavior

The application is installed. Its six primary entity tables were readable and
empty before acceptance. This was a table-readability check, not a full schema
comparison. A global application menu item now exposes the existing application.

Authenticated administrator browser checks passed for:

- device creation and rename;
- network creation;
- device interface creation and port update;
- custom service creation and rename;
- service-to-device interface binding and unchanged-binding save;
- updated interface data displayed on the service;
- binding disable and disabled status displayed.

The synthetic inventory is retained for inspection; its binding is disabled.
No SSH keys, live endpoints, or cluster services were created. These checks do
not establish ordinary-user browser behavior.

## Fixes

A missing service in the create-binding controller reached a typed initializer
with `null`, producing a `TypeError`. Return the existing 404 response before
initialization. Policy exceptions for inaccessible services remain unchanged.
The edit-binding page also displayed a create heading; use its existing title.

Replace stale empty-list references to Drydock and build hosts with descriptions
of the retained inventory capabilities. Add matching Chinese translations.

## Automated coverage

`AlmanacInventoryBoundaryTestCase` runs against a disposable, internal-only
MySQL/PHP pair with synthetic storage fixtures. All eight tests passed:

1. Ordinary users lack native creation and cluster-management capabilities;
   Drydock pool remains absent from creatable service types.
2. Missing and omitted service IDs return 404.
3. Ordinary users can read default-visible inventory but cannot edit it.
4. Binding queries omit objects when their service, device, or network is hidden;
   interface queries also enforce device and network visibility.
5. A denied device transaction leaves its persisted name unchanged.
6. Conduit binding search and interface selection omit restricted relations.
7. Binding edit and disable controllers reject an ordinary user.
8. Real binding create and disable transactions preserve the device/service
   links and persist the disabled state.

The suite is added to the existing database-backed CI job. A local pass is not a
claim that a future PR's full CI or review has passed. The isolated runtime used
an existing application image with the changed files overlaid; it is not a
qualification of a newly built release image. No production fix was deployed.

## Boundaries

Native defaults remain: logged-in users may view default-visible objects, while
editing and creation require the relevant native permissions. Object policies
remain authoritative. This work does not grant team-directory or delegated
administration capabilities. It does not certify anonymous proxy access,
concurrent revocation, bulk import, discovery, or a complete asset lifecycle.
