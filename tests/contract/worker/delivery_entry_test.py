"""A failed build must emit failure evidence and clean only its isolated project."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[3]
class EntryFailure(unittest.TestCase):
    def test_build_failure_records_manifest_and_cleans(self):
        with tempfile.TemporaryDirectory() as d:
            work=Path(d); docker=work/'docker'; trace=work/'trace'
            docker.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$TEST_TRACE"\ncase "$*" in *" build base") exit 9;; esac\nexit 0\n')
            docker.chmod(0o755)
            env=dict(os.environ,PATH=str(work)+os.pathsep+os.environ['PATH'],TEST_TRACE=str(trace),PYTHONDONTWRITEBYTECODE='1')
            out=work/'results'
            p=subprocess.run(['python3',str(ROOT/'deploy/acceptance/accept.py'),'--output',str(out)],env=env,capture_output=True,text=True)
            self.assertNotEqual(p.returncode,0)
            m=json.loads((out/'delivery-manifest.json').read_text())
            self.assertEqual(m['result'],'failed')
            self.assertIn('sourceSHA256',m['phorge'])
            calls=trace.read_text()
            self.assertIn('--env-file /dev/null',calls)
            self.assertIn('down --volumes --remove-orphans',calls)
            self.assertIn('-p '+m['project'],calls)
            self.assertNotIn('docker-compose.local.yml',calls)

if __name__=='__main__':unittest.main()
