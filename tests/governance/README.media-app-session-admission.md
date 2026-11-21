# Native application media-session admission: selected local negatives

This test-only package is based on current Phorge main
`7cd1a6fac24465da61ea010b6e3f8e89d33036b6`, tree
`0f7b84ec94d81bffbf946a03e03fa07dee045be7`. Its application `src`/`resources`
are byte-identical to the previously reviewed149 source. It changes no
application class, permission, session/credential semantics, defaults or mapper.

The concrete coverage gap was the actual application-origin media controller
entry. Existing media HTTP protocol units cover origins, payloads, cookie
attributes and the file-host credential firewall. They do not call the real
`PhorgeGovernanceMediaAuthorizeController::handleRequest()` through the native
AuthApplication route map. This package adds that early-admission coverage:

```
python3 -B -m unittest discover -s tests/governance -p test_media_app_session_admission.py -v
```

Four methods exercise the actual route-map feature-off/on behavior and ten
in-process negative controller calls, covering authorize GET and issue POST.
Each request first passes the real protocol origin/method/header checks. Its
actual NativeActorContext call must then reject with the exact early credential
error before session digest/HMAC lookup, and the actual controller must return
its generic403 without a Set-Cookie or page bootstrap/CSRF configuration. Media
cookies alone, missing/deleted native cookies, and anonymous/unknown native
session kinds never become native application authority. Attached unsaved other-user session metadata does not bypass anonymous or
unknown token-kind refusal. These cases stop at the token-kind gate and do NOT
exercise the attached-session userPHID mismatch branch or actual account switching.
The process-local Lisk connection registry remains empty. Existing native SQL
fixture source in media-http-cases.php already exercises page-bootstrap session
accept/revoke behavior; this gap is specifically the local real route/controller
early-admission matrix, not an assertion that all prior session coverage is absent.

Synthetic transport metadata (`HTTPS=on`, example.com origins) is not a TLS
handshake or actual browser request. Cookies and unsaved User/Session objects
are explicit **negative test inputs**, not native login, authenticated identity,
persistent session, same-browser account-switch or current-source proof. The
fixed missing/deleted/anonymous/unknown-token cases fail before named HMAC keys
or SQL are needed. No fake Query, positive identity provider, database, file
service, server, listener or network execution is used. Each PHP subprocess
owns all in-memory configuration/superglobal state; explicit test dictionary
overrides affect only that subprocess, and application defaults remain off.

## Next genuine integration gate and dependencies

The remaining positive path is native login -> loaded native web session ->
app `/auth/media/authorize/` and CSRF-protected `/auth/media/issue/` -> one-use
file-origin redemption -> current FileQuery authority. It needs a separately
source-reviewed and technically qualified isolated native SQL fixture with real session digest/HMAC storage,
current source and issuer state, real ordinary users and file policies, actual
login/password/provider plumbing, and paired File storage. Valid-token/session
owner mismatch also needs that genuine HMAC/session context; this package does
not simulate it. Current ExchangeService uses native User writer SQL and
NativeIdentitySource current binding checks, which these early tests never enter.

True native logout and subsequent old-media credential refusal, then a second
ordinary account's login and file-policy decisions, still require separately
reviewed actual session/browser/TLS testing. The existing native GD codec proof
is image processing evidence and cannot satisfy those identity/authorization
steps. No prior blocked media operator is invoked, repackaged, or scheduled by
this package; it adds no execution/installation/remote approval.

Local success only proves the selected actual PHP early-refusal entry behavior.
It does not complete N09/N10, transport/login, browser, SQL or deployment gates.


## Exact token-kind and deleted-cookie scope

The anonymous `A/` and unknown `INVALID/` inputs deliberately fail the first
NativeActorContext guard before the session-owner/digest checks. No correct-kind
token is added to imply an authenticated session without real named HMAC storage.
The literal cookie value `deleted` is handled by native AphrontRequest::getCookie:
it returns null before the context's is_string/kind check. The test directly
asserts that native normalization, so it cannot silently reach newSessionDigest.
This is a literal cookie normalization negative, not evidence of a real Logout
controller/session deletion or a legacy valid-user token flow. Positive native
session and valid-kind owner mismatch remain separately SQL-dependent.
