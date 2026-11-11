"""Actual selector/native role methods/whole finally/public receipt controls, no SQL."""
from recovery_oauth_code_test_cohort import complete_source_cohort
from pathlib import Path
import subprocess,json,unittest,itertools,sys,copy,hashlib,tempfile
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_borrowed_session as gate
import test_recovery_session_lease_gate as previous
def canonical_temp_base():
 return Path(tempfile.gettempdir()).resolve(strict=True)
def historical_atomic_source(path='.github/workflows/atomic-feature-regressions.yml',raw=None):
 # Test-only exact current Atomic -> actual20b view; runtime readers stay current.
 import project_description_admission_delta as current
 current.assert_current_recipe(ROOT)
 producer='scripts/governance/check_recovery_borrowed_session.py'
 if path!='.github/workflows/atomic-feature-regressions.yml' or path not in current.CURRENT_METADATA or producer not in current.CURRENT_METADATA:
  raise ValueError('unreviewed Atomic historical source role')
 if Path(gate.__file__).resolve()!=ROOT/producer:raise ValueError('unreviewed current producer source role')
 if (ROOT/producer).stat().st_mode&0o7777!=0o644:raise ValueError('unknown current producer mode')
 producer_raw=current.description.checked_bytes(ROOT/producer);pin=current.CURRENT_METADATA[producer]
 if len(producer_raw)!=pin['bytes'] or hashlib.sha256(producer_raw).hexdigest()!=pin['sha256']:raise ValueError('unknown current producer bytes')
 if (ROOT/path).stat().st_mode&0o7777!=0o644:raise ValueError('unknown current Atomic source mode')
 actual=current.description.checked_bytes(ROOT/path)
 if raw is None:raw=actual
 if type(raw)is not bytes or raw!=actual:raise ValueError('unknown current Atomic source bytes')
 prior=current._normalize_metadata(path,raw)
 expected=subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(ROOT),'show','20b138b4cd9a2cf61522613b4a8e5e21eca7341b:'+path])
 if prior!=expected:raise ValueError('unknown Atomic historical stage')
 return prior.decode('utf-8')
