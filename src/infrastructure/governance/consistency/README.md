# Isolated native consistent read view

`PhorgeGovernanceNativeReadView::begin(binding, capabilities, readerConfig)`
creates an owned, verified SELECT-only native source reader and starts its
actual MySQL REPEATABLE READ, READ ONLY consistent snapshot. The reader's
`sampleOpenReadOnlySnapshot()` reads the bounded fixed source tables while
retaining that transaction. The dedicated scope uses fixed Reflection to lend
its private mysqli to app-specific Aphront adapters; no public raw-connection
accessor exists. Each adapter selects only its exact owned app database and
uses that same physical connection. Native r/w-mode cache entries point at
read-only adapters; no writer privilege or transaction executor is introduced.

`finish()` loads the actual native user/target and calls actual PolicyFilter
and automatic capability methods. It then rereads the source tables through
the same reader in the still-open snapshot. Exact cache identity, physical
thread identity, frozen routing/effective policy keys, supported schema types,
InnoDB tables, SQL/row/byte/time budgets and real grants are prerequisites.
Initial unknown subjects/rules/accounts/sessions reject before native rules.
Unknown schemas, extra ORM connections, unsupported functions/queries,
locking reads, variables, comments and multi-statements fail closed. Native
queries use a fixed SELECT/function shape gate, not an arbitrary SQL sandbox.
The supported fixed product cut remains projects/tasks/wiki and the fixed four
native rule classes; unsupported plugins/organization sources remain blocked.

A scope temporarily installs a cloned static fixture configuration using the
explicit reader principal. It refuses inherited SQL pools and alternate
cluster/Gorge routes. Finally closes only its owned pool, rolls back the reader
snapshot and restores the original environment/caches. No deployment config,
public route, application registration or business mutation is installed.

Successful `same_consistent_read_view=true` proves a narrower fact than the
prior two-sample observer: selected native SQL and bounded source captures used
one physical read view. Concurrent A→B and A→B→A commits cannot switch what that
read view sees. Fresh subsequent observations can see later commits. It does
not prove current authorization at a future write or prevent concurrent
revocation after the snapshot. `execution_freshness_proved`,
`execution_atomicity_proved`, `can_execute`, risk/native closure remain false.
The aggregate digest is not an authorization token. Existing complete runtime,
organization, dynamic extension and native pipeline closure unknowns remain.

Before a fixed native SELECT, the bridge acquires the table metadata lock with
COUNT and performs SQL-side COUNT/MAX/SUM(OCTET_LENGTH(all real columns)) in the
same snapshot. Only bounded metadata and aggregate numbers are returned before
this gate. More than 512 table rows, 64 KiB in one source row or 512 KiB across a
table refuses the original native fetch. Native projections remain unchanged:
only bounded source column/star projections and COUNT are supported; duplicate
or computed/amplifying projections, cross-schema names, subqueries and unbounded
join shapes are refused. An explicit FROM/JOIN schema qualifier is allowed only
when byte-identical to the bridge's owned database; foreign schemas remain
refused. Classification does not rewrite the actual native SQL. Internal session max_execution_time follows the owned
reader deadline; no server-global setting is modified. Failed prefetch budgets
have a separate safe counter, distinct from successful native query counts.

This proves the selected profile's source/returned-data budget, not all MySQL
internal memory use, arbitrary SQL plans or plugins. The bridge streams results
and also retains post-fetch/elapsed checks as secondary defenses. Actual grants
and READ ONLY transaction state are independent controls. Full execution
freshness/locking requires separate real isolated writer proof; none of those
gates are lifted by read-view consistency.

The bridge verifies and pins the actual owned session SQL_MODE. Only the fixed
standard strict/group/date/error modes (including STRICT_ALL_TABLES in CI) are accepted; NO_BACKSLASH_ESCAPES,
ANSI_QUOTES and every unknown mode are refused. Each native query rechecks the
same mode before parsing/execution. Double-quoted SQL is unsupported. Therefore
the small literal-aware SELECT gate is scoped to that exact lexer environment;
it is not a generic SQL parser or safety guarantee for plugins/arbitrary SQL.

The complete static fixture Dictionary is privately frozen, including product
and optional-feature keys, in addition to the explicit effective policy keys.
Source, route, policy and Dictionary comparison digests are never exposed.
History/workspace/draft/search optional modes must remain off/native; enabled
modes fail before snapshot/native observation. This is the current fixed cut,
not a discovery mechanism that silently expands the supported application set.

Before native calls, `PhorgeGovernanceNativeRuntimeProfile::assertCurrent()`
requires exactly arcanist and phorge at the canonical bundled runtime/src and
project src roots. Actual merged maps must equal unextended maps; any registered
extension map, including a no-op/duplicate map, is refused. The read-view scope
rechecks the same metadata before returning. The shared assertion returns only
library names, map hashes and booleans, never absolute paths. It may also guard
the owned writable pipeline fixture. This concrete fixed-library boundary does
not claim arbitrary registered native extension actions were exercised, nor
that already bootstrapping unknown code could be undone safely.

If the open begin/finish window gains foreign SQL pool entries, the observation
blocks. Cleanup partitions connections by object identity: only the scope's
original bridges and its private reader are closed; new/replacement foreign
entries are preserved at their current keys and remain open. Context cleanup
sees only the owned subset, and foreign entries are restored in finally. A
failed cleanup remains failed on subsequent close calls, rather than changing
into a successful cleanup receipt.

Scopes and bridges cannot be cloned or serialized. This prevents an apparent
foreign clone from retaining the same privately borrowed physical session.
Debug summaries contain only scope/closed status, not environment or credentials.

The complete FROM clause is anchored to one table with an optional alias, or
the exact native Project materialized-member join and fixed ON condition.
Enumerating FROM/JOIN tokens is not sufficient: STRAIGHT_JOIN, NATURAL/outer/
cross joins, ODBC table references, parenthesized/derived factors, partition and
index-hint grammars are unsupported and refuse before preflight/native fetch.
Every consumed table must match this full grammar's table set.
