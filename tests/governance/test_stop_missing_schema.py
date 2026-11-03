"""Native exception constructor/fixture classifier regression; no SQL proof."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
NS = 'governance_native_' + 'a' * 16
MESSAGE = "#1146: Table '" + NS + "_user.recovery_stop_audit' doesn't exist"


def classify(kind, message, namespace=NS, code=0):
    script = r'''
require $argv[1].'/src/infrastructure/storage/exception/AphrontQueryException.php';
require $argv[1].'/src/infrastructure/storage/exception/AphrontSchemaQueryException.php';
require $argv[1].'/support/governance/recovery/stop-boundary-cases.php';
$type=$argv[2];$error=new $type($argv[3],(int)$argv[5]);
echo json_encode(array('code'=>$error->getCode(),
  'accepted'=>governance_recovery_stop_missing_audit_error($error,$argv[4])));
'''
    result = subprocess.run(['php', '-r', script, str(ROOT), kind, message, namespace, str(code)],
                            capture_output=True, text=True, check=True)
    if result.stderr:
        raise AssertionError('unexpected host PHP output')
    return json.loads(result.stdout)


class StopMissingSchemaTest(unittest.TestCase):
    def test_actual_native_schema_constructor_has_zero_code(self):
        self.assertEqual({'code': 0, 'accepted': True},
                         classify('AphrontSchemaQueryException', MESSAGE))

    def test_generic_driver_or_unrelated_schema_failure_is_not_missing_audit(self):
        for kind, code in [('AphrontQueryException', 1146), ('RuntimeException', 0),
                           ('AphrontSchemaQueryException', 1146)]:
            self.assertFalse(classify(kind, MESSAGE, code=code)['accepted'])
        for message in (MESSAGE.replace('#1146:', '#1054:'), MESSAGE + '\nextra',
                        MESSAGE.replace('recovery_stop_audit', 'user'), '#1146: Simulated error.'):
            self.assertFalse(classify('AphrontSchemaQueryException', message)['accepted'])

    def test_foreign_or_unproved_namespace_never_satisfies_fixture_error(self):
        self.assertFalse(classify('AphrontSchemaQueryException', MESSAGE,
                                 namespace='governance_native_' + 'b' * 16)['accepted'])
        for namespace in ('production', NS + '_extra', '', NS + "'"):
            self.assertFalse(classify('AphrontSchemaQueryException', MESSAGE, namespace=namespace)['accepted'])
