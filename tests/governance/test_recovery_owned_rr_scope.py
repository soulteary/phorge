"""Host-only exact PHP pure decoder controls; no fake physical writer proof."""
from pathlib import Path
import subprocess,json,unittest
ROOT=Path(__file__).resolve().parents[2]
class RR(unittest.TestCase):
 def test_exact_production_PHP_decoder_methods(self):
  # Extract only two database-free methods; do not substitute a native writer.
  text=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryOwnedRRScope.php').read_text()
  start=text.index('  public static function isolationPlan');end=text.index('  private static function depth')
  methods=text[start:end]
  code='class Decoder {'+methods+"}\n"+"$v=['thread'=>'10','event'=>'20'];$r=['THREAD_ID'=>'10','EVENT_ID'=>'20','STATE'=>'ACTIVE','END_EVENT_ID'=>null,'ISOLATION_LEVEL'=>'REPEATABLE READ'];$out=[Decoder::isolationPlan('REPEATABLE-READ'),Decoder::classifyEvent([$r],$v)];$r['EVENT_ID']='21';$out[]=Decoder::classifyEvent([$r],$v);$r['EVENT_ID']='20';$r['STATE']='COMMITTED';$r['END_EVENT_ID']='30';$out[]=Decoder::classifyEvent([$r],$v);$r['STATE']='ROLLED BACK';$out[]=Decoder::classifyEvent([$r],$v);$out[]=Decoder::classifyEvent([],$v);echo json_encode($out);"
  p=subprocess.run(['php','-r',code],capture_output=True,text=True,check=True);self.assertEqual('',p.stderr)
  self.assertEqual(['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ','active','unknown','committed-observed','rollback-observed','unknown'],json.loads(p.stdout))
 def test_real_scope_old_terminal_owner_cannot_clear_new_token(self):
  # Load the actual class, supplying only its empty object base. No native
  # connection/query/physical identity is mocked or invoked in this control.
  code="class Phobject {} require $argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryOwnedRRScope.php';$c=new ReflectionClass('PhorgeRecoveryOwnedRRScope');$p=$c->getProperty('active');$writer=new stdClass();$a=new stdClass();$b=new stdClass();$old=$c->newInstanceWithoutConstructor();foreach(['writer'=>$writer,'ownerToken'=>$a,'state'=>'committed']as$k=>$v){$c->getProperty($k)->setValue($old,$v);}$owners=new WeakMap();$owners[$writer]=$b;$p->setValue(null,$owners);$out=[];foreach(['commit','rollback','getFacts']as$m){try{$old->$m();$out[]=false;}catch(RuntimeException$e){$out[]=$e->getMessage()==='recovery_rr_scope_not_active'&&$owners[$writer]===$b;}}$old->abort();$out[]=$owners[$writer]===$b;$c->getProperty('state')->setValue($old,'invalid');$facts=$old->abort();$out[]=$owners[$writer]===$b&&$facts['failure_physical_state']==='ownership-changed'&&$facts['cleanup_proved']===false;$owners[$writer]=$a;$c->getMethod('releaseOwnedWriter')->invoke($old);$out[]=!isset($owners[$writer]);echo json_encode($out);"
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,check=True);self.assertEqual('',p.stderr);self.assertEqual([True]*6,json.loads(p.stdout))
 def test_no_route_enable_or_session_isolation_mutation(self):
  s=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryOwnedRRScope.php').read_text()
  for forbidden in ('SET SESSION','SET autocommit','setIsDisabled(','setIsAdmin(','GRANT ','REVOKE ','LOCK TABLES','closeAllConnections('):self.assertNotIn(forbidden,s)
  self.assertIn("$writer->isInsideTransaction()",s);self.assertIn("self::depth($this->writer)===0",s)
  self.assertIn("queryfx($this->writer,'ROLLBACK')",s);self.assertIn("$this->failureState==='active'",s)
  self.assertIn("$this->failureState==='rollback-observed'||$this->failureState==='committed-observed'",s)
 def test_owned_begin_order_and_default_off(self):
  s=(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryOwnedRRScope.php').read_text()
  self.assertLess(s.index("queryfx($writer,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')"),s.index('$writer->openTransaction()'))
  options=(ROOT/'src/infrastructure/governance/recovery/PhorgeGovernanceRecoveryConfigOptions.php').read_text()
  self.assertIn("'governance.account-recovery.owned-rr-scope.enabled','bool',false",options)
if __name__=='__main__':unittest.main()
