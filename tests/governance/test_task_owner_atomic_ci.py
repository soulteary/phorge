"""Real shell/filesystem/strict public CLI controls; no real SQL or hosted CI."""
from pathlib import Path
import os,stat,json,subprocess,tempfile,unittest,importlib.util,sys
ROOT=Path(__file__).resolve().parents[2]
def stable_stamp(path):
 s=path.lstat();return(s.st_dev,s.st_ino,s.st_mode,s.st_nlink,s.st_uid,s.st_gid,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
def step_script():
 workflow=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text();name='      - name: Verify fresh native Task owner source facts after Space source facts\n';step=workflow.split(name,1)[1].split('      - uses:',1)[0];return '\n'.join(line[10:]for line in step.splitlines()if line.startswith('          '))
class TaskOwnerAtomic(unittest.TestCase):
 def test_actual_CI_shell_exclusive_paths_and_native_failure_stop(self):
  workflow=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text();name='      - name: Verify fresh native Task owner source facts after Space source facts\n';step=workflow.split(name,1)[1].split('      - uses:',1)[0];script='\n'.join(line[10:] for line in step.splitlines() if line.startswith('          '));syntax=subprocess.run(['bash','-n'],input=script,text=True,capture_output=True);self.assertEqual(syntax.returncode,0,syntax.stderr)
  head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
  with tempfile.TemporaryDirectory() as temp:
   tmp=Path(temp);foreign=tmp/'foreign';foreign.mkdir();sentinel=foreign/'sentinel';sentinel.write_bytes(b'foreign-private');before=(stable_stamp(sentinel),sentinel.read_bytes());target=tmp/'governance-task-owner-source-facts';env=dict(os.environ,RUNNER_TEMP=temp,GITHUB_SHA=head,GORGE_TEST_MYSQL_PASSWORD='synthetic-only')
   for kind in ('directory','symlink','file'):
    if kind=='directory':target.mkdir();(target/'sentinel').write_bytes(b'owned-elsewhere')
    elif kind=='symlink':target.symlink_to(foreign,target_is_directory=True)
    else:target.write_bytes(b'owned-elsewhere')
    identity=stable_stamp(target);p=subprocess.run(['bash','-e','-o','pipefail','-c',script],cwd=ROOT,env=env,capture_output=True,text=True,timeout=10);self.assertNotEqual(p.returncode,0);self.assertEqual(stable_stamp(target),identity);self.assertEqual((stable_stamp(sentinel),sentinel.read_bytes()),before)
    if kind=='directory':self.assertEqual((target/'sentinel').read_bytes(),b'owned-elsewhere');(target/'sentinel').unlink();target.rmdir()
    else:target.unlink()
   for directory in ('governance-batch-1','governance-native-organization'):(tmp/directory).mkdir()
   (tmp/'governance-batch-1/native.json').write_bytes(b'synthetic-reference-only');(tmp/'governance-native-organization/accepted.json').write_bytes(b'synthetic-reference-only')
   fake=tmp/'commands';fake.mkdir();php=fake/'php';php.write_text('#!/bin/sh\nexit 42\n');php.chmod(0o700);env['PATH']=str(fake)+os.pathsep+env['PATH']
   p=subprocess.run(['bash','-e','-o','pipefail','-c',script],cwd=ROOT,env=env,capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,42,p.stderr);self.assertEqual((target/'source-revision.txt').read_text(),head+'\n');self.assertFalse((target/'accepted.json').exists());self.assertEqual((target/'shared-native-pure.json').read_bytes(),b'synthetic-reference-only');self.assertEqual(stat.S_IMODE(target.stat().st_mode),0o700)

 def test_real_shell_actual_public_gate_positive_and_invalid_receipt_stop(self):
  spec=importlib.util.spec_from_file_location('task_ci_template',ROOT/'tests/governance/test_recovery_task_owner_source_gate.py');t=importlib.util.module_from_spec(spec);spec.loader.exec_module(t)
  head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip();data=t.template();data['native']=t.gate.summary(head);script=step_script()
  for valid in (True,False):
   with tempfile.TemporaryDirectory()as temp:
    tmp=Path(temp);fake=tmp/'commands';fake.mkdir();payload=tmp/'payload';(tmp/'gorge-file-storage').write_bytes(b'synthetic-binary-reference-only');data['file_service_cleanup']['binary_sha256']=__import__('hashlib').sha256((tmp/'gorge-file-storage').read_bytes()).hexdigest();payload.write_text(json.dumps(data)if valid else '{"passed":true}');payload.chmod(0o600)
    php=fake/'php';php.write_text('#!/bin/sh\ncat "$TASK_CI_SYNTHETIC_RECEIPT"\n');php.chmod(0o700)
    for directory in ('governance-batch-1','governance-native-organization'):(tmp/directory).mkdir()
    for path in ('governance-batch-1/native.json','governance-native-organization/accepted.json'):(tmp/path).write_bytes(b'same-run-synthetic-reference-only')
    env=dict(os.environ,RUNNER_TEMP=temp,GITHUB_SHA=head,GORGE_TEST_MYSQL_PASSWORD='synthetic-only',TASK_CI_SYNTHETIC_RECEIPT=str(payload),PATH=str(fake)+os.pathsep+str(Path(sys.executable).parent)+os.pathsep+os.environ['PATH']);p=subprocess.run(['bash','-e','-o','pipefail','-c',script],cwd=ROOT,env=env,capture_output=True,text=True,timeout=15)
    target=tmp/'governance-task-owner-source-facts'
    if valid:
     self.assertEqual(p.returncode,0,p.stderr);self.assertEqual({x.name for x in target.iterdir()},{'integration.json','accepted.json','source-revision.txt','shared-native-pure.json','normal-regression-accepted.json'});accepted=json.loads((target/'accepted.json').read_text());self.assertEqual(accepted['checks'],30);self.assertFalse(accepted['restoration_authorized']);self.assertEqual(accepted['source_revision'],head)
    else:
     self.assertNotEqual(p.returncode,0);self.assertEqual((target/'accepted.json').read_bytes(),b'')
