"""Mutation checks for a partial caller inventory, not authorization evidence."""
import copy
import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    'governance_caller_checker', ROOT / 'scripts/governance/check_callers.py')
CHECKER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CHECKER)


class CallerInventoryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.original = CHECKER.load(ROOT / 'docs/governance/batch-2-callers.json')
        cls.scan = CHECKER.scan_sources(ROOT)

    def setUp(self):
        self.data = copy.deepcopy(self.original)

    def rejects(self, expected):
        with self.assertRaisesRegex(ValueError, expected):
            CHECKER.validate(self.data, ROOT, self.scan)

    def test_partial_inventory_and_eight_blockers_remain_blocked(self):
        result = CHECKER.validate(self.data, ROOT, self.scan)
        self.assertTrue(result['activation_blocked'])
        self.assertFalse(result['runtime_certified'])
        self.assertEqual(len(self.data['unsupported']), 8)
        self.assertGreater(result['unsupported_candidates'], 0)

    def test_selected_rule_and_native_axis_drift(self):
        self.data['selected_rules']['V04'] = 'A'
        self.rejects('selected rule drift')
        self.data = copy.deepcopy(self.original)
        self.data['native_axes']['historical_edges'] = 'candidate'
        self.rejects('native axis drift')

    def test_activation_and_registration_cannot_be_enabled(self):
        for key in ('active', 'can_execute', 'production_registration'):
            with self.subTest(key=key):
                self.data = copy.deepcopy(self.original)
                self.data[key] = True
                self.rejects('activation prohibited')
        self.data = copy.deepcopy(self.original)
        self.data['production_registrations'] = ['new-policy']
        self.rejects('registrations prohibited')

    def test_static_inventory_cannot_claim_runtime_certification(self):
        self.data['runtime_certified'] = True
        self.rejects('not behavioral certification')
        self.data = copy.deepcopy(self.original)
        self.data['consumers'][0]['evidence_status'] = 'pass'
        self.rejects('not behavioral proof')

    def test_duplicate_ids_in_each_collection_rejected(self):
        for key in ('consumers', 'links', 'triage', 'unsupported'):
            with self.subTest(key=key):
                self.data = copy.deepcopy(self.original)
                self.data[key].append(copy.deepcopy(self.data[key][0]))
                self.rejects('duplicate')

    def test_known_entry_cannot_disappear_or_change_source(self):
        self.data['consumers'].pop()
        self.rejects('known consumer inventory drift')
        self.data = copy.deepcopy(self.original)
        self.data['consumers'][0]['path'] = 'missing.php'
        self.rejects('known source/symbol drift')

    def test_existing_source_digest_change_blocks(self):
        self.data['consumers'][0]['sha256'] = '0' * 64
        self.rejects('reviewed source changed')

    def test_graph_cannot_drop_edges_or_claim_runtime_pass(self):
        self.data['links'].pop()
        self.rejects('known graph inventory drift')
        self.data = copy.deepcopy(self.original)
        self.data['links'][0]['runtime_verified'] = True
        self.rejects('must not claim runtime')

    def test_dangling_graph_and_readset_operation_reference(self):
        self.data['links'][0]['to'] = 'NONEXISTENT'
        self.rejects('graph topology drift|dangling')
        for key in ('source_ids', 'operation_ids'):
            self.data = copy.deepcopy(self.original)
            self.data['consumers'][0][key] = ['unknown']
            self.rejects('unknown operation/source ID')

    def test_each_candidate_needs_explicit_disposition(self):
        self.data['triage'].pop()
        self.rejects('each candidate needs')
        self.data = copy.deepcopy(self.original)
        self.data['triage'][0]['disposition'] = 'irrelevant-pass'
        self.rejects('cannot become complete/pass')

    def test_unsupported_stays_pending_and_cannot_disappear(self):
        self.data['unsupported'][0]['status'] = 'pass'
        self.rejects('pending blocker must not become pass')
        self.data = copy.deepcopy(self.original)
        self.data['unsupported'].pop()
        self.rejects('eight blockers')

    def test_scanner_schema_and_pattern_changes_block(self):
        for key, value in (('version', 99), ('patterns', {}),
                           ('source_snapshot_sha256', '0' * 64)):
            self.data = copy.deepcopy(self.original)
            self.data['scan'][key] = value
            self.rejects('source/candidate drift')

    def test_deterministic_scan_and_candidate_addition(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / 'src').mkdir()
            (root / 'src/First.php').write_text('<?php $e->applyTransactions($o,$x);')
            first = CHECKER.scan_sources(root)
            self.assertEqual(first, CHECKER.scan_sources(root))
            self.assertEqual(len(first['candidates']), 1)
            (root / 'src/New.php').write_text('<?php $u->setIsDisabled(false);')
            second = CHECKER.scan_sources(root)
            self.assertEqual(len(second['candidates']), 2)
            self.assertNotEqual(first['source_snapshot_sha256'],
                                second['source_snapshot_sha256'])

    def test_unrecognized_runtime_edit_still_changes_snapshot(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / 'src').mkdir()
            path = root / 'src/Indirect.php'
            path.write_text('<?php $call($object);')
            first = CHECKER.scan_sources(root)
            path.write_text('<?php $other($object);')
            second = CHECKER.scan_sources(root)
            self.assertEqual(first['candidates'], [])
            self.assertEqual(second['candidates'], [])
            self.assertNotEqual(first['source_snapshot_sha256'],
                                second['source_snapshot_sha256'])
            path.unlink()
            self.assertNotEqual(second, CHECKER.scan_sources(root))

    def test_generated_map_and_isolated_tree_are_explicitly_excluded(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / 'src/infrastructure/governance').mkdir(parents=True)
            first = CHECKER.scan_sources(root)
            (root / 'src/__phutil_library_map__.php').write_text('<?php applyTransactions();')
            (root / 'src/infrastructure/governance/Contract.php').write_text(
                '<?php applyTransactions();')
            self.assertEqual(first, CHECKER.scan_sources(root))

    def test_references_cannot_escape_and_missing_file_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            for path in ('../outside.php', '/outside.php', './file.php', 'missing.php'):
                with self.subTest(path=path):
                    with self.assertRaises(ValueError):
                        CHECKER.regular_reference(Path(tmp), path)

    def test_duplicate_json_keys_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'dup.json'
            path.write_text('{"active":false,"active":true}')
            with self.assertRaisesRegex(ValueError, 'duplicate JSON key'):
                CHECKER.load(path)


if __name__ == '__main__':
    unittest.main()
