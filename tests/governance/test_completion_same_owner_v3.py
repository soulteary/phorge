"""Pure/static/owned-FS controls; never actual SourceOperation or application SQL.
Real Completion source/SQL positive and negative qualification is a separate gate.
"""
import ast,copy,hashlib,hmac,json,os,re,stat,subprocess,tempfile,types,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
CONTROLLER='scripts/governance/run_completion_task_v3.py'
CONSUMER='scripts/governance/check_completion_task_receipt_v3.py'
BASE='support/governance/completion-task-v3/'

def tree(relative):return ast.parse((ROOT/relative).read_bytes())
def function(module,name):return next(n for n in module.body if isinstance(n,ast.FunctionDef)and n.name==name)
def selected(module,names):
 nodes=[copy.deepcopy(n)for n in module.body if isinstance(n,(ast.FunctionDef,ast.ClassDef))and n.name in names]
 if {n.name for n in nodes}!=set(names):raise AssertionError('missing exact pure definitions')
 ns={'hashlib':hashlib,'hmac':hmac,'json':json,'os':os,'stat':stat,'Path':Path,'re':re,'copy':copy,'subprocess':subprocess}
 exec(compile(ast.fix_missing_locations(ast.Module(body=nodes,type_ignores=[])),'<CURRENT-pure-control>','exec'),ns)
 return ns

def calls(node,attribute):return [n for n in ast.walk(node)if isinstance(n,ast.Call)and isinstance(n.func,ast.Attribute)and n.func.attr==attribute]

