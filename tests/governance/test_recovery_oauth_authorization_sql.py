"""New fixed SQL phase source/offline controls; these are not SQL results."""
from pathlib import Path
import ast,copy,hashlib,json,subprocess,sys,tempfile,unittest
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_oauth_authorization_producer_v1 as gate
import test_native_organization as base
from test_recovery_oauth_code_threads import PHP as THREADS
class AuthorizationSQL(unittest.TestCase):
 def php(self,code,value=None,*args,disabled='mysqli_init,mysqli_connect,fsockopen,stream_socket_client'):
  p=subprocess.run(['php','-d','disable_functions='+disabled,'-r',code,str(ROOT),*args],input=None if value is None else json.dumps(value),capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
 def result(self,operation='INSERT'):
  return {'authorization_id_digest':'a'*64,'client_phid_digest':'b'*64,'subject_id_digest':'c'*64,'subject_phid_digest':'d'*64,'native_admin_preflight_before_owner':True,'new_modified':2,'old_modified':None if operation=='INSERT'else 1,'operation':operation,'profile':'borrowed-owner-native-oauth-authorization-v1','restoration_authorized':False,'status':'committed'}
 def identities(self):
  return [{'operation':op,'owner_end':end,'subject_id_digest':hashlib.sha256(('id-'+end).encode()).hexdigest(),'subject_phid_digest':hashlib.sha256(('phid-'+end).encode()).hexdigest(),'client_phid_digest':hashlib.sha256(('client-'+op+end).encode()).hexdigest(),'authorization_id_digest':hashlib.sha256(('row-'+op+end).encode()).hexdigest(),'old_modified':None if op=='INSERT'else 1,'new_modified':2,'owner_persistent_probe':'committed-probe'if end=='commit'else'rolled-back-original'}for op in ['INSERT','UPDATE']for end in ['commit','rollback']]
 def test_actual_twenty_obligations_raw56_and_strict30_receipt(self):
  self.assertEqual(len(gate.SOURCE_INPUTS),56);self.assertEqual(len(gate.SQL_obligations()),20);self.assertEqual(len(gate.obligations()),30)
  actual=self.php("require$argv[1].'/support/governance/recovery/oauth-authorization-cases.php';echo json_encode(governance_recovery_borrowed_oauth_authorization_obligations());")
  self.assertEqual(set(actual),gate.SQL_obligations());self.assertEqual(len(actual),20)
  data=base.template();labels=sorted(gate.obligations());data.update(batch='native-oauth-authorization-producer-owner',scope='owned-native-oauth-authorization-producer-owner-no-permit',checks=labels,check_count=30,native=gate.summary('a'*40,self.identities()),file_service_cleanup={'kind':'file','binary_sha256':'b'*64,'process_stopped':True,'private_storage_removed':True},successful_owned_file_service_startups=1,worker_cleanup={'private_directories_removed':4,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':4})
  self.assertEqual(gate.validate(data,source_revision='a'*40,file_digest='b'*64)['SQL_obligations'],20)
  for mutate in ['missing','wrongending','unchangedtime','booltime','childcleanup','authority','token']:
   bad=copy.deepcopy(data)
   if mutate=='missing':bad['checks'].pop();bad['check_count']=len(bad['checks'])
   elif mutate=='wrongending':bad['native']['source_identities'][0]['owner_end']='rollback'
   elif mutate=='unchangedtime':bad['native']['source_identities'][2]['old_modified']=2
   elif mutate=='booltime':bad['native']['source_identities'][2]['new_modified']=True
   elif mutate=='childcleanup':bad['worker_cleanup']['private_directories_remaining']=1
   elif mutate=='authority':bad['native']['all_13_sources_complete']=True
   else:bad['native']['source_identities'][0]['token']='CANARY_SECRET'
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
 def test_actual_result_decoder_sorted_keys_and_old_new_identity_guards(self):
  code="require$argv[1].'/support/governance/recovery/oauth-authorization-producer.php';$v=json_decode(stream_get_contents(STDIN),true);try{$r=governance_oauth_authorization_native_borrowed_oauth_authorization_result($v);echo json_encode(['accepted'=>true,'result'=>$r]);}catch(Throwable$e){echo json_encode(['accepted'=>false]);}"
  for op in ['INSERT','UPDATE']:
   v=self.result(op);self.assertEqual(self.php(code,dict(reversed(list(v.items())))),{'accepted':True,'result':v})
  for key,value in [('status','failed'),('operation','DELETE'),('old_modified',2),('new_modified',True),('client_phid_digest','not-a-digest'),('subject_id_digest',None),('restoration_authorized',True),('extra','CANARY_SECRET')]:
   v=self.result('UPDATE');v[key]=value;self.assertFalse(self.php(code,v)['accepted'])
 def test_actual_two_ID_observer_and_terminal_proof_still_require_both(self):
  code=THREADS.replace('oauth_code','oauth_authorization').replace('oauth-code','oauth-authorization').replace('recovery.oauth-authorization.borrowed-owner.issue','recovery.oauth-authorization.borrowed-owner.save')
  for mode,expected in [('good',True),('active-11',False),('active-22',False),('same',False),('missing',False)]:
   with tempfile.TemporaryDirectory()as tmp:
    p=Path(tmp).resolve();p.chmod(0o700);(p/'owner').write_text('a'*64);(p/'owner').chmod(0o600);m=p/'borrowed-preflight-ready.json';m.write_text(json.dumps({'status':'borrowed-preflight-committed','mysql_thread':11,'oauth_mysql_thread':22}));m.chmod(0o600)
    d=self.php(code,None,str(p),mode);self.assertEqual(d['accepted'],expected)
    if expected:self.assertEqual(d['observed'],[11,22]);self.assertEqual(d['terminal_checked'],['11','22'])
 def test_action_operation_guard_precedes_directory_or_process_IO(self):
  code=r'''require$argv[1].'/support/governance/native-organization/oauth-authorization-workers.php';class OwnedFixture{function assertOwned(){}}$a=json_decode(stream_get_contents(STDIN),true);try{governance_oauth_authorization_native_start_worker(new OwnedFixture(),$a);echo json_encode(['refused'=>false]);}catch(RuntimeException$e){echo json_encode(['refused'=>true,'registered'=>count($GLOBALS['governance_oauth_authorization_native_workers'])]);}'''
  valid={'action':'recovery.oauth-authorization.borrowed-owner.save','operation':'INSERT','subject':'PHID-USER-'+'a'*20,'token':'synthetic'}
  for key,value in [('action','recovery.oauth-code.borrowed-owner.issue'),('operation','REPLACE'),('token',''),('subject','other'),('extra',True)]:
   bad={**valid,key:value};self.assertEqual(self.php(code,bad,disabled='mkdir,proc_open,mysqli_init,mysqli_connect'),{'refused':True,'registered':0})
 def test_failed_exit_after_handle_consumption_still_retains_private_files(self):
  code=r'''require$argv[1].'/support/governance/native-organization/oauth-authorization-workers.php';function governance_recovery_borrowed_cleanup_ready(){return false;}$p=$argv[2];$s=lstat($p);$nonce=str_repeat('a',64);$GLOBALS['governance_recovery_oauth_authorization_cleanup_proved']=false;$GLOBALS['governance_oauth_authorization_native_workers']=[['private'=>$p,'dev'=>$s['dev'],'ino'=>$s['ino'],'nonce'=>$nonce,'owner_ready'=>true,'process'=>null,'cleaned'=>false]];$r=governance_oauth_authorization_native_cleanup_workers();echo json_encode(['cleanup'=>$r,'private_retained'=>is_file($p.'/owner')&&is_dir($p)]);'''
  with tempfile.TemporaryDirectory()as t:
   p=Path(t).resolve();p.chmod(0o700);(p/'owner').write_text('a'*64);(p/'owner').chmod(0o600);d=self.php(code,None,str(p));self.assertTrue(d['private_retained']);self.assertEqual(d['cleanup']['cleanup_failures'],1);self.assertEqual(d['cleanup']['private_directories_removed'],0)
 def test_real_owned_child_exit7_cannot_become_commit_or_cleanup_after_proc_close(self):
  code=r'''require$argv[1].'/support/governance/native-organization/oauth-authorization-workers.php';function governance_recovery_borrowed_cleanup_ready(){return false;}umask(0077);$p=$argv[2];$exit=(int)$argv[3];$v=json_decode(stream_get_contents(STDIN),true);$s=lstat($p);$nonce=str_repeat('a',64);$child=proc_open([PHP_BINARY,'-r','file_put_contents($argv[1],$argv[2]);exit((int)$argv[3]);',$p.'/result.json',json_encode($v),(string)$exit],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);if(!is_resource($child)){throw new RuntimeException('host_child_not_started');}$worker=['index'=>0,'private'=>$p,'dev'=>$s['dev'],'ino'=>$s['ino'],'nonce'=>$nonce,'owner_ready'=>true,'process'=>$child,'cleaned'=>false,'action'=>'recovery.oauth-authorization.borrowed-owner.save'];$GLOBALS['governance_oauth_authorization_native_workers']=[$worker];$handle=$worker;$accepted=false;$error=null;try{$accepted=governance_oauth_authorization_native_finish_worker($handle)===$v;}catch(RuntimeException$e){$error=$e->getMessage();}$GLOBALS['governance_recovery_oauth_authorization_cleanup_proved']=false;$cleanup=governance_oauth_authorization_native_cleanup_workers();echo json_encode(['accepted'=>$accepted,'error'=>$error,'handle_consumed'=>$GLOBALS['governance_oauth_authorization_native_workers'][0]['process']===null,'private_retained'=>is_file($p.'/owner')&&is_file($p.'/result.json'),'cleanup_removed'=>$cleanup['private_directories_removed'],'cleanup_failures'=>$cleanup['cleanup_failures']]);'''
  for exitcode,accepted in [(0,True),(7,False)]:
   with tempfile.TemporaryDirectory()as t:
    p=Path(t).resolve();p.chmod(0o700);(p/'owner').write_text('a'*64);(p/'owner').chmod(0o600);d=self.php(code,self.result(),str(p),str(exitcode));self.assertEqual(d['accepted'],accepted);self.assertTrue(d['handle_consumed']and d['private_retained']);self.assertEqual(d['cleanup_removed'],0);self.assertEqual(d['cleanup_failures'],1)
    if exitcode:self.assertEqual(d['error'],'oauth_authorization_worker_nonzero_or_unknown_exit')
 def test_fixed_entry_and_dead_action_branches_and_unknown_no_signal(self):
  worker=(ROOT/'support/governance/native-organization/oauth-authorization-worker.php').read_text();self.assertLess(worker.index("$candidate=$payload['action']??null"),worker.index('$proof=new mysqli'))
  registry=(ROOT/'support/governance/native-organization/oauth-authorization-workers.php').read_text();self.assertNotIn('proc_terminate(',registry)
  entry=(ROOT/'support/governance/native-organization/test-oauth-authorization-owner-mysql.php').read_text();phases=entry.split('$phases=',1)[1].split(';',1)[0];self.assertEqual(phases,"array('--oauth-authorization-producer-owner'=>'oauth-authorization-producer-owner')")
  producer=(ROOT/'support/governance/recovery/oauth-authorization-producer.php').read_text();self.assertIn('->authorizeClient(array())',producer);self.assertIn('->setScope(array())->save()',producer);self.assertIn('time()<=$old_modified',producer);self.assertIn('(int)$token->getDateModified()<=$old_modified',producer)
if __name__=='__main__':unittest.main()
