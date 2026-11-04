"""Host-only fixed worker decoder checks, never native source/runtime proof."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class PolicyProducerResultTests(unittest.TestCase):
    def accepted(self, value):
        program = (
            "require $argv[1];$v=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);"
            "try{governance_native_policy_producer_result($v);echo 'true';}"
            "catch(RuntimeException $e){if($e->getMessage()!=='native_policy_producer_result_refused'){throw $e;}echo 'false';}"
        )
        php = shutil.which("php")
        self.assertIsNotNone(php)
        result = subprocess.run([php, "-r", program, str(ROOT / "support/governance/native-organization/workers.php")],
                                input=json.dumps(value), text=True, capture_output=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def good(self):
        return {"status": "committed", "native_policy_digest": "a" * 64, "restoration_authorized": False}

    def test_native_success_exact_shape(self):
        self.assertTrue(self.accepted(self.good()))

    def test_unknown_backend_or_denial_cannot_pass(self):
        for value in ({"status": "rejected", "reason": "governance_native_credential_ineligible"},
                      {"status": "failed", "error_type": "AphrontQueryException"}):
            self.assertFalse(self.accepted(value))

    def test_types_and_authority_promotion_are_rejected(self):
        for key, value in (("restoration_authorized", True), ("restoration_authorized", 0),
                           ("native_policy_digest", None), ("native_policy_digest", "A" * 64),
                           ("native_policy_digest", "a" * 63), ("unknown", False)):
            changed = self.good()
            changed[key] = value
            self.assertFalse(self.accepted(changed))

    def test_missing_success_field_is_rejected(self):
        for key in self.good():
            value = self.good()
            del value[key]
            self.assertFalse(self.accepted(value))
