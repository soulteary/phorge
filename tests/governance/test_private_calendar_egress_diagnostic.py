"""Actual fixture-loop ordering and fixed bool projection; no SQL/network proof."""
import base64,json,subprocess,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_calendar_failure as projection

PHP=r'''
class PhabricatorMailOutboundStatus {const STATUS_QUEUE='queue';}
class Event {function __construct(public $phid){}function getPHID(){$GLOBALS['calls'][]='identity-'.$this->phid;return$GLOBALS['mode']==='identity-mismatch'?'foreign':$this->phid;}}
class PhabricatorCalendarEventQuery {private $phid;function setViewer($v){return$this;}function withPHIDs($v){$this->phid=$v[0];return$this;}function executeOne(){$GLOBALS['calls'][]='query-'.$this->phid;if($GLOBALS['mode']==='throw-query')throw$GLOBALS['primary'];return$GLOBALS['mode']==='object-missing'?null:new Event($this->phid);}}
class PhabricatorMetaMTAMail {
 private $phid;private $prepared=false;
 function __construct($phid=null){$this->phid=$phid;}
 function getID(){return$this->phid;}
 function load($id){return$GLOBALS['mails'][$id];}
 function getStatus(){return'queue';}
 function getParameters(){return[];}
 function getPrivateMailPolicyValue($key,$default=null){return$key==='governance.delivery-quarantined'&&$this->prepared&&$GLOBALS['mode']==='prepare-void'?true:$default;}
 function prepareGorgeDelivery(){$GLOBALS['calls'][]='prepare-'.$this->phid;$this->prepared=true;if($GLOBALS['mode']==='throw-prepare')throw$GLOBALS['primary'];if($GLOBALS['mode']==='prepare-void')return['state'=>'void'];return['deliveryID'=>'delivery','authorization'=>['messageHash'=>'fixed'],'message'=>[]];}
 function authorizeGorgePhysicalDelivery(...$args){$GLOBALS['calls'][]='callback-'.$this->phid;if($GLOBALS['mode']==='throw-callback')throw$GLOBALS['primary'];return['allowed'=>$GLOBALS['mode']!=='callback-denied'];}
 function getBody(){return'/object/'.$this->phid.'/';}function getHTMLBody(){return null;}function getAttachments(){return[];}
}
function phutil_json_encode($v){return json_encode($v);}
function run_loop($source,$mode){
 $GLOBALS['mode']=$mode;$GLOBALS['calls']=[];$GLOBALS['primary']=new Error('private-primary');
 unset($GLOBALS['governance_calendar_attempt'],$GLOBALS['governance_calendar_egress_probe']);
 $by_event=$GLOBALS['mails']=['A'=>new PhabricatorMetaMTAMail('A'),'B'=>new PhabricatorMetaMTAMail('B')];$recipient=new stdClass();
 $record=static function($v,$name){if($v!==true)throw new RuntimeException('fixed-assertion');};
 $error=null;$primary=false;try{eval($source);}catch(Throwable$e){$error=get_class($e);$primary=$e===$GLOBALS['primary'];}
 return['calls'=>$GLOBALS['calls'],'error'=>$error,'primary'=>$primary,'probe'=>$GLOBALS['governance_calendar_egress_probe']??null,'attempt'=>$GLOBALS['governance_calendar_attempt']??null];
}
$current=file_get_contents($argv[1].'/support/governance/notification/calendar-reminder-cases.php');$old=base64_decode($argv[2],true);
$cut=static function($s){$a=strpos($s,'    $snapshots=array();$allowed=true;$safe=true;');$b=strpos($s,'    $record((bool)$safe',$a);return substr($s,$a,$b-$a);};
$out=[];foreach(['good','object-missing','identity-mismatch','prepare-void','callback-denied','throw-query','throw-prepare','throw-callback']as$mode){$before=run_loop($cut($old),$mode);$after=run_loop($cut($current),$mode);$out[$mode]=['same_calls'=>$before['calls']===$after['calls'],'same_error'=>$before['error']===$after['error'],'same_primary'=>$before['primary']===$after['primary'],'calls'=>$after['calls'],'probe'=>$after['probe'],'attempt'=>$after['attempt']];}echo json_encode($out);
'''

