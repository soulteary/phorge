"""Actual Store/Assessment/participant controls; explicit SQL collaborators, no DB."""
import copy,hashlib,json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
class AssessmentCredentials(unittest.TestCase):
 def run_case(self,mode):
  p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/assessment-credential-owner-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=15)
  self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');d=json.loads(p.stdout);self.assertFalse(d['physical_SQL_executed']);return d
 def test_real_assessment_reads_three_actual_participants_under_only_store_owner(self):
  d=self.run_case('good');self.assertIsNone(d['error']);self.assertEqual((d['opens'],d['commits'],d['rollbacks']),(1,1,0));self.assertEqual(set(d['reads']),{'session','conduit','oauth-access'})
  b=d['facts']['before'];self.assertEqual(b['schema_version'],4);self.assertEqual(len(b['families']),13);self.assertTrue(all(not f['complete']for f in b['families'].values()));self.assertTrue(d['facts']['blocked']);self.assertEqual(b['credential_readset']['availability'],'observed-partial');self.assertFalse(b['credential_readset']['production_observer_factory_available']);self.assertFalse(b['restoration_authorized']);self.assertTrue(d['cleanup_proved'])
 def test_each_real_source_change_changes_next_owner_assessment_binding(self):
  for name in ['session','conduit','oauth-access']:
   d=self.run_case('next-'+name);self.assertIsNone(d['error']);before=d['facts']['before'];after=d['facts']['after']['binding'];self.assertNotEqual(d['facts']['digest'],d['facts']['after']['digest']);changed=[k for k in before['credential_readset']['sources']if before['credential_readset']['sources'][k]!=after['credential_readset']['sources'][k]];self.assertEqual(changed,[name]);self.assertEqual(d['commits'],2)
 def test_real_store_review_recapture_refuses_each_stale_selected_source(self):
  for name in ['session','conduit','oauth-access']:
   d=self.run_case('stale-'+name);self.assertEqual(d['error'],'recovery_restore_sources_stale');self.assertEqual((d['opens'],d['commits'],d['rollbacks']),(2,1,1));self.assertTrue(d['cleanup_proved'])
 def test_same_owner_changes_and_unsupported_reads_fail_closed_without_downgrade(self):
  for mode in ['mutate-session','mutate-conduit','mutate-oauth-access','unsupported-conduit','unsupported-oauth-access','budget-conduit','budget-oauth-access']:
   d=self.run_case(mode);self.assertIsNotNone(d['error'],mode);self.assertIsNone(d['facts']);self.assertEqual((d['commits'],d['rollbacks']),(0,1),mode)
 def test_absent_production_observer_is_explicit_not_empty_source_proof(self):
  d=self.run_case('unavailable');self.assertIsNone(d['error']);self.assertEqual(d['reads'],[]);r=d['facts']['before']['credential_readset'];self.assertEqual(r['availability'],'production-observer-unavailable');self.assertTrue(all(x['source_digest']is None for x in r['sources'].values()));self.assertFalse(r['complete']);self.assertTrue(d['facts']['blocked'])
 def test_actual_descriptor_rejects_missing_unknown_modes_and_claimed_provider(self):
  r=self.run_case('good')['facts']['before'];cases=[]
  for kind in ['missing','extra','mode','digest','scope','complete','factory','version','absence']:
   b=copy.deepcopy(r);x=b['credential_readset']
   if kind=='missing':del x['sources']['session']
   elif kind=='extra':x['sources']['ssh']={}
   elif kind=='mode':x['mode']='owned-RR'
   elif kind=='digest':x['sources']['session']['source_digest']=None
   elif kind=='scope':x['sources']['conduit']['scope']='all-api'
   elif kind=='complete':x['complete']=True
   elif kind=='factory':x['production_observer_factory_available']=True
   elif kind=='version':x['provider_schema']=2
   elif kind=='absence':x['availability']='production-observer-unavailable'
   cases.append(b)
  code="require$argv[1].'/support/runtime/src/object/Phobject.php';require$argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryRestoreRequest.php';$out=[];foreach(json_decode(stream_get_contents(STDIN),true)as$x){try{$out[]=PhorgeRecoveryRestoreRequest::sourceVersion($x);}catch(Throwable$e){$out[]=false;}}echo json_encode($out);"
  p=subprocess.run(['php','-r',code,str(ROOT)],input=json.dumps([r]+cases),capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),[4]+[False]*len(cases))
 def test_unknown_observer_events_retain_owner_and_caught_read_failure_cannot_commit(self):
  for mode in ['unknown-begin','unknown-end']:
   d=self.run_case(mode);self.assertIsNotNone(d['error']);self.assertFalse(d['cleanup_proved']);self.assertTrue(d['retained_owner']);self.assertEqual(d['rollbacks'],1 if mode=='unknown-begin'else 0)
  for name in ['conduit','oauth-access']:
   d=self.run_case('caught-unsupported-'+name);self.assertEqual(d['error'],'recovery_borrowed_not_active');self.assertEqual((d['commits'],d['rollbacks']),(0,1))
 def test_actual_native_actor_and_final_connection_do_not_mint_store_observer(self):
  code=r"""require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');
$user=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('a',20));$context=PhorgeGovernanceNativeActorContext::newFromAuthenticatedAPIToken($user,new PhutilOpaqueEnvelope('api-'.str_repeat('a',28)));
$conn=new AphrontMySQLiDatabaseConnection([]);$rejected=false;try{PhorgeRecoveryRestoreService::captureAssessmentCredentialSources($conn,$user->getPHID());}catch(Throwable$e){$rejected=true;}
echo json_encode(['native_context'=>get_class($context),'credential_type'=>$context->getCredentialType(),'final_connection'=>(new ReflectionClass($conn))->isFinal(),'rejected'=>$rejected,'owner'=>(new ReflectionProperty(PhorgeRecoveryRestoreService::class,'borrowedOwner'))->getValue(),'observer'=>(new ReflectionProperty(PhorgeRecoveryRestoreService::class,'borrowedObserver'))->getValue()]);"""
  p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen','-r',code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),{'native_context':'PhorgeGovernanceNativeActorContext','credential_type':'api-token','final_connection':True,'rejected':True,'owner':None,'observer':None})
 def test_older_stored_versions_remain_syntax_only_and_cannot_smuggle_readset(self):
  b=self.run_case('good')['facts']['before'];legacy=copy.deepcopy(b);legacy['schema_version']=3;legacy['selected_producer_profile']='native-authsession-insert-fence-v1-not-all-producers';del legacy['credential_readset'];smuggle=copy.deepcopy(legacy);smuggle['credential_readset']=b['credential_readset']
  code="require$argv[1].'/support/runtime/src/object/Phobject.php';require$argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryRestoreRequest.php';$out=[];foreach(json_decode(stream_get_contents(STDIN),true)as$x){try{$out[]=PhorgeRecoveryRestoreRequest::sourceVersion($x);}catch(Throwable$e){$out[]=false;}}echo json_encode($out);"
  p=subprocess.run(['php','-r',code,str(ROOT)],input=json.dumps([legacy,smuggle]),capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),[3,False])
 def test_versioned_source_candidate_and_exact_inverse_do_not_allow_repin_escape(self):
  import sys,tempfile
  sys.path.insert(0,str(ROOT/'scripts/governance'))
  import recovery_assessment_source_delta as delta,check_recovery_borrowed_owner_source as gate
  source=json.loads((ROOT/'docs/governance/recovery-assessment-native-credential-source-v5.json').read_text());self.assertEqual(gate.validate(source)['source_inputs'],9)
  # Independently captured immutable 1722 blob pins; no local-only Git object
  # is needed, and changing the adapter's own baseline cannot bless a mutation.
  baseline={'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php':'b2978b1bca3eea757c4be7e4fdbf84038fb61493e23f2cf39b1421162d6ebc04','src/infrastructure/governance/recovery/PhorgeRecoveryRestoreAssessment.php':'dcf6226ee57ed303273a231e4c81f2505751e60a0e5a4c3701d2d703d7d45403','src/infrastructure/governance/recovery/PhorgeRecoveryRestoreRequest.php':'876eafed1c36c11006242587906419561b1688657f6494d90a2a25a0e29489cf'}
  self.assertEqual(delta.BASE_PINS,baseline)
  for path,pin in delta.SOURCE_INPUTS.items():
   raw=(ROOT/path).read_bytes();old=delta.reduce_current(path,raw);self.assertEqual(hashlib.sha256(old).hexdigest(),baseline[path])
   for bad in [raw+b'// unrelated source delta',raw.replace(b'false',b'true',1)]:
    delta.SOURCE_INPUTS[path]=hashlib.sha256(bad).hexdigest()
    try:
     with self.assertRaises(ValueError):delta.reduce_current(path,bad)
    finally:delta.SOURCE_INPUTS[path]=pin
  for flag in ['runtime_certified','physical_SQL_cases_executed','production_observer_factory_available','business_submit_review_integration_proved','all_sources_complete','restoration_authorized']:
   bad=copy.deepcopy(source);bad[flag]=True
   with self.assertRaises(ValueError):gate.validate(bad)
if __name__=='__main__':unittest.main()
