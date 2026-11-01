"""Reject silently dropped anchors, invented native methods and scope inflation."""
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class DesignEvidenceLedgerTest(unittest.TestCase):
    def test_every_delivered_scope_anchor_has_valid_candidate_evidence(self):
        ledger = json.loads((ROOT / 'tests/atomic/design-coverage.json').read_text())
        registered = set()
        for path in (ROOT / 'tests/atomic/packages').glob('*.json'):
            package = json.loads(path.read_text())
            for suite in package['tests']:
                for method in suite['methods']:
                    registered.add((package['id'], suite['path'], suite['class'], method))
        cases = ledger['cases']
        self.assertEqual(len(cases), 84)
        self.assertEqual(len({c['id'] for c in cases}), 84)
        atoms = {c['atom'] for c in cases}
        self.assertEqual(len(atoms), 42)
        for atom in atoms:
            self.assertEqual({c['id'] for c in cases if c['atom'] == atom}, {atom + '-A', atom + '-B'})
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
