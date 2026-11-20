import json
from pathlib import Path
import shutil
import subprocess
import unittest


class WebserverSelfCheck(unittest.TestCase):
    def test_enabled_proxy_skips_only_anonymous_probe_and_headers_cannot_disable(self):
        php = shutil.which('php')
        self.assertIsNotNone(php, 'PHP CLI is required for the isolated self-check fixture')
        result = subprocess.run(
            [php, str(Path(__file__).with_name('webserver_selfcheck_fixture.php'))],
            capture_output=True, text=True, timeout=15)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), {'passed_cases': 5, 'network_used': False})
