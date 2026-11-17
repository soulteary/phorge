# CI validation cost policy

The required Atomic gate includes native integration, two product-unit partitions,
and the acceptance-tool partition. All three partitions discover the full routed
test inventory; a same-run aggregate proves identical inventory and a complete,
disjoint, successful union. No test may be silently skipped or omitted.

Acceptance-tool modules are listed explicitly in tests/governance/ci_test_partition.py.
Unknown and similarly named modules retain ordinary product ownership. Update the
list only with a coverage review. Tool tests remain required in this first phase;
separation improves attribution and permits measured balancing without weakening
source, fixture or permission protections.

The eight focused SQL workflows are supplementary diagnostics. Their own workflow,
fixture, test and role-driver changes trigger them; workflow_dispatch remains
available for product investigations. General product changes continue to run the
required native integration and product safety checks. No optional feature is enabled.

PR runs cancel older runs of the same workflow and PR. Main, manually dispatched
runs and image publication keep their existing independent/serialized behavior.
A cancelled run is not success and cannot satisfy the aggregate.

Record actual wall time, runner time, partition counts, skipped tests, failed tests,
and the aggregate result when evaluating changes. Do not infer wall-clock savings
by adding parallel job durations. This phase adds one setup job; compute cost may
increase even if feedback latency improves. Measure before making further changes.
