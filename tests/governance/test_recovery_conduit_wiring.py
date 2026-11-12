"""Actual fixed driver selection/whole cleanup and strict synthetic receipts; no SQL."""
from pathlib import Path
import unittest,sys,subprocess,json,tempfile,hashlib,copy
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_conduit_producer as gate
import recovery_conduit_source_delta as delta
import test_recovery_session_lease_gate as previous
class ConduitWiring(unittest.TestCase):
 def template(self,digest='b'*64):
  d=previous.template(digest);labels=sorted(gate.obligations());d.update(batch='native-conduit-producer-owner',scope='owned-native-conduit-producer-owner-no-permit',checks=labels,check_count=len(labels),native=gate.summary('a'*40));return d
 def test_actual_driver_selector_and_exact_prior_driver_inverse(self):
  text=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text();a=text.index('$arguments=');b=text.index('$workbench_http=',a);block=text[a:b]
  for argv,want in [(['--conduit-producer-owner'],'conduit-producer-owner'),(['--conduit-producer-owner','--session-borrowed-owner'],'invalid'),(['--conduit-producer-owner','--conduit-producer-owner'],'invalid'),(['--conduit'],'invalid')]:
   code='$argv=array_merge(["driver"],json_decode($argv[1],true));'+block+'echo json_encode($phase);';p=subprocess.run(['php','-r',code,json.dumps(argv)],capture_output=True,text=True,timeout=5);self.assertEqual(0,p.returncode,p.stderr);self.assertEqual(want,json.loads(p.stdout))
  raw=text.encode();self.assertEqual(delta.reduce_driver(raw),subprocess.check_output(['git','-C',str(ROOT),'show','c496e2cf0ae25fb3e5153c6a1070eb53cf56b504:support/governance/native-organization/test-native-organization-mysql.php']))
  pin=delta.DRIVER_SHA256
  try:
   bad=raw+b'\n// unrelated driver mutation\n';delta.DRIVER_SHA256=hashlib.sha256(bad).hexdigest()
   with self.assertRaises(ValueError):delta.reduce_driver(bad)
  finally:delta.DRIVER_SHA256=pin
 def test_actual_whole_driver_finally_unknown_before_any_generic_cleanup(self):
  for mode in ['unknown','forged-flags','early','good','old']:
   p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/conduit-driver-cleanup-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=5);self.assertEqual(0,p.returncode,p.stderr);d=json.loads(p.stdout)
   if mode in ['unknown','forged-flags','early']:self.assertEqual([0]*5,[d[k]for k in ['rollbacks','pool_closes','disposes','drops','control_closes']]);self.assertEqual('current',d['env'])
   else:self.assertEqual(1,d['drops']);self.assertEqual(1,d['control_closes'])
  source=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text();self.assertLess(source.index("$GLOBALS['governance_recovery_conduit_cleanup_proved']=$phase!=="),source.index('$fixture = null;'))
 def test_source_derived_6_SQL_16_total_37_sources_and_strict_tamper(self):
  d=self.template();v=gate.validate(d,source_revision='a'*40,file_digest='b'*64);self.assertEqual((6,16,37),(v['SQL_obligations'],v['checks'],v['source_inputs']))
  for mutate in [lambda b:b['checks'].pop(),lambda b:b['native'].__setitem__('all_sources_complete',True),lambda b:b['native'].__setitem__('continuous_admin_lease_proved',True),lambda b:b['native'].__setitem__('source_revision','c'*40),lambda b:b['native']['observer_cleanup'].__setitem__('cleanup_proved',False),lambda b:b['file_service_cleanup'].__setitem__('process_stopped',False),lambda b:b.__setitem__('unknown',False)]:
   b=copy.deepcopy(d);mutate(b)
   with self.assertRaises(ValueError):gate.validate(b,source_revision='a'*40,file_digest='b'*64)
 def test_actual_public_CLI_requires_independent_binary_and_rejects_duplicate_receipt(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   p=Path(tmp);binary=p/'file-data';binary.write_bytes(b'binary data only; never executed');binary.chmod(0o700);digest=hashlib.sha256(binary.read_bytes()).hexdigest();receipt=p/'receipt';receipt.touch(mode=0o600);body=json.dumps(self.template(digest));cmd=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_conduit_producer.py'),'--receipt',str(receipt),'--source-revision','a'*40,'--file-binary',str(binary),'--file-binary-sha256',digest]
   for raw,good in [(body,True),(body.replace('"passed": true','"passed": true,"passed": true',1),False),(body[:-1]+',"unknown":false}',False)]:
    receipt.write_text(raw);r=subprocess.run(cmd,capture_output=True,text=True,timeout=10);self.assertEqual(good,r.returncode==0,r.stderr)
   receipt.write_text(body);binary.write_bytes(b'changed');r=subprocess.run(cmd,capture_output=True,text=True,timeout=10);self.assertNotEqual(0,r.returncode)
 def test_actual_worker_unknown_does_not_explicitly_rollback_close_or_replace_primary(self):
  for mode in ['failed','retained','good','old']:
   p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/conduit-worker-cleanup-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=5);self.assertEqual(0,p.returncode,p.stderr);d=json.loads(p.stdout)
   self.assertEqual([0 if mode in ['failed','retained']else 1]*4,[d[k]for k in ['rollbacks','closes','disposes','proof_closes']])
   if mode=='failed':self.assertTrue(d['original_failure_preserved'])
   if mode=='retained':self.assertEqual('failed',d['status'])

if __name__=='__main__':unittest.main()

class SourceOperationPlumbingUnit(unittest.TestCase):
 CASE='support/governance/recovery/conduit-cases.php'
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
  self.assertEqual(ast.dump(a['main'],include_attributes=False),ast.dump(b['main'],include_attributes=False))
  self.assertEqual(ast.dump(a['read_source_checked'],include_attributes=False),ast.dump(b['read_source_checked'],include_attributes=False))
  original=next(ast.literal_eval(n.value)for n in old.body if isinstance(n,ast.Assign)and getattr(n.targets[0],'id',None)=='SOURCE_INPUTS')
  self.assertEqual(gate.SOURCE_INPUTS,original)
  body=copy.deepcopy(b['_validate_with_source_operation']);body.name='validate';body.args.kwonlyargs.pop();body.args.kw_defaults.pop()
  for call in [x for x in ast.walk(body)if isinstance(x,ast.Call)]:
   call.keywords=[x for x in call.keywords if x.arg!='_source_operation']
   if isinstance(call.func,ast.Name)and call.func.id=='summary':call.args=call.args[:1]
  self.assertEqual(ast.dump(a['validate'],include_attributes=False),ast.dump(body,include_attributes=False))
