# CI validation cost policy

PR and `main` feedback uses the core tier. The stable required context
`Native packages (PHP 8.5.12)` reports real core-check results and terminates
without running disabled-adapter integration branches or acceptance-tool
partitions. Its core checks must succeed; skipped core work is not acceptance.
Other existing core runtime, native-security and Phorge/Gorge contract workflows
remain active. This change enables no optional feature.

Publication requires the full tier for the exact immutable tag commit. It includes
native integration, both product-unit partitions and the acceptance-tool partition.
All three partitions discover the full routed inventory; the same-run aggregate
requires identical inventory and a complete, disjoint, successful union. These
checks remain mandatory for full qualification. A skipped, cancelled or failed
full partition refuses publication.

Acceptance-tool modules remain explicitly listed in
`tests/governance/ci_test_partition.py`. Unknown and similarly named modules keep
ordinary product ownership. Changes to the list require a coverage review; moving
a workflow trigger does not authorize removing tests or changing ownership.

All twelve focused adapter workflows are callable full-release checks. They no
longer trigger on PR or `main` pushes. Each may be manually dispatched for an
explicit source commit, but that diagnostic result cannot substitute for the
publication run's complete full aggregate. The authoritative workflow inventory
and the core/release/feature-enable boundaries are in
[the CI policy](../../docs/ci/README.md) and its support-baseline contract.

PR runs cancel older runs of the same workflow and PR. Main runs and manually
requested diagnostics keep their independent behavior. Image publication remains
serialized and waits for its own full qualification; cancellation never counts as
success. Reusable full checks must not share a cancelling concurrency group with
their publication caller.

Record actual wall time, runner time, inventory and partition counts, skipped and
failed tests, and aggregate results when evaluating this change. Do not estimate
wall-clock savings by adding parallel durations or claim measured speedups before
collecting runs. Release cost remains explicit: moving optional checks out of the
PR critical path does not remove their release execution requirement.
