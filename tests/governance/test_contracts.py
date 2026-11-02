"""Mutation tests for isolated scope; not native permission acceptance."""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    'governance_contract_checker', ROOT / 'scripts/governance/check_contracts.py')
CHECKER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CHECKER)


class ContractInventoryTest(unittest.TestCase):
    def setUp(self):
        self.data = CHECKER.load_contract(
            ROOT / 'docs/governance/batch-1-contracts.json')

    def reject(self, data, message):
        with self.assertRaisesRegex(ValueError, message):
            CHECKER.validate(data, ROOT)

    def test_inventory_remains_partial_and_blocks_activation(self):
        result = CHECKER.validate(self.data, ROOT)
        self.assertEqual(result['rules'], 10)
        self.assertEqual(result['packages'], 5)
        self.assertTrue(result['activation_blocked'])
        self.assertFalse(result['runtime_certified'])

    def test_every_selected_branch_and_effect_is_pinned(self):
        for index in range(10):
            with self.subTest(index=index):
                data = copy.deepcopy(self.data)
                data['rules'][index]['selected'] = 'unselected'
                self.reject(data, 'branch or effect drift')
                data = copy.deepcopy(self.data)
                data['rules'][index]['effect'] = 'weakened-contract'
                self.reject(data, 'branch or effect drift')

    def test_duplicate_rule_source_package_or_pending_id_rejected(self):
        for section in ('rules', 'sources', 'packages', 'unsupported'):
            with self.subTest(section=section):
                data = copy.deepcopy(self.data)
                data[section].append(copy.deepcopy(data[section][0]))
                self.reject(data, 'duplicate ID')

    def test_native_axis_and_activation_drift_rejected(self):
        for axis in self.data['native_axes']:
            data = copy.deepcopy(self.data)
            data['native_axes'][axis] = 'candidate'
            self.reject(data, 'native axes drift')
        for flag in self.data['constraints']:
            data = copy.deepcopy(self.data)
            data['constraints'][flag] = True
            self.reject(data, 'constraint drift')

    def test_false_constraint_must_be_boolean_not_zero(self):
        self.data['constraints']['production_permission_migration'] = 0
        self.reject(self.data, 'constraint drift')

    def test_production_registration_and_active_package_rejected(self):
        data = copy.deepcopy(self.data)
        data['production_registrations'] = ['application']
        self.reject(data, 'production registration')
        self.data['packages'][0]['active'] = True
        self.reject(self.data, 'activation or registration')

    def test_pending_cannot_be_pass_or_disappear(self):
        data = copy.deepcopy(self.data)
        data['unsupported'][0]['status'] = 'pass'
        self.reject(data, 'pending coverage must block')
        data = copy.deepcopy(self.data)
        data['unsupported'][0]['blocks_activation'] = False
        self.reject(data, 'pending coverage must block')
        self.data['unsupported'].pop()
        self.reject(self.data, 'unsupported scope must not disappear')

    def test_static_sources_cannot_become_behavioral_pass(self):
        self.data['sources'][0]['evidence_status'] = 'pass'
        self.reject(self.data, 'not behavioral proof')
        data = copy.deepcopy(self.data)
        data['evidence_status'] = 'behavioral-pass'
        self.reject(data, 'not become behavioral passes')

    def test_source_reference_change_and_missing_file_rejected(self):
        data = copy.deepcopy(self.data)
        data['sources'][0]['path'] = 'missing.php'
        self.reject(data, 'source reference drift')
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaisesRegex(ValueError, 'missing source file'):
                CHECKER.validate(self.data, Path(tmp))

    def test_source_paths_cannot_escape(self):
        with tempfile.TemporaryDirectory() as tmp:
            for path in ('../outside.php', '/outside.php', './source.php'):
                with self.subTest(path=path):
                    with self.assertRaises(ValueError):
                        CHECKER._source_file(Path(tmp), path)

    def test_interface_dependency_and_partial_status_pinned(self):
        for key, value in (('interface_id', 'replacement'),
                           ('depends_on', ['N02']), ('coverage', 'complete'),
                           ('status', 'pass')):
            data = copy.deepcopy(self.data)
            data['packages'][0][key] = value
            self.reject(data, 'drift|cannot be pass')

    def test_duplicate_json_keys_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'duplicate.json'
            path.write_text('{"schema_version":1,"schema_version":2}')
            with self.assertRaisesRegex(ValueError, 'duplicate JSON key'):
                CHECKER.load_contract(path)

    def test_protected_paths_reject_runtime_registration_changes(self):
        for path in (
                'src/applications/people/application/Production.php',
                'resources/sql/patches/new.sql', 'conf/local/local.json',
                'webroot/index.php', '../outside.php'):
            with self.subTest(path=path):
                with self.assertRaises(ValueError):
                    CHECKER.validate_changed_paths([path])

    def test_only_isolated_source_and_class_map_exempted(self):
        CHECKER.validate_changed_paths([
            'src/infrastructure/governance/contracts/Isolated.php',
            'src/infrastructure/governance/directory/__tests__/IsolatedTest.php',
            'src/__phutil_library_map__.php',
            'support/governance/fixtures/directory.sql',
            'tests/governance/test_contracts.py'])


if __name__ == '__main__':
    unittest.main()
