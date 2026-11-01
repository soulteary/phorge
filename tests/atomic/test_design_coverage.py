"""Reject silently dropped anchors, invented native methods and scope inflation."""
import copy
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


def validate_identities(ledger, packages):
    expected = {(p['id'], atom) for p in packages for atom in p['atoms']}
    anchors = {(package, atom, atom + suffix)
               for package, atom in expected for suffix in ('-A', '-B')}
    actual = [(c['package'], c['atom'], c['id']) for c in ledger['cases']]
    if len(actual) != len(anchors) or set(actual) != anchors:
        raise ValueError('Design anchors must exactly match registered package/atom identities.')


class DesignEvidenceLedgerTest(unittest.TestCase):
    def setUp(self):
        self.ledger = json.loads((ROOT / 'tests/atomic/design-coverage.json').read_text())
        self.packages = [json.loads(p.read_text()) for p in
                         (ROOT / 'tests/atomic/packages').glob('*.json')]

    def test_dropped_invented_duplicate_and_misassigned_anchors_fail(self):
        mutations = []
        invented = copy.deepcopy(self.ledger)
        for case in invented['cases']:
            if case['atom'] == 'W04':
                case['atom'] = 'W99'
                case['id'] = case['id'].replace('W04', 'W99')
                case['evidenceStatus'] = 'candidate_native_evidence'
        mutations.append(invented)
        dropped = copy.deepcopy(self.ledger)
        dropped['cases'].pop()
        mutations.append(dropped)
        duplicate = copy.deepcopy(self.ledger)
        duplicate['cases'][-1] = duplicate['cases'][0]
        mutations.append(duplicate)
        reassigned = copy.deepcopy(self.ledger)
        reassigned['cases'][0]['package'] = 'D06'
        mutations.append(reassigned)
        for mutation in mutations:
            with self.assertRaises(ValueError):
                validate_identities(mutation, self.packages)

    def test_every_delivered_scope_anchor_has_valid_candidate_evidence(self):
        ledger = self.ledger
        validate_identities(ledger, self.packages)
        registered = set()
        for package in self.packages:
            for suite in package['tests']:
                for method in suite['methods']:
                    registered.add((package['id'], suite['path'], suite['class'], method))
        cases = ledger['cases']
        self.assertEqual(len(cases), 84)
        self.assertEqual(len({c['atom'] for c in cases}), 42)
        for case in cases:
            self.assertTrue(case['limitations'])
            self.assertNotIn(case['evidenceStatus'], ('passed', 'complete'))
            for candidate in case['nativeCandidates']:
                self.assertIn((case['package'], candidate['path'], candidate['class'], candidate['method']), registered)
                source = (ROOT / candidate['path']).read_text()
                if candidate['class'] == 'PhabricatorLibraryTestCase':
                    source += (ROOT / 'support/runtime/src/__tests__/PhutilLibraryTestCase.php').read_text()
                self.assertIn('function ' + candidate['method'] + '(', source)
            if case['atom'] != 'B07':
                self.assertTrue(case['nativeCandidates'], case['id'])
            if case['atom'] in ('B08', 'F02'):
                self.assertIn('D14', ' '.join(case['limitations']))
            if case['atom'] == 'W04':
                self.assertEqual(case['evidenceStatus'], 'browser_receipt_required')
