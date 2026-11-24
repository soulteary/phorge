"""Pure artifact/owned-FS/shell parse controls, never native SQL qualification."""
import copy,importlib.util,json,os,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
S=ROOT/'scripts/governance/project_completion_ci_integration_v3.py'
spec=importlib.util.spec_from_file_location('completion_safe_projection',S);gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
def valid():
 d={'schema':'Completion-Task-strict-consumer-v3','source_window_kind':'one-genuine-owner-direct-process-and-validation','Task_additive_schema':{'source_revision':'a'*40,'native_profile_mode':'completion-v3'},'unchanged_completion_consumer':{'schema_valid':True,'can_execute':False,'global_completion':False}}
 d.update({k:True for k in ('completion_DATA_validated','completion_v3_accepted','producer_window_proven','consumer_source_exit_verified','driver_executed_actual_producer','SQL_executed_by_driver')});d.update({k:False for k in ('raw_current_receipt_modified','SQL_executed_by_checker','deployment_proved','activation_authorized','full_CI_qualified')});return d
class IntegrationControls(unittest.TestCase):
 def block(self):
  text=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text();a=text.index('      - name: Test actual Completion-v3 same-owner integration');b=text.index('      - uses: actions/upload-artifact@',a);return text[a:b]
 def test_actual_driver_external_checkpoint_public_trust_real_image_and_pairs_required(self):
  block=self.block()
  for text in ('--event-file "$GITHUB_EVENT_PATH"','--event-name "$GITHUB_EVENT_NAME"','--expected-head "$EXPECTED_SOURCE_CHECKPOINT"','--prepare-public-trust','export HOME="$trust_home"','mysql_image=$(docker inspect "$MYSQL_CONTAINER"',"mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7",'--observed-mysql-image "$mysql_image"','--file-binary "$RUNNER_TEMP/gorge-file-storage"','--taskqueue-binary "$RUNNER_TEMP/gorge-taskqueue"','--expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT"'):
   self.assertIn(text,block)
  self.assertEqual(block.count('scripts/governance/check_completion_task_receipt_v3.py'),1);self.assertNotIn('php support/governance/completion/fixture-run.php',block);self.assertNotIn('scripts/governance/check_completion.py --receipt',block);self.assertNotIn('continue-on-error',block);self.assertNotIn('||',block)
 def test_original_public_batch_copies_and_artifact_boundary_preserved(self):
  block=self.block()
  for text in ('governance-batch-1/native.json','governance-batch-3/native-source.json','governance-batch-4/native-rule-branches.json','governance-batch-5/native-pipeline-composition.json'):self.assertIn(text,block)
  workflow=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text();a=workflow.index('name: governance-completion-php-');upload=workflow[a:workflow.index('      - name:',a)];self.assertIn('path: ${{ runner.temp }}/governance-completion',upload);self.assertNotIn('private',upload)
  self.assertIn('project_completion_ci_integration_v3.py',block);self.assertIn('completion-v3-checker.stderr.private',block);self.assertNotIn('capture-native-body.private.json',block)
 def test_projection_drops_all_sensitive_extras_and_keeps_qualification_false(self):
  d=valid();d.update(hmac_key='SECRET',raw_stderr='SECRET',environment={'password':'SECRET'});d['Task_additive_schema']['source_root']='/private/SECRET';before=copy.deepcopy(d);row=gate.project(d,'a'*40);self.assertEqual(d,before);self.assertNotIn('SECRET',json.dumps(row));self.assertFalse(row['full_CI_qualified']);self.assertFalse(row['artifact_is_qualification_authority']);self.assertTrue(row['actual_same_owner_integration_passed'])
 def test_missing_wrong_bool_checkpoint_and_role_refused(self):
  original=valid()
  for key in ('completion_DATA_validated','completion_v3_accepted','producer_window_proven','consumer_source_exit_verified','driver_executed_actual_producer','SQL_executed_by_driver','raw_current_receipt_modified','SQL_executed_by_checker','deployment_proved','activation_authorized','full_CI_qualified'):
   for value in (None,1,0,'true'):
    d=copy.deepcopy(original);d[key]=value
    with self.assertRaises(ValueError):gate.project(d,'a'*40)
  for field,value in (('source_revision','b'*40),('native_profile_mode','conduit-Task-OFF')):
   d=copy.deepcopy(original);d['Task_additive_schema'][field]=value
   with self.assertRaises(ValueError):gate.project(d,'a'*40)
 def test_private_input_rejects_links_hardlinks_and_nonprivate_modes(self):
  with tempfile.TemporaryDirectory()as td:
   p=Path(td)/'input';p.write_bytes(json.dumps(valid()).encode());p.chmod(0o600);self.assertEqual(json.loads(gate.private(p)),valid());q=Path(td)/'alias';q.symlink_to(p)
   with self.assertRaises(OSError):gate.private(q)
   q.unlink();os.link(p,q)
   with self.assertRaises(ValueError):gate.private(p)
   q.unlink();p.chmod(0o644)
   with self.assertRaises(ValueError):gate.private(p)
 def test_changed_actual_shell_block_parses_without_execution(self):
  block=self.block();lines=block.split('        run: |\n',1)[1].splitlines();body='\n'.join(x[10:]for x in lines)+'\n';p=subprocess.run(['bash','-n'],input=body,text=True,capture_output=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr)
if __name__=='__main__':unittest.main()
