"""Closed test-only workflow orchestration stubs, never SQL or source authority.
The unchanged legacy public CLI is tested separately. Real new consumer acceptance
requires signed same-owner SQL, not any stub output from this module.
"""
import hashlib,json,os,re,shlex,stat,subprocess,sys,tempfile
from pathlib import Path
PROFILES={'file-parent-policy': ('      - name: Verify fresh native File parent-policy endpoints after File source facts\n', 'governance-file-parent-policy', 'python3 -B scripts/governance/run_task_directory_native_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --role file-parent-policy --directory "$RUNNER_TEMP/task-file-parent-policy-v2-private" --stdout "$RUNNER_TEMP/governance-file-parent-policy/integration.json" --stderr "$RUNNER_TEMP/file-parent-policy.stderr.private" || { native_status=$?; python3 -B scripts/governance/project_file_parent_failure.py --receipt "$RUNNER_TEMP/task-file-parent-policy-v2-private/capture-native-body.private.json" || true; exit "$native_status"; }', 'python3 -B scripts/governance/check_paired_session_facts_task_schema_receipt_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --role file-parent-policy --envelope "$RUNNER_TEMP/governance-file-parent-policy/integration.json" --native-body "$RUNNER_TEMP/task-file-parent-policy-v2-private/capture-native-body.private.json" --profile "$RUNNER_TEMP/task-file-parent-policy-v2-private/capture/schema-profile.private.json" --profile-sha "$(python3 -c \'import json,sys; print(json.load(open(sys.argv[1]))["profile_sha256"])\' "$RUNNER_TEMP/task-file-parent-policy-v2-private/capture/binding.safe.json")" --runtime-before "$RUNNER_TEMP/governance-file-parent-policy/mysql-before.json" --runtime-after "$RUNNER_TEMP/governance-file-parent-policy/mysql-after.json" --file-binary "$RUNNER_TEMP/gorge-file-storage" --file-sha "$(sha256sum "$RUNNER_TEMP/gorge-file-storage" | cut -d \' \' -f1)" > "$RUNNER_TEMP/governance-file-parent-policy/accepted.pending.json"'), 'session-producer-lease': ('      - name: Verify fresh native Session producer lease after File source facts\n', 'governance-session-producer-lease', 'python3 -B scripts/governance/run_task_directory_native_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --role session-producer-lease --directory "$RUNNER_TEMP/task-session-producer-lease-v2-private" --stdout "$RUNNER_TEMP/governance-session-producer-lease/integration.json" --stderr "$RUNNER_TEMP/session-producer-lease.stderr.private"', 'python3 -B scripts/governance/check_paired_session_facts_task_schema_receipt_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --role session-producer-lease --envelope "$RUNNER_TEMP/governance-session-producer-lease/integration.json" --native-body "$RUNNER_TEMP/task-session-producer-lease-v2-private/capture-native-body.private.json" --profile "$RUNNER_TEMP/task-session-producer-lease-v2-private/capture/schema-profile.private.json" --profile-sha "$(python3 -c \'import json,sys; print(json.load(open(sys.argv[1]))["profile_sha256"])\' "$RUNNER_TEMP/task-session-producer-lease-v2-private/capture/binding.safe.json")" --runtime-before "$RUNNER_TEMP/governance-session-producer-lease/mysql-before.json" --runtime-after "$RUNNER_TEMP/governance-session-producer-lease/mysql-after.json" --file-binary "$RUNNER_TEMP/gorge-file-storage" --file-sha "$(sha256sum "$RUNNER_TEMP/gorge-file-storage" | cut -d \' \' -f1)" > "$RUNNER_TEMP/governance-session-producer-lease/accepted.pending.json"'), 'session-borrowed-owner': ('      - name: Verify fresh borrowed native Session owner after Session producer lease\n', 'governance-borrowed-session-owner', 'python3 -B scripts/governance/run_task_directory_native_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --role session-borrowed-owner --directory "$RUNNER_TEMP/task-session-borrowed-owner-v2-private" --stdout "$RUNNER_TEMP/governance-borrowed-session-owner/integration.json" --stderr "$RUNNER_TEMP/session-borrowed-owner.stderr.private" || { native_status=$?; if mkdir "$RUNNER_TEMP/governance-borrowed-session-failure"; then if python3 -B scripts/governance/project_borrowed_failure.py --receipt "$RUNNER_TEMP/task-session-borrowed-owner-v2-private/capture-native-body.private.json" > "$RUNNER_TEMP/governance-borrowed-session-failure/diagnostic.json"; then cat "$RUNNER_TEMP/governance-borrowed-session-failure/diagnostic.json" >&2; echo "diagnostic_owned=true" >> "$GITHUB_OUTPUT"; fi; fi; exit "$native_status"; }', 'python3 -B scripts/governance/check_paired_session_facts_task_schema_receipt_v2.py --root "$GITHUB_WORKSPACE" --expected-checkpoint "$EXPECTED_SOURCE_CHECKPOINT" --role session-borrowed-owner --envelope "$RUNNER_TEMP/governance-borrowed-session-owner/integration.json" --native-body "$RUNNER_TEMP/task-session-borrowed-owner-v2-private/capture-native-body.private.json" --profile "$RUNNER_TEMP/task-session-borrowed-owner-v2-private/capture/schema-profile.private.json" --profile-sha "$(python3 -c \'import json,sys; print(json.load(open(sys.argv[1]))["profile_sha256"])\' "$RUNNER_TEMP/task-session-borrowed-owner-v2-private/capture/binding.safe.json")" --runtime-before "$RUNNER_TEMP/governance-borrowed-session-owner/mysql-before.json" --runtime-after "$RUNNER_TEMP/governance-borrowed-session-owner/mysql-after.json" --file-binary "$RUNNER_TEMP/gorge-file-storage" --file-sha "$(sha256sum "$RUNNER_TEMP/gorge-file-storage" | cut -d \' \' -f1)" > "$RUNNER_TEMP/governance-borrowed-session-owner/accepted.pending.json"')}
TRUST_COMMANDS={'file-parent-policy': ('python3 -B scripts/governance/check_task_ci_head_v1.py --prepare-public-trust --public-key "$GITHUB_WORKSPACE/support/governance/source-operation/ci-source-verifier.asc" --trust-home "$trust_home"', 'python3 -B scripts/governance/check_task_ci_head_v1.py --root "$GITHUB_WORKSPACE" --event-file "$GITHUB_EVENT_PATH" --event-name "$GITHUB_EVENT_NAME" --expected-head "$EXPECTED_SOURCE_CHECKPOINT" --github-sha "$GITHUB_SHA" --trust-home "$trust_home"'), 'session-producer-lease': ('python3 -B scripts/governance/check_task_ci_head_v1.py --prepare-public-trust --public-key "$GITHUB_WORKSPACE/support/governance/source-operation/ci-source-verifier.asc" --trust-home "$trust_home"', 'python3 -B scripts/governance/check_task_ci_head_v1.py --root "$GITHUB_WORKSPACE" --event-file "$GITHUB_EVENT_PATH" --event-name "$GITHUB_EVENT_NAME" --expected-head "$EXPECTED_SOURCE_CHECKPOINT" --github-sha "$GITHUB_SHA" --trust-home "$trust_home"'), 'session-borrowed-owner': ('python3 -B scripts/governance/check_task_ci_head_v1.py --prepare-public-trust --public-key "$GITHUB_WORKSPACE/support/governance/source-operation/ci-source-verifier.asc" --trust-home "$trust_home"', 'python3 -B scripts/governance/check_task_ci_head_v1.py --root "$GITHUB_WORKSPACE" --event-file "$GITHUB_EVENT_PATH" --event-name "$GITHUB_EVENT_NAME" --expected-head "$EXPECTED_SOURCE_CHECKPOINT" --github-sha "$GITHUB_SHA" --trust-home "$trust_home"')}
IMAGE='mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'
def need(value):
 if value is not True:raise ValueError('test_only_paired_workflow_refused')
