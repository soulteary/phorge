import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location(
    'pipeline_boundaries', ROOT/'scripts/governance/check_pipeline_boundaries.py')
checker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(checker)


class PipelineBoundaryTests(unittest.TestCase):
    def setUp(self):
        self.data = json.loads((ROOT/'docs/governance/batch-5-pipeline.json').read_text())

    def test_reviewed_stage_inventory_never_certifies_runtime(self):
        r = checker.validate(self.data)
        self.assertEqual(r['stage_count'], 28)
        self.assertFalse(r['runtime_certified'])
        self.assertFalse(r['can_execute'])
        self.assertTrue(r['activation_blocked'])

    def test_order_missing_and_unknown_stages_fail_closed(self):
        for mode in ['swap', 'remove', 'append', 'anchor']:
            data = copy.deepcopy(self.data)
            if mode == 'swap':
                data['stages'][1], data['stages'][2] = data['stages'][2], data['stages'][1]
            elif mode == 'remove':
                data['stages'].pop()
            elif mode == 'append':
                data['stages'].append(copy.deepcopy(data['stages'][0]))
            else:
                data['stages'][0]['anchor'] = '$this->invented();'
            with self.subTest(mode=mode), self.assertRaises(ValueError):
                checker.validate(data)

    def test_false_certification_and_boolean_type_confusion_refused(self):
        for key in ['runtime_certified', 'can_execute', 'activation_blocked',
                    'preview_read_only_proved']:
            for value in [True, False, 0, 1]:
                if type(value) is bool and value is self.data[key]:
                    continue
                data = copy.deepcopy(self.data)
                data[key] = value
                with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                    checker.validate(data)
        data = copy.deepcopy(self.data)
        data['complete_pipeline'] = True
        with self.assertRaises(ValueError):
            checker.validate(data)
        data = copy.deepcopy(self.data)
        data['stages'][0]['whole_stage_verified'] = True
        with self.assertRaises(ValueError):
            checker.validate(data)

    def test_source_drift_cannot_be_accepted_by_refreshing_manifest_hash(self):
        import hashlib
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            path = root/checker.SOURCE
            path.parent.mkdir(parents=True)
            raw = (ROOT/checker.SOURCE).read_bytes()+b'\n// unreviewed drift\n'
            path.write_bytes(raw)
            with self.assertRaisesRegex(ValueError, 'manual review'):
                checker.validate(self.data, root)
            data = copy.deepcopy(self.data)
            data['source_sha256'] = hashlib.sha256(raw).hexdigest()
            with self.assertRaises(ValueError):
                checker.validate(data, root)

    def test_foreign_or_symlinked_source_is_refused(self):
        data = copy.deepcopy(self.data)
        data['source'] = '../foreign.php'
        with self.assertRaises(ValueError):
            checker.validate(data)
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            path = root/checker.SOURCE
            path.parent.mkdir(parents=True)
            path.symlink_to(ROOT/checker.SOURCE)
            with self.assertRaises(ValueError):
                checker.validate(self.data, root)

    def test_preview_boundary_stays_after_initial_effects(self):
        ids = [r['id'] for r in self.data['stages']]
        self.assertLess(ids.index('initial-effects'), ids.index('preview-return'))
        self.assertLess(ids.index('commit'), ids.index('herald'))
        self.assertLess(ids.index('herald'), ids.index('publishing'))
        self.assertTrue(all(r['whole_stage_verified'] is False for r in self.data['stages']))
