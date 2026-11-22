"""Execute the complete current governance inventory through its existing routes."""
import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

def need(value, label):
    if value is not True:
        raise ValueError('candidate_unit_refused:' + label)

def leaves(suite):
    if isinstance(suite, unittest.TestSuite):
        need(type(suite) is unittest.TestSuite, 'custom_suite')
        return [leaf for child in suite for leaf in leaves(child)]
    need(isinstance(suite, unittest.TestCase), 'custom_leaf')
    return [suite]

def inventory(folder):
    loader = unittest.TestLoader()
    suite = loader.discover(str(folder), pattern='test_*.py')
    need(not loader.errors, 'discovery_errors')
    groups, rows, seen = [], [], set()
    for child in suite:
        tests = leaves(child)
        if not tests:
            continue
        modules = {test.__class__.__module__ for test in tests}
        need(len(modules) == 1, 'cross_module_wrapper')
        module = next(iter(modules))
        need(module not in seen, 'split_module')
        seen.add(module)
        module_rows = [{'id':test.id(), 'module':module} for test in tests]
        need(all(row['id'].startswith(module + '.') for row in module_rows), 'identity')
        groups.append((module, child, module_rows))
        rows.extend(module_rows)
    need(0 < len(rows) <= 32768 and len({row['id'] for row in rows}) == len(rows), 'inventory')
    for module in list(sys.modules.values()):
        source = getattr(module, '__file__', None)
        if source and Path(source).resolve().is_relative_to(folder):
            need(not callable(getattr(module, 'load_tests', None)), 'load_tests_hook')
    return groups, rows

class RecordedResult(unittest.TextTestResult):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.started = []
        self.succeeded = []
    def startTest(self, test):
        self.started.append(test.id())
        super().startTest(test)
    def addSuccess(self, test):
        self.succeeded.append(test.id())
        super().addSuccess(test)

def validate(result, assigned):
    need(result.wasSuccessful() and not result.skipped and not result.expectedFailures
         and not result.unexpectedSuccesses and result.testsRun == len(assigned)
         and result.started == assigned and result.succeeded == assigned,
         'failed_skipped_or_incomplete')

def main():
    folder = (ROOT / 'tests/governance').resolve()
    sys.path.insert(0, str(folder))
    from task_explicit_shard_routes_v5 import route_groups, route_metadata, validate_routed_inventory
    groups, full = inventory(folder)
    groups, routed, metadata = route_groups(groups, full)
    need(metadata == route_metadata(), 'routing_metadata')
    need(validate_routed_inventory(routed, metadata) is True, 'routed_inventory')
    assigned = [row['id'] for row in routed]
    result = unittest.TextTestRunner(verbosity=2, resultclass=RecordedResult).run(
        unittest.TestSuite([suite for module, suite, rows in groups]))
    validate(result, assigned)
    print(json.dumps({'schema':'php86-governance-unit-v1', 'passed':True,
                      'discovered':len(full), 'executed':len(assigned), 'routes':metadata}))

if __name__ == '__main__':
    main()
