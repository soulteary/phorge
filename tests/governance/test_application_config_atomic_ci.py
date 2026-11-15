"""Host workflow contracts and mutations; no actual CI or database execution."""
from pathlib import Path
import re,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
NAME='      - name: Verify fresh native application configuration facts after API concurrency\n'
ARTIFACT='governance-application-config-facts'
MEMBERS={'integration.json','accepted.json','source-revision.txt','shared-native-pure.json','normal-regression-accepted.json'}
CURRENT_NOTIFICATION_GATE = 'python3 -B scripts/governance/check_full_native_task_schema_receipt_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --consumer notifications --envelope "$RUNNER_TEMP/governance-native-organization/integration.json" --native-body "$RUNNER_TEMP/task-full-native-v2-private/capture-native-body.private.json" --profile "$RUNNER_TEMP/task-full-native-v2-private/capture/schema-profile.private.json" --runtime-before "$RUNNER_TEMP/governance-native-organization/mysql-before.json" --runtime-after "$RUNNER_TEMP/governance-native-organization/mysql-after.json" --profile-sha "$(python3 -c \'import json,sys; print(json.load(open(sys.argv[1]))["profile_sha256"])\' "$RUNNER_TEMP/task-full-native-v2-private/capture/binding.safe.json")" --file-binary "$RUNNER_TEMP/gorge-file-storage" --file-sha "$(sha256sum "$RUNNER_TEMP/gorge-file-storage" | cut -d \' \' -f1)" --render-binary "$RUNNER_TEMP/gorge-render" --render-sha "$(sha256sum "$RUNNER_TEMP/gorge-render" | cut -d \' \' -f1)" --taskqueue-binary "$RUNNER_TEMP/gorge-taskqueue" --taskqueue-sha "$(sha256sum "$RUNNER_TEMP/gorge-taskqueue" | cut -d \' \' -f1)" > "$RUNNER_TEMP/governance-native-organization/private-email-sources.json"'
CURRENT_NOTIFICATION_REQUIRED_FLAGS = ('--root', '--expected-checkpoint', '--consumer', '--envelope', '--native-body', '--profile', '--runtime-before', '--runtime-after', '--profile-sha', '--file-binary', '--file-sha', '--render-binary', '--render-sha', '--taskqueue-binary', '--taskqueue-sha')
def require_current_notification_gate(before):
 lines=[line.strip()for line in before.splitlines()if 'check_full_native_task_schema_receipt_v2.py' in line and '--consumer notifications' in line]
 if lines != [CURRENT_NOTIFICATION_GATE]:raise ValueError('current_Task_notification_predecessor_refused')
 return before.index(CURRENT_NOTIFICATION_GATE)
def validate_workflow(workflow):
 def need(value):
  if not value:raise ValueError('config CI contract refused')
 need(workflow.count(NAME)==1)
 before,rest=workflow.split(NAME,1);step,artifact=rest.split('      - uses:',1);artifact=artifact.split('      - name:',1)[0]
 require_current_notification_gate(before)
 need('python3 -B scripts/governance/check_api_credential_concurrency.py --receipt' in before)
 need('if:' not in step and 'continue-on-error' not in step and '|| true' not in step)
 need('test "$(git rev-parse HEAD)" = "$EXPECTED_SOURCE_CHECKPOINT"' in step)
 need('umask 077' in step)
 need('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$EXPECTED_SOURCE_CHECKPOINT"' in step)
 need('PHORGE_GOVERNANCE_ISOLATED_TEST=1' in step)
 need('--application-config-facts >' in step)
 need('python3 -B scripts/governance/check_recovery_application_config.py --receipt' in step)
 need('--source-revision "$EXPECTED_SOURCE_CHECKPOINT"' in step)
 need('cp "$RUNNER_TEMP/governance-batch-1/native.json"' in step)
 need('cp "$RUNNER_TEMP/governance-native-organization/accepted.json"' in step)
 need(set(re.findall(r'\$RUNNER_TEMP/governance-application-config-facts/([^"\n]+)"',step))==MEMBERS)
 need('if: always()' in artifact and 'if-no-files-found: error' in artifact)
 need('name: governance-application-config-facts-php-${{ matrix.php }}' in artifact)
 need('path: ${{ runner.temp }}/governance-application-config-facts' in artifact)
 for forbidden in ('docker ','podman ','PHORGE_GOVERNANCE_GORGE_','--file-binary','sha256sum','bin/rebuild-library-map','tests/governance/run.php','team-readiness'):
  need(forbidden not in step)
 return step
class ApplicationConfigAtomic(unittest.TestCase):
 def setUp(self):self.workflow=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text()
 def test_actual_serial_workflow_and_shell(self):
  step=validate_workflow(self.workflow)
  script='\n'.join(x[10:]for x in step.splitlines()if x.startswith('          '))
  p=subprocess.run(['bash','-n'],input=script,text=True,capture_output=True)
  self.assertEqual(0,p.returncode,p.stderr)
 def test_failure_guard_order_and_source_mutants_refused(self):
  validate_workflow(self.workflow)
  mutants=[self.workflow.replace(NAME,NAME+'        if: always()\n'),
   self.workflow.replace(NAME,NAME+'        continue-on-error: true\n'),
   self.workflow.replace('--application-config-facts >','--application-config-facts || true >'),
   self.workflow.replace('python3 -B scripts/governance/check_api_credential_concurrency.py --receipt','python3 omitted-API-gate --receipt'),
   self.workflow.replace('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$EXPECTED_SOURCE_CHECKPOINT"','PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="old-head"')]
  for mutant in mutants:
   with self.assertRaises(ValueError):validate_workflow(mutant)
 def test_unknown_artifact_or_fake_dependency_refused(self):
  for mutant in [self.workflow.replace(ARTIFACT+'/integration.json',ARTIFACT+'/old-result.json'),
   self.workflow.replace(ARTIFACT+'/shared-native-pure.json',ARTIFACT+'/binary-sha256.txt'),
   self.workflow.replace(NAME,NAME+'        run-extra: docker start unwanted\n')]:
   with self.assertRaises(ValueError):validate_workflow(mutant)
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
