"""Execute the acceptance program with controlled versions and effective INI.

Version substitution exercises the real stable-version helper and acceptance
branches; it does not qualify the interpreter as a published PHP runtime.
"""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class PHPSecurityPredicate(unittest.TestCase):
    def check(self, version="8.5.13", overrides=None):
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI is required for acceptance predicate tests")
        settings = {"session.upload_progress.enabled": "0",
                    "session.use_strict_mode": "1", "opcache.jit": "disable",
                    "opcache.jit_buffer_size": "0"}
        settings.update(overrides or {})
        program = (ROOT / "deploy/acceptance/php_security.php").read_text()
        # Preserve the actual native helper and every acceptance check.
        program = program.removeprefix("<?php").replace(
            "dirname(__DIR__, 2)", json.dumps(str(ROOT))).replace(
            "PHP_VERSION", json.dumps(version))
        probe = "$observed=[];foreach(" + json.dumps(list(settings)) + " as $key){$observed[$key]=ini_get($key);}" + 'echo json_encode($observed)."\\n";'
        args = [php]
        for key, value in settings.items():
            args.extend(["-d", key + "=" + value])
        result = subprocess.run(args + ["-r", probe + program],
                                capture_output=True, timeout=15)
        observed = json.loads(result.stdout.splitlines()[0])
        expected = dict(settings)
        if expected["opcache.jit"] == "off":
            expected["opcache.jit"] = ""
        self.assertEqual(observed, expected)
        return result

    def test_stock_image_workflow_mounts_the_complete_acceptance_dependency(self):
        workflow = (ROOT / ".github/workflows/php-core-security.yml").read_text()
        self.assertIn(
            '-v "$PWD/deploy/acceptance/php_security.php:/opt/acceptance/deploy/acceptance/php_security.php:ro"',
            workflow)
        self.assertIn(
            '-v "$PWD/support/runtime/php-version.php:/opt/acceptance/support/runtime/php-version.php:ro"',
            workflow)
        self.assertIn('"$ref" php /opt/acceptance/deploy/acceptance/php_security.php', workflow)

    def test_stable_patch_versions(self):
        for version in ("8.5.13", "8.5.14"):
            with self.subTest(version=version):
                result = self.check(version)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn(("Verified PHP " + version).encode(), result.stdout)

    def test_unsupported_versions(self):
        for version in ("8.5.12", "8.5.13RC1", "8.5.14RC1", "8.5.14-dev",
                        "8.5.13-dev", "8.4.99", "8.6.0", "9.0.0"):
            with self.subTest(version=version):
                result = self.check(version)
                self.assertEqual(result.returncode, 1, result.stderr)
                self.assertIn(b"Unsupported PHP runtime", result.stderr)

    def test_effective_session_and_jit_rejection(self):
        for overrides, error in (
            ({"session.upload_progress.enabled": "1"}, b"session security settings"),
            ({"session.use_strict_mode": "0"}, b"session security settings"),
            ({"opcache.jit": "off"}, b"JIT must be explicitly disabled"),
            ({"opcache.jit": "tracing"}, b"JIT must be explicitly disabled"),
            ({"opcache.jit_buffer_size": "1048576"}, b"JIT must be explicitly disabled"),
        ):
            with self.subTest(overrides=overrides):
                result = self.check(overrides=overrides)
                self.assertEqual(result.returncode, 1, result.stderr)
                self.assertIn(error, result.stderr)
                self.assertNotIn(b"Unsupported PHP runtime", result.stderr)


if __name__ == "__main__":
    unittest.main()
