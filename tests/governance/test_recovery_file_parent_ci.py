"""Actual Atomic shell ownership/failure controls; collaborators never execute SQL."""
from pathlib import Path
import os,stat,subprocess,tempfile,unittest
ROOT=Path(__file__).resolve().parents[2]
class FileParentCI(unittest.TestCase):
 def test_actual_CI_step_refuses_foreign_paths_native_failure_and_checker_failure(self):
  # Closed synthetic orchestration only; actual source/SQL qualifies separately.
  import task_three_paired_workflow_shell_test_api_v2 as shell
  workflow=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text()
  shell.assert_shell(self,ROOT,workflow,'file-parent-policy');shell.assert_mutations(self,workflow,'file-parent-policy')
 def test_required_predecessors_and_real_focused_source_bootstrap_are_retained(self):
  s=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text()
  labels=['Verify fresh native partial restoration facts after Space source facts','Verify fresh native File source facts after Task owner facts','Verify fresh native File parent-policy endpoints after File source facts','Verify fresh native organization approval workbench after File source facts']
  self.assertEqual(sorted(s.index(x) for x in labels),[s.index(x) for x in labels])
  part=s.split('Verify fresh native File parent-policy endpoints after File source facts',1)[1].split('      - uses:',1)[0]
  for value in ('sha256sum --check /tmp/atomic-maps.sha256','verified-maps.sha256','--role file-parent-policy','check_paired_session_facts_task_schema_receipt_v2.py','--file-binary "$RUNNER_TEMP/gorge-file-storage"','--expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT"','PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$EXPECTED_SOURCE_CHECKPOINT"','test "$(git rev-parse HEAD)" = "$EXPECTED_SOURCE_CHECKPOINT"'):
   self.assertIn(value,part)
  driver=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text()
  self.assertLess(driver.index("require_once $root.'/support/runtime/bootstrap-cli.php'"),driver.index("$phase==='file-parent-policy'){"))
  self.assertIn("phutil_load_library($root.'/src/')",driver)
 def test_public_CLI_missing_required_independent_inputs_refuses_before_SQL(self):
  import sys
  cli=ROOT/'scripts/governance/check_recovery_file_parent_policy.py'
  args=['--receipt','unused','--source-revision','a'*40,'--file-binary','unused-binary','--file-binary-sha256','b'*64]
  for i in range(0,len(args),2):
   result=subprocess.run([sys.executable,'-B',str(cli)]+args[:i]+args[i+2:],capture_output=True,text=True,timeout=10)
   self.assertEqual(result.returncode,2);self.assertEqual(result.stdout,'');self.assertIn('required',result.stderr)
