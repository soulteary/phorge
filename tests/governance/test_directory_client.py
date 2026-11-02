import json
from pathlib import Path
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]

class DirectoryClientTest(unittest.TestCase):
    def test_actual_native_cache_and_literal_matching(self):
        run=subprocess.run(['node',str(ROOT/'tests/governance/test_directory_typeahead.js')],
            cwd=ROOT,check=True,capture_output=True,text=True,timeout=15)
        self.assertEqual(json.loads(run.stdout),dict(passed=True,native_cache_regression_reproduced=True,
            directory_literal_mode_verified=True,browser_proved=False))
