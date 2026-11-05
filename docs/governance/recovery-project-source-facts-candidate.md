# Project relationship source facts — isolated candidate

Initial source-only prototype base: signed PR111 `38ed283161a38b65871670b0c9d0877bcd6645cd`.
This integration explicitly uses signed PR112 `e41afddcb6c7fc76274a8273eeca4cb8e3599d1a`
as its baseline, preserving the existing native User RR and config components.
This source-only component is not wired into RestoreAssessment, policy evaluation,
restoration permits or account enable. No SQL, supported map or new native pure
execution has been performed. Ordinary native four-axis permissions are unchanged.

## Native consumers and storage boundaries

`ProjectHasMember(13)` originates in `project.edge`; its native inverse
`MemberOfProject(14)` originates in `user.edge`. EdgeQuery routes by source PHID
through EdgeConfig. In the ordinary path, EdgeEditor opens source-specific native
connections, writes both inverses and commits those connections. The existing
controlled Project execution hook can instead supply a qualified active connection;
this collector does not activate that hook or infer cross-schema atomicity. Data-bearing edges use source-local edgedata. This
candidate refuses dataID other than NULL/canonical zero and unknown selected types.
It does not execute dynamic edge providers or instantiate them to learn semantics.
The existing RestoreProfile/NativeRuntimeProfile rejects extra libraries or extension
maps before SQL; deployment source-body and complete provider closure are still
separate unproved gates, not authority inferred from a map shape.

`MaterializedMember(60)` is project-local and has no inverse. IndexEngine copies
members from non-milestone leaf descendants, or the project itself when there are
no subprojects. It does not filter archived status by default. It preserves old
parent direct rows; those rows are not leaf aggregation inputs when subprojects
exist. A milestone's own materialized rows copy its parent's direct13.

ProjectQuery uses materialized60 and expands ancestor membership. For milestones,
it reads the parent's materialized60, not the milestone's own60. MembersPolicyRule
separately reads subject14. Milestone getPolicy/automatic capability delegates to
its attached parent; parent edit capability and join behavior remain native.
`loadFromArray` does not attach parents or membership and alone is no permission
proof. The collector never calls a policy evaluator or returns an allowed decision.

Native parent links, binary path/pathKey and depth must agree; root depth is zero
and native maximum depth is sixteen. Unknown subtype/provider, missing/dangling
parent, cycle, contradictory child flags, duplicate identities, inverse mismatch,
stale materialization and budget overflow refuse. Archive statuses are native
canonical 0/100, not display strings. No repair/rematerialization occurs in capture.

## Subject-centric current read-set

The collector runs only on the exact existing isolated native User writer in an
owned transaction, using qualified project/user schemas. It verifies required
InnoDB tables and actual PRIMARY/key_dst/key_phid/key_milestone indices. The subject
is planned via key_phid then revalidated on its actual PRIMARY record. User outgoing
Project-destination edges use PRIMARY(src,type,dst); project incoming edges use
unique key_dst(dst,type,src). Known nonmembership subscription/watch pairs and
silenced edges are privately retained, never treated as membership authority.

Seeds are the actual subject's outgoing/incoming Project relationships. Only their
ancestors, immediate boundary children and selected-parent milestones are loaded;
unrelated projects elsewhere in the installation are not scanned. Child discovery
uses key_milestone; unrelated non-milestone descendant branches are not recursively
scanned. A wide selected parent can still hit the explicit bound. Maximum128
observed project rows/256 selected edges/64 selected historical transactions/512KiB
is a partial-profile availability bound, not a final global64-project requirement.
Future scalable source leases need appropriate bounded paging and real indexed
range/producer proof. No new native index or migration is added to pass the checks.

Selected Project/User edge/parent/milestone transaction rows are separately read
through key_object. Their old/new values remain opaque private history; they never
supply missing current membership. History coverage is selected and bounded, not
an audit-completeness certificate. Other object/tag relationships belong to other
source families; unsupported selected edge types/data/providers hard-refuse.

All subject PHIDs, relationships, historical values and keyed snapshot bindings
remain private memory. Public summary/debug output contains fixed scope/reasons and
false authority/lease fields, no PHID, raw relation, membership count or secret
unkeyed digest. Pure untrusted row validation explicitly reports no actual SQL.
Finish recaptures the selected read-set, is single-use and invalidates on failure.
Equal snapshots do not prove absence, producer closure, DDL/grant stability,
continuous protection or ABA immunity; transaction close/reopen is not a lease.

## Native producer / remaining gate matrix

- ProjectTransactionEditor membership and parent/milestone creation, first-child
  member copy, and final materialization: actual native fixture candidate.
- EdgeEditor direct add/remove/data/inverse and its multiple source transactions:
  real add path candidate; remove, rollback, partial cross-connection faults and
  producer races still need dedicated SQL cases.
- MembershipIndexEngine reindex/rematerialize and raw Project destructor/subtree
  reparent: native producer sources read; destruction/reparent/race fixtures pending.
- Raw SQL rows/index/trigger/FK mutations and deployment/provider configuration:
  no absence or metadata-privilege lease proved; they block family completion.
- Policy rules, automatic capabilities, spaces and historical interpretation:
  separate family/interpreter dependencies; consistency alone grants no access.

## Executable fixture candidate

