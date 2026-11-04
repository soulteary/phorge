# Native policy and Space source profile development

This isolated successor implements current locked source-row capture for native
policy.policy, policy.policy_namedpolicy and spaces.spaces_namespace. The exact
column sets come from the signed native quickstart and current DAO definitions.
The fixed cross-schema SQL runs only on the evidenced owned People writer and
requires the native primary id index, exact columns and InnoDB under MDL.

The aggregate limit is 64 selected rows, 16 KiB per row and 64 KiB total across
all three tables. A current locking primary-index size pass precedes payload
hashing; locked rows remain stable. SQL SHA256 field framing preserves NULL vs
empty and byte length, without returning policy names, descriptions, rules or
identifiers. Oversize, unknown schema and SQL errors are refusals, never empty
source proofs. Even bounded scans can block unrelated policy/Space edits; this
cost is explicit and not proof of production-scale readiness.

The reader does not claim phantom exclusion under the current service RC
transaction. Empty source sets and unmodeled native automatic object grants
remain incomplete. It is not yet wired into assessment, whose v2 shape remains
frozen. The later integrated binding must explicitly introduce source version3;
old v1/v2 proposals must keep their old comparison semantics and become stale
when compared to a newer provider, never silently gain completeness.

Before integration: convert the relevant source preflight reads to current
reads, then hold a proved RR primary-key next-key range through final restore
commit. Verify real concurrent inserts/updates, empty-table gaps, DDL/timeout,
rollback, and actor/session expiry. A time-of-check COUNT or disabled feature
flag is not an absence fence. The supported interpreter must also resolve each
native custom rule and named effective policy against current object sources,
Space policies and configuration. Unknown extensions or dynamic producers stay
hard blocked. No policy decision or native permission field is changed here.

No new class map, supported PHP pure run, SQL run, catalog gate or restoration
success is claimed. This source-only checkpoint follows the raw-flag correction;
the historical b3 229-method run applies only to b3. R04/R05 adoption remains
blocked pending a genuinely complete deployment profile and two-person native
restore success with durable one-use permit/audit and final source validation.
