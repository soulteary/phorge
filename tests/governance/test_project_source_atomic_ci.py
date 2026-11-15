"""Host workflow contracts and mutations; no actual CI or database execution."""
from pathlib import Path
import os,re,subprocess,tempfile,unittest
ROOT=Path(__file__).resolve().parents[2]
NAME='      - name: Verify fresh native Project source facts after application configuration\n'
ARTIFACT='governance-project-source-facts'
MEMBERS={'integration.json','accepted.json','source-revision.txt','shared-native-pure.json','normal-regression-accepted.json'}
CURRENT_NOTIFICATION_GATE = 'python3 -B scripts/governance/check_full_native_task_schema_receipt_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --consumer notifications --envelope "$RUNNER_TEMP/governance-native-organization/integration.json" --native-body "$RUNNER_TEMP/task-full-native-v2-private/capture-native-body.private.json" --profile "$RUNNER_TEMP/task-full-native-v2-private/capture/schema-profile.private.json" --runtime-before "$RUNNER_TEMP/governance-native-organization/mysql-before.json" --runtime-after "$RUNNER_TEMP/governance-native-organization/mysql-after.json" --profile-sha "$(python3 -c \'import json,sys; print(json.load(open(sys.argv[1]))["profile_sha256"])\' "$RUNNER_TEMP/task-full-native-v2-private/capture/binding.safe.json")" --file-binary "$RUNNER_TEMP/gorge-file-storage" --file-sha "$(sha256sum "$RUNNER_TEMP/gorge-file-storage" | cut -d \' \' -f1)" --render-binary "$RUNNER_TEMP/gorge-render" --render-sha "$(sha256sum "$RUNNER_TEMP/gorge-render" | cut -d \' \' -f1)" --taskqueue-binary "$RUNNER_TEMP/gorge-taskqueue" --taskqueue-sha "$(sha256sum "$RUNNER_TEMP/gorge-taskqueue" | cut -d \' \' -f1)" > "$RUNNER_TEMP/governance-native-organization/private-email-sources.json"'
CURRENT_NOTIFICATION_REQUIRED_FLAGS = ('--root', '--expected-checkpoint', '--consumer', '--envelope', '--native-body', '--profile', '--runtime-before', '--runtime-after', '--profile-sha', '--file-binary', '--file-sha', '--render-binary', '--render-sha', '--taskqueue-binary', '--taskqueue-sha')
def require_current_notification_gate(before):
 lines=[line.strip()for line in before.splitlines()if 'check_full_native_task_schema_receipt_v2.py' in line and '--consumer notifications' in line]
 if lines != [CURRENT_NOTIFICATION_GATE]:raise ValueError('current_Task_notification_predecessor_refused')
 return before.index(CURRENT_NOTIFICATION_GATE)
def stable_stamp(path):
 st=path.lstat()
 # Reading an owned path may update atime; it is not evidence of a write.
 return (st.st_dev,st.st_ino,st.st_mode,st.st_nlink,st.st_uid,st.st_gid,st.st_size,st.st_mtime_ns,st.st_ctime_ns)
def validate_workflow(workflow):
 def need(value):
  if not value:raise ValueError('Project CI contract refused')
 need(workflow.count(NAME)==1)
 before,rest=workflow.split(NAME,1);step,artifact=rest.split('      - uses:',1);artifact=artifact.split('      - name:',1)[0]
 require_current_notification_gate(before)
 need('python3 -B scripts/governance/check_api_credential_concurrency.py --receipt' in before)
 need('python3 -B scripts/governance/check_recovery_application_config.py --receipt' in before)
 need('if:' not in step and 'continue-on-error' not in step and '|| true' not in step)
 need('test "$(git rev-parse HEAD)" = "$EXPECTED_SOURCE_CHECKPOINT"' in step)
 need('umask 077' in step)
 need('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$EXPECTED_SOURCE_CHECKPOINT"' in step)
 need('PHORGE_GOVERNANCE_ISOLATED_TEST=1' in step)
 need('--project-source-facts >' in step)
 need('python3 -B scripts/governance/check_recovery_project_source.py --receipt' in step)
 need('--source-revision "$EXPECTED_SOURCE_CHECKPOINT" > "$RUNNER_TEMP/governance-project-source-facts/accepted.json"' in step)
 need('cp "$RUNNER_TEMP/governance-batch-1/native.json"' in step)
 need('cp "$RUNNER_TEMP/governance-native-organization/accepted.json"' in step)
 need(set(re.findall(r'\$RUNNER_TEMP/governance-project-source-facts/([^"\n]+)"',step))==MEMBERS)
 need('if: success()' in artifact and 'if: always()' not in artifact and 'if-no-files-found: error' in artifact)
 need('mkdir "$RUNNER_TEMP/governance-project-source-facts"' in step)
 need('mkdir -p' not in step)
 need('name: governance-project-source-facts-php-${{ matrix.php }}' in artifact)
 need('path: ${{ runner.temp }}/governance-project-source-facts' in artifact)
 for forbidden in ('docker ','podman ','PHORGE_GOVERNANCE_GORGE_','--file-binary','sha256sum','bin/rebuild-library-map','tests/governance/run.php','team-readiness'):
  need(forbidden not in step)
 return step
