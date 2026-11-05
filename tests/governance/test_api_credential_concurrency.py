"""Actual PHP decoder tests only; not native authentication or SQL evidence."""
import json
import importlib.util
import copy
import tempfile
import hashlib
import sys
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
WORKERS = ROOT / 'support/governance/native-organization/workers.php'

class CredentialConcurrencyDecoderTests(unittest.TestCase):
    def validate(self, action, value):
        script = '''require $argv[1]; $x=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
try { $r=($argv[2]==='api')?governance_native_api_concurrency_result($x):governance_native_oauth_concurrency_result($x);
echo json_encode(array('accepted'=>true,'value'=>$r),JSON_THROW_ON_ERROR); }
catch(RuntimeException $e) { echo json_encode(array('accepted'=>false),JSON_THROW_ON_ERROR); }'''
        result = subprocess.run(['php', '-r', script, str(WORKERS), action], input=json.dumps(value),
                                text=True, capture_output=True, check=True)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)['accepted']

    def test_actual_wait_projection_keeps_multiple_roles_and_hides_values(self):
        cases=ROOT/'support/governance/recovery/api-concurrency-cases.php'
        script="""require $argv[1];$v=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
try{$out=governance_recovery_api_wait_projection($v['rows'],$v['roles'],21,22,
 'governance_native_aaaaaaaaaaaaaaaa',$v['tables']);echo json_encode(array('accepted'=>true,'value'=>$out),JSON_THROW_ON_ERROR);}
catch(RuntimeException $e){echo '{"accepted":false}';}"""
        namespace='governance_native_'+'a'*16
        tables={'user':{'schema':namespace+'_user','table':'user'},
                'conduit-token':{'schema':namespace+'_conduit','table':'conduit_token'}}
        row=dict(requester='17',blocker='22',blocker_event_creator='22',schema_name=namespace+'_user',table_name='user',
                 index_name='PRIMARY',mode_name='S,REC_NOT_GAP',type_name='RECORD',
                 LOCK_DATA='PRIVATE_SENTINEL',query='PRIVATE_SENTINEL')
        def invoke(rows):
            value={'rows':rows,'roles':{'17':['writer_thread','reader_thread','credential_thread']},'tables':tables}
            result=subprocess.run(['php','-r',script,str(cases)],input=json.dumps(value),text=True,capture_output=True,check=True)
            self.assertEqual(result.stderr,'');self.assertNotIn('PRIVATE_SENTINEL',result.stdout)
            return json.loads(result.stdout)
        result=invoke([row]);self.assertTrue(result['accepted']);value=result['value'][0]
        self.assertEqual(value['requester_roles'],['writer_thread','reader_thread','credential_thread'])
        self.assertTrue(value['blocker_matches_parent_actor_connection'])
        self.assertFalse(value['blocker_matches_producer_connection'])
        self.assertEqual(value['object_class'],'user');self.assertEqual(value['index_class'],'primary')
        hidden=dict(row,schema_name=namespace+'_other',table_name='PRIVATE_SENTINEL',index_name='PRIVATE_SENTINEL')
        result=invoke([hidden]);self.assertEqual(result['value'][0]['object_class'],'namespace-other')
        foreign=dict(row,schema_name='PRIVATE_SENTINEL',table_name='user')
        self.assertEqual(invoke([foreign])['value'][0]['object_class'],'foreign-other')
        self.assertFalse(invoke([row]*17)['accepted'])
        self.assertFalse(invoke([dict(row,requester='99')])['accepted'])

    def test_failure_registry_multiple_roles_and_unknown_blocker_are_exact(self):
        cases=ROOT/'support/governance/recovery/api-concurrency-cases.php'
        script="""require $argv[1];$v=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
try{$r=array();foreach($v['pairs']as$p){governance_recovery_api_registry_add($r,$p[0],$p[1]);}
 echo json_encode(array('accepted'=>true,'roles'=>governance_recovery_api_registry_matches($r,$v['blocker']),
 'registered'=>governance_recovery_api_registry_role_names($r)),JSON_THROW_ON_ERROR);}
catch(RuntimeException $e){echo '{"accepted":false}';}"""
        def invoke(pairs,blocker=17):
            run=subprocess.run(['php','-r',script,str(cases)],input=json.dumps(dict(pairs=pairs,blocker=blocker)),text=True,capture_output=True,check=True)
            self.assertEqual(run.stderr,'');self.assertNotIn('PRIVATE_SENTINEL',run.stdout)
            return json.loads(run.stdout)
        pairs=[['parent-producer',17],['parent-cached-conduit-w',17],['child-credential_thread',18]]
        self.assertEqual(invoke(pairs)['roles'],['parent-cached-conduit-w','parent-producer'])
        self.assertEqual(invoke(pairs,18)['roles'],['child-credential_thread'])
        self.assertEqual(invoke(pairs,99)['roles'],[])
        for bad in ([['PRIVATE_SENTINEL',17]],[['parent-producer','17']],[['parent-producer',True]],
                    [['parent-producer',0]],[['parent-producer',17],['parent-producer',18]]):
            self.assertFalse(invoke(bad)['accepted'])

    def test_wait_projection_registry_is_failure_metadata_not_evidence(self):
        cases=ROOT/'support/governance/recovery/api-concurrency-cases.php'
        script="""require $argv[1];$v=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$r=governance_recovery_api_wait_projection($v['rows'],array(17=>array('writer_thread')),21,22,
 'governance_native_aaaaaaaaaaaaaaaa',array(),array('parent-setup'=>23,'child-credential_thread'=>23));echo json_encode($r);"""
        row=dict(requester='17',blocker='23',blocker_event_creator='17',schema_name='PRIVATE_SENTINEL',table_name='PRIVATE_SENTINEL',index_name='PRIMARY',mode_name='S',type_name='RECORD')
        run=subprocess.run(['php','-r',script,str(cases)],input=json.dumps(dict(rows=[row])),text=True,capture_output=True,check=True)
        self.assertEqual(run.stderr,'');self.assertNotIn('PRIVATE_SENTINEL',run.stdout)
        value=json.loads(run.stdout)[0]
        self.assertEqual(value['blocker_registry_roles'],['child-credential_thread','parent-setup'])
        self.assertFalse(value['blocker_matches_producer_connection'])
        self.assertFalse(value['blocker_matches_parent_actor_connection'])
        self.assertNotIn('state',value)

    def test_child_credential_role_uses_real_conduit_connection(self):
        worker=(ROOT/'support/governance/native-organization/native-worker.php').read_text()
        self.assertIn("$credential_conn=(new PhabricatorConduitToken())->establishConnection('w')",worker)
        self.assertIn("queryfx_one($credential_conn,'SELECT CONNECTION_ID() n')",worker)
        self.assertIn("$api_phase('before-current-read',array('credential_thread'=>$credential_thread))",worker)
        self.assertNotIn("$api_phase('context-ready',array('credential_thread'=>$thread))",worker)
        source=(ROOT/'support/governance/recovery/api-concurrency-cases.php').read_text()
        self.assertIn("new ReflectionMethod(LiskDAO::class,'getEstablishedConnection')",source)
        self.assertIn("$setup=$fixture->getAdmin()",source)
        self.assertIn("'parent-control',(int)$observer->thread_id",source)

    def test_implicit_lock_creator_is_not_the_blocking_transaction_owner(self):
        cases=ROOT/'support/governance/recovery/api-concurrency-cases.php'
        script="""require $argv[1];$v=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
try{$r=governance_recovery_api_wait_projection($v['rows'],array(17=>array('writer_thread','reader_thread')),21,22,
 'governance_native_aaaaaaaaaaaaaaaa',array(),array('parent-producer'=>21,'child-writer_thread'=>17,'child-reader_thread'=>17));
 echo json_encode(array('accepted'=>true,'value'=>$r));}catch(RuntimeException $e){echo '{"accepted":false}';}"""
        row=dict(requester='17',blocker='21',blocker_event_creator='17',schema_name='PRIVATE_SENTINEL',table_name='PRIVATE_SENTINEL',index_name='PRIMARY',mode_name='S',type_name='RECORD')
        def run(value):
            p=subprocess.run(['php','-r',script,str(cases)],input=json.dumps(dict(rows=[value])),text=True,capture_output=True,check=True)
            self.assertEqual('',p.stderr);self.assertNotIn('PRIVATE_SENTINEL',p.stdout);return json.loads(p.stdout)
        value=run(row)['value'][0]
        self.assertTrue(value['blocking_transaction_owner_proved']);self.assertTrue(value['blocker_matches_producer_connection'])
        self.assertEqual(['parent-producer'],value['blocker_registry_roles'])
        self.assertEqual(['child-reader_thread','child-writer_thread'],value['blocker_event_creator_registry_roles'])
        unknown=run(dict(row,blocker=None))['value'][0]
        self.assertFalse(unknown['blocking_transaction_owner_proved']);self.assertFalse(unknown['blocker_matches_producer_connection'])
        self.assertEqual([],unknown['blocker_registry_roles'])
        self.assertFalse(run(dict(row,blocker=True))['accepted'])
        self.assertFalse(run(dict(row,blocker='9223372036854775808'))['accepted'])

    def test_observer_uses_transaction_owner_and_full_engine_identity(self):
        s=(ROOT/'support/governance/recovery/api-concurrency-cases.php').read_text()
        begin=s.index("    $sql='SELECT COUNT(*) n FROM performance_schema.data_lock_waits w '")
        end=s.index("    $query=$observer->query($sql)",begin);query=s[begin:end]
        self.assertIn('r.TRX_REQUESTED_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID',query)
        self.assertIn('b.TRX_ID=CAST(w.BLOCKING_ENGINE_TRANSACTION_ID AS CHAR)',query)
        self.assertIn('l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE=w.ENGINE',query)
        self.assertIn("w.ENGINE='INNODB' AND r.TRX_STATE='LOCK WAIT'",query)
        self.assertIn('r.TRX_MYSQL_THREAD_ID=',query);self.assertIn('b.TRX_MYSQL_THREAD_ID=',query)
        self.assertNotIn('w.BLOCKING_THREAD_ID',query)
        self.assertNotIn('w.REQUESTING_ENGINE_TRANSACTION_ID',query)
        self.assertIn("$deadline=hrtime(true)+5000000000",s)

    def test_observer_timeout_stays_failure_after_diagnostic_fault(self):
        source=(ROOT/'support/governance/recovery/api-concurrency-cases.php').read_text()
        boundary=source.index("  try{$GLOBALS['governance_api_concurrency_child_failure']=governance_recovery_api_wait_diagnostic")
        end=source.index("/** Actual requester/blocker",boundary)
        branch=source[boundary:end]
        self.assertIn('catch(Throwable$diagnostic_error)',branch)
        self.assertIn("throw new RuntimeException('recovery_api_observation_timeout')",branch)
        self.assertNotIn('return ',branch)
        self.assertIn('$deadline=hrtime(true)+5000000000',source)
        self.assertIn("if($count>0){return array('state'=>'blocked-by-owned-writer'",source[:boundary])

    def test_startup_phase_validator_rejects_owner_unknown_and_noninteger_thread(self):
        script = """require $argv[1];$x=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
try { governance_native_api_startup_phase($x,str_repeat('a',64));echo '{"accepted":true}'; }
catch(RuntimeException $e) { echo '{"accepted":false}'; }"""
        good=dict(owner='a'*64,phase='before-current-read',proof_thread=1,writer_thread=2,reader_thread=3,credential_thread=2)
        def run(value):
            result=subprocess.run(['php','-r',script,str(WORKERS)],input=json.dumps(value),text=True,capture_output=True,check=True)
            self.assertEqual(result.stderr,'');return json.loads(result.stdout)['accepted']
        self.assertTrue(run(good))
        for value in [dict(good,owner='b'*64),dict(good,phase='PRIVATE_SENTINEL'),
                      dict(good,writer_thread='2'),dict(good,writer_thread=True),dict(good,writer_thread=0),
                      dict(good,query='PRIVATE_SENTINEL')]:
            self.assertFalse(run(value))

    def test_startup_observation_never_replaces_the_original_timeout(self):
        # Static integration contract only; the new SQL/P_S observation is unrun.
        source=WORKERS.read_text()
        begin=source.index("  if (!is_file($path+'/ready.json'))") if "  if (!is_file($path+'/ready.json'))" in source else source.index("  if (!is_file($path.'/ready.json'))")
        end=source.index("  $ready=json_decode",begin)
        branch=source[begin:end]
        self.assertIn('governance_native_api_startup_diagnostic',branch)
        self.assertIn('catch(Throwable$diagnostic_error)',branch)
        self.assertIn("throw new RuntimeException('native_worker_not_ready')",branch)
        self.assertNotIn('return ',branch)

    def test_observer_table_literals_are_real_source_tables(self):
        # Static fixture/schema integration check, not a native DAO runtime test.
        schema=(ROOT/'resources/sql/quickstart.sql').read_text()
        fixture=(ROOT/'support/governance/recovery/api-concurrency-cases.php').read_text()
        for table in ('conduit_token','oauth_server_oauthserveraccesstoken','oauth_server_oauthclientauthorization'):
            self.assertIn('CREATE TABLE `'+table+'`',schema)
            self.assertIn("'"+table+"'",fixture)
        self.assertNotIn("'oauth_server_clientauthorization'",fixture)

    def test_exact_current_source_allowed_and_denied(self):
        self.assertTrue(self.validate('api', {'status':'committed','current_token_verified':True,'restoration_authorized':False}))
        self.assertTrue(self.validate('api', {'status':'rejected','reason':'governance_native_credential_ineligible'}))
        for value in [False, 1, '1', None]:
            self.assertFalse(self.validate('api', {'status':'committed','current_token_verified':value,'restoration_authorized':False}))

    def test_failed_unknown_and_backend_are_never_denial(self):
        for action in ['api', 'oauth']:
            for value in [{'status':'failed','error_type':'AphrontDeadlockQueryException'},
                          {'status':'rejected','reason':'governance_unknown_backend'},
                          {'status':'committed','outcome':'unknown','authentication_code':'unknown','restoration_authorized':False}]:
                self.assertFalse(self.validate(action, value))

    def test_oauth_correlated_native_outcome_and_no_authority(self):
        for outcome, code in [('allowed','native-success'),('denied','ERR-INVALID-AUTH')]:
            good={'status':'committed','outcome':outcome,'authentication_code':code,'restoration_authorized':False}
            self.assertTrue(self.validate('oauth', good))
            for bad in [dict(good,restoration_authorized=True), dict(good,extra='private'),
                        dict(good,authentication_code='ERR-OAUTH-ACCESS')]:
                self.assertFalse(self.validate('oauth', bad))
        self.assertFalse(self.validate('oauth', {'status':'committed','outcome':'allowed','authentication_code':'ERR-INVALID-AUTH','restoration_authorized':False}))

