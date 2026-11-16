"""Real shell/filesystem/strict public CLI controls; no real SQL or hosted CI."""
from pathlib import Path
import os,stat,json,subprocess,tempfile,unittest,importlib.util,sys
ROOT=Path(__file__).resolve().parents[2]
WORKFLOW=ROOT/'.github/workflows/atomic-feature-regressions.yml'
from task_paired_workflow_shell_test_api_v2 import assert_shell,assert_mutations
LEGACY_PUBLIC_CLI_SHELL = 'umask 077\ntest "$(git rev-parse HEAD)" = "$EXPECTED_SOURCE_CHECKPOINT"\nmkdir "$RUNNER_TEMP/governance-task-owner-source-facts"\ngit rev-parse HEAD > "$RUNNER_TEMP/governance-task-owner-source-facts/source-revision.txt"\ncp "$RUNNER_TEMP/governance-batch-1/native.json" "$RUNNER_TEMP/governance-task-owner-source-facts/shared-native-pure.json"\ncp "$RUNNER_TEMP/governance-native-organization/accepted.json" "$RUNNER_TEMP/governance-task-owner-source-facts/normal-regression-accepted.json"\nPHORGE_GOVERNANCE_ISOLATED_TEST=1 \\\nPHORGE_GOVERNANCE_FIXTURE_DSN=\'mysql:host=127.0.0.1;port=3306;dbname=governance_fixture_bootstrap;charset=utf8mb4\' \\\nPHORGE_GOVERNANCE_FIXTURE_USER=root \\\nPHORGE_GOVERNANCE_FIXTURE_PASSWORD="$GORGE_TEST_MYSQL_PASSWORD" \\\nPHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$EXPECTED_SOURCE_CHECKPOINT" \\\nPHORGE_GOVERNANCE_GORGE_FILE_BINARY="$RUNNER_TEMP/gorge-file-storage" \\\nPHORGE_GOVERNANCE_GORGE_FILE_SHA256="$(sha256sum "$RUNNER_TEMP/gorge-file-storage" | cut -d \' \' -f1)" \\\nphp support/governance/native-organization/test-native-organization-mysql.php --task-owner-source-facts > "$RUNNER_TEMP/governance-task-owner-source-facts/integration.json"\npython3 -B scripts/governance/check_recovery_task_owner_source.py --receipt "$RUNNER_TEMP/governance-task-owner-source-facts/integration.json" --source-revision "$EXPECTED_SOURCE_CHECKPOINT" --file-binary-sha256 "$(sha256sum "$RUNNER_TEMP/gorge-file-storage" | cut -d \' \' -f1)" > "$RUNNER_TEMP/governance-task-owner-source-facts/accepted.json"'
def stable_stamp(path):
 s=path.lstat();return(s.st_dev,s.st_ino,s.st_mode,s.st_nlink,s.st_uid,s.st_gid,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
def step_script():
 workflow=WORKFLOW.read_text();name='      - name: Verify fresh native Task owner source facts after Space source facts\n';step=workflow.split(name,1)[1].split('      - uses:',1)[0];return '\n'.join(line[10:]for line in step.splitlines()if line.startswith('          '))
class TaskOwnerAtomic(unittest.TestCase):
 def test_actual_CI_shell_exclusive_paths_and_native_failure_stop(self):
  """Actual CURRENT shell with explicit test-only endpoints; no SQL proof."""
  workflow=WORKFLOW.read_text()
  assert_shell(self,ROOT,workflow,'task-owner-source-facts')
  assert_mutations(self,workflow,'task-owner-source-facts')

 def test_real_shell_actual_public_gate_positive_and_invalid_receipt_stop(self):
  """Retain real unchanged legacy CLI semantics, not CURRENT runtime proof."""
  spec=importlib.util.spec_from_file_location('task_ci_template',ROOT/'tests/governance/test_recovery_task_owner_source_gate.py');t=importlib.util.module_from_spec(spec);spec.loader.exec_module(t)
  head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip();data=t.template();data['native']=t.gate.summary(head);script=LEGACY_PUBLIC_CLI_SHELL
  for valid in (True,False):
   with tempfile.TemporaryDirectory()as temp:
    tmp=Path(temp);fake=tmp/'commands';fake.mkdir();payload=tmp/'payload';(tmp/'gorge-file-storage').write_bytes(b'synthetic-binary-reference-only');data['file_service_cleanup']['binary_sha256']=__import__('hashlib').sha256((tmp/'gorge-file-storage').read_bytes()).hexdigest();payload.write_text(json.dumps(data)if valid else '{"passed":true}');payload.chmod(0o600)
    php=fake/'php';php.write_text('#!/bin/sh\ncat "$TASK_CI_SYNTHETIC_RECEIPT"\n');php.chmod(0o700)
    for directory in ('governance-batch-1','governance-native-organization'):(tmp/directory).mkdir()
    for path in ('governance-batch-1/native.json','governance-native-organization/accepted.json'):(tmp/path).write_bytes(b'same-run-synthetic-reference-only')
    env=dict(os.environ,RUNNER_TEMP=temp,EXPECTED_SOURCE_CHECKPOINT=head,GITHUB_SHA=('0' if head[0]!='0' else '1')+head[1:],GORGE_TEST_MYSQL_PASSWORD='synthetic-only',TASK_CI_SYNTHETIC_RECEIPT=str(payload),PATH=str(fake)+os.pathsep+str(Path(sys.executable).parent)+os.pathsep+os.environ['PATH']);p=subprocess.run(['bash','-e','-o','pipefail','-c',script],cwd=ROOT,env=env,capture_output=True,text=True,timeout=15)
    target=tmp/'governance-task-owner-source-facts'
    if valid:
     self.assertEqual(p.returncode,0,p.stderr);self.assertEqual({x.name for x in target.iterdir()},{'integration.json','accepted.json','source-revision.txt','shared-native-pure.json','normal-regression-accepted.json'});accepted=json.loads((target/'accepted.json').read_text());self.assertEqual(accepted['checks'],30);self.assertFalse(accepted['restoration_authorized']);self.assertEqual(accepted['source_revision'],head)
    else:
     self.assertNotEqual(p.returncode,0);self.assertEqual((target/'accepted.json').read_bytes(),b'')
