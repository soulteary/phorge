"""Actual observer/owner core with explicit SQL callbacks and owned marker files.
No database, network or physical lock proof is claimed by these controls.
"""
from pathlib import Path
import json, subprocess, tempfile, unittest, sys
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
PHP=r'''
class Phobject{} class AphrontDatabaseConnection{}
require $argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php';
require $argv[1].'/support/governance/recovery/oauth-access-producer.php';
require $argv[1].'/support/governance/recovery/session-lease-cases.php';
$dir=$argv[2];$mode=$argv[3];$seen=[];$ended=[];$stat=lstat($dir);
$worker=['index'=>0,'private'=>$dir,'dev'=>$stat['dev'],'ino'=>$stat['ino'],'nonce'=>str_repeat('a',64),'action'=>'recovery.oauth-access.borrowed-owner.issue','cleaned'=>false,'process'=>null,'mysql_thread'=>11,'oauth_mysql_thread'=>22];
if($mode==='missing')unset($worker['oauth_mysql_thread']);
if($mode==='same')$worker['oauth_mysql_thread']=11;
if($mode==='numeric')$worker['oauth_mysql_thread']='22';
$GLOBALS['governance_oauth_native_workers']=[$worker];
function queryfx_all($observer,$sql,...$args){
 if(str_contains($sql,'setup_consumers'))return array_map(static fn($n)=>['NAME'=>$n,'ENABLED'=>'YES'],['global_instrumentation','thread_instrumentation','events_transactions_current']);
 if(str_contains($sql,'events_transactions_current')){$id=$args[0];$GLOBALS['seen'][]=$id;$bad=$GLOBALS['mode']==='active-'.$id;return[['THREAD_ID'=>(string)($id*10),'EVENT_ID'=>'2','STATE'=>$bad?'ACTIVE':'COMMITTED','END_EVENT_ID'=>$bad?null:'3','ISOLATION_LEVEL'=>'READ COMMITTED']];}
 if(str_contains($sql,'performance_schema.threads')){$GLOBALS['ended'][]=$args[0];return[];}
 throw new RuntimeException('unexpected_query');
}
function queryfx_one($observer,$sql,...$args){
 if(str_contains($sql,'setup_instruments'))return['ENABLED'=>'YES','TIMED'=>'YES'];
 if(str_contains($sql,'INSTRUMENTED'))return['INSTRUMENTED'=>'YES'];
 if(str_contains($sql,'active_transactions'))return['active_transactions'=>'0','record_locks'=>'0'];
 throw new RuntimeException('unexpected_query');
}
$accepted=false;$error=null;
try{governance_oauth_native_preflight_thread_ids($worker);$accepted=governance_oauth_native_wait_borrowed_oauth_access_preflight($worker,new AphrontDatabaseConnection());if($accepted)governance_oauth_native_threads_ended(new AphrontDatabaseConnection(),[$worker]);}catch(Throwable $e){$error=get_class($e);}
echo json_encode(['accepted'=>$accepted,'observed'=>$seen,'terminal_checked'=>$ended,'error'=>$error]);
'''
class ActualTwoPhysicalThreads(unittest.TestCase):
 def invoke(self,mode):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   d=Path(tmp);d.chmod(0o700);(d/'owner').write_text('a'*64);(d/'owner').chmod(0o600)
   marker={'status':'borrowed-preflight-committed','mysql_thread':11,'oauth_mysql_thread':22}
   (d/'borrowed-preflight-ready.json').write_text(json.dumps(marker));(d/'borrowed-preflight-ready.json').chmod(0o600)
   p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,fsockopen,stream_socket_client','-r',PHP,str(ROOT),str(d),mode],capture_output=True,text=True,timeout=5)
   self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
 def test_actual_marker_observer_and_terminal_queries_use_both_distinct_measured_ids(self):
  d=self.invoke('good');self.assertTrue(d['accepted']);self.assertEqual(d['observed'],[11,22]);self.assertEqual(d['terminal_checked'],['11','22'])
 def test_either_thread_active_or_malformed_refuses(self):
  for mode in ['active-11','active-22','missing','same','numeric']:
   d=self.invoke(mode);self.assertFalse(d['accepted'],mode);self.assertIsNotNone(d['error']);self.assertEqual([],d['terminal_checked'])
 def test_actual_core_User_PRIMARY_precedes_OAuth_writer_begin(self):
  import test_recovery_oauth_access_source as core
  old="if(str_contains($q,'SELECT phid'))return ['phid'=>'PHID-USER-'.str_repeat('a',20)];"
  new="if(str_contains($q,'SELECT phid')){$GLOBALS['events'][]=$c->name.'.PRIMARY';return ['phid'=>'PHID-USER-'.str_repeat('a',20)];}"
  self.assertIn(old,core.BOOT)
  with patch.object(core,'BOOT',core.BOOT.replace(old,new)):
   d=core.OAuthProducer().run_core('success')
  self.assertFalse(d['failed']);events=d['events'];self.assertLess(events.index('user.PRIMARY'),events.index('conduit.begin'));self.assertLess(events.index('conduit.begin'),events.index('native-insert'))
if __name__=='__main__':unittest.main()
