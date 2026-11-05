import copy
from pathlib import Path
import tempfile
import unittest
from pure_inventory import inventory, validate_pure


class PureInventoryTests(unittest.TestCase):
    def fixture(self, root):
        driver = root / 'tests/governance/run.php'
        driver.parent.mkdir(parents=True)
        driver.write_text("$classes = array('PhorgeExampleTestCase',);")
        source = root / 'src/PhorgeExampleTestCase.php'
        source.parent.mkdir()
        source.write_text('public function testOne() {}')
        return driver, source

    def test_current_source_has_277_distinct_methods(self):
        ids = inventory(Path(__file__).resolve().parents[3])
        self.assertEqual(len(ids), 277)

    def test_added_method_changes_expected_inventory(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            _, source = self.fixture(root)
            self.assertEqual(inventory(root), {'PhorgeExampleTestCase::testOne'})
            source.write_text(source.read_text() + '\npublic function testTwo() {}')
            self.assertEqual(len(inventory(root)), 2)

    def test_duplicate_or_unknown_class_registration_refused(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            driver, _ = self.fixture(root)
            for block in ("'PhorgeExampleTestCase','PhorgeExampleTestCase'", "'OtherTestCase'", '$dynamic'):
                driver.write_text('$classes = array(' + block + ');')
                with self.assertRaises(ValueError):
                    inventory(root)

    def test_ambiguous_source_or_duplicate_methods_refused(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            _, source = self.fixture(root)
            source.write_text(source.read_text() + '\npublic function testOne() {}')
            with self.assertRaises(ValueError):
                inventory(root)
            source.write_text('public function testOne() {}')
            other = source.parent / 'other'
            other.mkdir()
            (other / source.name).write_text(source.read_text())
            with self.assertRaises(ValueError):
                inventory(root)

    def test_same_count_wrong_duplicate_missing_or_failed_ids_refused(self):
        ids = {'Class::testOne', 'Class::testTwo'}
        good = {'passed': True, 'runtime': '8.5.12', 'expected_methods': 2,
                'results': [{'id': name, 'result': 'pass'} for name in sorted(ids)]}
        self.assertTrue(validate_pure(good, ids, 2))
        mutations = []
        for value in ('Other::testOne', 'Class::testTwo', None):
            bad = copy.deepcopy(good)
            bad['results'][0]['id'] = value
            mutations.append(bad)
        bad = copy.deepcopy(good)
        bad['results'][0]['result'] = 'fail'
        mutations.append(bad)
        bad = copy.deepcopy(good)
        bad['results'].pop()
        mutations.append(bad)
        for bad in mutations:
            self.assertFalse(validate_pure(bad, ids, 2))
        self.assertFalse(validate_pure(good, ids, 274))
        self.assertFalse(validate_pure(good, ids, True))
