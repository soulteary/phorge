# Native-core trial and optional-feature acceptance

Decision date: 2026-11-18. This document changes delivery and acceptance scope,
not application permissions. The four permission axes retain native semantics;
optional governance and media features remain disabled. No permission migration
or deployment is performed by this change.

## Three separate decisions

1. A core development change must pass required core CI: native runtime/security,
   closed-feature defaults and no-op boundaries, product cuts and paired contracts.
   Closed optional-feature full qualification does not block this development lane.
2. A new release must pass the complete optional and native regression suites on
   the exact release source, in addition to the core checks. A development-core
   success, manually selected subset or historical test receipt cannot authorize
   publication. See [CI delivery policy](../ci/README.md).
3. Enabling a feature requires its own functional and security qualification.
   Passing release SQL/unit tests does not establish browser TLS, current-session
   authorization, revocation timing or production readiness of an inactive feature.

## Current native trial

The native-core trial is restricted to non-sensitive synthetic/trial data. It
keeps native project policies, edit inheritance, membership aggregation and
historical edge handling. It does not advertise the future private-media identity
protocol as enabled or accepted. Successful current native attachment read/write
is not evidence of that future guarantee.

Owlmail is a separate validation service. Its access admission belongs to
Stargate and the configured identity/gateway chain. Phorge/Gorge account disable
state is not an Owlmail access-control source. Application acceptance verifies
recipient selection, message production and actual delivery; it does not invent
cross-service account-disable coupling. Disabling a Phorge account must still
respect Phorge's own native business-access rules.

DingTalk delivery is recorded as an operator self-test, not as an independently
reproduced browser acceptance result. Do not make the trial wait for duplicate
agent qualification of the same operator-owned integration.

## Restore is different from version rollback

Backups and bounded isolated restoration remain relevant to recoverability.
They must describe what was actually captured: a logical database/configuration
backup plus a later stable object copy is not a simultaneous full filesystem,
repository and external-object-store disaster-recovery backup.

A new version-rollback certification is not a prerequisite for this trial.
A future chosen baseline may qualify paired image/configuration rollback and
schema compatibility. Never infer permission to rewind a live database over new
writes. Transaction rollback, authorization atomicity and queue fencing tests
remain correctness checks; they are not deferred by this delivery decision.

## Supported acceptance environment

Use one isolated Linux environment for new N09/N10 qualification. Reuse the
existing supported container/namespace boundaries, ordinary browser flows and
strict TLS verification. Changes must not affect the active trial instance.

The previous macOS hosts/certificate/host-443 operator handoffs are historical
acceptance infrastructure, not required product features or supported runtime
entrypoints. Retain their evidence; do not extend them into another acceptance
backend. Do not add host trust or weaken browser/TLS validation to manufacture a
pass. A fixed source-only tool image qualifies its stated source checks only;
it does not qualify the formal Phorge/Gorge image cohort.

## Current status versus historical receipts

Release-time and failed-attempt receipts are immutable historical evidence.
Fields such as "not deployed" in a publication-time snapshot do not describe
current deployment status. Maintain one current, sanitized status projection
with dated evidence links; preserve original receipts rather than rewriting a
failure or a previous stage as success.

A status projection must separately expose source CI, release publication,
deployment, native acceptance, optional-feature enablement and optional-feature
qualification. No passwords, addresses, raw mail, cookies, bearer file links or
private host paths belong in a public status projection.
