# Project policy failure boundaries (partial)

This isolated change separates expected ticket refusals from damaged stored state. It does not grant capability, introduce a route, change MFA or CSRF, or change transaction ordering. Optional project approval remains off by default.

The store emits a fixed typed refusal for an absent ticket, real expiry, a non-submitted ticket, a mismatched executor or intent, stale sources, or a non-independent approver. The pure projection returns the same public 403 for these opaque ticket states. Identifier syntax has an explicit 400 boundary. Arbitrary exception messages and prefixes never authorize a projection.

Malformed stored JSON, row shape or digest, unsupported stored intent, invalid time ordering, clock rollback and a failed locked compare-and-swap remain backend failures. Native high-security challenges and malformed-request/CSRF exceptions are untouched. SQL/schema/deadlock, programming, cleanup and commit failures must propagate to the native handler.

## Integration gate

The classifier is not yet wired into ApprovalController or native EditEngine responses. Their existing response behavior is not claimed fixed here. A later narrow boundary must close or roll back the owned execution scope before projecting only PhorgeProjectPolicyRefusalException. Unknown exceptions must be rethrown. EditEngine should use its native transaction-validation response, without a missing-field flag that ContinueOnMissingFields can ignore. Controller request JSON parsing must be caught only around caller-input decoding, never around stored-binding decoding.

## Verification scope

Six unit methods cover real decoder/refusal boundaries, native MFA/CSRF exception classes, stored corruption, expiry versus clock corruption, and duplicate state versus locked CAS failure. The supported PHP 8.5.12 map build and unit execution remain pending. Host PHP 8.5.8 was correctly rejected before test execution; host lint is not runtime proof. No SQL, native HTTP, deployment or all-family completion is claimed.
