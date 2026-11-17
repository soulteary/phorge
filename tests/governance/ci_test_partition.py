"""Explicit ownership of acceptance-tool tests; no test is discarded."""
import hashlib
TOOL_MODULES = frozenset({
 'test_source_operation_snapshot',
 'test_source_operation_snapshot_task_v2',
 'test_source_snapshot_assessment_task_v2',
 'test_n11_task_current_source_operation_v2',
 'test_task_explicit_shard_routes_v4',
 'test_task_explicit_shard_routes_v5',
 'test_task_description_shard_routes_v3',
 'test_ci_test_partition',
})
def owner(module):
 if module in TOOL_MODULES:return 2
 return int.from_bytes(hashlib.sha256(module.encode()).digest()[:8],'big')%2
