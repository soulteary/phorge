"""Actual PHP failure projection controls; no SQL/cache causality proof."""
from pathlib import Path
import subprocess,json,unittest
ROOT=Path(__file__).resolve().parents[2]
class Sampling(unittest.TestCase):
 def control(self):
  return dict(ps=[dict(request_creator='1',block_creator='1',request_lock_id='opaque:request',blocking_trx_id='opaque-blocker',schema_name='governance_native_'+'a'*16+'_conduit',table_name='conduit_token',index_name='PRIMARY',mode_name='S,REC_NOT_GAP',type_name='RECORD')],trx=[],registry={'child-writer_thread':1,'child-reader_thread':1,'parent-producer':2},namespace='governance_native_'+'a'*16,tables={'conduit-token':{'schema':'governance_native_'+'a'*16+'_conduit','table':'conduit_token'}})
 def php(self,v):
  code="require $argv[1].'/support/governance/native-organization/workers.php';require $argv[1].'/support/governance/recovery/api-concurrency-cases.php';require $argv[1].'/support/governance/recovery/api-wait-sampling.php';$v=json_decode(stream_get_contents(STDIN),true);try{echo json_encode(governance_recovery_api_sample_project($v['ps'],$v['trx'],$v['registry'],$v['namespace'],$v['tables']));}catch(RuntimeException$e){echo '{\"refused\":true}';}"
  p=subprocess.run(['php','-r',code,str(ROOT)],input=json.dumps(v),capture_output=True,text=True,check=True);self.assertEqual('',p.stderr);self.assertNotIn('SENTINEL',p.stdout);self.assertNotIn('opaque:',p.stdout);return json.loads(p.stdout)
 def test_live_ps_reference_without_is_match_remains_unproved(self):
  out=self.php(self.control());row=out['ps_waits'][0]
  self.assertEqual(['child-reader_thread','child-writer_thread'],row['request_event_creator_roles'])
  self.assertFalse(row['creator_thread_I_S_row_present']);self.assertFalse(row['creator_I_S_requested_id_equals_P_S']);self.assertFalse(out['ownership_proved'])
 def test_separate_is_actual_rows_expose_only_comparison_bools(self):
  v=self.control();v['trx']=[dict(process_id='1',trx_id='opaque-reader',trx_state='LOCK WAIT',requested_id='opaque:request'),dict(process_id='2',trx_id='opaque-blocker',trx_state='RUNNING',requested_id=None)]
  out=self.php(v);row=out['ps_waits'][0];self.assertTrue(row['creator_I_S_requested_id_equals_P_S']);self.assertTrue(row['producer_I_S_trx_id_equals_P_S_blocking_id']);self.assertFalse(row['event_creator_is_transaction_owner_proved'])
  v['trx'][0]['requested_id']='different';self.assertFalse(self.php(v)['ps_waits'][0]['creator_I_S_requested_id_equals_P_S'])
 def test_budget_unknown_fields_and_roles_refused(self):
  v=self.control();v['ps']*=17;self.assertTrue(self.php(v)['refused'])
  v=self.control();v['ps'][0]['SQL_TEXT']='SENTINEL';self.assertTrue(self.php(v)['refused'])
  v=self.control();v['registry']['SENTINEL']=99;self.assertTrue(self.php(v)['refused'])
 def test_actual_monotonic_sample_schedule_and_deadline(self):
  code="require $argv[1].'/support/governance/recovery/api-concurrency-cases.php';$out=[];foreach([[1000000000,0,6000000000],[1249999999,1000000000,6000000000],[1250000000,1000000000,6000000000],[6000000000,5000000000,6000000000],[5999999999,5900000000,6000000000]]as$args){$out[]=governance_recovery_api_sample_due(...$args);}$last=0;$times=[];for($n=1000000000;$n<=6000000000;$n+=20000000){if(governance_recovery_api_sample_due($n,$last,6000000000)){$times[]=$n;$last=$n;}}$ok=true;for($i=1;$i<count($times);$i++){$ok=$ok&&$times[$i]-$times[$i-1]>=250000000;}$out[]=$ok&&count($times)<=20&&max($times)<6000000000;try{governance_recovery_api_sample_due(true,0,6000000000);}catch(RuntimeException$e){$out[]=true;}echo json_encode($out);"
  p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,check=True);self.assertEqual('',p.stderr);self.assertEqual([True,False,True,False,False,True,True],json.loads(p.stdout))
 def test_source_success_matcher_exact_parent_and_gap_has_no_is_read(self):
  source=(ROOT/'support/governance/recovery/api-concurrency-cases.php').read_text()
  old=subprocess.check_output(['git','-C',str(ROOT),'show','9cfadecf5903a2a43a39fd1217ed5ff99bd92f0b:support/governance/recovery/api-concurrency-cases.php'],text=True)
  def matcher(s):
   a=s.index("    $sql='SELECT COUNT(*) n FROM performance_schema.data_lock_waits w '")
   b=s.index("l.LOCK_STATUS='WAITING'\";",a)+len("l.LOCK_STATUS='WAITING'\";")
   return s[a:b]
  self.assertEqual(matcher(old),matcher(source))
  self.assertIn('$deadline=hrtime(true)+5000000000',source)
  self.assertIn('$last_sample_end<=$deadline',source)
  sample=(ROOT/'support/governance/recovery/api-wait-sampling.php').read_text();self.assertIn('usleep(250000)',sample);self.assertNotIn('JOIN information_schema.INNODB_TRX',sample)
  workers=(ROOT/'support/governance/native-organization/workers.php').read_text();self.assertNotIn("'waiting_on_owned_conduit'",workers);self.assertIn('creator_wait_reference_to_owned_conduit',workers)
if __name__=='__main__':unittest.main()
