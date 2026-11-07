"""Actual new core with explicit native-connection SQL collaborators, never SQL proof."""
from pathlib import Path
import json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
BOOT=r'''
class Phobject {} class LiskDAO {protected $forcedConnection;}
class PhutilAggregateException extends Exception {public $children;function __construct($m,$e){parent::__construct($m);$this->children=$e;}}
class AphrontMySQLiDatabaseConnection {public $depth=0;public $name;function __construct($n){$this->name=$n;}function isInsideTransaction(){return $this->depth>0;}function isReadLocking(){return false;}function isWriteLocking(){return false;}function openTransaction(){$GLOBALS['events'][]=$this->name.'.begin';$this->depth++;if($GLOBALS['mode']==='begin-unknown'&&$this->name==='conduit')throw $GLOBALS['primary'];}function saveTransaction(){$GLOBALS['events'][]=$this->name.'.commit';if($GLOBALS['mode']==='commit-unknown')throw $GLOBALS['primary'];$this->depth--;}function killTransaction(){$GLOBALS['events'][]=$this->name.'.rollback';if($GLOBALS['mode']==='rollback-failure')throw new RuntimeException('cleanup');$this->depth--;}}
class PhabricatorUser extends LiskDAO {function establishConnection($m){return $GLOBALS['owner'];}function getAphrontRefDatabaseName(){return 'owned_user';}function getTableName(){return 'user';}}
class PhabricatorConduitToken extends LiskDAO {public $id=null;function getID(){return $this->id;}function getObjectPHID(){return 'PHID-USER-'.str_repeat('a',20);}function getToken(){return 'synthetic';}function establishConnection($m){return $GLOBALS['writer'];}function getAphrontRefDatabaseName(){return 'owned_conduit';}function getTableName(){return 'conduit_token';}}
class PhorgeRecoveryRestoreService {static function assertConduitProducerOwnerAvailable($w){if($GLOBALS['mode']==='borrowed-reserved')throw $GLOBALS['primary'];}}
class PhorgeRecoverySessionProducerFence {static function assertConduitProducerWriterAvailable($w){if($GLOBALS['mode']==='lease-reserved')throw $GLOBALS['primary'];}}
class PhorgeRecoveryRestoreProfile {static function assertCurrent(){return ['synthetic-profile'];}static function assertOwnedWriter($w){if(!$w->isInsideTransaction())throw new RuntimeException("native-owner-required");}}
class PhorgeRecoveryStopRequest {static function user($p){return $p;}}
function queryfx($c,$q,...$a){$GLOBALS['events'][]=$c->name.'.sql';if($GLOBALS['mode']==='conduit-raw-outer'&&$c->name==='conduit')throw $GLOBALS['primary'];}
function queryfx_one($c,$q,...$a){if(str_contains($q,'@@autocommit'))return ['a'=>'1','t'=>(string)$c->depth,'u'=>'same-server'];if(str_contains($q,'SELECT ENGINE'))return ['ENGINE'=>'InnoDB'];if(str_contains($q,'SELECT id'))return ['id'=>'1'];if(str_contains($q,'SELECT phid'))return ['phid'=>'PHID-USER-'.str_repeat('a',20)];return ['objectPHID'=>'PHID-USER-'.str_repeat('a',20),'token'=>'synthetic'];}
'''
class ConduitParticipant(unittest.TestCase):
 def invoke(self,mode):
  code=BOOT+r'''require $argv[1];$mode=$argv[2];$events=[];$owner=new AphrontMySQLiDatabaseConnection('user');$writer=new AphrontMySQLiDatabaseConnection('conduit');$primary=new RuntimeException('primary');if($mode==='outer-user')$owner->depth=1;if($mode==='outer-conduit')$writer->depth=1;$t=new PhabricatorConduitToken();$caught=null;$result=null;
try{$result=PhorgeRecoveryConduitProducerFence::insert($t,'INSERT',function()use($t,$mode){$GLOBALS['events'][]='actual-insert-collaborator';if(in_array($mode,['insert-failure','rollback-failure']))throw$GLOBALS['primary'];$t->id=3;return$t;});}catch(Throwable$e){$caught=$e;}
$retained=(new ReflectionProperty(PhorgeRecoveryConduitProducerFence::class,'retained'))->getValue();echo json_encode(['events'=>$events,'failed'=>$caught!==null,'same_primary'=>$caught===$primary,'aggregate_primary'=>$caught instanceof PhutilAggregateException&&($caught->children['primary']??null)===$primary,'retained'=>count($retained),'returned_same'=>$result===$t]);'''
  r=subprocess.run(['php','-r',code,str(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryConduitProducerFence.php'),mode],capture_output=True,text=True,timeout=5);self.assertEqual(0,r.returncode,r.stderr);return json.loads(r.stdout)
 def test_actual_core_success_orders_insert_commit_before_User_unlock(self):
  d=self.invoke('success');self.assertFalse(d['failed']);self.assertTrue(d['returned_same']);self.assertEqual(0,d['retained']);e=d['events'];self.assertLess(e.index('actual-insert-collaborator'),e.index('conduit.commit'));self.assertLess(e.index('conduit.commit'),e.index('user.rollback'))
 def test_external_transactions_refused_before_any_SQL_or_insert(self):
  for m in ['outer-user','outer-conduit']:
   d=self.invoke(m);self.assertTrue(d['failed']);self.assertEqual([],d['events'])
 def test_known_insert_failure_rolls_back_only_owned_writers_and_preserves_primary(self):
  d=self.invoke('insert-failure');self.assertTrue(d['same_primary']);self.assertEqual(0,d['retained']);self.assertEqual(['conduit.rollback','user.rollback'],[e for e in d['events']if e.endswith('rollback')])
 def test_unknown_commit_retains_User_lock_and_never_claims_rollback(self):
  d=self.invoke('commit-unknown');self.assertTrue(d['same_primary']);self.assertEqual(1,d['retained']);self.assertFalse(any(e.endswith('rollback')for e in d['events']))
 def test_primary_and_cleanup_failures_aggregate_and_retain(self):
  d=self.invoke('rollback-failure');self.assertTrue(d['aggregate_primary']);self.assertEqual(1,d['retained']);self.assertNotIn('user.rollback',d['events'])
 def test_second_writer_raw_outer_refusal_does_not_leave_User_isolation_queued(self):
  d=self.invoke('conduit-raw-outer');self.assertTrue(d['same_primary']);self.assertEqual(0,d['retained']);self.assertIn('user.begin',d['events']);self.assertIn('user.rollback',d['events']);self.assertNotIn('conduit.begin',d['events']);self.assertNotIn('conduit.rollback',d['events'])
 def test_unknown_BEGIN_retains_both_references_and_never_rolls_back(self):
  d=self.invoke('begin-unknown');self.assertTrue(d['same_primary']);self.assertEqual(1,d['retained']);self.assertNotIn('user.rollback',d['events']);self.assertNotIn('conduit.rollback',d['events'])
 def test_other_selected_owner_reservations_refuse_before_SQL(self):
  for mode in ['borrowed-reserved','lease-reserved']:
   d=self.invoke(mode);self.assertTrue(d['same_primary']);self.assertEqual([],d['events'])

class NativeConduitEntry(unittest.TestCase):
 def test_actual_native_factory_and_callable_six_obligations_no_SQL(self):
  code=r"""require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');require $argv[1].'/support/governance/recovery/conduit-cases.php';$u='PHID-USER-'.str_repeat('a',20);$a=PhabricatorConduitToken::initializeNewToken($u,PhabricatorConduitToken::TYPE_STANDARD);$b=PhabricatorConduitToken::initializeNewToken($u,PhabricatorConduitToken::TYPE_CLUSTER);echo json_encode(['standard'=>$a->getTokenType(),'cluster'=>$b->getTokenType(),'owner'=>$a->getObjectPHID()===$u&&$b->getObjectPHID()===$u,'unsaved'=>$a->getID()===null&&$b->getID()===null,'obligations'=>count(governance_recovery_borrowed_conduit_obligations())]);"""
  r=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect','-r',code,str(ROOT)],capture_output=True,text=True,timeout=5);self.assertEqual(0,r.returncode,r.stderr);self.assertEqual({'standard':'api','cluster':'clr','owner':True,'unsaved':True,'obligations':6},json.loads(r.stdout))
 def test_default_OFF_hook_retains_original_native_parent_call(self):
  s=(ROOT/'src/applications/conduit/storage/PhabricatorConduitToken.php').read_text()
  self.assertIn("if (!PhorgeGovernanceRecoveryGuard::enabled()) {\n      return parent::insertRecordIntoDatabase($mode);",s)
  self.assertIn('PhorgeRecoveryConduitProducerFence::insert($this,$mode,',s)

class BorrowedConduitCapture(unittest.TestCase):
 def invoke(self,mode):
  r=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/borrowed-conduit-owner-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=5);self.assertEqual(0,r.returncode,r.stderr);return json.loads(r.stdout)
 def test_real_Store_and_participant_capture_rechecks_then_observes_owner_terminal(self):
  d=self.invoke('success');self.assertIsNone(d['error']);self.assertTrue(d['cleanup_proved']);self.assertFalse(d['retained_owner']);self.assertEqual('native-conduit-token-rows',d['facts']['selected_source']);self.assertFalse(d['facts']['restoration_authorized'])
 def test_source_drift_budget_and_wrong_primary_refuse(self):
  for mode in ['conduit-drift','conduit-budget','different-primary']:
   d=self.invoke(mode);self.assertIsNotNone(d['error']);self.assertFalse(d['physical_SQL_executed']);self.assertIsNone(d['facts'])

class ConduitVersionedSource(unittest.TestCase):
 def test_v1_remains_historical_and_current_v2_pins_four_exact_sources(self):
  import sys,hashlib
  sys.path.insert(0,str(ROOT/'scripts/governance'))
  import check_recovery_borrowed_owner_source as gate
  old=json.loads((ROOT/'docs/governance/recovery-borrowed-owner-source-v1.json').read_bytes())
  new=json.loads((ROOT/'docs/governance/recovery-conduit-owner-source-v2.json').read_bytes())
  self.assertEqual(2,len(old['source_sha256']));self.assertEqual(4,len(new['source_sha256']))
  self.assertEqual(2,gate.validate(old)['source_inputs']);self.assertEqual(4,gate.validate(new)['source_inputs'])
  self.assertFalse(gate.validate(new)['runtime_certified'])
 def test_inverse_still_refuses_unrelated_bytes_even_after_current_hash_repin(self):
  import sys,hashlib
  sys.path.insert(0,str(ROOT/'scripts/governance'));import recovery_conduit_source_delta as delta
  for name in delta.BASE_PINS:
   raw=(ROOT/name).read_bytes();prior=delta.SOURCE_INPUTS[name]
   try:
    for bad in [raw+b'\n// unrelated\n',raw.replace(delta.EDITS[name][0][0],delta.EDITS[name][0][0]*2,1)]:
     delta.SOURCE_INPUTS[name]=hashlib.sha256(bad).hexdigest()
     with self.assertRaises(ValueError):delta.reduce_owner(name,bad)
   finally:delta.SOURCE_INPUTS[name]=prior

if __name__=='__main__':unittest.main()
