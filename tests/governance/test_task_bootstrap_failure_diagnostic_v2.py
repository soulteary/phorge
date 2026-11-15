"""Pure bounded projection and private filesystem controls; no native/SQL run."""
import ast,importlib.util,json,os,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
SPEC=importlib.util.spec_from_file_location('bootstrap_projection_pure',ROOT/'scripts/governance/project_task_bootstrap_failure_v2.py')
M=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(M)

class BootstrapDiagnosticTest(unittest.TestCase):
 def row(self,**extra):
  failure={'stage':'preflight','error_type':'RuntimeException','source_file':'bootstrap-cli.php','source_line':3};failure.update(extra)
  return json.dumps({'batch':M.ROLES['conduit'],'passed':False,'failure':failure}).encode()
 def projection(self,raw=None,stderr=b'',code=37):return M.project_data('conduit',code,self.row()if raw is None else raw,stderr,{'support/runtime/bootstrap-cli.php':10})
 def owned(self,root,name,value):
  p=root/name;p.write_bytes(value if type(value)is bytes else json.dumps(value).encode());p.chmod(0o600);return p
 def test_closed_failure_metadata_original_exit_and_no_authority(self):
  row=self.projection();self.assertEqual(set(row),M.KEYS);self.assertEqual(row['native_exit_code'],37)
  self.assertEqual((row['phase'],row['error_class'],row['source_file'],row['source_line']),('preflight','RuntimeException','support/runtime/bootstrap-cli.php',3))
  for field in('passed','runtime_authority','native_body_created','schema_proof_claimed'):self.assertIs(row[field],False)
  self.assertEqual(row['cleanup'],'unknown');self.assertEqual(M.validate_safe(row,'conduit',37),row)
 def test_secret_message_sql_trace_config_hmac_nonce_address_never_projected(self):
  secret='CANARY-private-credential-not-for-artifacts';raw=self.row(message=secret,sql=secret,trace=[secret],config={secret:secret},hmac=secret,nonce=secret,token=secret,address=secret)
  out=json.dumps(self.projection(raw));self.assertNotIn(secret,out)
  for name in('message','sql','trace','config','hmac','nonce','token','address'):self.assertNotIn('"'+name+'"',out)
 def test_malformed_duplicate_nonfinite_success_wrong_role_are_not_proof(self):
  rows=[b'not JSON',b'{"passed":false,"passed":false}',b'{"x":NaN}',b'x'*1048577,
   json.dumps({'batch':M.ROLES['conduit'],'passed':True,'failure':{}}).encode(),
   json.dumps({'batch':M.ROLES['oauth-access'],'passed':False,'failure':{}}).encode()]
  for raw in rows:
   with self.subTest(raw=raw[:50]):
    row=self.projection(raw);self.assertEqual(row['phase'],'bootstrap-no-native-body');self.assertEqual(row['error_class'],'unavailable');self.assertIsNone(row['source_file'])
 def test_unknown_fields_types_and_out_of_bounds_cannot_escape(self):
  for extra in({'stage':[]},{'error_type':'secret'},{'source_file':'/private/secret'},{'source_line':True},{'source_line':11},{'source_file':'../bootstrap-cli.php'}):
   row=self.projection(self.row(**extra));self.assertNotIn('secret',json.dumps(row));self.assertIs(row['passed'],False)
   if 'source_'in str(extra):self.assertIsNone(row['source_file'])
  for code in(0,True,None,'37',256,-129):
   with self.assertRaises(ValueError):self.projection(code=code)
 def test_fixed_markers_only_exact_complete_lines(self):
  marker=b'Task_directory_v2_source_refused'
  self.assertEqual(self.projection(b'',marker+b'\n')['phase'],'source-validation')
  for raw in(b'PHP message '+marker,marker+b' poisoned',marker+b'\n'+marker):self.assertEqual(self.projection(b'',raw)['phase'],'bootstrap-no-native-body')
 def test_complete_stderr_protocol_line_without_message_copy(self):
  row={'scope':'native-conduit-producer-failure-v1','passed':False,'stage':'preflight','error_type':'TypeError','source_file':'bootstrap-cli.php','source_line':2,'message':'CANARY'}
  actual=self.projection(b'',json.dumps(row).encode());self.assertEqual(actual['error_class'],'TypeError');self.assertNotIn('CANARY',json.dumps(actual))
 def original(self):
  return {'scope':'native-conduit-producer-failure-v1','passed':False,'diagnostic_available':True,'stage':'preflight','attempt_stage':'preflight','error_type':'RuntimeException','source_file':None,'source_line':None,'query_code':None,'child_available':False,'child_status':'other','child_identity_started':False,'child_result_available':False}
 def test_original_priority_then_unavailable_fallback_only(self):
  with tempfile.TemporaryDirectory()as directory:
   root=Path(directory).resolve();original=self.owned(root,'original',self.original());bootstrap=self.owned(root,'bootstrap',self.projection())
   self.assertEqual(M.choose(original,bootstrap,'conduit',37),self.original())
   original.write_text(json.dumps({'diagnostic_available':False,'passed':False}));self.assertEqual(M.choose(original,bootstrap,'conduit',37),self.projection())
   original.write_text(json.dumps(dict(self.original(),message='CANARY')));self.assertEqual(M.choose(original,bootstrap,'conduit',37),self.projection())
 def test_bootstrap_closed_fields_role_exit_and_no_false_authority(self):
  for change in({'runtime_authority':True},{'passed':True},{'cleanup':'complete'},{'native_body_created':True},{'schema_proof_claimed':True},{'native_exit_code':1},{'role':'oauth-code'},{'message':'CANARY'}):
   with self.subTest(change=change),self.assertRaises((ValueError,TypeError)):M.validate_safe(dict(self.projection(),**change),'conduit',37)
 def test_private_reader_refuses_symlink_hardlink_modes_and_size(self):
  with tempfile.TemporaryDirectory()as directory:
   root=Path(directory).resolve();p=self.owned(root,'p',b'ok');self.assertEqual(M.private(p),b'ok')
   q=root/'link';q.symlink_to(p)
   with self.assertRaises(ValueError):M.private(q)
   q.unlink();os.link(p,q)
   with self.assertRaises(ValueError):M.private(p)
   q.unlink();p.chmod(0o644)
   with self.assertRaises(ValueError):M.private(p)
   p.chmod(0o600)
   with self.assertRaises(ValueError):M.private(p,1)
 def test_cli_refusal_has_no_traceback_or_secret(self):
  with tempfile.TemporaryDirectory()as directory:
   root=Path(directory).resolve();p=self.owned(root,'bad',b'{"CANARY":"secret"}')
   run=subprocess.run(['python3','-B',str(ROOT/M.SELF),'--bootstrap',str(p),'--role','conduit','--native-exit-code','37'],capture_output=True,timeout=5)
   self.assertEqual(run.returncode,1);self.assertEqual(run.stdout,b'');self.assertEqual(run.stderr,b'')
 def test_runner_retains_actual_source_window_native_argv_deadline_and_exit(self):
  raw=(ROOT/'scripts/governance/run_task_directory_native_v2.py').read_text();tree=ast.parse(raw)
  self.assertIn('return code',raw);self.assertIn('code=result.returncode',raw);self.assertIn('timeout=1800',raw)
  self.assertIn('context.current(relative)',raw);self.assertIn('diagnostic.capture_in_operation(operation,root,role,code,directory,stdout_path,stderr_path)',raw)
  self.assertIn('if sink_errors and code==0:raise sink_errors[0]',raw)
  self.assertEqual(sum(isinstance(n,ast.Call)and isinstance(n.func,ast.Attribute)and n.func.attr=='open_task_source_operation'for n in ast.walk(tree)),1)
  self.assertIn("'opcache.jit=disable'",raw);self.assertNotIn('capture-native-body.private.json',raw)
 def test_current_capture_requires_genuine_owner_and_preserves_native_body(self):
  raw=(ROOT/M.SELF).read_text();tree=ast.parse(raw);function=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='capture_in_operation');body=ast.get_source_segment(raw,function)
  self.assertEqual(body.count('source.require_task_operation(operation,root)'),2)
  self.assertIn('context.current(SELF)==Path(__file__).read_bytes()',body)
  self.assertIn('if body.exists()or body.is_symlink():return False',body)
  self.assertIn('os.O_EXCL|os.O_NOFOLLOW',body);self.assertIn('dir_fd=dfd',body);self.assertNotIn('body.write',body)

 def test_conduit_failure_sink_injections_preserve_original_native_exit(self):
  workflow=(ROOT/'.github/workflows/conduit-producer-focused.yml').read_text()
  start=workflow.index('            native_status=$?')
  end=workflow.index('          }',start)
  body='\n'.join(line[12:]for line in workflow[start:end].splitlines())
  self.assertLess(body.index('project_conduit_failure.py'),body.index('project_task_bootstrap_failure_v2.py'))
  self.assertNotIn('integration.json',body);self.assertNotIn('accepted',body)
  setup=r"""
set -e
python3(){
 case "$*" in
  *project_conduit_failure.py*)
   [ "$FAIL" != original ] || return 19
   printf '%s\n' '{"passed":false,"diagnostic_available":false}' ;;
  *project_task_bootstrap_failure_v2.py*)
   [ "$FAIL" != chooser ] || return 21
   printf '%s\n' '{"schema":"Task-native-bootstrap-failure-v2","passed":false}' ;;
  *)return 99;;
 esac
}
mkdir(){ [ "$FAIL" != mkdir ] && command mkdir "$@"; }
mv(){ [ "$FAIL" != mv ] && command mv "$@"; }
cat(){ [ "$FAIL" != cat ] && command cat "$@"; }
echo(){ [ "$FAIL" != marker ] && command echo "$@"; }
"""
  for status in(1,37):
   for failure in('none','original','chooser','mkdir','mv','cat','marker'):
    with self.subTest(status=status,failure=failure),tempfile.TemporaryDirectory()as directory:
     root=Path(directory).resolve();(root/'task-conduit-v2-private').mkdir(mode=0o700)
     env=dict(os.environ,RUNNER_TEMP=str(root),GITHUB_OUTPUT=str(root/'output'),FAIL=failure,NATIVE=str(status))
     run=subprocess.run(['bash','-c',setup+'\n(exit "$NATIVE") || {\n'+body+'\n}'],env=env,capture_output=True,timeout=5)
     self.assertEqual(run.returncode,status);self.assertNotIn(b'Traceback',run.stderr)
     self.assertFalse((root/'accepted.json').exists())
     if failure in('chooser','mkdir','mv'):self.assertFalse((root/'conduit-focused-failure/diagnostic.json').exists())
 def test_original_projection_rejects_unclosed_nested_leaks(self):
  for extra in({'message':'CANARY'},{'stage':'CANARY'},{'source_file':'/private/CANARY'},{'primary':{'message':'CANARY'}}):
   with self.subTest(extra=extra),self.assertRaises((ValueError,TypeError)):M.original_safe(dict(self.original(),**extra))

if __name__=='__main__':unittest.main()