class CompletionSameOwnerPureControls(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.controller=tree(CONTROLLER);cls.consumer=tree(CONSUMER);cls.validator=tree(BASE+'validate.py')

 def test_native_failure_location_is_bounded_owned_and_never_exports_message(self):
  ns=selected(self.consumer,{'native_failure_location'});ns['ROOT']=ROOT
  project=ns['native_failure_location'];secret='SYNTHETIC_PRIVATE_SQL_CREDENTIAL'
  with tempfile.TemporaryDirectory()as tmp:
   directory=Path(tmp).resolve();directory.chmod(0o700);path=directory/'native.stderr.private'
   raw=f'PHP Fatal error: {secret} in {ROOT}/support/governance/completion/fixture.php on line 123\n'
   path.write_text(raw);path.chmod(0o600)
   self.assertEqual(project(directory),{'kind':'php-fatal','source_file':'support/governance/completion/fixture.php','source_line':123})
   self.assertNotIn(secret,json.dumps(project(directory)))
   path.chmod(0o644);self.assertIsNone(project(directory));path.chmod(0o600)
   path.unlink();target=directory/'other';target.write_text(raw);target.chmod(0o600);path.symlink_to(target)
   self.assertIsNone(project(directory));path.unlink();path.write_bytes(b'x'*262145);path.chmod(0o600)
   self.assertIsNone(project(directory))

 def test_failure_location_never_exports_messages_or_arguments(self):
  namespace=selected(self.consumer,{'failure_location'});namespace['ROOT']=ROOT
  project=namespace['failure_location'];secret='CANARY_PRIVATE_ACCOUNT_TOKEN_SQL'
  for cls in (RuntimeError,type(secret,(RuntimeError,),{})):
   try:raise cls(secret)
   except Exception as error:result=project(error)
   self.assertEqual(set(result),{'error_type','source_file','source_line'})
   self.assertNotIn(secret,json.dumps(result))
   self.assertIn(result['error_type'],('RuntimeError','Exception'))

 def test_self_signed_window_has_no_source_authority_route(self):
  text=ast.unparse(self.controller)+ast.unparse(self.consumer)
  self.assertNotIn('producer-window.private.json',text)
  self.assertNotIn('hmac.new',ast.unparse(self.controller))
  self.assertNotIn('producer_window(',text)
  driver=function(self.consumer,'run_and_accept')
  self.assertEqual([a.arg for a in driver.args.args],['root','checkpoint','directory','file_binary','file_sha','taskqueue_binary','taskqueue_sha','observed_mysql_image','php'])
  self.assertFalse(any(isinstance(n,ast.FunctionDef)and n.name=='accept'for n in self.consumer.body))

 def test_DATA_validation_never_qualifies_producer_or_source_exit(self):
  node=function(self.consumer,'validate_DATA_in_operation')
  result=next(n.value for n in ast.walk(node)if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='result'for t in n.targets))
  values={k.value:v for k,v in zip(result.keys,result.values)if isinstance(k,ast.Constant)}
  for name in('completion_v3_accepted','producer_window_proven','consumer_source_exit_verified'):
   self.assertIsInstance(values[name],ast.Constant);self.assertIs(values[name].value,False)
  self.assertEqual(len(calls(node,'require_task_operation')),1)
  self.assertFalse(calls(node,'open_task_source_operation'))

 def test_one_owner_direct_process_validation_then_postexit_accept(self):
  driver=function(self.consumer,'run_and_accept');scopes=[n for n in driver.body if isinstance(n,ast.With)]
  self.assertEqual(len(scopes),1);scope=scopes[0]
  self.assertEqual(len(calls(scope,'open_task_source_operation')),1)
  self.assertEqual(len(calls(scope,'run_in_operation')),1)
  code=ast.unparse(scope);self.assertLess(code.index('controller.run_in_operation('),code.index('validate_DATA_in_operation('))
  self.assertEqual(sum(isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='probe_in_operation'for n in ast.walk(scope)),2)
  for node in ast.walk(scope):
   if isinstance(node,ast.Assign)and isinstance(node.value,ast.Constant)and node.value.value is True:
    self.assertFalse(any(isinstance(t,ast.Subscript)and isinstance(t.slice,ast.Constant)and t.slice.value in{'completion_v3_accepted','producer_window_proven','consumer_source_exit_verified'}for t in node.targets))
  post=ast.unparse(ast.Module(body=driver.body[driver.body.index(scope)+1:],type_ignores=[]))
  for key in('completion_v3_accepted','producer_window_proven','consumer_source_exit_verified'):self.assertIn("result['"+key+"'] = True",post)
  actual=function(self.controller,'run_in_operation');self.assertFalse(calls(actual,'open_task_source_operation'));self.assertEqual(len(calls(actual,'require_task_operation')),1)
  self.assertEqual(len(calls(actual,'run')),1)

 def test_actual_original_producer_is_exactly_reversible(self):
  ns=selected(self.controller,{'need','adapt'})
  for n in self.controller.body:
   if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id in{'PRODUCER_PIN','MARKER'}for t in n.targets):exec(compile(ast.Module(body=[n],type_ignores=[]),'<CURRENT-pure-constant>','exec'),ns)
  raw=(ROOT/'support/governance/completion/fixture-run.php').read_bytes();prefix=(ROOT/(BASE+'prefix.php')).read_bytes()
  adapted=ns['adapt'](raw,prefix) # Production adapter itself checks exact inverse.
  self.assertEqual(adapted.count(b'$checks[]'),raw.count(b'$checks[]'))
  self.assertEqual(adapted.count(b'$fixture->cleanup()'),1)
  for bad in(raw+b'\n',b'',raw.replace(b"  if ($phase === 'all') {",b'')):
   with self.assertRaises(ValueError):ns['adapt'](bad,prefix)
  with self.assertRaises(ValueError):ns['adapt'](raw,prefix+ns['MARKER'])

 def test_strict_schema_validator_keeps_original_functions(self):
  old=(ROOT/'support/governance/task-additive-schema-v2/validate.py').read_text()
  old=old.replace('support/governance/task-additive-schema-v2/capture.php',BASE+'capture.php').replace('Task-additive-schema-private-binding-v1','Completion-private-schema-v3').replace('Task-additive-schema-capture-v1','Completion-schema-capture-v3')
  old=old.replace('{"directory-Task-OFF","oauth-authorization-Task-OFF","oauth-client-lifecycle-Task-OFF","conduit-Task-OFF","oauth-access-Task-OFF","oauth-code-Task-OFF","assessment-credential-Task-OFF","api-credential-concurrency-Task-OFF","application-config-facts-Task-OFF","project-source-facts-Task-OFF","spaces-source-facts-Task-OFF","recovery-partial-composition-Task-OFF","task-owner-source-facts-Task-OFF","file-source-facts-Task-OFF","file-parent-policy-Task-OFF","session-producer-lease-Task-OFF","session-borrowed-owner-Task-OFF","organization-approval-workbench-Task-OFF","calendar-reminders-Task-OFF","full-native-Task-OFF"}','{"completion-v3"}').replace("{'directory-Task-OFF','oauth-authorization-Task-OFF','oauth-client-lifecycle-Task-OFF','conduit-Task-OFF','oauth-access-Task-OFF','oauth-code-Task-OFF','assessment-credential-Task-OFF','api-credential-concurrency-Task-OFF','application-config-facts-Task-OFF','project-source-facts-Task-OFF','spaces-source-facts-Task-OFF','recovery-partial-composition-Task-OFF','task-owner-source-facts-Task-OFF','file-source-facts-Task-OFF','file-parent-policy-Task-OFF','session-producer-lease-Task-OFF','session-borrowed-owner-Task-OFF','organization-approval-workbench-Task-OFF','calendar-reminders-Task-OFF','full-native-Task-OFF'}","{'completion-v3'}").replace('expected_mode="directory-Task-OFF"','expected_mode="completion-v3"').replace('"Task-directory-native-envelope-v2"if expected_mode=="directory-Task-OFF"else"Task-native-envelope-v3"','"Completion-Task-envelope-v3"')
  for n in ast.parse(old).body:
   if isinstance(n,ast.FunctionDef):self.assertEqual(ast.dump(n,include_attributes=False),ast.dump(function(self.validator,n.name),include_attributes=False),n.name)
  self.assertEqual(hashlib.sha256((ROOT/'scripts/governance/check_completion.py').read_bytes()).hexdigest(),'fa9f6c1e360a95822651f3b4af0277cc243e8edac78ad78f1cf924c713c46b1c')

 def test_completion_cleanup_is_never_the_focused_projection(self):
  # The generic schema projection and Completion's exact two-socket seam must
  # never both consume the same native cleanup field.
  name='CAPTURE_CLEANUP_MODES'
  def modes(module):
   assignment=next(n for n in module.body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id==name for t in n.targets))
   value=assignment.value
   self.assertIsInstance(value,ast.Call);self.assertEqual(ast.unparse(value.func),'frozenset');self.assertEqual(len(value.args),1)
   return frozenset(ast.literal_eval(value.args[0]))
  expected=modes(tree('support/governance/task-additive-schema-v2/validate.py'))
  self.assertEqual(modes(self.validator),expected)
  self.assertNotIn('completion-v3',expected);self.assertNotIn('full-native-Task-OFF',expected)
  def auxiliary(module):
   assignment=next(n for n in module.body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='CAPTURE_NATIVE_AUXILIARY'for t in n.targets))
   return ast.literal_eval(assignment.value)
  original=auxiliary(tree('support/governance/task-additive-schema-v2/validate.py'))
  self.assertEqual(auxiliary(self.validator),original)
  self.assertEqual(original,{'task-owner-source-facts-Task-OFF':(8,6),'file-source-facts-Task-OFF':(12,10)})
  self.assertFalse(set(original)&expected)
  self.assertNotIn('completion-v3',original);self.assertNotIn('full-native-Task-OFF',original)

 def test_private_reader_rejects_alias_hardlink_and_empty_profile(self):
  ns=selected(self.validator,{'need','private','process_output'})
  with tempfile.TemporaryDirectory()as td:
   p=Path(td).resolve()/'data';p.write_bytes(b'');p.chmod(0o600)
   self.assertEqual(ns['process_output'](p),b'')
   with self.assertRaises(ValueError):ns['private'](p)
   alias=p.with_name('alias');alias.symlink_to(p)
   with self.assertRaises(ValueError):ns['process_output'](alias)
   alias.unlink();os.link(p,alias)
   with self.assertRaises(ValueError):ns['process_output'](p)
   alias.unlink();p.chmod(0o644)
   with self.assertRaises(ValueError):ns['process_output'](p)

 def test_actual_private_readset_terminal_rejects_later_change(self):
  ns=selected(self.validator,{'need','private','process_output'});v=types.SimpleNamespace(private=ns['private'],process_output=ns['process_output'])
  data=function(self.consumer,'validate_DATA_in_operation');reader=next(n for n in data.body if isinstance(n,ast.FunctionDef)and n.name=='read_private')
  terminal=next(n for n in data.body if isinstance(n,ast.For)and isinstance(n.iter,ast.Call)and isinstance(n.iter.func,ast.Attribute)and isinstance(n.iter.func.value,ast.Name)and n.iter.func.value.id=='readset')
  env={'validator':v,'readset':{},'need':ns['need']};exec(compile(ast.Module(body=[reader],type_ignores=[]),'<CURRENT-pure-readset>','exec'),env)
  with tempfile.TemporaryDirectory()as td:
   p=Path(td).resolve()/'data';p.write_bytes(b'original');p.chmod(0o600);env['read_private'](p)
   exec(compile(ast.Module(body=[terminal],type_ignores=[]),'<CURRENT-pure-terminal>','exec'),env)
   p.write_bytes(b'changed')
   with self.assertRaises(ValueError):exec(compile(ast.Module(body=[terminal],type_ignores=[]),'<CURRENT-pure-terminal>','exec'),env)

 def test_process_timeout_and_spawn_errors_retain_all_sink_failures(self):
  finish=selected(self.controller,{'finish_process'})['finish_process']
  for primary in(subprocess.TimeoutExpired(['synthetic-not-spawned'],1800),OSError('synthetic-not-spawned')):
   for errors in([], [OSError('fsync')],[OSError('fsync'),ValueError('close')]):
    with self.subTest(primary=type(primary).__name__,sinks=len(errors)):
     try:finish(primary,errors,None)
     except BaseException as error:
      if errors:self.assertIsInstance(error,BaseExceptionGroup);self.assertEqual(error.exceptions,(primary,*errors))
      else:self.assertIs(error,primary)
     else:self.fail('primary lost')
  for code in(1,37):self.assertEqual(finish(None,[OSError('fsync')],code),code)
  with self.assertRaises(ExceptionGroup):finish(None,[OSError('fsync')],0)

 def test_nonzero_probe_preserves_status_and_secondary_cause(self):
  ns=selected(self.consumer,{'CompletionProcessExit'})
  probe=function(self.consumer,'probe_in_operation');terminal=next(n for n in probe.body if isinstance(n,ast.If)and ast.unparse(n.test)=='code != 0')
  for code in(1,37):
   for errors in([], [OSError('fsync')],[OSError('fsync'),ValueError('close')]):
    env=dict(ns,code=code,sink_errors=errors)
    with self.assertRaises(ns['CompletionProcessExit'])as caught:exec(compile(ast.Module(body=[terminal],type_ignores=[]),'<CURRENT-pure-probe-terminal>','exec'),env)
    error=caught.exception;self.assertEqual(error.returncode,code);self.assertEqual(error.shell_status(),code);self.assertEqual(error.sink_errors,tuple(errors))
    if errors:self.assertEqual(error.__cause__.exceptions,tuple(errors))
    else:self.assertIsNone(error.__cause__)

 def test_synthetic_shutdown_faults_never_mask_native_status(self):
  # Pure PHP callback controls only: no real profile/bootstrap/producer or SQL.
  prefix=(ROOT/(BASE+'prefix.php')).read_text()
  callback=prefix[prefix.index('$completion_failure_status=static function('):]
  self.assertTrue(callback.rstrip().endswith('});'))
  setup_base="""<?php
class PhorgeCompletionRunV3 {
 static function need($x){if(!$x)throw new Exception('synthetic');}
 static function put($path,$raw){if(MODE==='put')throw new Exception('synthetic');}
}
class PhorgeCompletionSchemaCaptureV3 {
 static function envelope($path,$native,$before,$after){if(MODE==='envelope')throw new Exception('synthetic');return array('synthetic_callback_only'=>true);}
}
const MODE=MODE_LITERAL;
$task_run=array('output'=>'unused');$task_binding=array('profile'=>'unused');$task_before=array();$task_after=array();
$completion_primary_exit=PRIMARY;$task_outer_level=ob_get_level();ob_start();
"""
  for mode in('outer','json','put','envelope'):
   for primary in(0,1,37):
    with self.subTest(mode=mode,primary=primary):
     setup=setup_base.replace('MODE_LITERAL',json.dumps(mode)).replace('PRIMARY',str(primary))
     body="echo 'invalid-json';"if mode=='json'else "echo '{\"passed\":true}';"
     if mode=='outer':body+='ob_start();'
     actual=subprocess.run(['php','-n'],input=setup+callback+'\n'+body+'\nexit('+str(primary)+');',text=True,capture_output=True,timeout=5)
     self.assertEqual(actual.returncode,primary or 1)
     self.assertNotIn('synthetic',actual.stderr);self.assertNotIn('unused',actual.stderr)

 def test_completion_capture_cleanup_exact_projection_keeps_raw_and_original_assertions(self):
  ns=selected(self.consumer,{'need','project_completion_capture_cleanup'})
  native={'cleanup':{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'ownedAuxiliarySetupConnectionsClosed':2},'untouched':['synthetic']};proof={'native_profile_mode':'completion-v3','exact_projection':{'migrations_removed':1},'actual_SQL_executed_by_validator':False};original=copy.deepcopy((native,proof))
  projected,delta=ns['project_completion_capture_cleanup'](native,proof)
  self.assertEqual((native,proof),original);self.assertEqual(projected['cleanup'],{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True});self.assertEqual(delta['exact_projection']['capture_auxiliary_setup_connections'],[2,0]);self.assertFalse(delta['actual_SQL_executed_by_validator'])
  old=selected(tree('scripts/governance/check_completion.py'),{'require','_equal','exact'})
  for key,value in [('ownedSchemasRemoved',54),('ownedRuntimeUsersRemoved',1),('readerRemoved',False)]:
   changed=copy.deepcopy(native);changed['cleanup'][key]=value;p,_=ns['project_completion_capture_cleanup'](changed,proof)
   with self.assertRaises(ValueError):old['exact'](p['cleanup'],projected['cleanup'],'synthetic')
 def test_completion_capture_cleanup_rejects_missing_extra_wrong_count_type_and_role(self):
  ns=selected(self.consumer,{'need','project_completion_capture_cleanup'});cleanup={'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':2,'readerRemoved':True,'ownedAuxiliarySetupConnectionsClosed':2};proof={'native_profile_mode':'completion-v3','exact_projection':{}}
  bad=[]
  for value in (True,False,0,1,3,2.0,'2',None):
   item=copy.deepcopy(cleanup);item['ownedAuxiliarySetupConnectionsClosed']=value;bad.append(item)
  for key in cleanup:
   item=copy.deepcopy(cleanup);del item[key];bad.append(item)
  item=copy.deepcopy(cleanup);item['unknown']=True;bad.append(item)
  for item in bad:
   with self.assertRaises(ValueError):ns['project_completion_capture_cleanup']({'cleanup':item},proof)
  for mode in ('conduit-Task-OFF','directory-Task-OFF','file-parent-policy-Task-OFF','session-producer-lease-Task-OFF','session-borrowed-owner-Task-OFF','organization-approval-workbench-Task-OFF','calendar-reminders-Task-OFF','full-native-Task-OFF',None):
   with self.assertRaises(ValueError):ns['project_completion_capture_cleanup']({'cleanup':cleanup},{'native_profile_mode':mode,'exact_projection':{}})
 def test_completion_cleanup_projection_is_after_bound_schema_and_before_original_strict_consumer(self):
  node=function(self.consumer,'validate_DATA_in_operation');text=ast.unparse(node)
  self.assertEqual(sum(isinstance(n,ast.Call)and isinstance(n.func,ast.Name)and n.func.id=='project_completion_capture_cleanup'for n in ast.walk(node)),1)
  self.assertLess(text.index('validator.validate_envelope('),text.index('project_completion_capture_cleanup('));self.assertLess(text.index("native == envelope['native_receipt']"),text.index('project_completion_capture_cleanup('));self.assertLess(text.index('project_completion_capture_cleanup('),text.index('original.validate('))

 def test_deadlines_JIT_and_source_calls_are_not_relaxed(self):
  actual=ast.unparse(function(self.controller,'run_in_operation'));probe=ast.unparse(function(self.consumer,'probe_in_operation'))
  self.assertIn('timeout=1800',actual);self.assertIn('timeout=15',probe)
  for text in(actual,probe):
   self.assertIn('opcache.enable_cli=0',text);self.assertIn('opcache.jit=disable',text);self.assertIn('opcache.jit_buffer_size=0',text)
  self.assertIn('operation._task_expected_checkpoint == checkpoint',actual)
  self.assertIn('finish_process(primary, sink_errors, code)',actual)
  self.assertIn('controller.finish_process(primary, sink_errors, code)',probe)

if __name__=='__main__':unittest.main()
