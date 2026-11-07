"""Actual cross-source entry methods; native SQL/connection state is explicit host collaboration."""
from pathlib import Path
import json,subprocess,unittest
from test_recovery_conduit_participant import BOOT
ROOT=Path(__file__).resolve().parents[2]
class ConduitCrossSourceQuarantine(unittest.TestCase):
 def invoke(self,mode):
  boot='\n'.join(line for line in BOOT.splitlines()if not line.startswith(('class PhorgeRecoveryRestoreService ','class PhorgeRecoverySessionProducerFence ')))
  boot=boot.replace('class AphrontMySQLiDatabaseConnection {','class AphrontDatabaseConnection {} class AphrontMySQLiDatabaseConnection extends AphrontDatabaseConnection {')
  code=boot+r'''
class PhorgeGovernanceNativeActorContext {}
class PhabricatorEnv{static function getEnvConfig($k){return true;}}
class PhabricatorAuthSession extends PhabricatorUser{
 const TYPE_WEB='web',TYPE_CONDUIT='conduit';
 function getID(){return null;}function getUserPHID(){return 'PHID-USER-'.str_repeat('a',20);}function getType(){return self::TYPE_WEB;}function getSessionKey(){return str_repeat('a',64);}function getIsPartial(){return 0;}function getSignedLegalpadDocuments(){return 0;}function getSessionStart(){return 1;}function getSessionExpires(){return 2;}
}
$root=$argv[1];foreach(['PhorgeRecoveryRestoreService','PhorgeRecoverySessionProducerFence','PhorgeRecoveryNativeCaptureScope','PhorgeRecoveryConduitProducerFence']as$name)require$root.'/src/infrastructure/governance/recovery/'.$name.'.php';
$mode=$argv[2];$owner=new AphrontMySQLiDatabaseConnection('user');$writer=new AphrontMySQLiDatabaseConnection('conduit');$observer=new AphrontMySQLiDatabaseConnection('observer');$primary=new RuntimeException('primary');$events=[];$MDL=[];
if(in_array($mode,['lease-reserved','borrowed-reserved'])){
 if($mode==='lease-reserved'){$map=new WeakMap();$map[$owner]=new stdClass();(new ReflectionProperty(PhorgeRecoverySessionProducerFence::class,'leaseActive'))->setValue(null,$map);}
 else{(new ReflectionProperty(PhorgeRecoveryRestoreService::class,'borrowedOwner'))->setValue(null,['writer'=>$owner]);}
 $caught=null;try{PhorgeRecoveryConduitProducerFence::insert(new PhabricatorConduitToken(),'INSERT',static function(){throw new RuntimeException('unexpected INSERT');});}catch(Throwable$e){$caught=$e;}
 echo json_encode(['error'=>$caught?->getMessage(),'events'=>$events]);exit;
}
$mode='commit-unknown';$token=new PhabricatorConduitToken();try{PhorgeRecoveryConduitProducerFence::insert($token,'INSERT',static function()use($token){$token->id=3;return$token;});}catch(Throwable$e){if($e!==$primary)throw$e;}
if($argv[2]==='bookkeeping-zero'){$owner->depth=0;}
$events=[];$errors=[];$context=new PhorgeGovernanceNativeActorContext();$subject='PHID-USER-'.str_repeat('a',20);
$entries=[
 'SessionINSERT'=>static fn()=>PhorgeRecoverySessionProducerFence::begin(new PhabricatorAuthSession(),'INSERT'),
 'StoreOwner'=>static fn()=>(new ReflectionMethod(PhorgeRecoveryRestoreService::class,'withTransaction'))->invoke(null,$context,[],static function(){throw new RuntimeException('unexpected work');}),
 'UserPlanning'=>static fn()=>(new ReflectionMethod(PhorgeRecoveryRestoreService::class,'planning'))->invoke(null,'synthetic'),
 'OwnedLease'=>static fn()=>PhorgeRecoverySessionProducerFence::beginLease($observer,$subject),
 'BorrowedLease'=>static fn()=>PhorgeRecoverySessionProducerFence::beginBorrowed(new stdClass(),$owner,$observer),
 'BorrowedCurrentUserCapture'=>static fn()=>PhorgeRecoveryNativeCaptureScope::captureForRestoration($context,$owner,$subject)
];foreach($entries as$name=>$entry){try{$entry();$errors[$name]=null;}catch(Throwable$e){$errors[$name]=$e->getMessage();}}
echo json_encode(['errors'=>$errors,'events'=>$events,'retained'=>count((new ReflectionProperty(PhorgeRecoveryConduitProducerFence::class,'retained'))->getValue()),'physical_SQL'=>false]);
'''
  p=subprocess.run(['php','-r',code,str(ROOT),mode],capture_output=True,text=True,timeout=5);self.assertEqual(0,p.returncode,p.stderr);return json.loads(p.stdout)
 def test_unknown_Conduit_COMMIT_blocks_actual_selected_Session_User_and_borrowed_entries(self):
  for mode in ['active-owner','bookkeeping-zero']:
   d=self.invoke(mode);self.assertEqual(6,len(d['errors']));self.assertEqual({'recovery_conduit_writer_quarantined'},set(d['errors'].values()));self.assertEqual([],d['events']);self.assertEqual(1,d['retained']);self.assertFalse(d['physical_SQL'])
 def test_Conduit_reciprocally_rejects_actual_selected_Session_and_Store_reservations(self):
  for mode,error in [('lease-reserved','recovery_conduit_session_lease_writer_reserved'),('borrowed-reserved','recovery_conduit_borrowed_owner_reserved')]:
   d=self.invoke(mode);self.assertEqual(error,d['error']);self.assertEqual([],d['events'])
 def test_actual_storage_hooks_preserve_OFF_parent_and_refuse_retained_owner_after_flag_reset(self):
  for mode in ['clear','retained']:
   code=BOOT+r'''require $argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryConduitProducerFence.php';
class PhorgeGovernanceRecoveryGuard{static function enabled(){return false;}}
class NativeParent{protected function insertRecordIntoDatabase($mode){$GLOBALS['parent_calls'][]=$mode;return 'native-parent';}}
$mode=$argv[2];$events=[];$owner=new AphrontMySQLiDatabaseConnection('user');$writer=new AphrontMySQLiDatabaseConnection('conduit');$primary=new RuntimeException('primary');$MDL=[];$parent_calls=[];
if($mode==='retained'){$mode='commit-unknown';$token=new PhabricatorConduitToken();try{PhorgeRecoveryConduitProducerFence::insert($token,'INSERT',function()use($token){$token->id=3;return$token;});}catch(Throwable$e){if($e!==$primary)throw$e;}}
$events=[];$out=[];foreach(['auth/storage/PhabricatorAuthSession.php','conduit/storage/PhabricatorConduitToken.php']as$i=>$path){$source=file_get_contents($argv[1].'/src/applications/'.$path);$a=strpos($source,'  protected function insertRecordIntoDatabase');$b=strpos($source,"\n  }",$a);$method=substr($source,$a,$b-$a+4);$name='ActualHookBridge'.$i;eval('class '.$name.' extends NativeParent {'.$method.' public function invoke(){return $this->insertRecordIntoDatabase("INSERT");}}');try{$out[]=($object=new $name())->invoke();}catch(Throwable$e){$out[]=$e->getMessage();}}
echo json_encode(['results'=>$out,'parent_calls'=>$parent_calls,'events'=>$events]);'''
   p=subprocess.run(['php','-r',code,str(ROOT),mode],capture_output=True,text=True,timeout=5);self.assertEqual(0,p.returncode,p.stderr);d=json.loads(p.stdout)
   self.assertEqual(['native-parent']*2 if mode=='clear'else ['recovery_conduit_writer_quarantined']*2,d['results']);self.assertEqual(['INSERT']*2 if mode=='clear'else [],d['parent_calls']);self.assertEqual([],d['events'])

if __name__=='__main__':unittest.main()
