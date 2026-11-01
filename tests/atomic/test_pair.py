import unittest
from check_pair import validate


class PairContractTest(unittest.TestCase):
    def test_reviewed_pair(self):
        validate("a" * 40, "a" * 40)

    def test_missing_moving_malformed_and_different_pairs_fail(self):
        for value in (None, "", "main", "a" * 39, "A" * 40, "b" * 40):
            with self.subTest(value=value), self.assertRaises(ValueError):
                validate(value, "a" * 40)
