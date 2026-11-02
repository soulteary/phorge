# Isolated organization writes (schema v1)

This is a real transactional database implementation, not a pure array model,
but it has no application, route, native patch registration or production
permission grant. The new database namespace/nonce/schema lineage is required;
existing directory fixture tables are not migrated. `org_user` is an explicit
synthetic account projection maintained only by fixture setup. It does not
prove native account/session or identity-service freshness. Unit lifecycle,
primary-department assignment, handovers and production/native adapters remain
outside this batch. Ordinary membership never copies into native project edges.

`PhorgeGovernanceOrganizationStore::newFixture(config)` accepts exact loopback,
port, `governance_org_<16 hex>` database, user/password and owner nonce fields.
The CLI isolated opt-in, supported PHP, fixed runtime libraries and absence of
deployment selectors/configuration are checked. Real grants are restricted by
table/column: identity rows are SELECT-only; guard and units may update only
version columns; membership/appointment/approval rows permit required DML;
operation/audit records are insert-only to this role. DDL and grant authority
are refused. Every participating table must be InnoDB; transaction reads hold
metadata locks through validation, writes and rollback. Setup alone seeds the
existing independent system administrators and initial team administrator;
there is no first-administrator bootstrap method.

`execute({operation_id, actor, mutations})` accepts at most 32 explicit items:
`action`, `team`, `target_user`, expected team/member/appointment versions,
`valid_from`, `valid_until`, and `approval_id`. Actions are member.add/update/
end/suspend and appointment.grant/revoke. Team versions start at one; zero
member/appointment version means no existing relationship. A team/user cannot
occur twice in a batch, so implicit add-and-appoint is unsupported. The full
fresh canonical request binds operation-ID replay. Current caller authority is
checked before reading an operation record and again before returning it.
Regular results contain only status, operation ID and team versions, never an
audit pointer or approval history. Native `can_execute`/production execution
remain false, while isolated writes are explicitly expected and committed.

All transactions first lock the owned guard row, then current identity, unit,
member and appointment rows. This deliberately serializes this first isolated
implementation and is not a production throughput claim. Identity and immutable
operation rows use FOR SHARE (no identity-write privilege); mutable rows use
FOR UPDATE. System administrators manage the isolated scope; team admins need
both current eligible membership and a current valid team_admin appointment
for that exact active team. Parents, departments, owner labels and ordinary
membership grant no implicit authority. Ordinary member maintenance needs no
per-action approval, but cannot remove, suspend or shorten an administrator's
membership prerequisite. Disabled subjects may be ended/revoked without
preventing safety reduction; adds/updates/grants require eligible subjects.

Appointments require a separate durable approval on the same physical database
transaction. `proposeApproval` and `approveApproval` wrap the adapter; consumption
is only inside the actual appointment mutation. An independent current system
administrator must approve. The target must already be a valid ordinary member;
no member is auto-added. Member validity must cover the appointment. Each
approval binds its own operation/subject/action/read-set, while one batch may
consume several distinct approvals atomically. Audit, consumption, relationship
versions and the operation ledger commit or roll back together. Full before/
after relationship data stays only in protected database audit rows. No audit
history read endpoint is installed in this batch; later history queries must
enforce current system-administrator qualification independently of write access.

Database time is refreshed after all relevant row locks, at period checks and
before commit. Actors, target prerequisites and consumed approval validity are
rechecked after writes/audits; proposal and approval commits also recheck TTL.
This defines a transaction decision boundary, not an absolute wall-clock
revocation guarantee after a future commit or a native identity protocol.
Unknown actors, unsupported payloads, stale versions, changed source bindings
and unknown roles fail closed. No caller `is_admin` or approval boolean exists.

The companion SQL fixture uses setup-only DDL/identity privileges, a restricted
writer and an independent SELECT-only reader. It verifies real persistence,
replay, authorization, approval, lifecycle invariants, trigger-induced batch
rollback and real concurrent lock/expiry behavior. Pure tests establish value
contracts only; supported-runtime SQL receipts establish database behavior.
No optional feature is activated, no notification dispatcher is installed and
no online permission migration is performed.
