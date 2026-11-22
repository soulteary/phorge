"""Candidate governance routing retains strict result accounting."""
import importlib.util
import io
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('candidate_units', ROOT/'scripts/runtime/php86-unit-suite.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)

class UnitAccounting(unittest.TestCase):
    def result(self, case):
        return unittest.TextTestRunner(stream=io.StringIO(), resultclass=runner.RecordedResult).run(
            unittest.TestSuite([case]))
    def test_success_requires_exact_order_count_and_identity(self):
        case = unittest.FunctionTestCase(lambda:None)
        result = self.result(case)
        runner.validate(result, [case.id()])
        for assigned in ([], [case.id(), case.id()], ['unknown']):
            with self.assertRaises(ValueError): runner.validate(result, assigned)
    def test_skip_failure_and_expected_failure_are_refused(self):
        class Cases(unittest.TestCase):
            @unittest.skip('negative control')
            def test_skip(self): pass
            def test_fail(self): self.fail('negative control')
            @unittest.expectedFailure
            def test_expected(self): self.fail('negative control')
        for name in ('test_skip','test_fail','test_expected'):
            case = Cases(name)
            with self.assertRaises(ValueError): runner.validate(self.result(case), [case.id()])
    def test_candidate_uses_existing_versioned_routes(self):
        source = (ROOT/'scripts/runtime/php86-unit-suite.py').read_text()
        self.assertIn('from task_explicit_shard_routes_v5 import route_groups, route_metadata, validate_routed_inventory', source)
        self.assertIn('groups, routed, metadata = route_groups(groups, full)', source)
        self.assertIn('validate_routed_inventory(routed, metadata)', source)
        workflow = (ROOT/'.github/workflows/php86-isolated-compatibility.yml').read_text()
        self.assertIn('python3 -B scripts/runtime/php86-unit-suite.py', workflow)