def validate(workflow,role):
 need(role in PROFILES);profile=PROFILES[role];name,artifact,producer,consumer=profile
 need(workflow.count(name)==1);section=workflow.split(name,1)[1];step,upload=section.split('      - uses:',1);upload=upload.split('      - name:',1)[0]
 need('if:'not in step and 'continue-on-error'not in step and '|| true'not in step.replace(producer,'') and '; true'not in step)
 for expected in (producer,consumer,*TRUST_COMMANDS[role]):
  program=expected.split(' ',3)[2];qualifier='--prepare-public-trust'if '--prepare-public-trust'in expected else '--root'
  need([x.strip()for x in step.splitlines()if program in x and (qualifier in x if 'check_task_ci_head_v1.py'in program else True)]==[expected])
 need(step.index(producer)<step.index(consumer))
 need('test "$(git rev-parse HEAD)" = "$EXPECTED_SOURCE_CHECKPOINT"'in step)
 need('mkdir "$RUNNER_TEMP/'+artifact+'"'in step and 'mkdir -p'not in step)
 need('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$EXPECTED_SOURCE_CHECKPOINT"'in step)
 if role=='spaces-source-facts':
  need('PHORGE_GOVERNANCE_GORGE_'not in step and '--file-binary'not in consumer and '--file-sha'not in consumer)
 else:
  need(role in {'file-parent-policy','session-producer-lease','session-borrowed-owner'})
  need('PHORGE_GOVERNANCE_GORGE_FILE_BINARY="$RUNNER_TEMP/gorge-file-storage"'in step)
  need('PHORGE_GOVERNANCE_GORGE_FILE_SHA256='in step)
 need('if: success()'in upload and 'if: always()'not in upload and 'if-no-files-found: error'in upload)
 need('path: ${{ runner.temp }}/'+artifact in upload)
 need(step.count(IMAGE)==2 and step.index('mysql-before.json')<step.index(producer)<step.index('mysql-after.pending.json')<step.index(consumer))
 if role in PROFILES:need('mv "$RUNNER_TEMP/'+artifact+'/accepted.pending.json" "$RUNNER_TEMP/'+artifact+'/accepted.json"'in step)
 return step

