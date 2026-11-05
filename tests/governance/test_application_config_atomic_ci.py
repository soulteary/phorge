"""Host workflow contracts and mutations; no actual CI or database execution."""
from pathlib import Path
import re,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
NAME='      - name: Verify fresh native application configuration facts after API concurrency\n'
ARTIFACT='governance-application-config-facts'
MEMBERS={'integration.json','accepted.json','source-revision.txt','shared-native-pure.json','normal-regression-accepted.json'}
def validate_workflow(workflow):
 def need(value):
  if not value:raise ValueError('config CI contract refused')
 need(workflow.count(NAME)==1)
 before,rest=workflow.split(NAME,1);step,artifact=rest.split('      - uses:',1);artifact=artifact.split('      - name:',1)[0]
 need('python3 scripts/governance/check_private_notifications.py --receipt' in before)
 need('python3 -B scripts/governance/check_api_credential_concurrency.py --receipt' in before)
 need('if:' not in step and 'continue-on-error' not in step and '|| true' not in step)
 need('test "$(git rev-parse HEAD)" = "$GITHUB_SHA"' in step)
 need('umask 077' in step)
 need('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$GITHUB_SHA"' in step)
 need('PHORGE_GOVERNANCE_ISOLATED_TEST=1' in step)
 need('--application-config-facts >' in step)
 need('python3 -B scripts/governance/check_recovery_application_config.py --receipt' in step)
 need('--source-revision "$GITHUB_SHA"' in step)
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
   self.workflow.replace('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$GITHUB_SHA"','PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="old-head"')]
  for mutant in mutants:
   with self.assertRaises(ValueError):validate_workflow(mutant)
 def test_unknown_artifact_or_fake_dependency_refused(self):
  for mutant in [self.workflow.replace(ARTIFACT+'/integration.json',ARTIFACT+'/old-result.json'),
   self.workflow.replace(ARTIFACT+'/shared-native-pure.json',ARTIFACT+'/binary-sha256.txt'),
   self.workflow.replace(NAME,NAME+'        run-extra: docker start unwanted\n')]:
   with self.assertRaises(ValueError):validate_workflow(mutant)
if __name__=='__main__':unittest.main()
