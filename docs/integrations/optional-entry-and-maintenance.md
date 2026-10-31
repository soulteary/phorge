# Optional entry identity and account maintenance

These integrations are disabled by default. They do not install or replace an
identity gateway, registration service, or account database. The PHP adapter is
usable independently of the optional team architecture and project board work.

## Entry identity

`TeamEntrySessionGuard` binds a native Phorge web session to the immutable
external account identifier and the gateway session cookie observed when that
web session is first used. It stores keyed hashes, not the cookie or identifier.
Changing the entry identity or rotating the gateway cookie revokes the old
Phorge session. A normal HTML document GET automatically enters the selected
native authentication provider and then returns to a validated local URI.
Forms, workflows, API calls, and file resources are never replayed or silently
retried. A rejected operation must be submitted again after signing in.

The native provider must be enabled for login, with an external account linked
to the exact gateway identifier. Usernames, mutable email addresses, gateway
roles, and application display names do not establish that identity binding.
The default provider key is the upstream Traefik provider; another native
provider may be selected explicitly if it uses the same identifier contract.

The trusted proxies list contains exact TCP peer IP addresses, not forwarded
client addresses or networks. The gateway must remove incoming copies of the
principal header and supply its verified value. Prevent direct browser access
to the PHP backend. The entry cookie must be host-only, Secure, HttpOnly, scoped
to `/`, and available on the Phorge host. Its name must differ from native
Phorge cookie names. Identity integrations which do not expose such a cookie
need an adapter at the gateway before this feature can be enabled.

For service-to-service Conduit traffic, the existing exception still requires
both an authenticated native API user and the configured Gorge Conduit service
token, on a POST to `/api/<method>`. It applies only to a direct peer outside the
configured browser proxy set. It does not exempt browser sessions or grant a
native API identity from a service token alone.

Example local configuration (all addresses below are documentation examples):

```json
{
  "integrations.entry-session.enabled": true,
  "integrations.entry-session.trusted-proxies": ["192.0.2.10"],
  "integrations.entry-session.cookie": "entry_session",
  "integrations.entry-session.principal-header": "X-Auth-User",
  "integrations.entry-session.provider": "traefik:traefik",
  "integrations.entry-session.provider-login-uri": "",
  "integrations.entry-session.entry-login-uri": "/entry/login/",
  "integrations.entry-session.enforce-file-policy": true
}
```

An empty provider login URI derives `/auth/login/<provider-key>/`. An explicit
value must be that exact native path; arbitrary login redirects are rejected.
The entry login URI is a local gateway login path used after self password
changes. Set it to the path served by the selected gateway.

The independent `enforce-file-policy` switch can be enabled without entry
session binding. It requires native current-viewer policies for file data,
download, and transform bearer URLs, including instance-qualified routes.
Partial or unapproved sessions cannot supply confidential viewer privileges.
Builtin login resources remain usable. Source ancestry is checked with cycle
and depth limits. Without this switch, native bearer URL behavior is retained.
The core `PhabricatorFileQuery` fix independently ensures profile transforms do
not bypass a private original file's visibility. Standalone public profile
images and genuine builtin resources retain their native visibility.

## Optional account maintenance UI

The adapter provides `/maintenance/` overview counts, searchable registration
approval/rejection, searchable login access and password management, and an
audit history. Only approved, active site administrators in a complete native
web session can use it. Requests require the validated entry integration.
Actions require a native CSRF-protected POST, exact same-origin Origin, explicit
confirmation, a stable operation ID, and an audit reason where applicable.
Passwords are not echoed into forms, URLs, messages, or audit reasons.

The app has no routes, menu entry, or pin until both its explicit flag and all
required settings/key file are valid. A configured backend outage produces a
generic error. Team management links appear only when that separate native
application is installed; no team extension is required to load this adapter.

Configure a separately deployed service implementing the contract below:

```json
{
  "integrations.maintenance.enabled": true,
  "integrations.maintenance.endpoint": "https://maintenance.example.invalid/rpc",
  "integrations.maintenance.key-file": "/run/secrets/account-maintenance-key",
  "integrations.maintenance.allow-http": false
}
```

