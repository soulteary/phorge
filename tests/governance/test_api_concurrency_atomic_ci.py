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
CURRENT_API_PRODUCER = 'python3 -B scripts/governance/run_task_directory_native_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --role api-credential-concurrency --directory "$RUNNER_TEMP/task-api-credential-v2-private" --stdout "$RUNNER_TEMP/governance-api-credential-concurrency/integration.json" --stderr "$RUNNER_TEMP/api-credential.stderr.private"'
CURRENT_API_CONSUMER = 'python3 -B scripts/governance/check_api_credential_task_schema_receipt_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --envelope "$RUNNER_TEMP/governance-api-credential-concurrency/integration.json" --native-body "$RUNNER_TEMP/task-api-credential-v2-private/capture-native-body.private.json" --profile "$RUNNER_TEMP/task-api-credential-v2-private/capture/schema-profile.private.json" --profile-sha "$(python3 -c \'import json,sys; print(json.load(open(sys.argv[1]))["profile_sha256"])\' "$RUNNER_TEMP/task-api-credential-v2-private/capture/binding.safe.json")" --runtime-before "$RUNNER_TEMP/governance-api-credential-concurrency/mysql-before.json" --runtime-after "$RUNNER_TEMP/governance-api-credential-concurrency/mysql-after.json" --file-binary "$RUNNER_TEMP/gorge-file-storage" --file-sha "$(sha256sum "$RUNNER_TEMP/gorge-file-storage" | cut -d \' \' -f1)" > "$RUNNER_TEMP/governance-api-credential-concurrency/accepted.json"'
def require_current_api_commands(step):
 for program,expected in (('run_task_directory_native_v2.py',CURRENT_API_PRODUCER),('check_api_credential_task_schema_receipt_v2.py',CURRENT_API_CONSUMER)):
  if [line.strip()for line in step.splitlines()if program in line] != [expected]:raise ValueError('current_Task_API_command_refused')
 if step.index(CURRENT_API_PRODUCER)>=step.index(CURRENT_API_CONSUMER):raise ValueError('current_Task_API_order_refused')
 return step.index(CURRENT_API_PRODUCER)
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
        producer=require_current_api_commands(self.step)
        self.assertIn('--role api-credential-concurrency',CURRENT_API_PRODUCER)
        self.assertIn('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$EXPECTED_SOURCE_CHECKPOINT"',self.step)
        self.assertIn('test "$(git rev-parse HEAD)" = "$EXPECTED_SOURCE_CHECKPOINT"',self.step)
        self.assertNotIn('team-readiness',self.step)
        docker_lines=[line.strip()for line in self.step.splitlines()if 'docker 'in line]
        self.assertEqual(docker_lines,["mysql_image=$(docker inspect \"$MYSQL_CONTAINER\" --format '{{.Config.Image}}')"]*2)
        self.assertLess(self.step.index('binary-sha256.txt'),producer)
        self.assertEqual(self.step.count('mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'),2)
        self.assertLess(self.step.index('mysql-before.json'),producer)
        self.assertLess(producer,self.step.index('mysql-after.pending.json'))
        self.assertIn('shared-native-pure.json',self.step)
        self.assertIn('normal-regression-accepted.json',self.step)

    def test_current_api_commands_reject_missing_inputs_and_failure_masks(self):
        require_current_api_commands(self.step)
        import re
        for command in (CURRENT_API_PRODUCER,CURRENT_API_CONSUMER):
            for flag in re.findall(r'(?<!\S)--[a-z][a-z-]*',command):
                mutant=self.step.replace(command,command.replace(flag+' ', '--omitted-'+flag[2:]+' ',1),1)
                with self.subTest(flag=flag),self.assertRaises(ValueError):require_current_api_commands(mutant)
            for suffix in (' || true','; true'):
                with self.assertRaises(ValueError):require_current_api_commands(self.step.replace(command,command+suffix,1))
            with self.assertRaises(ValueError):require_current_api_commands(self.step.replace(command,command.replace('$EXPECTED_SOURCE_CHECKPOINT','$GITHUB_SHA'),1))

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
