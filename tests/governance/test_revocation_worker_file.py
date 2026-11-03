"""Host contract tests for a test-only fixed worker dependency; no DB/auth proof."""
import copy
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class MeasurementWorkerFileSourceTests(unittest.TestCase):
    def sample(self):
        namespace = 'governance_native_' + 'a' * 16
        return ['measurement.api-token-auth', {'namespace': namespace, 'uri': 'http://127.0.0.1:12345',
                'token': 'b' * 64}, namespace, 'c' * 64, 'd' * 64]

    def invoke(self, args):
        php = shutil.which('php')
        self.assertIsNotNone(php, 'Host PHP is needed for this pure dependency contract; not a supported-runtime claim.')
        code = '''require $argv[1];$a=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
try{$r=governance_native_measurement_file_source(...$a);echo json_encode(['accepted'=>true,'binding'=>$r===null?null:$r['binding']]);}
catch(RuntimeException $e){echo json_encode(['accepted'=>false,'reason'=>$e->getMessage()]);}'''
        result = subprocess.run([php, '-r', code, str(ROOT / 'support/governance/native-organization/workers.php')],
            input=json.dumps(args), text=True, capture_output=True, check=True)
        self.assertEqual('', result.stderr)
        return json.loads(result.stdout)

    def test_fixed_API_action_accepts_only_exact_owner_source_binding(self):
        args = self.sample(); result = self.invoke(args)
        self.assertTrue(result['accepted']); self.assertRegex(result['binding'], r'^[a-f0-9]{64}$')
        changed = copy.deepcopy(args); changed[3] = 'e' * 64
        self.assertNotEqual(result['binding'], self.invoke(changed)['binding'])
        changed = copy.deepcopy(args); changed[4] = 'e' * 64
        self.assertNotEqual(result['binding'], self.invoke(changed)['binding'])

    def test_file_dependency_is_not_available_to_other_actions(self):
        for action in ['org.list', 'measurement.project-query', 'unknown']:
            args = self.sample(); args[0] = action
            self.assertFalse(self.invoke(args)['accepted'])
        args = self.sample(); args[0] = 'org.list'; args[1] = None
        self.assertEqual({'accepted': True, 'binding': None}, self.invoke(args))

    def test_foreign_routing_port_aliases_or_credential_formats_fail_closed(self):
        for uri in ['http://localhost:12345', 'http://127.0.0.2:12345', 'https://127.0.0.1:12345',
                    'http://127.0.0.1:0', 'http://127.0.0.1:65536', 'http://127.0.0.1:0123',
                    'http://127.0.0.1:12345/path', 'http://127.0.0.1:12345?x=1', 'http://user@127.0.0.1:12345']:
            args = self.sample(); args[1]['uri'] = uri
            with self.subTest(uri=uri): self.assertFalse(self.invoke(args)['accepted'])
        for key, value in [('token', None), ('token', 'b' * 63), ('token', 'B' * 64),
                           ('namespace', 'governance_native_' + 'f' * 16)]:
            args = self.sample(); args[1][key] = value
            with self.subTest(key=key): self.assertFalse(self.invoke(args)['accepted'])

    def test_unknown_fields_and_malformed_owner_source_are_not_coerced(self):
        args = self.sample(); args[1]['complete'] = True
        self.assertFalse(self.invoke(args)['accepted'])
        for index in [2, 3, 4]:
            for value in [True, None, '', '0']:
                args = self.sample(); args[index] = value
                with self.subTest(index=index, value=value): self.assertFalse(self.invoke(args)['accepted'])
