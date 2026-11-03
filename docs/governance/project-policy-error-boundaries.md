# Project policy failure boundaries (partial)

This isolated change separates expected ticket refusals from damaged stored state. It does not grant capability, introduce a route, change MFA or CSRF, or change transaction ordering. Optional project approval remains off by default.

The store emits a fixed typed refusal for an absent ticket, real expiry, a non-submitted ticket, a mismatched executor or intent, stale sources, or a non-independent approver. The pure projection returns the same public 403 for these opaque ticket states. Identifier syntax has an explicit 400 boundary. Arbitrary exception messages and prefixes never authorize a projection.

Malformed stored JSON, row shape or digest, unsupported stored intent, invalid time ordering, clock rollback and a failed locked compare-and-swap remain backend failures. Native high-security challenges and malformed-request/CSRF exceptions are untouched. SQL/schema/deadlock, programming, cleanup and commit failures must propagate to the native handler.

## Integration gate

The classifier is not yet wired into ApprovalController or native EditEngine responses. Their existing response behavior is not claimed fixed here. A later narrow boundary must close or roll back the owned execution scope before projecting only PhorgeProjectPolicyRefusalException. Unknown exceptions must be rethrown. EditEngine should use its native transaction-validation response, without a missing-field flag that ContinueOnMissingFields can ignore. Controller request JSON parsing must be caught only around caller-input decoding, never around stored-binding decoding.

## Verification scope

Six unit methods cover real decoder/refusal boundaries, native MFA/CSRF exception classes, stored corruption, expiry versus clock corruption, and duplicate state versus locked CAS failure. The signed source checkpoint `05dc4bb9efc7dc4231fc4fb39787808cfd64c51e` was executed in an isolated PHP 8.5.12 test container: all 223 registered pure methods passed, including the six new methods. The actual rebuilt map was byte-identical to the signed map (SHA256 `2744bbd4f6468a9815e3761ff005c5f1bdcd09205d96be21acce905dd4a329f0`). Both owned host and container source directories were removed with no cleanup failures. This documentation update is not another runtime execution.

Host PHP 8.5.8 was correctly rejected before the supported run; host lint alone was not runtime proof. No SQL, native HTTP, deployment or all-family completion is claimed.