def stub_program():
 return '#!'+sys.executable+'\n'+r'''
# Synthetic orchestration endpoint only. No SQL/source proof is issued.
import json,os,pathlib,sys
args=sys.argv[1:];root=pathlib.Path(os.environ['RUNNER_TEMP']);mode=os.environ['PAIRED_SHELL_TEST_MODE'];role=os.environ['PAIRED_SHELL_TEST_ROLE'];artifact=os.environ['PAIRED_SHELL_TEST_ARTIFACT'];label=None
name=pathlib.Path(sys.argv[0]).name
if name=='docker':
 if args!=['inspect',os.environ['MYSQL_CONTAINER'],'--format','{{.Config.Image}}']:raise SystemExit(90)
 print('mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7');label='image'
elif name=='php':
 if len(args)!=3 or not args[0].endswith('/probe_full_native_task_mysql84.php') or args[1]not in ('before','after')or args[2]!=os.environ['EXPECTED_SOURCE_CHECKPOINT']:raise SystemExit(91)
 label=args[1];print('{}')
elif name=='python3':
 if args[0]=='-c':
  expected='import json,sys; print(json.load(open(sys.argv[1]))["profile_sha256"])'
  expected_path=root/('task-'+role+'-v2-private/capture/binding.safe.json')
  if len(args)!=3 or args[1]!=expected or pathlib.Path(args[2])!=expected_path:raise SystemExit(92)
  if mode=='profile-fails':raise SystemExit(44)
  if json.loads(expected_path.read_bytes())!={'profile_sha256':'a'*64}:raise SystemExit(92)
  print('a'*64);raise SystemExit(0)
 if args[0]!='-B':raise SystemExit(92)
 path=args[1];argv=args[2:]
 if path.endswith('/check_task_ci_head_v1.py'):
  label='public'if '--prepare-public-trust'in argv else'head'
 elif path.endswith('/run_task_directory_native_v2.py'):
  label='producer'
  if argv[argv.index('--role')+1]!=role or argv[argv.index('--expected-checkpoint')+1]!=os.environ['EXPECTED_SOURCE_CHECKPOINT']:raise SystemExit(93)
  if role=='spaces-source-facts':
   if any(k.startswith('PHORGE_GOVERNANCE_GORGE_')for k in os.environ):raise SystemExit(94)
  elif role in ('file-parent-policy','session-producer-lease','session-borrowed-owner'):
   if not os.environ.get('PHORGE_GOVERNANCE_GORGE_FILE_SHA256'):raise SystemExit(94)
  else:raise SystemExit(94)
  if mode in ('producer-fails','missing-body','projector-fails'):
   raw=root/('task-'+role+'-v2-private/capture-native-body.private.json');raw.parent.mkdir(parents=True)
   if mode!='missing-body':raw.write_text('{"passed":false,"secret":"not-for-output"}')
   raise SystemExit(42)
  pathlib.Path(argv[argv.index('--stdout')+1]).write_bytes(b'synthetic-native-orchestration-only')
  binding=pathlib.Path(argv[argv.index('--directory')+1])/'capture/binding.safe.json';binding.parent.mkdir(parents=True);binding.write_text(json.dumps({'profile_sha256':'a'*64}))
 elif path in ('scripts/governance/check_paired_session_facts_task_schema_receipt_v2.py','scripts/governance/check_source_facts_task_schema_receipt_v2.py'):
  label='consumer'
  if argv[argv.index('--role')+1]!=role or argv[argv.index('--profile-sha')+1]!='a'*64:raise SystemExit(95)
  if role=='spaces-source-facts':
   if path!='scripts/governance/check_source_facts_task_schema_receipt_v2.py' or '--file-binary'in argv or '--file-sha'in argv:raise SystemExit(95)
  elif role in ('file-parent-policy','session-producer-lease','session-borrowed-owner'):
   if path!='scripts/governance/check_paired_session_facts_task_schema_receipt_v2.py' or argv[argv.index('--file-binary')+1]!=str(root/'gorge-file-storage') or not argv[argv.index('--file-sha')+1]:raise SystemExit(95)
  else:raise SystemExit(95)
  if mode=='consumer-fails':raise SystemExit(43)
  print(json.dumps({'synthetic_shell_only':True,'source_owner_qualified':False,'SQL_qualified':False}))
 elif path in ('scripts/governance/project_file_parent_failure.py','scripts/governance/project_session_lease_failure.py','scripts/governance/project_borrowed_failure.py'):
  with (root/'projector-called').open('w')as f:f.write(path)
  if mode=='projector-fails':raise SystemExit(43)
  os.execv(sys.executable,[sys.executable,'-B',path]+argv)
 else:raise SystemExit(96)
else:raise SystemExit(97)
with (root/'stub-events.jsonl').open('a')as f:f.write(json.dumps(label)+'\n')
'''

