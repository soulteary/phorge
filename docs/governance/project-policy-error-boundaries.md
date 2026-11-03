# Project policy failure boundaries (partial)

This isolated change separates expected ticket refusals from damaged stored state. It does not grant capability, introduce a route, change MFA or CSRF, or change transaction ordering. Optional project approval remains off by default.

The store emits a fixed typed refusal for an absent ticket, real expiry, a non-submitted ticket, a mismatched executor or intent, stale sources, or a non-independent approver. The pure projection returns the same public 403 for these opaque ticket states. Identifier syntax has an explicit 400 boundary. Arbitrary exception messages and prefixes never authorize a projection.

Malformed stored JSON, row shape or digest, unsupported stored intent, invalid time ordering, clock rollback and a failed locked compare-and-swap remain backend failures. Native high-security challenges and malformed-request/CSRF exceptions are untouched. SQL/schema/deadlock, programming, cleanup and commit failures must propagate to the native handler.

## Integration gate

The independent successor wires only typed refusals into ApprovalController fixed JSON 400/403 responses and the native default EditEngine form's existing transaction-validation response. The native editor completes its owned rollback/cleanup before the latter conversion. There is no missing-field flag that ContinueOnMissingFields can ignore. GET and POST no longer catch arbitrary Throwable; backend, programming, MFA and CSRF exceptions propagate. Request JSON is caught only inside the caller-input decoder. Stored-binding JSON never uses that decoder.

Only explicit typed sites are projected. Native source/engine/profile refusal exceptions that still mix unsupported authority with unavailable backend facts remain unclassified and propagate; no message-prefix fallback is introduced. Actual SQL/HTTP and backend-fault rollback evidence for this successor are still required before acceptance.

## Verification scope

The foundation’s six unit methods cover real decoder/refusal boundaries, native MFA/CSRF exception classes, stored corruption, expiry versus clock corruption, and duplicate state versus locked CAS failure. The signed source checkpoint `05dc4bb9efc7dc4231fc4fb39787808cfd64c51e` was executed in an isolated PHP 8.5.12 test container: all 223 registered pure methods passed, including the six new methods. The actual rebuilt map was byte-identical to the signed map (SHA256 `2744bbd4f6468a9815e3761ff005c5f1bdcd09205d96be21acce905dd4a329f0`). Both owned host and container source directories were removed with no cleanup failures. This documentation update is not another runtime execution.

Host PHP 8.5.8 was correctly rejected before the supported run; host lint alone was not runtime proof. No SQL, native HTTP, deployment or all-family completion is claimed.

The HTTP successor adds three response/validation and known-effect classification unit methods (226 methods expected); these new methods and wiring have not yet been executed. The 223-method earlier checkpoint proves only its earlier source.

Known additional effects are classified only inside the genuine native form WeakMap lease. The final filtered batch must contain the exact original approved policy transaction and original supported content/description objects, unchanged values, current author, and only canonical metadata. Extra policy, Space, JOIN, hierarchy, cloned/inserted transactions, changed values or unknown metadata remain unclassified backend/invariant failures. Recognized content effects produce an actionable refusal; no transaction is removed and the exact-one-effect consume gate remains closed.

Seven new direct route/controller obligations (757 checks on the historical d302 baseline) are prepared, not executed. They include real missing-description NULL-to-empty rejection, a separate native content save, stale old proposal refusal, and a fresh proposal executing only the approved policy. They are not an HTTP wire proof. The root native semantic-default-Space fix and ordinary fixture description initialization require an explicit integration before full SQL execution; this independent tree does not claim that integration.
