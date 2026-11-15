"""Host contracts for the public CI invocation; no database execution."""
from pathlib import Path
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]

CURRENT_NOTIFICATION_GATE = 'python3 -B scripts/governance/check_full_native_task_schema_receipt_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --consumer notifications --envelope "$RUNNER_TEMP/governance-native-organization/integration.json" --native-body "$RUNNER_TEMP/task-full-native-v2-private/capture-native-body.private.json" --profile "$RUNNER_TEMP/task-full-native-v2-private/capture/schema-profile.private.json" --runtime-before "$RUNNER_TEMP/governance-native-organization/mysql-before.json" --runtime-after "$RUNNER_TEMP/governance-native-organization/mysql-after.json" --profile-sha "$(python3 -c \'import json,sys; print(json.load(open(sys.argv[1]))["profile_sha256"])\' "$RUNNER_TEMP/task-full-native-v2-private/capture/binding.safe.json")" --file-binary "$RUNNER_TEMP/gorge-file-storage" --file-sha "$(sha256sum "$RUNNER_TEMP/gorge-file-storage" | cut -d \' \' -f1)" --render-binary "$RUNNER_TEMP/gorge-render" --render-sha "$(sha256sum "$RUNNER_TEMP/gorge-render" | cut -d \' \' -f1)" --taskqueue-binary "$RUNNER_TEMP/gorge-taskqueue" --taskqueue-sha "$(sha256sum "$RUNNER_TEMP/gorge-taskqueue" | cut -d \' \' -f1)" > "$RUNNER_TEMP/governance-native-organization/private-email-sources.json"'
CURRENT_NOTIFICATION_REQUIRED_FLAGS = ('--root', '--expected-checkpoint', '--consumer', '--envelope', '--native-body', '--profile', '--runtime-before', '--runtime-after', '--profile-sha', '--file-binary', '--file-sha', '--render-binary', '--render-sha', '--taskqueue-binary', '--taskqueue-sha')
def require_current_notification_gate(before):
 lines=[line.strip()for line in before.splitlines()if 'check_full_native_task_schema_receipt_v2.py' in line and '--consumer notifications' in line]
 if lines != [CURRENT_NOTIFICATION_GATE]:raise ValueError('current_Task_notification_predecessor_refused')
 return before.index(CURRENT_NOTIFICATION_GATE)
class AtomicAPIConcurrencyTests(unittest.TestCase):
    def setUp(self):
        self.workflow=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text()
        self.step=self.workflow.split('      - name: Verify fresh native API credential concurrency after native regression\n',1)[1].split('      - uses:',1)[0]

    def test_serial_public_commands_and_failure_propagation(self):
        normal=require_current_notification_gate(self.workflow)
        focused=self.workflow.index('      - name: Verify fresh native API credential concurrency')
        self.assertLess(normal,focused)
        self.assertNotIn('if:',self.step)
        self.assertNotIn('continue-on-error',self.step)
        self.assertNotIn('|| true',self.step)
        self.assertIn('--api-credential-concurrency >',self.step)
        self.assertIn('--source-revision "$EXPECTED_SOURCE_CHECKPOINT"',self.step)
        self.assertIn('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$EXPECTED_SOURCE_CHECKPOINT"',self.step)
        self.assertIn('test "$(git rev-parse HEAD)" = "$EXPECTED_SOURCE_CHECKPOINT"',self.step)
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

    def test_current_notification_predecessor_requires_all_versioned_inputs(self):
        require_current_notification_gate(self.workflow)
        for flag in CURRENT_NOTIFICATION_REQUIRED_FLAGS:
            mutant=self.workflow.replace(CURRENT_NOTIFICATION_GATE,CURRENT_NOTIFICATION_GATE.replace(flag+' ', '--omitted-'+flag[2:]+' ',1),1)
            with self.subTest(flag=flag),self.assertRaises(ValueError):require_current_notification_gate(mutant)
        for suffix in (' || true','; true'):
            mutant=self.workflow.replace(CURRENT_NOTIFICATION_GATE,CURRENT_NOTIFICATION_GATE+suffix,1)
            with self.assertRaises(ValueError):require_current_notification_gate(mutant)
        for changed in (CURRENT_NOTIFICATION_GATE.replace('$EXPECTED_SOURCE_CHECKPOINT','$GITHUB_SHA'),CURRENT_NOTIFICATION_GATE.replace('--consumer notifications','--consumer native')):
            with self.assertRaises(ValueError):require_current_notification_gate(self.workflow.replace(CURRENT_NOTIFICATION_GATE,changed,1))
if __name__=='__main__':unittest.main()