def stable_stamp(path):
 s=Path(path).lstat()
 return(s.st_dev,s.st_ino,s.st_mode,s.st_nlink,s.st_uid,s.st_gid,s.st_size,s.st_mtime_ns,s.st_ctime_ns)

def assert_shell(test,root,workflow,role):
 root=Path(root);name,artifact,producer,consumer=PROFILES[role];step=validate(workflow,role);body=step.split('        run: |\n',1)[1];script='\n'.join(x[10:]for x in body.splitlines()if x.startswith('          '))
 syntax=subprocess.run(['bash','-n'],input=script,text=True,capture_output=True,timeout=5);test.assertEqual(syntax.returncode,0,syntax.stderr)
 head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip()
 with tempfile.TemporaryDirectory()as temp:
  tmp=Path(temp);maps=tmp/'maps.sha256';maps.write_text(subprocess.check_output(['sha256sum','src/__phutil_library_map__.php','support/runtime/src/__phutil_library_map__.php','support/runtime/manifest.json'],cwd=root,text=True));script=script.replace('/tmp/atomic-maps.sha256',str(maps));foreign=tmp/'foreign';foreign.mkdir();(foreign/'sentinel').write_bytes(b'private-foreign');target=tmp/artifact
  env=dict(os.environ,RUNNER_TEMP=temp,EXPECTED_SOURCE_CHECKPOINT=head,GITHUB_SHA=('0'if head[0]!='0'else'1')+head[1:],GITHUB_WORKSPACE=str(root),GITHUB_EVENT_PATH=str(tmp/'unused-event'),GITHUB_EVENT_NAME='pull_request',GITHUB_OUTPUT=str(tmp/'github-output'),MYSQL_CONTAINER='synthetic-db-not-real',GORGE_TEST_MYSQL_PASSWORD='synthetic-only',PAIRED_SHELL_TEST_ROLE=role,PAIRED_SHELL_TEST_ARTIFACT=artifact,PAIRED_SHELL_TEST_MODE='producer-fails')
  def run():return subprocess.run(['bash','-e','-o','pipefail','-c',script],cwd=root,env=env,capture_output=True,text=True,timeout=15)
  for kind in ('file','directory','symlink'):
   if kind=='file':target.write_bytes(b'foreign')
   elif kind=='directory':target.mkdir();(target/'sentinel').write_bytes(b'foreign')
   else:target.symlink_to(foreign,target_is_directory=True)
   before=stable_stamp(target);foreign_before=(stable_stamp(foreign/'sentinel'),(foreign/'sentinel').read_bytes())
   own_before=(target/'sentinel').read_bytes()if kind=='directory'else target.read_bytes()if kind=='file'else os.readlink(target)
   test.assertNotEqual(run().returncode,0);test.assertEqual(stable_stamp(target),before)
   test.assertEqual((stable_stamp(foreign/'sentinel'),(foreign/'sentinel').read_bytes()),foreign_before)
   test.assertEqual((target/'sentinel').read_bytes()if kind=='directory'else target.read_bytes()if kind=='file'else os.readlink(target),own_before)
   if kind=='directory':(target/'sentinel').unlink();target.rmdir()
   else:target.unlink()
  good_maps=maps.read_bytes();maps.write_text('0'*64+'  src/__phutil_library_map__.php\n');test.assertNotEqual(run().returncode,0);test.assertFalse((target/'accepted.json').exists());target.rmdir();maps.write_bytes(good_maps)
  for d in ('governance-batch-1','governance-native-organization'):(tmp/d).mkdir()
  for f in ('governance-batch-1/native.json','governance-native-organization/accepted.json'):(tmp/f).write_bytes(b'synthetic-reference-only')
  (tmp/'gorge-file-storage').write_bytes(b'synthetic paired binary; never executed')
  commands=tmp/'commands';commands.mkdir()
  for name in ('python3','php','docker'):
   p=commands/name;p.write_text(stub_program());p.chmod(0o700)
  env['PATH']=str(commands)+os.pathsep+env['PATH'];import shutil
  for mode,expected in (('producer-fails',42),('missing-body',42),('projector-fails',42),('consumer-fails',43),('profile-fails',95),('success',0)):
   env['PAIRED_SHELL_TEST_MODE']=mode;result=run();test.assertEqual(result.returncode,expected,result.stderr);test.assertEqual(stat.S_IMODE(target.stat().st_mode),0o700)
   test.assertEqual((target/'source-revision.txt').read_text(),head+'\n');test.assertEqual((target/'shared-native-pure.json').read_bytes(),b'synthetic-reference-only')
   if expected:
    test.assertFalse((target/'accepted.json').exists()and(target/'accepted.json').stat().st_size>0)
    test.assertNotIn('not-for-output',result.stdout+result.stderr)
    if mode in ('producer-fails','missing-body','projector-fails'):
     test.assertFalse((target/'accepted.pending.json').exists())
     if role!='session-producer-lease':test.assertTrue((tmp/'projector-called').exists())
   else:
    test.assertEqual(result.stderr,'')
    accepted=json.loads((target/'accepted.json').read_bytes());test.assertFalse(accepted['SQL_qualified']);test.assertFalse(accepted['source_owner_qualified']);test.assertFalse((target/'accepted.pending.json').exists())
    events=[json.loads(x)for x in (tmp/'stub-events.jsonl').read_text().splitlines()];test.assertEqual(events,['public','head','image','before','producer','image','after','consumer'])
   shutil.rmtree(tmp/'governance-borrowed-session-failure',ignore_errors=True);(tmp/'projector-called').unlink(missing_ok=True);shutil.rmtree(target);shutil.rmtree(tmp/('task-'+role+'-v2-private'),ignore_errors=True);(tmp/'stub-events.jsonl').unlink(missing_ok=True)

def assert_mutations(test,workflow,role):
 validate(workflow,role)
 name=PROFILES[role][0];before,section=workflow.split(name,1)
 def mutant(command,changed):return before+name+section.replace(command,changed,1)
 for command in (*PROFILES[role][2:],*TRUST_COMMANDS[role]):
  for flag in re.findall(r'(?<!\S)--[a-z][a-z-]*',command):
   with test.subTest(flag=flag),test.assertRaises(ValueError):validate(mutant(command,command.replace(flag+' ','--omitted-'+flag[2:]+' ',1)),role)
  for changed in (command+' || true',command+'; true',command.replace('$EXPECTED_SOURCE_CHECKPOINT','$GITHUB_SHA')):
   if changed==command:continue
   with test.assertRaises(ValueError):validate(mutant(command,changed),role)