class EgressDiagnostic(unittest.TestCase):
 def test_actual_old_new_fixture_loops_keep_query_prepare_identity_callback_order_and_primary(self):
  old=subprocess.check_output(['git','-C',str(ROOT),'show','a33ab4eb826710362ae7a7f484d344dfa87e858f:support/governance/notification/calendar-reminder-cases.php'])
  p=subprocess.run(['php','-r',PHP,str(ROOT),base64.b64encode(old).decode()],capture_output=True,text=True,timeout=15)
  self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');out=json.loads(p.stdout)
  for mode,row in out.items():
   with self.subTest(mode=mode):
    self.assertTrue(row['same_calls']);self.assertTrue(row['same_error']);self.assertTrue(row['same_primary'])
    self.assertIsNotNone(projection.egress_probe(row['probe']))
    self.assertEqual(None if mode=='good' else 'calendar-private-each-original-event-current-query-and-egress-allow',row['attempt'])
  good=out['good']['probe']['rows'];self.assertEqual(len(good),2);self.assertTrue(all(x['physical_callback_allowed'] for x in good))
  denied=out['callback-denied']['probe']['rows'];self.assertTrue(denied[0]['physical_callback_attempted']);self.assertFalse(denied[1]['physical_callback_attempted'])
  void=out['prepare-void']['probe']['rows'];self.assertTrue(all(x['snapshot_void'] and x['quarantined_after'] for x in void))
  self.assertEqual(out['good']['calls'],['query-A','prepare-A','identity-A','callback-A','query-B','prepare-B','identity-B','callback-B'])
 def test_strict_fixed_bool_only_shape_refuses_raw_ids_text_numbers_and_budget(self):
  row=dict.fromkeys(projection.EGRESS_KEYS,False);good={'schema':'calendar-egress-bool-v1','rows':[row,row]}
  self.assertIsNotNone(projection.egress_probe(good))
  for bad in [dict(good,email='SECRET'),dict(good,rows=[dict(row,raw='SECRET')]),dict(good,rows=[dict(row,queue_before=1)]),dict(good,rows=[dict(row,queue_before='SECRET')]),dict(good,rows=[row]*3),dict(good,schema='SECRET')]:
   self.assertIsNone(projection.egress_probe(bad))
   self.assertNotIn('SECRET',json.dumps(projection.failure_fields({'calendar_egress_probe':bad})))

class NativeMetadataAndFailureHandoff(unittest.TestCase):
 def test_actual_native_mail_metadata_getters_need_no_SQL_and_driver_keeps_primary_fields(self):
  code=r'''require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');$mail=(new PhabricatorMetaMTAMail())->setStatus(PhabricatorMailOutboundStatus::STATUS_QUEUE);$source=file_get_contents($argv[1].'/support/governance/native-organization/test-native-organization-mysql.php');$a=strpos($source,"  if($"."phase==='calendar-reminders'&&isset($"."GLOBALS['governance_calendar_egress_probe'])){");$b=strpos($source,'  if ($error instanceof mysqli_sql_exception)',$a);if($a===false||$b===false)throw new RuntimeException('fixed handoff absent');$phase='calendar-reminders';$error=new RuntimeException('PRIVATE_PRIMARY_MESSAGE');$primary=$error;$failure=['stage'=>'calendar-private-owned-queue-cleanup','error_type'=>'RuntimeException','source_file'=>'calendar-reminder-cases.php','source_line'=>65];$GLOBALS['governance_calendar_attempt']='calendar-private-each-original-event-current-query-and-egress-allow';$GLOBALS['governance_calendar_egress_probe']=['schema'=>'calendar-egress-bool-v1','rows'=>[]];eval(substr($source,$a,$b-$a));echo json_encode(['queue'=>$mail->getStatus()===PhabricatorMailOutboundStatus::STATUS_QUEUE,'snapshot_absent'=>($mail->getParameters()['gorge.delivery-snapshot']??null)===null,'quarantine_false'=>$mail->getPrivateMailPolicyValue('governance.delivery-quarantined',false)===false,'mysqli_disabled'=>!function_exists('mysqli_init'),'primary_preserved'=>$error===$primary,'failure'=>$failure]);'''
  p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect','-r',code,str(ROOT)],capture_output=True,text=True,timeout=15)
  self.assertEqual(0,p.returncode,p.stderr);self.assertEqual('',p.stderr);value=json.loads(p.stdout)
  for name in ['queue','snapshot_absent','quarantine_false','mysqli_disabled','primary_preserved']:self.assertTrue(value[name])
  self.assertEqual(value['failure'],dict(stage='calendar-private-each-original-event-current-query-and-egress-allow',error_type='RuntimeException',source_file='calendar-reminder-cases.php',source_line=65,calendar_egress_probe={'schema':'calendar-egress-bool-v1','rows':[]}))
  self.assertNotIn('PRIVATE_PRIMARY_MESSAGE',p.stdout)
