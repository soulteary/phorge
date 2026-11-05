"""Host contracts for the public CI invocation; no database execution."""
from pathlib import Path
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]

class AtomicAPIConcurrencyTests(unittest.TestCase):
    def setUp(self):
        self.workflow=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text()
        self.step=self.workflow.split('      - name: Verify fresh native API credential concurrency after native regression\n',1)[1].split('      - uses:',1)[0]

    def test_serial_public_commands_and_failure_propagation(self):
        normal=self.workflow.index('python3 scripts/governance/check_private_notifications.py --receipt')
        focused=self.workflow.index('      - name: Verify fresh native API credential concurrency')
        self.assertLess(normal,focused)
        self.assertNotIn('if:',self.step)
        self.assertNotIn('continue-on-error',self.step)
        self.assertNotIn('|| true',self.step)
        self.assertIn('--api-credential-concurrency >',self.step)
        self.assertIn('--source-revision "$GITHUB_SHA"',self.step)
        self.assertIn('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$GITHUB_SHA"',self.step)
        self.assertIn('test "$(git rev-parse HEAD)" = "$GITHUB_SHA"',self.step)
        self.assertNotIn('team-readiness',self.step)
        self.assertNotIn('docker ',self.step)
        self.assertLess(self.step.index('binary-sha256.txt'),self.step.index('php support/'))
        self.assertIn('shared-native-pure.json',self.step)
        self.assertIn('normal-regression-accepted.json',self.step)

    def test_shell_syntax_and_separate_always_artifact(self):
        script='\n'.join(line[10:] for line in self.step.splitlines() if line.startswith('          '))
        result=subprocess.run(['bash','-n'],input=script,text=True,capture_output=True)
        self.assertEqual(result.returncode,0,result.stderr)
        artifact=self.workflow.split('      - name: Verify fresh native API credential concurrency',1)[1].split('      - name:',1)[0]
        self.assertIn('if: always()',artifact)
        self.assertIn('name: governance-api-credential-concurrency-php-${{ matrix.php }}',artifact)
        self.assertIn('if-no-files-found: error',artifact)

if __name__=='__main__':unittest.main()
