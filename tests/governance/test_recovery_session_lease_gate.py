"""Strict actual public CLI tampering controls; receipts are synthetic, no SQL."""
from recovery_oauth_code_test_cohort import complete_source_cohort
from pathlib import Path
import copy,hashlib,importlib.util,json,os,subprocess,sys,tempfile,unittest
import test_recovery_partial_composition_gate as previous
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('session_gate',ROOT/'scripts/governance/check_recovery_session_lease.py');gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
def template(digest='b'*64):
 d=previous.template();labels=sorted(gate.obligations());d.update(batch='native-session-producer-lease',scope='owned-native-session-producer-lease-no-permit',checks=labels,check_count=len(labels),native=gate.summary('a'*40),worker_cleanup={'private_directories_removed':2,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':2});d['file_service_cleanup']['binary_sha256']=digest;return d
class SessionGate(unittest.TestCase):
 def test_six_actual_source_cases_and_sixteen_total_obligations(self):
  self.assertEqual(len(gate.SOURCE_INPUTS),19);self.assertIn('support/governance/native-organization/native-cases.php',gate.SOURCE_INPUTS)
  self.assertEqual(len(gate.SQL_obligations()),6);self.assertEqual(len(gate.obligations()),16);result=gate.validate(template(),source_revision='a'*40,file_digest='b'*64);self.assertEqual(result['SQL_obligations'],6);self.assertFalse(result['fault_matrix_proved']);self.assertFalse(result['restoration_authorized']);self.assertTrue(result['execution_blocked'])
  code="require$argv[1].'/support/governance/recovery/session-lease-focused.php';echo json_encode(governance_recovery_session_lease_obligations());"
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(set(json.loads(p.stdout)),gate.SQL_obligations())
 def test_each_obligation_and_cleanup_or_permission_tamper_refused(self):
  original=template()
  for label in original['checks']:
   bad=copy.deepcopy(original);bad['checks'].remove(label);bad['check_count']-=1
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
  for path,value in [(('native','fault_matrix_proved'),True),(('native','all_sources_complete'),True),(('native','restoration_authorized'),True),(('native','durable_review_compatible'),True),(('native','worker_SQL_sessions_absent'),False),(('native','observer_public_close_returned'),False),(('native','observer_cleanup','cleanup_proved'),False),(('native','observer_cleanup','native_writer_grants_unchanged'),False),(('native','observer_cleanup','owned_observer_removed'),False),(('worker_cleanup','workers_registered'),0),(('worker_cleanup','live_processes_after_cleanup'),1),(('file_service_cleanup','process_stopped'),False),(('file_service_cleanup','private_storage_removed'),False),(('file_service_cleanup','kind'),'image'),(('production_optional_features_enabled',),True),(('passed',),1)]:
   bad=copy.deepcopy(original);target=bad
   for k in path[:-1]:target=target[k]
   target[path[-1]]=value
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
 def test_real_CLI_requires_independent_binary_receipt_revision_and_strict_shape(self):
  with tempfile.TemporaryDirectory()as temp:
   root=Path(temp).resolve();binary=root/'binary';raw=b'host synthetic paired binary; never executed';binary.write_bytes(raw);binary.chmod(0o700);digest=hashlib.sha256(raw).hexdigest();receipt=root/'receipt';receipt.touch(mode=0o600);cmd=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_session_lease.py'),'--receipt',str(receipt),'--source-revision','a'*40,'--file-binary',str(binary),'--file-binary-sha256',digest];good=json.dumps(template(digest))
   for body,ok in [(good,True),(good.replace('"passed": true','"passed": true,"passed": false',1),False),(good.replace('a'*40,'c'*40),False),(good.replace(digest,'d'*64),False),(good[:-1]+',"unknown":false}',False)]:
    receipt.write_text(body);p=subprocess.run(cmd,capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode==0,ok,p.stderr)
   receipt.write_text(good);binary.write_bytes(raw+b'changed');self.assertNotEqual(subprocess.run(cmd,capture_output=True,text=True,timeout=10).returncode,0)
 def test_pinned_real_source_changes_cannot_self_certify(self):
  with tempfile.TemporaryDirectory()as temp:
   root=Path(temp).resolve()
   for name in gate.SOURCE_INPUTS:
    p=root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/name).read_bytes())
   complete_source_cohort(root)
   self.assertEqual(gate.SQL_obligations(root),gate.SQL_obligations())
   for name in ('support/governance/native-organization/native-cases.php','support/governance/recovery/session-lease-cases.php','support/governance/recovery/session-lease-observer.php','src/applications/auth/storage/PhabricatorAuthSession.php'):
    p=root/name;old=p.read_bytes();p.write_bytes(old+b'\n// unauthorized source drift\n')
    with self.assertRaises(ValueError):gate.SQL_obligations(root)
    p.write_bytes(old)