class BorrowedWiring(unittest.TestCase):
 def test_current_Atomic_history_refuses_unknown_names_bytes_and_producer_role(self):
  from unittest.mock import patch
  path='.github/workflows/atomic-feature-regressions.yml';raw=(ROOT/path).read_bytes()
  old=subprocess.check_output(['/usr/bin/git','--no-replace-objects','-C',str(ROOT),'show','20b138b4cd9a2cf61522613b4a8e5e21eca7341b:'+path])
  self.assertEqual(historical_atomic_source().encode(),old)
  for name,body in [('.github/workflows/unreviewed.yml',raw),(path,raw+b'\n# unknown'),(path,raw.decode())]:
   with self.subTest(name=name),self.assertRaises(ValueError):historical_atomic_source(name,body)
  if raw!=old:
   with self.assertRaises(ValueError):historical_atomic_source(path,old)
  with patch.object(gate,'__file__',str(ROOT/'scripts/governance/unreviewed.py')):
   with self.assertRaises(ValueError):historical_atomic_source()
 def php(self,path,*args):
  p=subprocess.run(['php',str(ROOT/path),str(ROOT),*args],capture_output=True,text=True,timeout=12);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_actual_native_four_role_sorting_has_distinct_admins_and_worker_order(self):
  for order in itertools.permutations('abcd'):
   d=self.php('support/governance/recovery/tests/borrowed-actor-order-native-host.php',json.dumps(order),'good');self.assertIsNone(d['error']);self.assertEqual(d['issuer'],'a');self.assertTrue(d['owner_distinct']);self.assertEqual(d['admin_flags'],{'issuer':True,'owner':True,'subject':False,'other':False,'disabled':False});self.assertEqual(d['subject_by_terminal'],{'commit':'c','rollback':'d'});self.assertEqual(d['native_update_statements'],6);self.assertTrue(d['avatar_and_ID_fields_preserved']);self.assertEqual(d['both_terminal_worker_guards'],{'commit':True,'rollback':True});self.assertFalse(d['physical_SQL_executed']);self.assertTrue(d['host_preparation_uses_forced_isolated_DAO_only'])
 def test_enabled_or_duplicate_preparation_rejects_before_any_native_update(self):
  for mode in ['enabled','duplicate']:
   d=self.php('support/governance/recovery/tests/borrowed-actor-order-native-host.php',json.dumps(list('dcba')),mode);self.assertIsNotNone(d['error']);self.assertEqual(d['native_update_statements'],0)
 def test_actual_entire_NEWphase_finally_unknown_never_explicitly_rolls_closes_restores_or_drops(self):
  for mode in ['unknown','forged-flags']:
   d=self.php('support/governance/recovery/tests/borrowed-driver-cleanup-host.php',mode);self.assertEqual([d[k]for k in ['rollbacks','pool_closes','disposes','drops','control_closes']],[0]*5);self.assertEqual(d['env'],'current');self.assertEqual(d['failures'],[{'stage':'borrowed-parent-or-observer-terminal-unproved','error_type':'RuntimeException'}])
  d=self.php('support/governance/recovery/tests/borrowed-driver-cleanup-host.php','good');self.assertEqual([d[k]for k in ['pool_closes','disposes','drops','control_closes']],[1]*4);self.assertEqual(d['env'],'original');self.assertEqual(d['failures'],[])
  d=self.php('support/governance/recovery/tests/borrowed-driver-cleanup-host.php','old');self.assertEqual(d['rollbacks'],1);self.assertEqual(d['drops'],1)
 def test_exact_actual_driver_selector_alias_duplicate_mixed_refused(self):
  text=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text();block=text[text.index('$arguments='):text.index('$workbench_http=')]
  for argv,want in [(['--session-borrowed-owner'],'session-borrowed-owner'),(['--session-borrowed-owner=1'],'invalid'),(['--session-borrowed-owner']*2,'invalid'),(['--session-borrowed-owner','--session-producer-lease'],'invalid')]:
   code='$argv=array_merge(["driver"],json_decode($argv[2],true));'+block+'echo json_encode($phase);';p=subprocess.run(['php','-r',code,str(ROOT),json.dumps(argv)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),want)
 def template(self,digest='b'*64):
  d=previous.template(digest);labels=sorted(gate.obligations());d.update(batch='native-borrowed-session-owner',scope='owned-native-borrowed-session-owner-no-permit',checks=labels,check_count=len(labels),native=gate.summary('a'*40));return d
 def test_strict_receipt_source_cases_production_flags_cleanup_route_and_binary_tamper(self):
  d=self.template();self.assertEqual(len(gate.SQL_obligations()),6);self.assertEqual(gate.validate(d,source_revision='a'*40,file_digest='b'*64)['checks'],16)
  for key in ['production_observer_factory_available','business_submit_review_integration_proved','durable_review_compatible','Task_File_RR_combined','all_sources_complete','restoration_authorized','forced_connection_used']:
   bad=copy.deepcopy(d);bad['native'][key]=True
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
  for key in ['default_native_User_writer','both_native_identity_epochs_prewarmed']:
   bad=copy.deepcopy(d);bad['native'][key]=False
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
  for label in d['checks']:
   bad=copy.deepcopy(d);bad['checks'].remove(label);bad['check_count']-=1
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
 def test_actual_public_receipt_CLI_binary_revision_unknown_duplicate_refused(self):
  with tempfile.TemporaryDirectory()as tmp:
   p=Path(tmp).resolve();binary=p/'file';binary.write_bytes(b'host binary data only');binary.chmod(0o700);digest=hashlib.sha256(binary.read_bytes()).hexdigest();receipt=p/'receipt';receipt.touch(mode=0o600);body=json.dumps(self.template(digest));cmd=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_borrowed_session.py'),'--receipt',str(receipt),'--source-revision','a'*40,'--file-binary',str(binary),'--file-binary-sha256',digest]
   for raw,good in [(body,True),(body.replace('"passed": true','"passed": true,"passed": true',1),False),(body[:-1]+',"unknown":false}',False),(body.replace('a'*40,'c'*40),False)]:
    receipt.write_text(raw);r=subprocess.run(cmd,capture_output=True,text=True,timeout=10);self.assertEqual(r.returncode==0,good,r.stderr)
   receipt.write_text(body);binary.write_bytes(b'changed');self.assertNotEqual(subprocess.run(cmd,capture_output=True,text=True,timeout=10).returncode,0)
 def test_default_writer_gate_refuses_forced_or_mismatched_models_before_store(self):
  code=r'''class LiskDAO{protected $forcedConnection;}class PhabricatorLiskDAO extends LiskDAO{private static $namespaceStack=[];}class PhabricatorUser extends PhabricatorLiskDAO{function __construct(){if($GLOBALS['mode']==='forced')$this->forcedConnection=new stdClass();}function establishConnection($mode){return$GLOBALS['w'];}}class PhabricatorAuthSession extends PhabricatorUser{function establishConnection($mode){return$GLOBALS['mode']==='route'?new AphrontMySQLiDatabaseConnection():$GLOBALS['w'];}}final class AphrontMySQLiDatabaseConnection{function isInsideTransaction(){return false;}function isReadLocking(){return false;}function isWriteLocking(){return false;}}class PhorgeRecoveryRestoreProfile{static function assertCurrent(){}}require$argv[1].'/support/governance/recovery/borrowed-session-focused.php';$GLOBALS['w']=new AphrontMySQLiDatabaseConnection();$out=[];foreach(['good','forced','route']as$mode){$GLOBALS['mode']=$mode;try{$out[]=governance_recovery_borrowed_default_writer()===$GLOBALS['w'];}catch(Throwable$e){$out[]=$e->getMessage();}}echo json_encode($out);'''
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),[True,'borrowed_forced_writer_refused','borrowed_default_writer_unknown'])
 def test_failure_projection_drops_raw_data_unknown_file_and_boolean_numbers(self):
  code="require$argv[1].'/support/governance/recovery/borrowed-session-diagnostic.php';echo json_encode(governance_recovery_borrowed_failure_projection(json_decode($argv[2],true)));"
  for f in [{'stage':'focused-native-borrowed-session-owner','error_type':'RuntimeException','source_file':'borrowed-session-focused.php','source_line':17,'message':'SECRET SQL password'},{'stage':'SECRET','error_type':'Foreign','source_file':'private.json','source_line':True,'query_code':False}]:
   p=subprocess.run(['php','-r',code,str(ROOT),json.dumps(f)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertNotIn('SECRET',p.stdout);d=json.loads(p.stdout)
   if f['stage']=='SECRET':self.assertIsNone(d['source_file']);self.assertIsNone(d['source_line']);self.assertIsNone(d['query_code'])
 def test_mandatory_CI_owned_real_FS_failure_never_uploads_and_original_blocks_preserved(self):
  text=historical_atomic_source();a=text.index('      - name: Verify fresh borrowed native Session owner');b=text.index('      - name: Verify fresh native organization approval workbench',a);block=text[a:b]
  old=subprocess.check_output(['git','-C',str(ROOT),'show','8805c4aa5f533605c1e0004a6691bda458f35202:.github/workflows/atomic-feature-regressions.yml']).decode();self.assertEqual(text[:a]+text[b:],old)
  self.assertIn('if: success()',block);self.assertNotIn('continue-on-error',block);self.assertIn('sha256sum --check /tmp/atomic-maps.sha256',block);self.assertIn('--session-borrowed-owner',block);self.assertIn('check_recovery_borrowed_session.py',block)
  owned='mkdir "$RUNNER_TEMP/governance-borrowed-session-owner"'
  with tempfile.TemporaryDirectory()as tmp:
   base=Path(tmp).resolve();root=base/'runner';root.mkdir();foreign=base/'foreign';foreign.mkdir();(foreign/'secret').write_text('private foreign')
   for mode in ['foreign-directory','foreign-symlink','native-fail','checker-fail','good']:
    dest=root/'governance-borrowed-session-owner'
    if dest.is_symlink():dest.unlink()
    elif dest.exists():import shutil;shutil.rmtree(dest)
    if mode=='foreign-directory':dest.mkdir();(dest/'secret').write_text('private foreign')
    elif mode=='foreign-symlink':dest.symlink_to(foreign,target_is_directory=True)
    status='false'if mode in ['native-fail','checker-fail']else'true';script='set -eu\numask 077\n'+owned+'\n'+status+'\n';r=subprocess.run(['bash','-c',script],env={'RUNNER_TEMP':str(root),'PATH':'/usr/bin:/bin'},capture_output=True)
    self.assertEqual(r.returncode==0,mode=='good');self.assertEqual((foreign/'secret').read_text(),'private foreign')
    # Actual workflow success-only condition is coupled to this whole run status.
    upload=r.returncode==0 and 'if: success()'in block;self.assertEqual(upload,mode=='good')

 def test_fresh_selected_process_loads_actual_native_factory_definition(self):
  code="require_once$argv[1].'/support/governance/recovery/borrowed-session-focused.php';$r=new ReflectionFunction('governance_native_request');echo json_encode(['file'=>realpath($r->getFileName()),'parameters'=>array_map(static fn($p)=>(string)$p->getType(),$r->getParameters()),'SQL_executed'=>false]);"
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);d=json.loads(p.stdout);self.assertEqual(d['file'],str(ROOT/'support/governance/native-organization/native-cases.php'));self.assertEqual(d['parameters'],['PhabricatorUser','PhabricatorAuthSessionEngine']);self.assertFalse(d['SQL_executed'])

 def test_actual_source_pins_reject_one_changed_dependency_before_obligations(self):
  import shutil
  with tempfile.TemporaryDirectory(dir=canonical_temp_base())as tmp:
   root=Path(tmp).resolve(strict=True)
   for relative in gate.SOURCE_INPUTS:
    dst=root/relative;dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/relative,dst)
   complete_source_cohort(root)
   self.assertEqual(len(gate.SQL_obligations(root)),6)
   path=root/'support/governance/recovery/project-source-focused.php';path.write_bytes(path.read_bytes()+b'\n// corruption negative control\n')
   with self.assertRaisesRegex(ValueError,'reviewed borrowed source changed'):gate.SQL_obligations(root)

 def test_actual_native_transaction_prewarm_preserves_both_failures_and_ownership(self):
  for mode,queries,primary,dual in [('good',['START TRANSACTION','COMMIT'],False,False),('primary',['START TRANSACTION','ROLLBACK'],True,False),('dual',['START TRANSACTION','ROLLBACK'],False,True),('begin',['START TRANSACTION'],True,False),('commit',['START TRANSACTION','COMMIT'],True,False),('unowned',[],False,False),('depth',['START TRANSACTION','SAVEPOINT AphrontDatabaseTransactionState_1'],True,False)]:
   d=self.php('support/governance/recovery/tests/borrowed-prewarm-native-host.php',mode);self.assertEqual(d['native_transaction_method'],'AphrontDatabaseConnection');self.assertFalse(d['physical_SQL_executed']);self.assertEqual(d['primary_exact'],primary);self.assertEqual(d['children_exact'],dual)
   if mode=='depth':self.assertEqual(len(d['queries']),2);self.assertTrue(d['queries'][1].startswith('SAVEPOINT '));self.assertTrue(d['inside'])
   else:self.assertEqual(d['queries'],queries)
   if mode=='unowned':self.assertTrue(d['refused_unowned'])

 def test_portable_temp_topologies_resolve_alias_without_weakening_source_guard(self):
  from unittest.mock import patch
  with tempfile.TemporaryDirectory(dir=canonical_temp_base())as tmp:
   root=Path(tmp).resolve(strict=True);native=root/'linux-temp';native.mkdir();alias=root/'mac-temp-alias';alias.symlink_to(native,target_is_directory=True)
   for candidate in [native,alias]:
    with patch.object(tempfile,'gettempdir',return_value=str(candidate)):
     base=canonical_temp_base();self.assertEqual(base,native)
     with tempfile.TemporaryDirectory(dir=base)as owned:
      source=Path(owned)/'source.php';source.write_bytes(b'<?php // owned temporary source\n');self.assertEqual(gate.read_source_checked(source),source.read_bytes())
      aliased=alias/source.parent.name/source.name
      with self.assertRaisesRegex(ValueError,'source parent alias refused'):gate.read_source_checked(aliased)
      self.assertEqual(gate.read_source_checked(aliased.resolve(strict=True)),source.read_bytes())

 def test_actual_borrowed_callback_preflight_ends_before_owner_and_continue_waits_for_capture(self):
  for mode in ['continue-fail','capture-fail','owner-fail','ready-fail','dual','ready-dual','writer-changed']:
   d=self.php('support/governance/recovery/tests/borrowed-preflight-order-host.php',mode);self.assertTrue(d['actual_case_function']);self.assertFalse(d['physical_SQL_executed']);trace=d['trace'];self.assertEqual(trace[:2],['start','ready']);self.assertEqual(trace[-2:],['stop','worker-SQL-ended'])
   if mode in ['ready-fail','ready-dual']:self.assertNotIn('owner-enter',trace)
   else:self.assertLess(trace.index('ready'),trace.index('owner-enter'))
   if mode in ['continue-fail','dual']:self.assertLess(trace.index('capture'),trace.index('continue'))
   if mode=='capture-fail':self.assertNotIn('continue',trace)
   if mode=='writer-changed':self.assertTrue(d['writer_changed_refused']);self.assertNotIn('capture',trace)
   else:self.assertEqual(d['primary_exact'],mode not in ['dual','ready-dual']);self.assertEqual(d['aggregate_exact'],mode in ['dual','ready-dual'])

class SourceOperationPlumbingUnit(unittest.TestCase):
 CASE='support/governance/recovery/borrowed-session-cases.php'
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
