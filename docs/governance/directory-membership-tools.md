# Directory membership tools

These tools extend the native People organization directory. They do not add
project policy rules, project roles, account provisioning or external directory
synchronization. `governance.organization.enabled` remains locked and false by
default. No deployment or permission migration is required to review this change.

## Import ordinary members

Open a team, then **Import Ordinary Members**. Select existing users or paste
existing usernames separated by spaces, commas, semicolons or newlines. The
request accepts at most 200 selections and merges duplicates. Unknown or
ineligible accounts reject the preview. The tool does not create accounts,
expand another team's roster, change a primary department or appoint managers.

The preview distinguishes additions, retained relationships and reactivations.
Retained relationships keep their existing validity periods. A suspended or
ended relationship requires an explicit reactivation in the preview. Ordinary
team administrators can import only into their own currently administered team.

Confirmation requires CSRF and explicit acknowledgement. The server rebuilds
the plan under native identity locks and the organization mutex. A changed
account, unit, member or appointment invalidates the reviewed digest. The
existing 32-pair mutation engine runs bounded chunks inside one transaction:
member, audit, unit-version and operation changes all roll back if any chunk
fails. Operation IDs bind the actor and complete request, including the reviewed
digest. Replays require current authorization.

## Replace one user's team memberships

**Replace User Team Memberships** is restricted to current system administrators.
Select one existing user and the complete desired team list. Empty means end all
ordinary team memberships; it does not mean leave them unchanged. The preview
shows additions, reactivations, retained relationships and endings. At most 200
teams may be involved; source history is bounded to 1,000 stored relationships.

The full stored relationship snapshot participates in confirmation. A team added
after preview therefore invalidates the request even when it was absent from
the original selection. An archived or unknown team is not silently omitted.
The operation refuses to end or shorten a current/future administrator's
membership prerequisite: administrator changes still require the existing
approval process. Primary departments, appointments, native project memberships
and object policies remain unchanged. Ended relationships and audit history are
retained. There is no implicit deletion or two-way external synchronization.

Membership changes may affect separately configured explicit content grants.
The confirmation warns about this consequence; it does not grant native EDIT.

## Restricted profile fields

The People profile displays the primary department and business teams only
through the directory's existing protected queries. System administrators can
see permitted current relationships. A team administrator sees only independently
visible relationships; managing a parent or a project confers no directory
exception. Ineligible subjects have no current eligibility projection.

Hidden relationships have no placeholder, name, identifier or count. Visible
team pages use a keyset cursor. Directory failure or an expired credential omits
the optional fields instead of making the native profile unavailable.

## Tree navigation

The directory offers a server-rendered, lazy tree beside its existing flat
search. Open a node's children, follow visible breadcrumbs, return one level or
to the root, filter the current branch and page that branch independently.
No JavaScript is required for these navigation operations.

Visibility is applied before pagination. A visible child whose parent is hidden
appears at the visible root without revealing the hidden parent. A parent cannot
be expanded without independent visibility. No descendant counts or inaccessible
placeholders are shown. Unit IDs such as `0` remain valid. Moving a unit continues
to use the existing versioned form and cycle checks, not drag-and-drop or implicit
permission inheritance.

## Validation

Run value contracts with PHP 8.5.13:

```sh
php bin/unit --no-coverage \
  src/applications/people/governance/__tests__/PhorgeOrganizationRequestTestCase.php
```

The dedicated real-SQL runner is:

```sh
php support/governance/native-organization/directory-product-run.php
```

It requires the existing explicit isolated fixture opt-in, loopback fixture DSN,
setup credentials and hash-bound paired Gorge file binary. It rejects deployment
selectors/configuration, creates random owned schemas and restricted principals,
and verifies cleanup. Never run it against a deployment database. Credentials and
raw SQL errors are not written to its public receipt.

The optional browser fixture also requires an explicit loopback-only test origin.
It is an acceptance harness, not a deployment front controller. Its synthetic
native sessions do not prove enterprise OTP delivery or media authentication.
Local targeted receipts do not replace the repository's existing source-admission,
full CI or release gates.

For a reproducible container run, use preverified local PHP 8.5.13 and MySQL
images plus the paired Linux Gorge file executable:

```sh
python3 -B tests/governance/directory_product/run.py \
  --php-image "$TEST_PHP_IMAGE" \
  --gorge-file-binary "$TEST_GORGE_FILE"
```

Add `--browser`, `--playwright-module` and optionally `--chromium` to exercise
real browser navigation, autocomplete, preview, confirmation and role privacy.
The tool uses generated native synthetic sessions, an ephemeral loopback-only
relay and no Docker port publication. It verifies source bytes before and after,
checks exact resource ownership before cleanup, and removes transient credential
files. Browser success and SQL success are separate required results in browser
mode. No hosts, routing, DNS or certificate-trust changes are needed.