class SourceOperationPlumbingUnit(unittest.TestCase):
 CASE='support/governance/recovery/session-lease-cases.php'
 """Unadmitted collaborators test API plumbing, never issued/runtime authority."""
 def fixture(self):
  from contextlib import ExitStack
  from unittest.mock import patch
  from types import SimpleNamespace
  test=self;self.issued=[];self.roots=[];self.active=False;self.entry_fault=False;self.exit_fault=False
  class UnitCap:
   def __init__(cap,root):
    cap.root=Path(root);cap.alive=False
    cap.coordinator=SimpleNamespace(assert_cohort=lambda root,reader:test.assertTrue(Path(root)==cap.root and reader.__self__ is cap),source_view=lambda path,raw:raw)
    cap.worker_terminal=SimpleNamespace(reduce_if_selected=lambda path,raw:raw)
   def read_producer(cap,path):return Path(path).read_bytes()
  class Context:
   def __init__(context,root):context.cap=UnitCap(root)
   def __enter__(context):
    if test.entry_fault or test.active:raise ValueError('unit_unadmitted_entry')
    test.active=True;context.cap.alive=True;test.issued.append(context.cap);return context.cap
   def __exit__(context,*args):
    context.cap.alive=False;test.active=False
    if test.exit_fault:raise ValueError('unit_unadmitted_exit')
  def opened(root=ROOT):self.roots.append(Path(root));return Context(root)
  def require(cap,root):
   if not any(cap is item for item in self.issued)or not cap.alive or Path(root)!=cap.root:raise ValueError('unit_unissued')
   return cap
  stack=ExitStack();self.addCleanup(stack.close)
  raw=(ROOT/self.CASE).read_bytes()
  stack.enter_context(patch.dict(gate.SOURCE_INPUTS,{self.CASE:hashlib.sha256(raw).hexdigest()},clear=True))
  stack.enter_context(patch.object(gate.source_operation,'open_source_operation',side_effect=opened))
  stack.enter_context(patch.object(gate.source_operation,'require_operation',side_effect=require))
  return stack
 def test_unit_default_calls_are_fresh_and_public_ROOT_cannot_rebind_code_root(self):
  from unittest.mock import patch
  self.fixture()
  with patch.object(gate,'ROOT',Path('/unit/foreign')):
   first=gate.SQL_obligations();second=gate.SQL_obligations()
  self.assertEqual(first,second);self.assertEqual(len(first),6)
  self.assertEqual(self.roots,[ROOT,ROOT]);self.assertIsNot(self.issued[0],self.issued[1]);self.assertFalse(any(x.alive for x in self.issued))
 def test_real_unissued_cap_refuses_before_source_reads(self):
  from unittest.mock import patch
  with patch.object(gate,'read_source_checked',side_effect=AssertionError('source read'))as reader:
   with self.assertRaises(ValueError):gate.SQL_obligations(_source_operation=object())
   reader.assert_not_called()
 def test_unit_entry_and_exit_faults_never_fallback_or_return_labels(self):
  from unittest.mock import patch
  self.fixture()
  with patch.object(gate.code_coord,'assert_cohort',side_effect=AssertionError('strict fallback'))as fallback:
   self.entry_fault=True
   with self.assertRaises(ValueError):gate.SQL_obligations()
   self.entry_fault=False;self.exit_fault=True
   with self.assertRaises(ValueError):gate.SQL_obligations()
   fallback.assert_not_called()
 def test_unit_validate_one_scope_forwards_root_and_same_cap(self):
  from unittest.mock import patch
  self.fixture();seen=[]
  def body(data,root=ROOT,*,source_revision,file_digest,_source_operation=None):
   seen.append((Path(root),_source_operation))
   labels=gate.SQL_obligations(root,_source_operation=_source_operation)
   self.assertEqual(labels,set(gate.summary(source_revision,root,_source_operation=_source_operation)['executed_case_obligations']))
   self.assertTrue(labels.issubset(gate.obligations(root,_source_operation=_source_operation)))
   return {'unit_only':True}
  with patch.object(gate,'_validate_with_source_operation',side_effect=body):self.assertEqual(gate.validate({},source_revision='a'*40,file_digest='b'*64),{'unit_only':True})
  self.assertEqual(len(self.issued),1);self.assertIs(seen[0][1],self.issued[0]);self.assertEqual(seen[0][0],ROOT);self.assertFalse(self.issued[0].alive)
 def test_unit_nested_default_refuses_explicit_same_scope_works_and_closed_refuses(self):
  self.fixture()
  with gate.source_operation.open_source_operation(ROOT)as cap:
   with self.assertRaises(ValueError):gate.SQL_obligations()
   self.assertEqual(len(gate.SQL_obligations(_source_operation=cap)),6)
   with self.assertRaises(ValueError):gate.SQL_obligations(ROOT.parent,_source_operation=cap)
  with self.assertRaises(ValueError):gate.SQL_obligations(_source_operation=cap)
 def test_minimal_other_root_retains_strict_reader_and_hash_refusal(self):
  from unittest.mock import patch
  self.fixture()
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as directory:
   root=Path(directory);path=root/self.CASE;path.parent.mkdir(parents=True);path.write_bytes((ROOT/self.CASE).read_bytes());path.chmod(0o600)
   with patch.object(gate.source_operation,'open_source_operation',side_effect=AssertionError('unexpected operation')),patch.object(gate.code_coord,'assert_cohort')as cohort,patch.object(gate.code_coord,'source_view',side_effect=lambda name,raw:raw),patch.object(gate.worker_terminal,'reduce_if_selected',side_effect=lambda name,raw:raw):
    self.assertEqual(len(gate.SQL_obligations(root)),6);cohort.assert_called_with(root,gate.read_source_checked)
    path.write_bytes(path.read_bytes()+b'\n// source drift')
    with self.assertRaises(ValueError):gate.SQL_obligations(root)
 def test_unit_summary_forwards_other_root_and_explicit_cap(self):
  self.fixture()
  with gate.source_operation.open_source_operation(ROOT)as cap:
   summary=gate.summary('a'*40,ROOT,_source_operation=cap)
   self.assertEqual(len(summary['executed_case_obligations']),6)
   with self.assertRaises(ValueError):gate.summary('a'*40,ROOT.parent,_source_operation=cap)
 def test_old_literals_main_and_validation_checks_are_preserved(self):
  import ast,copy
  path=Path(gate.__file__).relative_to(ROOT).as_posix()
  old=ast.parse(subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','8d7fac3491716a523648750ca496079562733033:'+path]))
  new=ast.parse((ROOT/path).read_bytes())
  defs=lambda tree:{x.name:x for x in tree.body if isinstance(x,ast.FunctionDef)}
  a=defs(old);b=defs(new)
  main=copy.deepcopy(b['main']);handlers=[x for x in ast.walk(main)if isinstance(x,ast.ExceptHandler)]
  self.assertEqual(len(handlers),1);handler=handlers[0]
  self.assertEqual(handler.name,'error');self.assertEqual(len(handler.body),2)
  diagnostic=ast.parse("print(json.dumps({'diagnostic':'session_lease_refusal_v1','stage':_refusal_stage(error)},sort_keys=True),file=sys.stderr)").body[0]
  self.assertEqual(ast.dump(handler.body[0],include_attributes=False),ast.dump(diagnostic,include_attributes=False))
  # Invert exactly this one closed diagnostic statement and exception binding.
  del handler.body[0];handler.name=None
  self.assertEqual(ast.dump(a['main'],include_attributes=False),ast.dump(main,include_attributes=False))
  self.assertEqual(ast.dump(a['read_source_checked'],include_attributes=False),ast.dump(b['read_source_checked'],include_attributes=False))
  original=next(ast.literal_eval(n.value)for n in old.body if isinstance(n,ast.Assign)and getattr(n.targets[0],'id',None)=='SOURCE_INPUTS')
  self.assertEqual(gate.SOURCE_INPUTS,original)
  body=copy.deepcopy(b['_validate_with_source_operation']);body.name='validate';body.args.kwonlyargs.pop();body.args.kw_defaults.pop()
  for call in [x for x in ast.walk(body)if isinstance(x,ast.Call)]:
   call.keywords=[x for x in call.keywords if x.arg!='_source_operation']
   if isinstance(call.func,ast.Name)and call.func.id=='summary':call.args=call.args[:1]
  self.assertEqual(ast.dump(a['validate'],include_attributes=False),ast.dump(body,include_attributes=False))

class SessionLeaseDiagnosticTests(unittest.TestCase):
 """Actual predicate failures only; no admission substitution or SQL."""
 def stage(self,call):
  try:call()
  except (ValueError,OSError,TypeError,KeyError,UnicodeError)as error:return gate._refusal_stage(error)
  self.fail('expected original predicate refusal')
 def test_actual_prepared_cache_refusal_is_redacted(self):
  self.assertEqual(self.stage(lambda:gate.source_operation._validate_prepared_cache(b'secret-canary')),'prepared_cache')
 def test_actual_receipt_revision_refusal(self):
  self.assertEqual(self.stage(lambda:gate._validate_with_source_operation({},source_revision='secret-canary',file_digest='x')),'receipt')
 def test_actual_exact_refusal_hides_values(self):
  self.assertEqual(self.stage(lambda:gate.exact('secret-canary','other-secret')),'native_exact')
 def test_unknown_same_named_function_is_untrusted(self):
  def _prepared_layout():raise ValueError('secret-canary')
  self.assertEqual(self.stage(_prepared_layout),'unclassified')

 def test_actual_cli_keeps_generic_failure_and_closed_stderr(self):
  with tempfile.TemporaryDirectory()as temp:
   root=Path(temp).resolve();binary=root/'binary';raw=b'host synthetic paired binary; never executed';binary.write_bytes(raw);binary.chmod(0o700)
   receipt=root/'receipt';receipt.touch(mode=0o600);receipt.write_text('{"secret-canary":')
   result=subprocess.run([sys.executable,'-B',gate.__file__,'--receipt',str(receipt),'--source-revision','a'*40,'--file-binary',str(binary),'--file-binary-sha256',hashlib.sha256(raw).hexdigest()],capture_output=True,text=True,timeout=15)
   self.assertEqual(result.returncode,1);self.assertEqual(result.stdout,'')
   self.assertEqual(result.stderr,json.dumps({'diagnostic':'session_lease_refusal_v1','stage':'unclassified'},sort_keys=True)+'\nSession lease receipt refused\n')
   self.assertNotIn('secret-canary',result.stderr);self.assertNotIn(str(root),result.stderr)

class SessionLayoutDiagnosticTests(unittest.TestCase):
 def test_actual_private_layout_refusal_emits_only_counts_and_name_hashes(self):
  import contextlib,io
  with tempfile.TemporaryDirectory()as temp:
   root=Path(temp).resolve();name='secret-canary.json';(root/name).write_text('never logged')
   output=io.StringIO()
   with contextlib.redirect_stderr(output):
    try:gate.source_operation._prepared_layout(root,{})
    except ValueError as error:self.assertEqual(gate._refusal_stage(error),'prepared_layout')
    else:self.fail('unknown extra accepted')
   data=json.loads(output.getvalue());self.assertTrue(data['available']);self.assertEqual(data['found_count'],1);self.assertEqual(data['expected_count'],0);self.assertEqual(data['unknown_extra_count'],1)
   self.assertEqual(data['unknown_extra_name_sha256'],[hashlib.sha256(name.encode()).hexdigest()]);self.assertEqual(data['unknown_suffix_counts'],{'pyc':0,'json':1,'log':0,'other':0})
   self.assertNotIn(name,output.getvalue());self.assertNotIn(str(root),output.getvalue());self.assertNotIn('never logged',output.getvalue())
 def test_fake_same_name_frame_cannot_emit_layout_metadata(self):
  import contextlib,io
  def _prepared_layout():
   found={'secret-canary':'100644'};source_expected={};raise ValueError('secret-canary')
  output=io.StringIO()
  with contextlib.redirect_stderr(output):
   try:_prepared_layout()
   except ValueError as error:self.assertEqual(gate._refusal_stage(error),'unclassified')
  self.assertEqual(output.getvalue(),'')
 def test_actual_layout_bad_expected_type_has_fixed_fallback(self):
  import contextlib,io
  with tempfile.TemporaryDirectory()as temp:
   output=io.StringIO()
   with contextlib.redirect_stderr(output):
    try:gate.source_operation._prepared_layout(Path(temp).resolve(),'secret-canary')
    except ValueError as error:self.assertEqual(gate._refusal_stage(error),'prepared_layout')
   self.assertEqual(json.loads(output.getvalue()),{'diagnostic':'session_layout_metadata_v1','available':False})

 def test_actual_partial_parser_is_admitted_category_not_unknown(self):
  import contextlib,io
  with tempfile.TemporaryDirectory()as temp:
   root=Path(temp).resolve();name=next(iter(gate.source_operation._prepared_policy()['catalog']))
   path=root/name;path.parent.mkdir(parents=True);path.write_bytes(b'metadata only; never read')
   output=io.StringIO()
   with contextlib.redirect_stderr(output):
    try:gate.source_operation._prepared_layout(root,{})
    except ValueError as error:self.assertEqual(gate._refusal_stage(error),'prepared_layout')
    else:self.fail('partial parser accepted')
   data=json.loads(output.getvalue());self.assertTrue(data['available']);self.assertEqual(data['parser_present_count'],1);self.assertEqual(data['parser_expected_count'],270);self.assertEqual(data['unknown_extra_count'],0)
   self.assertEqual(data['fresh_compiler_expected_count'],5);self.assertEqual(data['fresh_compiler_present_count'],0);self.assertFalse(data['installed_binary_present']);self.assertEqual(data['artifact_role_mode_mismatch_count'],0)
   self.assertNotIn(name,output.getvalue());self.assertNotIn(str(root),output.getvalue());self.assertNotIn('metadata only',output.getvalue())
