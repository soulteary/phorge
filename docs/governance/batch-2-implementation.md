# Governance batch 2: callers, capability mapping and read sets

This extends ADR v1.2's inactive, isolated foundations from main after #77.
It does not register applications, policies, routes, schema patches, writers,
transaction executors, session bindings or feature switches. The four native
project axes and every selected V01–V10 branch are preserved.

## N00: reviewed caller inventory and drift detection

`batch-2-callers.json` links 47 native consumers with 40 explicitly partial
relations. It distinguishes inherited/shared entry points, legacy historical
interpretation, modern transactions, inverse membership edges and edit/bulk/API
paths. Wiki comments-only editing is not treated as the document-body path.

The deterministic lexical scan records 277 candidates, of which 241 remain
explicitly unsupported. It is not an AST call graph or proof of complete caller
coverage. A digest of 4,433 non-test native PHP files makes any changed, added or
deleted file require manual review, even if it introduces no recognized token.
Generated maps, test code and these inactive governance classes are excluded.
No automatic refresh accepts drift. Script/plugin/direct-SQL/dynamic callers
and the eight prior activation blockers remain unverified.

The 17 new mutation tests verify inventory links, candidate triage, source drift,
missing and duplicate records, unresolved states and non-activation. Together
with batch 1 there are 31 Python tests. The checker only verifies bookkeeping;
its successful output still says `runtime_certified=false`.

## N05: native object/transaction capability shapes

`PhorgeGovernanceCapabilityMap` maps 24 fixed native object/envelope/type/field
combinations using actual constants and handler definitions. It retains nine
operation identifiers, checks exact metadata, rejects mixed or unknown batches,
and does not let callers provide an authoritative low-risk label. A high-risk
item cannot be diluted by other content items.

The new content path remains separate from existing native edit eligibility.
Project description uses the actual built-in custom-field key. Wiki title/body
are conservative high-risk content candidates: empty body and expanded document
transactions can delete, publish or restore content. Payload, object creation,
expanded transactions and real native eligibility are not certified here.
Workboard/status and unknown edge operations remain blocked. Every result has
`risk_complete=false`, partial coverage and `can_execute=false`.

Thirteen native methods verify real constants/fields, object confusion, metadata,
batches, source references and fixed dependency kinds. This is shape mapping,
not a new runtime authorization grant.

## N07-B: immutable, operation-bound source observations

Four new classes model typed immutable source rows, a bound read set, a fixed
validator and a guarded synthetic SQL observation adapter. Requirements come
from a fixed object/operation catalog, cross-checked with N05; callers cannot
pass an empty requirement list or declare complete coverage with a Boolean.

Actor, executor and independent approver each require their own account, session,
application, organization-membership and appointment roles with exact bound IDs.
Space, named-policy and target policy/ancestor/member/owner epochs are included.
Project member/parent epochs are common because task and Wiki policies can also
reference project groups. The family kind profiles are 10/11/11.

The binding includes operation ID, object class, target, operation, parameter
digest and the three identities/session references. All supplied dependencies
participate in the digest. Missing, added, removed, duplicate, unknown, ineligible
or changed sources invalidate the comparison even when the target is unchanged.
Matching multiple declared stores is blocked; labels do not prove consistency.
A match is only `matched-fixture-only`, never an execution authorization.

The SQL adapter opens a private autocommit READ COMMITTED connection and obtains
binding, manifest and sources in one new SELECT view. It refuses a caller's old
repeatable-read connection. A dangling manifest row becomes an explicit unknown
source instead of silently disappearing. The test bridge to the old approval
contract stays blocked on missing native coverage.

Fifteen pure methods and 30 real InnoDB checks cover source identity/version
changes, unchanged-target invalidation, additions/removals/dangling dependencies,
object/operation confusion, reference detachment, cross-store blocking and
stale-view/fresh-observation contrast. The adapter does not enumerate actual
application source closure or hold locks across a business write. No execution-
time revocation, durable replay or cross-database atomicity is claimed.

## Verification and remaining gates

Local execution used supported PHP 8.5.12 and MySQL 8.4.13 in an owned network-none
pod with tmpfs data and no published ports. All 51 native methods (the prior 23
plus 28 new methods), 31 Python tests, 30 read-set SQL checks and the prior 34
directory checks passed. The executed PHP/SQL/map files were hash-compared with
the worktree. This is development-fixture evidence, not a clean release receipt.

Run the supported-runtime tests in a clean development checkout:

```sh
python3 scripts/governance/check_contracts.py --check-worktree
python3 scripts/governance/check_callers.py
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests/governance -p 'test_*.py' -v
php bin/rebuild-library-map
php tests/governance/run.php
```

See `support/governance/fixtures/README.readset.md` for the disposable SQL fixture.
The existing required native CI also runs the new checker and read-set fixture
and publishes a separate batch-2 receipt. Unsupported PHP is refused before SQL.
Only a successfully created random fixture schema is owned for cleanup.

The lexical inventory, N05 shapes and synthetic epochs do not close the native
caller/source-completeness or cross-store/write-transaction gates. Actual native
adapters, expanded-transaction eligibility, media, mail, recovery, lifecycle and
capacity/retention proof remain activation-blocking. No production permissions
are migrated and no optional feature is enabled by this PR. Existing 134-atom
planning counts and historical acceptance receipts are not reclassified.