class CredentialConcurrencyGateTests(unittest.TestCase):
    def setUp(self):
        def load(name, path):
            spec=importlib.util.spec_from_file_location(name,path)
            module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
            return module
        self.gate=load('api_concurrency_gate',ROOT/'scripts/governance/check_api_credential_concurrency.py')
        source=load('synthetic_native_template',ROOT/'tests/governance/test_native_organization.py')
        self.data=source.template()  # Synthetic gate input, never SQL evidence.
        self.data.update(batch='native-api-credential-concurrency',scope='owned-native-credential-writer-reader-observation',
                         checks=sorted(self.gate.required_checks()),check_count=46)
        self.data['worker_cleanup'].update(workers_registered=12,private_directories_removed=12)
        rows=[]
        for p in self.gate.PROFILES:
            for op in self.gate.OPERATIONS:
                for b in self.gate.BOUNDARIES:
                    allowed=not(op=='revoke' and b=='commit')
                    rows.append(dict(profile=p,operation=op,boundary=b,
                      observation=dict(state='completed-before-release',exact_requester_and_blocker=False),
                      reader_allowed=allowed,fresh_auth_allowed=allowed))
        self.data['native']=dict(profile_version=1,source_revision='a'*40,observations=rows,attempts=12,
          actual_current_reader_profiles=['api','cli'],oauth_native_authentication_only=True,
          api_token_scan_deadlock_proven=False,production_query_changed=False,all_sources_complete=False,can_execute=False)

    def validate(self, data):
        return self.gate.validate(data,'a'*40,self.data['file_service_cleanup']['binary_sha256'])

    def test_positive_contract_before_mutations(self):
        self.assertTrue(self.validate(self.data)['accepted'])
        self.assertEqual(len(self.gate.required_checks()),46)

    def test_actual_cli_duplicate_fields_rejected_after_positive(self):
        with tempfile.TemporaryDirectory() as directory:
            binary=Path(directory)/'synthetic-binary'
            binary.write_bytes(b'contract-only, not native binary evidence')
            self.data['file_service_cleanup']['binary_sha256']=hashlib.sha256(binary.read_bytes()).hexdigest()
            receipt=Path(directory)/'synthetic.json'
            raw=json.dumps(self.data)
            command=[sys.executable,'-B',str(ROOT/'scripts/governance/check_api_credential_concurrency.py'),
                     '--receipt',str(receipt),'--source-revision','a'*40,'--file-binary',str(binary)]
            receipt.write_text(raw)
            positive=subprocess.run(command,capture_output=True,text=True)
            self.assertEqual(positive.returncode,0,positive.stderr)
            for before, after in [
                ('"source_revision": "'+'a'*40+'"','"source_revision": "'+'b'*40+'", "source_revision": "'+'a'*40+'"'),
                ('"can_execute": false','"can_execute": true, "can_execute": false')]:
                self.assertEqual(raw.count(before),1)
                receipt.write_text(raw.replace(before,after))
                refused=subprocess.run(command,capture_output=True,text=True)
                self.assertNotEqual(refused.returncode,0)
                self.assertIn('duplicate credential evidence field',refused.stderr)

    def test_missing_duplicate_wrong_source_and_authority_refused(self):
        self.validate(self.data)
        cases=[]
        d=copy.deepcopy(self.data);d['checks'].pop();cases.append(d)
        d=copy.deepcopy(self.data);d['native']['observations'][-1]=copy.deepcopy(d['native']['observations'][0]);cases.append(d)
        d=copy.deepcopy(self.data);d['native']['source_revision']='b'*40;cases.append(d)
        d=copy.deepcopy(self.data);d['native']['can_execute']=True;cases.append(d)
        d=copy.deepcopy(self.data);d['native']['observations'][0]['observation']['exact_requester_and_blocker']=0;cases.append(d)
        d=copy.deepcopy(self.data);d['cleanup']['readerRemoved']=1;cases.append(d)
        d=copy.deepcopy(self.data);d['native']['observations'][0]['observation']['state']='unknown';cases.append(d)
        for d in cases:
            with self.assertRaises(ValueError):self.validate(d)

if __name__ == '__main__':
    unittest.main()
