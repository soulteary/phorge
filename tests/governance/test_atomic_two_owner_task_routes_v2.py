"""CURRENT two-route shell contract DATA tests; actual SQL/source separately required."""
from pathlib import Path
import re,unittest
import task_atomic_access_shell_test_v2 as shell
ROOT=Path(__file__).resolve().parents[2]
class CurrentRoutes(unittest.TestCase):
 def changed(self,raw,role,old,new):
  parts=raw.decode().split('      - name: ')
  selected=[i for i,x in enumerate(parts)if '--role '+role+' --directory'in x];self.assertEqual(len(selected),1)
  i=selected[0];self.assertIn(old,parts[i]);parts[i]=parts[i].replace(old,new,1);return '      - name: '.join(parts).encode()
 def test_both_exact_current_commands_and_diagnostics(self):
  raw=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_bytes()
  for role in shell.CURRENT_COMMANDS:
   self.assertTrue(shell.assert_current_phase_contract(raw,role))
   with self.assertRaises(ValueError):shell.assert_current_phase_contract(raw+raw,role)
  with self.assertRaises(ValueError):shell.assert_current_phase_contract(raw,'unknown')
 def test_missing_flags_masks_wrongcheckpoint_and_failure_raw_route_refuse(self):
  raw=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_bytes()
  for role,commands in shell.CURRENT_COMMANDS.items():
   for command in commands:
    for flag in re.findall(r'(?<!\S)--[a-z][a-z-]*',command):
     changed=command.replace(flag+' ','--omitted-'+flag[2:]+' ',1)
     with self.subTest(role=role,flag=flag),self.assertRaises(ValueError):shell.assert_current_phase_contract(self.changed(raw,role,command,changed),role)
    for changed in (command+' || true',command.replace('$EXPECTED_SOURCE_CHECKPOINT','$GITHUB_SHA')):
     if changed!=command:
      with self.assertRaises(ValueError):shell.assert_current_phase_contract(self.changed(raw,role,command,changed),role)
   handler=shell.CURRENT_FAILURE_HANDLERS[role]
   for changed in (handler.replace('capture-native-body.private.json','integration.json'),handler.replace('exit "$native_status"','exit 0')):
    with self.assertRaises(ValueError):shell.assert_current_phase_contract(self.changed(raw,role,handler,changed),role)
 def test_both_owned_artifact_paths_refuse_foreign_identity_without_mutation(self):
  import os,subprocess,tempfile,textwrap
  raw=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_bytes();revision=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
  def stamp(path):
   st=path.lstat();return(st.st_dev,st.st_ino,st.st_mode,st.st_nlink,st.st_uid,st.st_gid,st.st_size,st.st_mtime_ns,st.st_ctime_ns)
  for role in shell.CURRENT_COMMANDS:
   shell.assert_current_phase_contract(raw,role);step=next(x for x in raw.decode().split('      - name: ')if '--role '+role+' --directory'in x).split('      - uses:',1)[0];body=textwrap.dedent(step.split('        run: |\n',1)[1])
   with tempfile.TemporaryDirectory()as temp:
    home=Path(temp);foreign=home/'foreign';foreign.mkdir();sentinel=foreign/'sentinel';sentinel.write_bytes(b'private foreign');target=home/('governance-conduit-producer-owner'if role=='conduit'else'governance-oauth-access-producer-owner')
    for kind in('file','directory','symlink'):
     if kind=='file':target.write_bytes(b'private target')
     elif kind=='directory':target.mkdir();(target/'sentinel').write_bytes(b'private target')
     else:target.symlink_to(foreign,target_is_directory=True)
     before=stamp(target);foreign_before=(stamp(sentinel),sentinel.read_bytes());own=(target/'sentinel').read_bytes()if kind=='directory'else target.read_bytes()if kind=='file'else os.readlink(target)
     r=subprocess.run(['/bin/bash','-euo','pipefail','-c',body],cwd=ROOT,env=dict(os.environ,RUNNER_TEMP=temp,EXPECTED_SOURCE_CHECKPOINT=revision),capture_output=True,timeout=5)
     self.assertNotEqual(r.returncode,0);self.assertEqual(stamp(target),before);self.assertEqual((stamp(sentinel),sentinel.read_bytes()),foreign_before);self.assertEqual((target/'sentinel').read_bytes()if kind=='directory'else target.read_bytes()if kind=='file'else os.readlink(target),own)
     if kind=='directory':(target/'sentinel').unlink();target.rmdir()
     else:target.unlink()
 def test_actual_probe_projection_matches_each_unchanged_consumer_runtime_schema(self):
  # Actual PHP projection of synthetic row DATA, never a database observation.
  import importlib.util,json,subprocess
  revision='a'*40
  for role,(probe,env,schema)in shell.ROLE_RUNTIME.items():
   function='conduit_mysql84_projection'if role=='conduit'else'oauth_access_mysql84_projection'
   code="require $argv[1];echo json_encode("+function+"(['version'=>'8.4.13','server_uuid'=>'11111111-2222-3333-4444-555555555555'],$argv[2],$argv[3],$argv[4]));"
   pair=[]
   for phase in('before','after'):
    result=subprocess.run(['php','-r',code,str(ROOT/'scripts/governance'/probe),phase,revision,shell.IMAGE],capture_output=True,text=True,timeout=5);self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(result.stderr,'');row=json.loads(result.stdout);self.assertEqual(row['schema'],schema);pair.append(row)
   name='conduit_task_strict_body_v2.py'if role=='conduit'else'oauth_access_task_strict_body_v2.py';spec=importlib.util.spec_from_file_location('pure_runtime_'+role.replace('-','_'),ROOT/'scripts/governance'/name);model=importlib.util.module_from_spec(spec);spec.loader.exec_module(model)
   self.assertTrue(model.runtime(*pair,revision)['same_primary_UUID'])
   for bad_schema in('native-full-mysql-runtime-v1','native-conduit-mysql-runtime-v1'if role=='oauth-access'else'native-oauth-access-mysql-runtime-v1'):
    bad=[dict(x,schema=bad_schema)for x in pair]
    with self.assertRaises(ValueError):model.runtime(*bad,revision)
   raw=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_bytes();generic=raw.replace(probe.encode(),b'probe_full_native_task_mysql84.php').replace(env.encode(),b'FULL_NATIVE_MYSQL_OBSERVED_IMAGE')
   with self.assertRaises(ValueError):shell.assert_current_phase_contract(generic,role)
 def test_actual_current_Access_all_original_diagnostic_modes(self):
  self.exercise('oauth-access',shell.run_atomic_phase)
 def test_actual_current_Conduit_diagnostic_modes(self):
  self.exercise('conduit',shell.run_conduit_phase)
 def exercise(self,role,run):
  import subprocess
  raw=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_bytes();shell.assert_current_phase_contract(raw,role)
  revision=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
  for mode in sorted(shell.MODES):
   with self.subTest(role=role,mode=mode):
    d=run(ROOT,mode,workflow_current=raw,revision=revision)
    self.assertEqual(d['rc'],0 if mode=='success'else 19 if mode=='consumer-failure'else 37,d)
    self.assertTrue(d['orchestration_only']);self.assertFalse(d['native_or_source_SQL_HMAC_qualified']);self.assertNotIn('PRIVATE_TOKEN_PASSWORD_SQL',d['stderr'])
    self.assertEqual(d['accepted'],mode=='success')
    if mode in('projector-failure','marker-failure','foreign-failure'):self.assertFalse(d['owned'])
    if mode=='foreign-failure':self.assertEqual(d['foreign'],'preserve')
    if mode=='native-failure':self.assertTrue(d['owned']);self.assertTrue(d['safe']['diagnostic_available'])
    if mode=='stderr-only':
     self.assertTrue(d['owned']);self.assertEqual(d['safe']['diagnostic_available'],role=='oauth-access')
if __name__=='__main__':unittest.main()
