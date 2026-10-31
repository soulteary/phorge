# File transform visibility (D02 / ADR-04)

A file marked as a profile image is normally public. A profile transform with
a real source must first prove that the viewer can query a source file. A
profile marker, the transform uploader, or a builtin-looking filename must not
bypass this check. A missing or cyclic source without a visible file witness
is denied by the existing policy-aware query ancestry.

## Multiple sources

`file_transformedfile` uniquely indexes `(originalPHID, transform)`, not
`transformedPHID`. Deduplicated transform content can therefore have multiple
source rows. Previously `mpull(..., transformedPHID)` selected one row without
an ordering guarantee, making visibility depend on the database row order.

The contract is **any visible real file source**, consistent with the existing
ANY-associated-object file rule and the separately proposed bearer guard. All
real sources are queried through the current viewer's native policy-aware
query. `PHID_VOID` is a builtin sentinel and contributes no grant when real
sources exist. The single-original attachment API receives a deterministic,
visible file witness (ascending PHID); it never receives an unfiltered object.

This corrects ambiguous multi-source results and can change access compared
with the old arbitrarily selected source. It does not promise that every
source is visible. Deployments requiring ALL-source semantics need a separate
policy/data design, rather than relying on accidental query order.

Standalone public profiles, VOID-only builtin transforms, and actual builtin
resources retain their native behavior. No schema, file bytes, source rows,
stored policy, or installation default is rewritten.

## Scope and verification

The source extraction is from PR #50 at
`47f9cd049c1a436e6a5ac377123627c4d2f010b0`, with the multi-source contract and
additional regression cases implemented here. Native ancestry handles cycles;
the optional bearer guard's 16-layer cap is not imported. The default-off entry
session guard, account maintenance, and private policy templates are deferred.

`tests/atomic/packages/D02.json` lists the exact regression methods. Storage
fixtures require the real isolated MySQL and pinned Gorge file/image services
prepared by D01. The test-only in-memory engine supplies synthetic file bytes
for individual ancestry records; it is not enabled for production or the user
avatar preflight. Gorge continues to own file writes, image transforms and
cleanup. People profile denial rendering uses the existing translated denial
page and adds no new user-facing error strings.