The key file contains exactly 32 random binary bytes, is readable by the PHP
process, and must be protected from other users. Keep it outside the web root
and Git. HTTPS with normal certificate validation is required by default.
Explicit HTTP opt-in is only for a protected private service network. URLs with
credentials, queries, fragments, or paths other than `/rpc` are rejected. The
client does not use a proxy or follow redirects. Connect timeout is 3 seconds,
total timeout 90 seconds, request limit 16 KiB, response limit 1 MiB.

## RPC wire contract

Every request is `POST /rpc`, Content-Type and Accept `application/json`.
The UTF-8 JSON body has these fields:

```json
{
  "action": "list_users",
  "actor_phid": "PHID-USER-example",
  "actor_principal": "immutable-external-identifier",
  "request_id": "00000000000000000000000000000000",
  "params": {"q": "", "offset": 0, "limit": 50}
}
```

`request_id` is 32 lowercase hex characters. Action forms preserve it across
retries. The service must enforce idempotency, reject reuse for a different
action/target/input, persist the result, and recover incomplete operations.
The client signs the exact serialized body bytes using HMAC-SHA256 and the
shared 32-byte key. Headers are `X-Maintenance-Timestamp` (Unix seconds),
`X-Maintenance-Nonce` (fresh 32 lowercase hex characters), and
`X-Maintenance-Signature` (lowercase hex digest). The signed string has no final
newline:

```text
POST\n/rpc\n<TIMESTAMP>\n<NONCE>\n<SHA256_HEX_OF_BODY>
```

The receiver must use constant-time signature comparison, bound timestamp
skew, reject reused nonces, authorize the PHID/principal pair against live
native identity and site administrator state, validate every field, and
enforce account safeguards itself. PHP UI checks are not a substitute for
service authorization. Never trust PHID, role, action eligibility, or target
identity merely because it appears in the JSON.

Success is HTTP 200 with `{"ok":true,"data":{...}}`. Errors use
`{"ok":false,"error":{"code":"invalid_input"}}`; no password, credential
hash, key, or private exception detail may appear in either response.

| Action | Parameters | Data consumed by the UI |
| --- | --- | --- |
| `overview` | `{}` | `counts`: `pending`, `approved`, `rejected`, `active`, `inactive`, `passwords` |
| `list_registrations` | `q`, `offset`, `limit`, `status` (`pending`, `approved`, `rejected`, `all`) | `total`, `items`: `registration_id`, `name`, `phone`, `email`, `status`, `created_at`, `password_available`, `approval_note`, `can_approve`, `can_reject` |
| `list_users` | `q`, `offset`, `limit` | `total`, `items`: `user_id`, `phorge_phid`, `name`, `username`, `mail`, `phone`, `is_admin`, `entry_status`, `is_disabled`, `is_approved`, `native_state_known`, `has_password`, `entry_only`, `manage_reason`, `can_enable`, `can_disable`, `can_set_password` |
| `list_audit` | `q`, `offset`, `limit` | `total`, `items`: `id`, `created_at`, `actor_username`, `action`, `target`, `status`, `reason`, `request_id` |
| `approve` | `registration_id`, `confirmed_phone`, `note`, optional `username` | Result object |
| `reject` | `registration_id`, `reason` | Result object |
| `set_user_status` | `user_id`, `status` (`active`/`inactive`), `reason` | Optional `entry_only`, `reauth_required` |
| `set_password` | `user_id`, `password`, `password_confirm`, `reason` | Optional `reauth_required` |

Timestamps are Unix seconds. Exact-item lookup uses the same list API and
matches the returned ID exactly. Passwords are 15–128 valid UTF-8 characters,
may contain whitespace, must match exactly, and must not contain NUL. They are
POST-body fields only. The service must safely hash passwords, reject legacy
applications without passwords, protect the current and last usable
administrator, and avoid creating elevated accounts through registration.
Where native identity is ambiguous, only removal of gateway access is allowed;
do not enable, change a password, or disable an unrelated native account.

