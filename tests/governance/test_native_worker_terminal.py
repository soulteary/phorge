"""Actual shared finish_worker, owned real PHP children, no native app/SQL/VM."""
from n05_legacy_audit_test_view import historical_tooling_source
from pathlib import Path
import ast,hashlib,json,subprocess,sys,tempfile,unittest
ROOT=Path(__file__).resolve().parents[2]
import sys
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_file_parent_policy as driver_admission
PROGRAM=r'''
require $argv[1].'/support/governance/native-organization/workers.php';
$path=$argv[2];$mode=$argv[3];$nonce=str_repeat('a',64);$stat=lstat($path);
$handle=['index'=>0,'private'=>$path,'dev'=>$stat['dev'],'ino'=>$stat['ino'],'nonce'=>$nonce,'cleaned'=>false,'action'=>'org.members.edit','process'=>null];
$code=match($mode){'zero'=>'exit(0);','seven'=>'exit(7);','fatal'=>'undefined_native_worker_test_function();',default=>'file_put_contents($argv[1],"ready");usleep(30000000);'};
$process=proc_open([PHP_BINARY,'-r',$code,$path.'/child-ready'],[0=>['file','/dev/null','r'],1=>['file',$path.'/stdout.private','w'],2=>['file',$path.'/stderr.private','w']],$pipes);
$handle['process']=$process;$GLOBALS['governance_native_workers']=[$handle];
$accepted=false;$reason=null;$timeout_still_live=null;$started=microtime(true);
try{
 if(in_array($mode,['signal','timeout'],true)){
  $deadline=microtime(true)+3;while(!is_file($path.'/child-ready')&&microtime(true)<$deadline){usleep(1000);}if(!is_file($path.'/child-ready'))throw new RuntimeException('child_not_ready');
 }
 if($mode==='signal'&&!proc_terminate($process,15))throw new RuntimeException('owned_signal_failed');
 $result=governance_native_finish_worker($handle);$accepted=true;
}catch(RuntimeException $error){$reason=$error->getMessage();if($mode==='timeout'&&is_resource($process))$timeout_still_live=proc_get_status($process)['running'];}
finally{
 if(is_resource($process)){
  if(proc_get_status($process)['running'])proc_terminate($process,15);
  $deadline=microtime(true)+2;while(proc_get_status($process)['running']&&microtime(true)<$deadline)usleep(1000);
  if(proc_get_status($process)['running'])proc_terminate($process,9);
  proc_close($process);
 }
}
echo json_encode(['accepted'=>$accepted,'reason'=>$reason,'seconds'=>microtime(true)-$started,'result_file_retained'=>is_file($path.'/result.json'),'timeout_handle_still_live_before_owned_cleanup'=>$timeout_still_live,'native_SQL_executed'=>false]);
'''
class WorkerTerminal(unittest.TestCase):
 def invoke(self,mode):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as temp:
   d=Path(temp);d.chmod(0o700);(d/'owner').write_text('a'*64);(d/'owner').chmod(0o600);(d/'result.json').write_text(json.dumps({'status':'committed','replayed':False}));(d/'result.json').chmod(0o600)
   p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect','-r',PROGRAM,str(ROOT),str(d),mode],capture_output=True,text=True,timeout=22)
   self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_actual_exit0_valid_native_result_accepts(self):
  d=self.invoke('zero');self.assertTrue(d['accepted']);self.assertTrue(d['result_file_retained'])
 def test_actual_exit7_fatal_and_owned_SIGTERM_cannot_be_saved_by_committed_JSON(self):
  for mode in ['seven','fatal','signal']:
   d=self.invoke(mode);self.assertFalse(d['accepted'],mode);self.assertEqual(d['reason'],'native_worker_nonzero_or_unknown_terminal');self.assertTrue(d['result_file_retained'])
 def test_actual_full_fifteen_second_timeout_retains_owned_live_handle(self):
  d=self.invoke('timeout');self.assertFalse(d['accepted']);self.assertEqual(d['reason'],'native_worker_timeout');self.assertGreaterEqual(d['seconds'],15);self.assertTrue(d['timeout_handle_still_live_before_owned_cleanup']);self.assertTrue(d['result_file_retained'])
 def test_exact_terminal_delta_inverse_refuses_other_changes_after_repin(self):
  sys.path.insert(0,str(ROOT/'scripts/governance'));import recovery_worker_terminal_delta as delta
  raw=(ROOT/delta.PATH).read_bytes();old=subprocess.check_output(['git','-C',str(ROOT),'show','fc0369af9d84c4a5f23a7080528e70f1ce84c4e7:'+delta.PATH]);self.assertEqual(delta.reduce_current(delta.PATH,raw),old)
  prior=delta.CURRENT_SHA256
  try:
   bad=raw+b'// unrelated bytes\n';delta.CURRENT_SHA256=hashlib.sha256(bad).hexdigest()
   with self.assertRaises(ValueError):delta.reduce_current(delta.PATH,bad)
  finally:delta.CURRENT_SHA256=prior
 def test_historical_maps_remain_exact_and_current_SQL_obligation_views_accept_only_narrow_delta(self):
  sys.path.insert(0,str(ROOT/'scripts/governance'))
  for module in ['check_recovery_session_lease','check_recovery_borrowed_session','check_recovery_conduit_producer']:
   gate=__import__(module);self.assertTrue(gate.SQL_obligations())
   source='scripts/governance/'+module+'.py'
   def pins(raw):return next(ast.literal_eval(n.value)for n in ast.parse(raw).body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='SOURCE_INPUTS'for t in n.targets))
   current=pins((ROOT/source).read_bytes())
   import recovery_conduit_source_delta as calendar
   driver='support/governance/native-organization/test-native-organization-mysql.php'
   # Preserve the original map while proving the new raw fixture's exact inverse.
   self.assertEqual(current[driver],hashlib.sha256(calendar.reduce_policy_cleanup_driver(driver_admission.admitted_native_driver_source(ROOT,(ROOT/driver).read_bytes()))).hexdigest())
   historical=dict(current);historical[driver]=hashlib.sha256(calendar.reduce_calendar_wire_union(driver_admission.admitted_native_driver_source(ROOT,(ROOT/driver).read_bytes()))).hexdigest()
   import recovery_assessment_source_delta as assessment
   compared=dict(pins(historical_tooling_source(source)))
   for name,pin in assessment.SOURCE_INPUTS.items():
    if name in compared:
     self.assertEqual(compared[name],pin);self.assertEqual(hashlib.sha256(assessment.reduce_current(name,(ROOT/name).read_bytes())).hexdigest(),assessment.BASE_PINS[name]);compared[name]=assessment.BASE_PINS[name]
   self.assertEqual(compared,pins(subprocess.check_output(['git','-C',str(ROOT),'show','732dd79049f017becc8b05d1c3fd0852ed00ff44:'+source])))
   self.assertEqual(historical[driver],pins(subprocess.check_output(['git','-C',str(ROOT),'show','fc0369af9d84c4a5f23a7080528e70f1ce84c4e7:'+source]))[driver])
