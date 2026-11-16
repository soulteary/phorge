"""Real shell/filesystem/strict public CLI controls; no real SQL or hosted CI."""
from pathlib import Path
import os,stat,json,subprocess,tempfile,unittest,importlib.util,sys
ROOT=Path(__file__).resolve().parents[2]
def stable_stamp(path):
 s=path.lstat();return(s.st_dev,s.st_ino,s.st_mode,s.st_nlink,s.st_uid,s.st_gid,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
def step_script():
 workflow=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text();name='      - name: Verify fresh native organization approval workbench after File source facts\n';step=workflow.split(name,1)[1].split('      - uses:',1)[0];return '\n'.join(line[10:]for line in step.splitlines()if line.startswith('          '))
LEGACY_PUBLIC_CLI_SHELL='umask 077\ntest "$(git rev-parse HEAD)" = "$EXPECTED_SOURCE_CHECKPOINT"\nmkdir "$RUNNER_TEMP/governance-organization-approval-workbench"\ngit rev-parse HEAD > "$RUNNER_TEMP/governance-organization-approval-workbench/source-revision.txt"\ncp "$RUNNER_TEMP/governance-batch-1/native.json" "$RUNNER_TEMP/governance-organization-approval-workbench/shared-native-pure.json"\ncp "$RUNNER_TEMP/governance-native-organization/accepted.json" "$RUNNER_TEMP/governance-organization-approval-workbench/normal-regression-accepted.json"\nPHORGE_GOVERNANCE_ISOLATED_TEST=1 \\\nPHORGE_GOVERNANCE_FIXTURE_DSN=\'mysql:host=127.0.0.1;port=3306;dbname=governance_fixture_bootstrap;charset=utf8mb4\' \\\nPHORGE_GOVERNANCE_FIXTURE_USER=root \\\nPHORGE_GOVERNANCE_FIXTURE_PASSWORD="$GORGE_TEST_MYSQL_PASSWORD" \\\nPHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$EXPECTED_SOURCE_CHECKPOINT" \\\nphp support/governance/native-organization/test-native-organization-mysql.php --organization-approval-workbench > "$RUNNER_TEMP/governance-organization-approval-workbench/integration.json"\npython3 -B scripts/governance/check_organization_approval_workbench.py --receipt "$RUNNER_TEMP/governance-organization-approval-workbench/integration.json" --source-revision "$EXPECTED_SOURCE_CHECKPOINT" > "$RUNNER_TEMP/governance-organization-approval-workbench/accepted.json"'
class ApprovalWorkbenchAtomic(unittest.TestCase):
 def test_actual_CI_shell_exclusive_paths_and_native_failure_stop(self):
  # Test-only closed orchestration; signed same-owner SQL is a separate gate.
  import task_workbench_workflow_shell_test_api_v2 as shell
  workflow=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text()
  shell.assert_shell(self,ROOT,workflow,'organization-approval-workbench');shell.assert_mutations(self,workflow,'organization-approval-workbench')

 def test_current_no_File_route_and_original_runtime_schema_are_explicit(self):
  # Actual PHP projection of synthetic DATA, never database/owner qualification.
  import task_workbench_workflow_shell_test_api_v2 as shell
  workflow=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text();role='organization-approval-workbench';step=shell.validate(workflow,role)
  for replacement in(step.replace('probe_full_native_task_mysql84.php','probe_conduit_task_mysql84.php'),step.replace('          umask 077','          PHORGE_GOVERNANCE_GORGE_FILE_BINARY=unexpected\n          umask 077')):
   with self.assertRaises(ValueError):shell.validate(workflow.replace(step,replacement,1),role)
  from check_full_native_task_schema_receipt_v2 import runtime_pair
  code="require $argv[1];echo json_encode(full_native_mysql84_projection(['version'=>'8.4.13','server_uuid'=>'11111111-2222-3333-4444-555555555555'],$argv[2],$argv[3],$argv[4]));";pair=[];revision='a'*40
  for phase in('before','after'):
   result=subprocess.run(['php','-r',code,str(ROOT/'scripts/governance/probe_full_native_task_mysql84.php'),phase,revision,shell.IMAGE],capture_output=True,text=True,timeout=5);self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(result.stderr,'');pair.append(json.loads(result.stdout))
  self.assertTrue(runtime_pair(*pair,revision,{'server_uuid':'11111111-2222-3333-4444-555555555555'})['same_primary_UUID'])
  with self.assertRaises(ValueError):runtime_pair(*[dict(x,schema='native-conduit-mysql-runtime-v1')for x in pair],revision,{'server_uuid':'11111111-2222-3333-4444-555555555555'})
  with self.assertRaises(ValueError):runtime_pair(*pair,revision,{'server_uuid':'ffffffff-2222-3333-4444-555555555555'})

 def test_real_shell_actual_public_gate_positive_and_invalid_receipt_stop(self):
  spec=importlib.util.spec_from_file_location('workbench_ci_template',ROOT/'tests/governance/test_organization_approval_workbench_gate.py');t=importlib.util.module_from_spec(spec);spec.loader.exec_module(t)
  head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip();data=t.template();data['native']=t.gate.summary(head);script=LEGACY_PUBLIC_CLI_SHELL
  for valid in (True,False):
   with tempfile.TemporaryDirectory()as temp:
    tmp=Path(temp);fake=tmp/'commands';fake.mkdir();payload=tmp/'payload';payload.write_text(json.dumps(data)if valid else '{"passed":true}');payload.chmod(0o600)
    php=fake/'php';php.write_text('#!/bin/sh\ncat "$WORKBENCH_CI_SYNTHETIC_RECEIPT"\n');php.chmod(0o700)
    for directory in ('governance-batch-1','governance-native-organization'):(tmp/directory).mkdir()
    for path in ('governance-batch-1/native.json','governance-native-organization/accepted.json'):(tmp/path).write_bytes(b'same-run-synthetic-reference-only')
    env=dict(os.environ,RUNNER_TEMP=temp,EXPECTED_SOURCE_CHECKPOINT=head,GITHUB_SHA=('0' if head[0]!='0' else '1')+head[1:],GORGE_TEST_MYSQL_PASSWORD='synthetic-only',WORKBENCH_CI_SYNTHETIC_RECEIPT=str(payload),PATH=str(fake)+os.pathsep+str(Path(sys.executable).parent)+os.pathsep+os.environ['PATH']);p=subprocess.run(['bash','-e','-o','pipefail','-c',script],cwd=ROOT,env=env,capture_output=True,text=True,timeout=15)
    target=tmp/'governance-organization-approval-workbench'
    if valid:
     self.assertEqual(p.returncode,0,p.stderr);self.assertEqual({x.name for x in target.iterdir()},{'integration.json','accepted.json','source-revision.txt','shared-native-pure.json','normal-regression-accepted.json'});accepted=json.loads((target/'accepted.json').read_text());self.assertEqual(accepted['checks'],26);self.assertFalse(accepted['restoration_authorized']);self.assertEqual(accepted['source_revision'],head)
    else:
     self.assertNotEqual(p.returncode,0);self.assertEqual((target/'accepted.json').read_bytes(),b'')
