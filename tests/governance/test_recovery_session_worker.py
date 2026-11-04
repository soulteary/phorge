"""Host-only decoder contract tests. No native session or SQL execution claim."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class SessionProducerResultTests(unittest.TestCase):
    def decode(self, value):
        program = (
            "require $argv[1];"
            "$input=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);"
            "try{$value=governance_native_session_producer_result($input);"
            "echo json_encode(array('accepted'=>true,'value'=>$value));}"
            "catch(RuntimeException $e){"
            "if($e->getMessage()!=='native_session_producer_result_refused'){throw $e;}"
            "echo json_encode(array('accepted'=>false));}"
        )
        php = shutil.which("php")
        self.assertIsNotNone(php, "Host decoder test requires PHP; not a native runtime test.")
        result = subprocess.run(
            [php, "-r", program, str(ROOT / "support/governance/native-organization/workers.php")],
            input=json.dumps(value), text=True, capture_output=True, timeout=5,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def test_only_fixed_success_shape_is_accepted(self):
        value = {"status": "committed", "session_id_digest": "a" * 64,
                 "restoration_authorized": False}
        self.assertEqual(self.decode(value), {"accepted": True, "value": value})

    def test_backend_errors_cannot_be_projected_as_authorization_denials(self):
        for reason in ("recovery_restore_admin_required", "governance_native_credential_ineligible"):
            with self.subTest(reason=reason):
                self.assertTrue(self.decode({"status": "rejected", "reason": reason})["accepted"])
        for value in (
            {"status": "failed", "error_type": "AphrontQueryException"},
            {"status": "rejected", "reason": "recovery_session_transactional_source_required"},
            {"status": "rejected", "reason": "recovery_session_isolation_unknown"},
            {"status": "rejected", "reason": "governance_native_source_unknown"},
        ):
            with self.subTest(value=value):
                self.assertFalse(self.decode(value)["accepted"])

    def test_types_unknown_fields_and_grant_claims_are_refused(self):
        valid = {"status": "committed", "session_id_digest": "a" * 64,
                 "restoration_authorized": False}
        for key, value in (
            ("restoration_authorized", True), ("restoration_authorized", 0),
            ("session_id_digest", True), ("session_id_digest", "A" * 64),
            ("session_id_digest", "a" * 63), ("can_execute", False),
            ("status", True),
        ):
            changed = dict(valid)
            changed[key] = value
            with self.subTest(key=key, value=value):
                self.assertFalse(self.decode(changed)["accepted"])
