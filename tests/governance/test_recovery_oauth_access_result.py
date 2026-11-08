"""Exercise the actual native worker-result validator without SQL transports."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
PHP = r'''require $argv[1].'/support/governance/recovery/oauth-access-producer.php';
$value=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
try { $result=governance_oauth_native_borrowed_oauth_access_result($value);
  echo json_encode(['accepted'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
} catch (RuntimeException $error) {
  echo json_encode(['accepted'=>false],JSON_THROW_ON_ERROR);
}'''


class NativeOAuthAccessResult(unittest.TestCase):
    def value(self):
        return {'token_id_digest': 'a' * 64, 'status': 'committed',
                'profile': 'borrowed-owner-native-oauth-access-v1',
                'restoration_authorized': False,
                'native_admin_preflight_before_owner': True}

    def validate(self, value):
        result = subprocess.run(
            ['php', '-d', 'disable_functions=mysqli_init,mysqli_connect,fsockopen,stream_socket_client',
             '-r', PHP, str(ROOT)], input=json.dumps(value),
            capture_output=True, text=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def test_committed_result_preserves_payload_independent_of_key_insertion_order(self):
        value = self.value()
        for candidate in (value, dict(reversed(list(value.items()))),
                          dict(sorted(value.items()))):
            with self.subTest(keys=list(candidate)):
                self.assertEqual(self.validate(candidate),
                                 {'accepted': True, 'result': candidate})

    def test_missing_or_extra_fields_are_refused(self):
        for key in self.value():
            candidate = self.value()
            del candidate[key]
            with self.subTest(missing=key):
                self.assertFalse(self.validate(candidate)['accepted'])
        candidate = self.value()
        candidate['unexpected'] = 'untrusted'
        self.assertFalse(self.validate(candidate)['accepted'])

    def test_identity_profile_status_flags_and_digest_remain_strict(self):
        invalid = [('status', 'failed'), ('status', True),
                   ('profile', 'borrowed-owner-native-session-v1'),
                   ('native_admin_preflight_before_owner', 1),
                   ('native_admin_preflight_before_owner', False),
                   ('restoration_authorized', True), ('restoration_authorized', 0),
                   ('token_id_digest', 123), ('token_id_digest', 'A' * 64),
                   ('token_id_digest', 'g' * 64), ('token_id_digest', 'a' * 63)]
        for key, value in invalid:
            candidate = self.value()
            candidate[key] = value
            with self.subTest(field=key, value=value):
                self.assertFalse(self.validate(candidate)['accepted'])


if __name__ == '__main__':
    unittest.main()
