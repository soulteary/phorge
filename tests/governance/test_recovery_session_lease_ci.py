"""Actual Atomic shell ownership/failure controls; collaborators never execute SQL."""
from pathlib import Path
import os,stat,subprocess,tempfile,unittest
ROOT=Path(__file__).resolve().parents[2]
class SessionLeaseCI(unittest.TestCase):
 def test_actual_CI_step_refuses_foreign_paths_native_failure_and_checker_failure(self):
  workflow=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text();name='      - name: Verify fresh native Session producer lease after File source facts\n';step=workflow.split(name,1)[1].split('      - uses:',1)[0];script='\n'.join(line[10:] for line in step.splitlines() if line.startswith('          '))
  self.assertEqual(subprocess.run(['bash','-n'],input=script,text=True,capture_output=True).returncode,0)
  upload=workflow.split(name,1)[1].split('      - uses: actions/upload-artifact@v4\n',1)[1].split('      - name:',1)[0]
  self.assertIn('        if: success()\n',upload);self.assertNotIn('always()',upload)
  self.assertIn('path: ${{ runner.temp }}/governance-session-producer-lease',upload)
  head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
  with tempfile.TemporaryDirectory() as temp:
   tmp=Path(temp);target=tmp/'governance-session-producer-lease';foreign=tmp/'foreign';foreign.mkdir();sentinel=foreign/'sentinel';sentinel.write_bytes(b'private-foreign');env=dict(os.environ,RUNNER_TEMP=temp,GITHUB_SHA=head,GORGE_TEST_MYSQL_PASSWORD='synthetic-only')
   maps=tmp/'maps.sha256';maps.write_text(subprocess.check_output(['sha256sum','src/__phutil_library_map__.php','support/runtime/src/__phutil_library_map__.php','support/runtime/manifest.json'],cwd=ROOT,text=True));script=script.replace('/tmp/atomic-maps.sha256',str(maps))
   uploaded=[]
   def run():
    result=subprocess.run(['bash','-e','-o','pipefail','-c',script],cwd=ROOT,env=env,capture_output=True,text=True,timeout=10)
    # Match the actual workflow's exact success-only action condition.
    if result.returncode==0:uploaded.append((target/'accepted.json').read_bytes())
    return result
   for kind in ('file','dir','symlink'):
    if kind=='file':target.write_bytes(b'foreign')
    elif kind=='dir':target.mkdir();(target/'sentinel').write_bytes(b'foreign')
    else:target.symlink_to(foreign,target_is_directory=True)
    before=target.lstat();self.assertNotEqual(run().returncode,0);after=target.lstat();self.assertEqual((before.st_ino,before.st_mode),(after.st_ino,after.st_mode));self.assertEqual(sentinel.read_bytes(),b'private-foreign');self.assertEqual(uploaded,[])
    if kind=='dir':(target/'sentinel').unlink();target.rmdir()
    else:target.unlink()
   good_maps=maps.read_text();maps.write_text('0'*64+'  src/__phutil_library_map__.php\n');self.assertNotEqual(run().returncode,0);self.assertFalse((target/'accepted.json').exists());self.assertEqual(uploaded,[]);target.rmdir();maps.write_text(good_maps)
   for name in ('governance-batch-1','governance-native-organization'):(tmp/name).mkdir()
   (tmp/'governance-batch-1/native.json').write_text('synthetic-reference');(tmp/'governance-native-organization/accepted.json').write_text('synthetic-reference')
   (tmp/'gorge-file-storage').write_bytes(b'synthetic paired binary; never executed')
   commands=tmp/'commands';commands.mkdir();env['PATH']=str(commands)+os.pathsep+env['PATH'];php=commands/'php';php.write_text('#!/bin/sh\ntest "$PHORGE_GOVERNANCE_GORGE_FILE_BINARY" = "$RUNNER_TEMP/gorge-file-storage" || exit 90\ntest -n "$PHORGE_GOVERNANCE_GORGE_FILE_SHA256" || exit 91\nexit 42\n');php.chmod(0o700)
   self.assertEqual(run().returncode,42);self.assertFalse((target/'accepted.json').exists());self.assertEqual(stat.S_IMODE(target.stat().st_mode),0o700);self.assertEqual(uploaded,[])
   import shutil
   shutil.rmtree(target);php.write_text('#!/bin/sh\nprintf "synthetic-native-result"\n');checker=commands/'python3';checker.write_text('#!/bin/sh\nexit 43\n');checker.chmod(0o700)
   self.assertEqual(run().returncode,43);self.assertFalse((target/'accepted.json').exists());self.assertEqual((target/'integration.json').read_text(),'synthetic-native-result');self.assertEqual(uploaded,[])
   shutil.rmtree(target);checker.write_text('#!/bin/sh\ncase "$*" in *--file-binary*--file-binary-sha256*) ;; *) exit 92 ;; esac\nprintf "synthetic-accepted-only"\n')
   self.assertEqual(run().returncode,0);self.assertEqual(uploaded,[b'synthetic-accepted-only']);self.assertFalse((target/'accepted.pending.json').exists())
 def test_required_predecessors_and_real_focused_source_bootstrap_are_retained(self):
  s=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text()
  labels=['Verify fresh native partial restoration facts after Space source facts','Verify fresh native File source facts after Task owner facts','Verify fresh native Session producer lease after File source facts','Verify fresh native organization approval workbench after File source facts']
  self.assertEqual(sorted(s.index(x) for x in labels),[s.index(x) for x in labels])
  part=s.split('Verify fresh native Session producer lease after File source facts',1)[1].split('      - uses:',1)[0]
  for value in ('sha256sum --check /tmp/atomic-maps.sha256','verified-maps.sha256','--session-producer-lease','check_recovery_session_lease.py','--file-binary "$RUNNER_TEMP/gorge-file-storage"','--source-revision "$GITHUB_SHA"','PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION="$GITHUB_SHA"','test "$(git rev-parse HEAD)" = "$GITHUB_SHA"'):
   self.assertIn(value,part)
  driver=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text()
  self.assertLess(driver.index("require_once $root.'/support/runtime/bootstrap-cli.php'"),driver.index("$phase==='session-producer-lease'){"))
  self.assertIn("phutil_load_library($root.'/src/')",driver)
 def test_public_CLI_missing_required_independent_inputs_refuses_before_SQL(self):
  import sys
  cli=ROOT/'scripts/governance/check_recovery_session_lease.py'
  args=['--receipt','unused','--source-revision','a'*40,'--file-binary','unused-binary','--file-binary-sha256','b'*64]
  for i in range(0,len(args),2):
   result=subprocess.run([sys.executable,'-B',str(cli)]+args[:i]+args[i+2:],capture_output=True,text=True,timeout=10)
   self.assertEqual(result.returncode,2);self.assertEqual(result.stdout,'');self.assertIn('required',result.stderr)