Approval must preserve the verified registration identity and create a normal
native account without automatically joining teams/projects or verifying email.
Status changes synchronize the safely resolved native and gateway accounts.
Password changes revoke old password-authenticated gateway sessions. Return
`reauth_required` for a self change; the adapter logs out the native session,
clears native and entry cookies, and redirects to the configured gateway login.
Separate OTP session semantics remain the backend's responsibility. Audit data
must exclude passwords, OTP values, signing keys, and credential hashes.

The adapter recognizes these safe error codes: `forbidden`, `unauthorized`,
`invalid_input`, `invalid_request`, `not_found`, `conflict`, `identity_conflict`,
`operation_conflict`, `protected_account`, `last_admin`, `self_disable`,
`invalid_password`, `password_invalid`, `legacy_application`, `rate_limited`,
`service_unavailable`, `native_unavailable`, `storage_unavailable`,
`operation_in_progress`, `unsupported_phone`, and `operation_failed`. The
existing phone-based approval contract supports mainland China mobile numbers;
an alternative backend must either implement it or return `unsupported_phone`.

## Install, migrate, and disable

1. Back up the database, uploaded files, native installation/encryption keys,
   native auth provider configuration, gateway identity/session storage, and
   separately deployed maintenance storage/key. Preserve PHIDs and immutable
   account identifiers. Do not place backups or live configuration in Git.
2. Deploy the PHP code and run normal `bin/storage upgrade`. The registered
   `optionalentrysession:20261031.entry-session.sql` patch creates
   `<namespace>_user.user_entrysessionbinding` after `phabricator:db.user`.
   `CREATE TABLE IF NOT EXISTS` preserves the same table used by older
   deployments. No ad-hoc startup schema command is required.
3. Configure the native provider and verified proxy/cookie/identifier contract
   before enabling the entry guard. For an existing installation, set these
   options to its existing values and preserve the named hash keys
   `team.entry.principal` and `team.entry.session` with the database. This
   preserves existing bindings; do not rebind an old session to a new cookie.
4. Deploy and validate the external RPC service and protected signing key,
   then explicitly enable maintenance. Test with disposable accounts, including
   denied regular users, last-administrator protection, repeated operation IDs,
   self password changes, and backend outages. No backend executable, private
   gateway config, infrastructure credential, or account dataset ships here.
5. To disable the optional UI, set `integrations.maintenance.enabled` to false.
   To disable gateway binding, also set `integrations.entry-session.enabled` to
   false. `enforce-file-policy` is separate and remains under its own flag.
   Disabling the integration does not delete native users, files, or bindings.

## Policy and private default choices

The collaboration product profile hides Moon Phase and Legalpad from new
custom-policy choices. The full profile keeps the native choices. The locked
`policy.editor.hidden-rules` option overrides this: `null` follows the profile,
`[]` hides nothing, and a list of native rule class names hides exactly those
classes. Existing policies continue to evaluate with their original semantics.
Historical policies containing unavailable rules are read-only in this editor;
an explicit replacement creates a new policy through the same policy workflow
and still requires saving the enclosing object form.

`TeamPrivateProjectPolicyRule` and `TeamPrivateTaskPolicyRule` provide selectable
creator-and-site-administrator object policies (`obj.team.project-author-admin`
and `obj.team.task-author-admin`). Existing member/assignee automatic rights
remain native. This code does not rewrite existing policies or automatically
change installation-wide creation defaults; select these rules in the relevant
native default/form configuration when desired.

## Regression tests

Run the native configuration, routing, safe recovery, historical policy, and
navigation tests with:

```sh
bin/unit --no-coverage \
  src/applications/auth/__tests__/PhorgeOptionalIntegrationsTestCase.php \
  src/applications/policy/__tests__/PhabricatorPolicyPresentationTestCase.php \
  src/applications/project/__tests__/PhabricatorProjectHierarchyNavigationTestCase.php
```

The private-original/profile-transform regression uses native storage fixtures
and needs an isolated test installation with its own MySQL backend:

```sh
bin/unit --no-coverage \
  src/applications/files/storage/__tests__/PhabricatorFileProfileAncestryTestCase.php
```

These tests do not exercise a real gateway or implement the separately deployed
maintenance server. Validate that integration with disposable accounts and the
actual receiver's authentication, replay, idempotency, and account-state tests
before enabling it in a deployment.
