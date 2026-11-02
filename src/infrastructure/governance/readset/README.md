# N07-B synthetic read-set slice

This slice does not execute transactions or register a policy, route, patch,
writer, session binding, or feature. All validator results have
`can_execute=false`. A matching result is `matched-fixture-only`, always with
`partial-fixture-only` coverage. It cannot make the previous approval state
machine eligible for real execution.

The fixed catalog is keyed by operation and object class. Its nine operation
names and per-family requirements are checked against the N05 capability map.
Callers cannot remove required kinds with an empty requirement array or declare
coverage complete with a boolean. Each actor, executor and independent approver
has a separate account, session, application, membership and appointment role,
with the exact bound ID. Missing one role is not satisfied by another source of
the same kind. All sources, including additional known dependencies, participate
in the binding digest; additions, removals, identity/eligibility/revision changes
and operation changes invalidate the comparison.

`policy.named`, `space.policy`, family policy/parent/member/owner sources and
organization source IDs describe synthetic logical dependency epochs. These
are not actual native policy PHIDs or proof that all real dependencies were
enumerated. A future trusted native adapter must determine actual sources and
prove closure/version coverage, including meaningful absence. No caller claim
of "no named policy" or "no organization dependency" removes the conservative
fixture requirement. Unknown kinds/eligibility and missing sources block.

Only one declared source store is supported in this fixture model. Even matching
rows from multiple stores are blocked: there is no implemented cross-store
consistency proof. Store labels in a pure value object are not authenticated
proof of a physical server. Unit tests may construct synthetic observations;
only the separate SQL fixture tests a real observation path.

The fixture store opens its own guarded, loopback connection, sets autocommit
and READ COMMITTED, and captures binding, manifest and source rows in one fresh
SQL statement. It never accepts an existing caller mysqli connection or its old
repeatable-read view. A new statement can still be followed by another commit:
there is no lock held across approval and a business write, no runtime read-set
adapter and no claim of execution-time revocation safety or cross-database
atomicity. The old approval bridge remains blocked on missing native coverage.

Value objects reconstruct all scalar and list/map containers into new slots,
preserving the previous batch's PHP-reference immutability fix. Their digests are
not credentials. No private data, connection details, user sessions or capability
URLs are emitted as evidence.