`--project-source-facts` precreates real actor/subject accounts and native Session/
CSRF before enabling the isolated recovery guard. Actual ProjectEditor creates
root, child and milestone; actual EdgeEditor adds both membership inverses and the
native IndexEngine rematerializes. Native ProjectQuery checks members, and capture
runs under the existing User writer transaction. Optional configuration is restored
on exit; the existing owned driver handles worker/principal/schema/env cleanup.
This is two focused candidate assertions plus six bootstrap and three cleanup (11
expected), not an executed result. Strict consumer, supported mapper/unit/catalog
registration, actual SQL negative/race tests and Root lease remain explicit gates.
No paired daemon or permission-policy replacement is introduced.

## Bounds corrective successor (source-only)

The original early candidate had two confirmed defects and remains frozen as
history: budgeting via serialize could call an untrusted object's magic method,
and historical LONGTEXT was fetched before its byte limit was checked. This
successor fixes those paths without broadening the feature or claiming SQL proof.

Every snapshot is first traversed as bounded primitive arrays/scalars: objects,
Closure, resources and floats are refused without casting, getters, iteration
protocols or serialization. Depth16/nodes8192 and a conservative byte-cost budget
precede every private keyed serialization. The host magic-method sentinel remains
false for all refusal controls. These limits are implementation safeguards, not
continuous source locks or permission decisions.

Historical reading now uses the actual key_object current-lock query to select
identity plus OCTET_LENGTH(oldValue/newValue/metadata). All selected plans are
checked for canonical non-NULL lengths (at most16384 each), row-count and cumulative
byte budget before any payload query. Payload uses PRIMARY, exact object identity
and all three length predicates, then checks identity/type/actual string lengths
again. Unknown/changed rows fail without fallback. History remains opaque private
evidence and cannot populate current membership. No SQL schema/index is added.

Eleven host methods passed: six existing row/entry controls and five new bounds
controls, including actual private-reader invocation with explicitly synthetic
query functions. That is not a real connection or SQL execution proof. Source
catalog, native map/unit registration, strict profile consumer and all real SQL,
producer, DDL/privilege, absence and ABA gates remain pending.


## Registered integration successor (all runtime gates pending)

The successor registers three native pure methods (derived269 total/46 classes,
not executed here). They use the real Project loadFromArray and explicit attachment
APIs to check milestone policy/automatic VIEW delegation, archived status and
attached-list precedence, plus pure direct/materialized/history and magic/length
refusal controls. No native map is hand-edited. The existing bounds controls
remain source-only host evidence rather than SQL or policy authority.

The focused fixture additionally removes the retained parent direct13/14 using
actual EdgeEditor, keeps the leaf direct membership, rematerializes root/milestone,
and archives the leaf with the actual ProjectEditor. It then checks that native
MembersPolicyRule14 rejects root direct membership while ProjectQuery root/parent
materialized membership still includes the subject and milestone own60 does not.
These are mandatory parts of the existing first compound native assertion, not new
claimed runtime results. A second assertion covers redacted source capture; total
11 remains six bootstrap, two profile assertions and three cleanup.

`check_recovery_project_source.py` is the exact-root, typed focused11 consumer with
strict public CLI, source revision, current restore ledger and 55schema/two runtime
users/reader cleanup, zero workers and no file-service startup. Duplicate/nonfinite
JSON, missing obligations, wrong PHP/source/authority and unknown fields refuse.
A successful typed control does not mean the SQL fixture passed.

Explicit N00 additions are static partial142sources/134links/124runtime files.
B2 remains byte-identical with277candidates because the new governance class is in
its existing excluded prefix; the new runtime source is instead explicitly pinned
by N00. Supported map, all269 methods, real SQL11 and all future leases are pending.
The legacy notice fixture's one-time whole-src freeze also needs an explicitly
reviewed additive-source policy before final CI; untracked-source host success
must not be mistaken for that post-signing integration gate.


The main112 union retains the existing notice source-audit guard's exact config/RR
exceptions and adds only the two Project class byte pins and their four map lines.
Before mapper execution those four lines are all absent; afterwards only the exact
all-four addition is accepted, never partial registration or unrelated drift.
Actual map generation and deterministic signed comparison remain separate mandatory
gates; this host exception is not a map execution certificate. Corruption controls
still reject changes to any old product/resource, extra classes, source bytes or
map content. The broader one-time whole-repository audit design is not refactored.


## Fixture-cleanup corrective successor

The prior integration candidate could skip dictionary/cache restoration if its
final transaction check or rollback threw, and a repeated rollback could replace
the original error. This successor preserves the collector bytes and the11 profile
obligations, but centralizes transaction cleanup exactly once. It rejects an existing
native outer transaction before claiming its own writer, then independently attempts
owned rollback, exact native dictionary restoration and cache restoration. Every
restoration runs even when another fails; failures aggregate with the original
Throwable object retained. Physical transaction/session termination still needs
independent SQL cleanup evidence and is not inferred from native depth.

Actual host fault controls invoke that cleanup function with real native dictionary
and Env cache objects plus a synthetic writer that throws on isInsideTransaction
or rollback. Both global restorations still run, original errors remain present,
and dictionary-restoration failure still permits cache restoration. This is host
control-flow proof only, not a SQL rollback or database-session closure proof.