class ProjectSourceAtomic(unittest.TestCase):
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
   self.workflow.replace('--project-source-facts >','--project-source-facts || true >'),
   self.workflow.replace('python3 -B scripts/governance/check_api_credential_concurrency.py --receipt','python3 omitted-API-gate --receipt'),
   self.workflow.replace('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$EXPECTED_SOURCE_CHECKPOINT"','PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="old-head"')]
  for mutant in mutants:
   with self.assertRaises(ValueError):validate_workflow(mutant)
 def test_unknown_artifact_or_fake_dependency_refused(self):
  for mutant in [self.workflow.replace(ARTIFACT+'/integration.json',ARTIFACT+'/old-result.json'),
   self.workflow.replace(ARTIFACT+'/shared-native-pure.json',ARTIFACT+'/binary-sha256.txt'),
   self.workflow.replace(NAME,NAME+'        run-extra: docker start unwanted\n'),
   self.workflow.replace('python3 -B scripts/governance/check_recovery_application_config.py --receipt','python3 missing-config-gate --receipt'),
   self.workflow.replace('check_recovery_project_source.py --receipt','check_recovery_project_source.py --receipt',1).replace('--source-revision "$EXPECTED_SOURCE_CHECKPOINT" > "$RUNNER_TEMP/governance-project-source-facts/accepted.json"','--source-revision old-head > "$RUNNER_TEMP/governance-project-source-facts/accepted.json"')]:
   with self.assertRaises(ValueError):validate_workflow(mutant)
 def test_exclusive_directory_real_prefix_preserves_foreign_paths(self):
  step=validate_workflow(self.workflow)
  script='\n'.join(x[10:] for x in step.splitlines() if x.startswith('          '))
  prefix=script.split('cp "',1)[0]
  def snapshot(path):
   result={}
   for child in [path,*sorted(path.rglob('*'))]:
    result[str(child.relative_to(path))]=(stable_stamp(child),child.read_bytes() if child.is_file() and not child.is_symlink() else None,os.readlink(child) if child.is_symlink() else None)
   return result
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp);foreign=root/'foreign';foreign.mkdir();(foreign/'sentinel').write_bytes(b'unchanged')
   target=root/ARTIFACT
   for kind in ('directory','symlink'):
    if kind=='directory':target.mkdir();(target/'sentinel').write_bytes(b'original')
    else:target.symlink_to(foreign,target_is_directory=True)
    before=snapshot(foreign);target_before=stable_stamp(target);own_before=snapshot(target) if kind=='directory' else None
    env=dict(os.environ,RUNNER_TEMP=tmp,EXPECTED_SOURCE_CHECKPOINT=(atomic_source_head := subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()),GITHUB_SHA=('0' if atomic_source_head[0]!='0' else '1')+atomic_source_head[1:])
    proc=subprocess.run(['bash','-e','-o','pipefail','-c',prefix],cwd=ROOT,env=env,capture_output=True)
    self.assertNotEqual(0,proc.returncode)
    self.assertEqual(before,snapshot(foreign));self.assertEqual(target_before,stable_stamp(target))
    if kind=='directory':self.assertEqual(own_before,snapshot(target));(target/'sentinel').unlink();target.rmdir()
    else:target.unlink()
   proc=subprocess.run(['bash','-e','-o','pipefail','-c',prefix],cwd=ROOT,env=env,capture_output=True)
   self.assertEqual(0,proc.returncode,proc.stderr)
   self.assertEqual(env['EXPECTED_SOURCE_CHECKPOINT']+'\n',(target/'source-revision.txt').read_text())
 def test_directory_and_upload_mutations_refused(self):
  for old,new in [('mkdir "$RUNNER_TEMP/governance-project-source-facts"','mkdir -p "$RUNNER_TEMP/governance-project-source-facts"'),('if: success()','if: always()')]:
   before,section=self.workflow.split(NAME,1)
   with self.assertRaises(ValueError):validate_workflow(before+NAME+section.replace(old,new,1))
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
