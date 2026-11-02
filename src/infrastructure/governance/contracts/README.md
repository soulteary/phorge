# Isolated governance contracts (N05-A / N07-A)

These classes are unregistered, side-effect-free contracts. They do not install
routes, edit policies, execute transactions, create approvals in a database,
consume approvals durably, or enable a feature. Every decision reports that it
cannot execute. An eligible contract result is not an authorization grant.

`PhorgeGovernanceContentDecision` separates a proposed new content path from
existing native edit eligibility. Known low-risk operations may be eligible in
this model; native edit does not exempt high-risk operations from approval.
New organization appointments never derive from native edit. Unknown operations,
unclassified risk and incomplete technical/coverage inputs are blocked.
The operation names are contract vocabulary, not a complete mapping to Phorge
transaction classes. In particular, board operations stay unsupported until
classified. Actual view, Space, application and automatic capability checks
remain the responsibility of a future trusted adapter.

`PhorgeGovernanceApprovalRequest` immutably binds operation ID, requester,
executor, target, operation, parameters, target revision, source read-set and
explicit validity times. Map order is normalized; list order and scalar types
are preserved. Object/resource/non-finite/invalid UTF-8 values and excessive
nesting are rejected. Its digest is not a signature or a bearer credential.
No implicit 24-hour approval lifetime is selected.

`PhorgeGovernanceApprovalState` models draft → submitted → approved →
ready-contract-only. Approval requires a different, currently eligible system
administrator; requester and executor cannot be the independent approver.
Execution evaluation rechecks the binding, time, current actors, read-set and
technical/coverage gates. Unknown sources or any missing proof block it.
Transitions return new values. Terminal values cannot transition again, but an
in-memory object cannot stop copied approvals across processes. A persistent
operation ledger and validated target adapter are still required for actual
replay protection and execution. No cross-database atomicity is claimed.

Boolean context flags in these classes are an input protocol for trusted future
adapters, not self-authenticating proof and not caller-set Web/API options. No
runtime adapter exists in this slice. Unit tests supply synthetic flags only;
they prove contract behavior, not real locking, revocation propagation, route
coverage or production authorization.
