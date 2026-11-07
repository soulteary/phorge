"""Actual selector/native role methods/whole finally/public receipt controls, no SQL."""
from pathlib import Path
import subprocess,json,unittest,itertools,sys,copy,hashlib,tempfile
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_borrowed_session as gate
import test_recovery_session_lease_gate as previous
def canonical_temp_base():
 return Path(tempfile.gettempdir()).resolve(strict=True)
class BorrowedWiring(unittest.TestCase):
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
  text=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text();a=text.index('      - name: Verify fresh borrowed native Session owner');b=text.index('      - name: Verify fresh native organization approval workbench',a);block=text[a:b]
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
