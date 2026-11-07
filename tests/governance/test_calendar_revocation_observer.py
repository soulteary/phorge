"""Host-only actual operation code, explicit native DAO/boot collaborators, real hrtime."""
from pathlib import Path
import subprocess,json,hashlib,unittest,importlib.util,copy,os
ROOT=Path(__file__).resolve().parents[2];PRODUCER=ROOT/'support/governance/revocation-measurement/calendar-observer.php'
spec=importlib.util.spec_from_file_location('calendar_consumer',PRODUCER.with_name('calendar-consumer.py'));consumer=importlib.util.module_from_spec(spec);spec.loader.exec_module(consumer)
BOOT=r'''
namespace CalendarHost;
use \ReflectionProperty;use \RuntimeException;use \Throwable;
class LiskDAO{private $forcedConnection=null;}
class AphrontMySQLiDatabaseConnection{function isInsideTransaction(){return false;}function isReadLocking(){return false;}function isWriteLocking(){return false;}}
class PhorgePrivateMailPolicy{static function enabled(){return true;}static function snapshotSafe($m,$s){return true;}}
class PhabricatorCalendarEventInvitee extends LiskDAO{
 public $next;const STATUS_UNINVITED='uninvited';static $status='attending';function getID(){return '17';}function getEventPHID(){return 'event-A';}function getInviteePHID(){return 'recipient';}function getAphrontRefDatabaseName(){return 'governance_native_aaaaaaaaaaaaaaaa_calendar';}function establishConnection($m){return $GLOBALS['conn'];}function load($id){return new self();}function isAttending(){return self::$status==='attending';}function getStatus(){return self::$status;}function setStatus($s){$this->next=$s;return$this;}function save(){self::$status=$this->next;return$this;}}
class PhabricatorMetaMTAMail {public $id;function __construct($id=null){$this->id=$id;}function getID(){return$this->id;}function load($id){return new self($id);}function getAphrontRefDatabaseName(){return 'governance_native_aaaaaaaaaaaaaaaa_metamta';}function getToPHIDs(){return ['recipient'];}function getRelatedPHID(){return$this->id===1?'event-A':'event-B';}function getParameters(){return ['gorge.delivery-snapshot'=>['deliveryID'=>'mail-'.$this->id,'authorization'=>['messageHash'=>str_repeat('f',64)],'message'=>['text'=>'generic']]];}function authorizeGorgePhysicalDelivery($a,$b,$c){return ['allowed'=>$this->id===2||PhabricatorCalendarEventInvitee::$status==='attending'];}}
function queryfx($c,$q){if(getenv('CALENDAR_HOST_RAW_TX')==='1')throw new \RuntimeException('physical_transaction_active');}function queryfx_one($c,$q){return ['a'=>'1'];}function phutil_json_encode($x){return json_encode($x);}
function file_get_contents($p){if($p==='/proc/sys/kernel/random/boot_id')return 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';return \file_get_contents($p);}
function hash_file($kind,$p){return \hash_file($kind,$GLOBALS['producer_path']);}
'''
class Observer(unittest.TestCase):
 def actual(self,isolated=True,raw=False):
  code=BOOT+r'''$GLOBALS['producer_path']=$argv[1];eval(substr(\file_get_contents($argv[1]),5));$GLOBALS['conn']=new AphrontMySQLiDatabaseConnection();$f=new class{function assertOwned(){}function getNamespace(){return 'governance_native_aaaaaaaaaaaaaaaa';}};putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=1');putenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION='.str_repeat('a',40));echo json_encode(governance_calendar_revocation_observe($f,new PhabricatorCalendarEventInvitee(),[new PhabricatorMetaMTAMail(1),new PhabricatorMetaMTAMail(2)]));'''
  if not isolated:code=code.replace("putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=1');","putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=0');")
  # Eval keeps the original function global; explicit aliases resolve native collaborators.
  code=code.replace("eval(substr(\\file_get_contents($argv[1]),5));","eval('namespace CalendarHost;use \\ReflectionProperty;use \\RuntimeException;use \\Throwable;'.substr(\\file_get_contents($argv[1]),5));")
  p=subprocess.run(['php','-r',code,str(PRODUCER)],capture_output=True,text=True,timeout=5,env={**os.environ,'CALENDAR_HOST_RAW_TX':'1'if raw else'0'});
  if not isolated or raw:return p
  self.assertEqual(0,p.returncode,p.stderr);return json.loads(p.stdout)
 def test_actual_function_records_real_monotonic_and_persisted_transition(self):
  d=self.actual();self.assertEqual(['allowed','allowed','denied','allowed','denied'],[x['outcome']for x in d['attempts']]);self.assertGreater(d['operation_start_ns'],0);self.assertTrue(d['persisted_uninvited_observed']);self.assertFalse(d['measurement_acceptance']);self.assertNotIn('event-A',json.dumps(d));r=consumer.describe(d,'a'*40,hashlib.sha256(PRODUCER.read_bytes()).hexdigest());self.assertIsNotNone(r['first_A_denial_after_return_ns']);self.assertFalse(r['measurement_acceptance'])
 def test_replay_refuses_wrong_source_clock_boolean_overlap_and_extra_fields(self):
  original=self.actual()
  for mode in ['source','clock','boolean','overlap','extra']:
   d=copy.deepcopy(original)
   if mode=='source':d['source_revision']='b'*40
   if mode=='clock':d['commit_confirmed_return_ns']=-1
   if mode=='boolean':d['operation_start_ns']=True
   if mode=='overlap':d['attempts'][2]['start_ns']=0
   if mode=='extra':d['physical_SQL_proved']=True
   with self.assertRaises(ValueError):consumer.describe(d,'a'*40,hashlib.sha256(PRODUCER.read_bytes()).hexdigest())
 def test_duplicate_and_budget_refused(self):
  for raw in [b'{"x":1,"x":2}',b' '*65537]:
   with self.assertRaises(ValueError):consumer.decode(raw)

 def test_OFF_refuses_before_DAO_mutation(self):
  p=self.actual(False);self.assertNotEqual(0,p.returncode);self.assertIn('calendar_measurement_isolated_only',p.stderr)
 def test_unknown_and_later_allow_are_reported_not_promoted(self):
  d=self.actual();d['attempts'][2]['outcome']='unknown';d['attempts'][4]['outcome']='allowed'
  r=consumer.describe(d,'a'*40,hashlib.sha256(PRODUCER.read_bytes()).hexdigest());self.assertIsNone(r['first_A_denial_after_return_ns']);self.assertEqual(['unknown','allowed'],r['postcommit_A_outcomes']);self.assertFalse(r['measurement_acceptance'])

 def test_raw_transaction_refusal_is_not_faked_commit(self):
  p=self.actual(raw=True);self.assertNotEqual(0,p.returncode);self.assertIn('physical_transaction_active',p.stderr);self.assertNotIn('commit_confirmed_return_ns',p.stdout)
