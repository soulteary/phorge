"""Evidence-gate mutations; templates do not establish runtime correctness."""
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('organization_gate', ROOT/'scripts/governance/check_organization.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


def template():
    return {'passed': True, 'checks': sorted(gate.REQUIRED_CHECKS),
        'check_count': len(gate.REQUIRED_CHECKS), 'failure': None,
        'cleanup_failures': [], 'cleanup': {'owned_schema_removed': True,
            'principals_removed': 3, 'private_directories_removed': 13},
        'schema_sha256': hashlib.sha256(gate.SCHEMA.read_bytes()).hexdigest(),
        'scope': 'owned-synthetic-organization-integration',
        'identity_source': 'synthetic-native-account-projection-not-production-authority',
        'production_execution': False, 'optional_features_enabled': False,
        'native_permission_axes_changed': False}


class OrganizationEvidenceTest(unittest.TestCase):
    def test_bounded_template_keeps_production_blocked(self):
        result = gate.validate(template())
        self.assertIn('production-native-identity-projection', result['remaining_blocked'])
        self.assertFalse(result['production_execution'])

    def test_every_reviewed_check_is_required_individually(self):
        for check in gate.REQUIRED_CHECKS:
            with self.subTest(check=check):
                data = template(); data['checks'].remove(check); data['check_count'] -= 1
                with self.assertRaises(ValueError): gate.validate(data)

    def test_duplicate_unknown_or_inconsistent_count_refused(self):
        for mode in ('duplicate', 'unknown', 'count', 'boolean-count'):
            data = template()
            if mode == 'duplicate': data['checks'].append(data['checks'][0]); data['check_count'] += 1
            if mode == 'unknown': data['checks'].append('unreviewed-check'); data['check_count'] += 1
            if mode == 'count': data['check_count'] -= 1
            if mode == 'boolean-count': data['check_count'] = True
            with self.subTest(mode=mode), self.assertRaises(ValueError): gate.validate(data)

    def test_failed_or_partial_cleanup_refused(self):
        for key, value in (('passed', False), ('passed', 1), ('failure', {}),
                           ('cleanup_failures', [{}])):
            data = template(); data[key] = value
            with self.subTest(key=key, value=value), self.assertRaises(ValueError): gate.validate(data)
        for key, value in (('owned_schema_removed', 1), ('principals_removed', 2),
                           ('private_directories_removed', 9), ('principals_removed', True)):
            data = template(); data['cleanup'][key] = value
            with self.subTest(key=key, value=value), self.assertRaises(ValueError): gate.validate(data)

    def test_scope_source_schema_and_typed_activation_refused(self):
        for key, value in (('scope', 'production'), ('identity_source', 'native-verified'),
                ('schema_sha256', '0'*64), ('production_execution', True),
                ('optional_features_enabled', 0), ('native_permission_axes_changed', None)):
            data = template(); data[key] = value
            with self.subTest(key=key), self.assertRaises(ValueError): gate.validate(data)

    def test_unknown_raw_fields_refused_at_both_levels(self):
        for nested in (False, True):
            data = template(); target = data['cleanup'] if nested else data
            target['password'] = 'synthetic-private-canary'
            with self.assertRaises(ValueError): gate.validate(data)

    def test_cli_rejects_duplicate_json_and_never_echoes_private_fields(self):
        for raw in ('{"passed":true,"passed":false}',
                    json.dumps(dict(template(), password='synthetic-private-canary'))):
            with tempfile.TemporaryDirectory() as directory:
                receipt = Path(directory)/'receipt.json'; receipt.write_text(raw)
                result = subprocess.run([sys.executable, str(ROOT/'scripts/governance/check_organization.py'),
                    '--receipt', str(receipt)], capture_output=True, text=True)
                self.assertEqual(result.returncode, 1)
                self.assertEqual(json.loads(result.stdout), {'passed': False, 'reason': 'organization_receipt_refused'})
                self.assertEqual(result.stderr, '')
                self.assertNotIn('synthetic-private-canary', result.stdout)

    def test_nested_duplicate_key_refused(self):
        with self.assertRaises(ValueError):
            json.loads('{"cleanup":{"principals_removed":3,"principals_removed":2}}',
                object_pairs_hook=gate.unique_object)


if __name__ == '__main__':
    unittest.main()