class InnoDBObserverPollSpacing(unittest.TestCase):
 """Model MySQL8.4.13 trx0i_s.cc last_read, not transaction permission."""
 @staticmethod
 def poll(interval, cached, actual, expected, process_count, external_reads=False):
  # can_cache_be_updated uses strictly >100ms since LAST READ; each read resets.
  last_read=0;now=0;reads=0;deadline=3000000
  while True:
   if external_reads and now>0:last_read=now-10000
   if now-last_read>100000:cached=actual
   last_read=now;reads+=1
   observed=(process_count,cached)
   if observed==expected:return True,now,reads
   now+=interval
   if now>=deadline:return False,now,reads
 def test_final_absence_old20ms_self_starves_but200ms_refreshes_within_same3s(self):
  old=self.poll(20000,1,0,(0,0),0)
  new=self.poll(200000,1,0,(0,0),0)
  self.assertEqual(old,(False,3000000,150))
  self.assertEqual(new,(True,200000,2))
 def test_live_transaction_old20ms_self_starves_but200ms_refreshes(self):
  self.assertEqual(self.poll(20000,0,1,(1,1),1),(False,3000000,150))
  self.assertEqual(self.poll(200000,0,1,(1,1),1),(True,200000,2))
 def test_exact100ms_is_not_idle_long_enough(self):
  self.assertEqual(self.poll(100000,1,0,(0,0),0),(False,3000000,30))
 def test_live_or_still_open_transaction_is_never_accepted_as_absent(self):
  for process_count,actual in ((1,1),(1,0),(0,1)):
   with self.subTest(process_count=process_count,transaction_count=actual):
    passed,elapsed,reads=self.poll(200000,1,actual,(0,0),process_count)
    self.assertFalse(passed);self.assertEqual(elapsed,3000000)
    self.assertEqual(reads,15)
 def test_other_readers_can_keep_cache_stale_and_deadline_still_refuses(self):
  self.assertEqual(self.poll(200000,1,0,(0,0),0,external_reads=True),
   (False,3000000,15))
 def test_actual_fixture_uses200ms_only_for_both_information_schema_observers(self):
  import re
  path=ROOT/'support/governance/native-execution/project-policy-nested-process.php'
  source=path.read_text()
  live=re.search(r'do\{if\(\$observe\(\)===array\(1,1\)\).*?usleep\((\d+)\);\}while\(microtime\(true\)<\$until\);',source)
  final=re.search(r'do\{\$last_observed=\$observe\(\);if\(\$last_observed===array\(0,0\)\).*?usleep\((\d+)\);\}while\(microtime\(true\)<\$until\);',source)
  self.assertIsNotNone(live);self.assertIsNotNone(final)
  self.assertEqual(int(live.group(1)),200000)
  self.assertEqual(int(final.group(1)),200000)
  self.assertEqual(source.count('$until=microtime(true)+3;'),2)
  self.assertIn('$until=microtime(true)+8;',source)
  self.assertGreaterEqual(source.count('usleep(20000)'),2)
  self.assertIn('$final_snapshot!==$before',source)
  self.assertIn("$GLOBALS['governance_policy_nested_cleanup_permitted']=true;",source)

if __name__=='__main__':unittest.main()
