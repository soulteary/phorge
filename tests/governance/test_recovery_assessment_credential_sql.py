"""Fixed real SQL entry/dependency/guard controls; no database or runtime launch."""
from recovery_oauth_code_test_cohort import complete_source_cohort,normalized_source_tree
from pathlib import Path
import copy,hashlib,json,subprocess,sys,tempfile,unittest
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'scripts/governance'))
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_file_parent_policy as driver_admission
import check_recovery_assessment_credentials_v2 as gate
import test_recovery_session_lease_gate as old
class AssessmentCredentialSQL(unittest.TestCase):
 def php(self,code,*args):
  p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen','-r',code,str(ROOT),*args],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def template(self,digest='b'*64):
  d=old.template(digest);labels=sorted(gate.obligations());d.update(batch='native-assessment-credential-readset',scope='owned-native-assessment-credential-readset-no-permit',checks=labels,check_count=len(labels),native=gate.summary('a'*40));d['worker_cleanup']=dict.fromkeys(d['worker_cleanup'],0);return d
 def test_fresh_PHP_loads_actual_factory_observer_cases_and_fixed_native_registry(self):
  d=self.php(r'''require$argv[1].'/support/governance/recovery/assessment-credential-focused.php';require$argv[1].'/support/governance/recovery/assessment-credential-cases.php';require$argv[1].'/support/governance/native-organization/oauth-access-workers.php';$out=[];foreach(['governance_native_request','governance_recovery_assessment_credential_cases','governance_recovery_borrowed_with_observer','governance_recovery_restore_prime_space_cache','governance_recovery_borrowed_default_writer']as$f){$out[$f]=basename((new ReflectionFunction($f))->getFileName());}echo json_encode(['functions'=>$out,'labels'=>governance_recovery_assessment_credential_obligations(),'registry'=>governance_oauth_native_cleanup_workers()]);''')
  self.assertEqual(d['functions']['governance_native_request'],'native-cases.php');self.assertEqual(set(d['labels']),gate.SQL_obligations());self.assertEqual(d['registry'],dict.fromkeys(['private_directories_removed','owned_processes_stopped','cleanup_failures','private_directories_remaining','live_processes_after_cleanup','workers_registered'],0))
 def test_fixed_selector_refuses_missing_extra_duplicate_and_old_actions(self):
  s=(ROOT/'support/governance/native-organization/test-assessment-credential-readset-mysql.php').read_text();block=s[s.index('$arguments='):s.index('$workbench_http=')]
  for argv,want in [(['--assessment-credential-readset'],'assessment-credential-readset'),([], 'invalid'),(['--oauth-access-producer-owner'],'invalid'),(['--assessment-credential-readset']*2,'invalid')]:
   d=self.php('$argv=array_merge(["entry"],json_decode($argv[2],true));'+block+'echo json_encode($phase);',json.dumps(argv));self.assertEqual(d,want)
 def test_actual_case_refusal_helper_requires_exact_native_error_and_no_domain_write(self):
  d=self.php(r'''require$argv[1].'/support/governance/recovery/assessment-credential-cases.php';class PhabricatorUser{}function phutil_json_encode($v){return json_encode($v);}function queryfx_all(...$v){return[['generation'=>$GLOBALS['generation']??0]];}$conn=new class{function isInsideTransaction(){return false;}};$out=[];foreach(['expected','wrong','subclass','none','write']as$case){$GLOBALS['generation']=0;try{$out[$case]=governance_recovery_assessment_credential_refuse($conn,static function()use($case){if($case==='none')return;if($case==='write')$GLOBALS['generation']=1;if($case==='subclass')throw new class('stale')extends RuntimeException{};throw new RuntimeException($case==='wrong'?'different':'stale');},'stale');}catch(Throwable$e){$out[$case]='propagated';}}echo json_encode($out);''')
  self.assertEqual(d,{'expected':True,'wrong':'propagated','subclass':'propagated','none':False,'write':False})
 def test_actual_source_and_receipt_refuse_tamper_before_success(self):
  d=self.template();out=gate.validate(d,source_revision='a'*40,file_digest='b'*64);self.assertEqual((8,18),(out['SQL_obligations'],out['checks']));self.assertEqual(len(gate.SOURCE_INPUTS)+len(gate.ADAPTER_SOURCE_INPUTS),out['source_inputs']);self.assertFalse(out['production_observer_factory_available'])
  for mutate in [lambda b:b['checks'].pop(),lambda b:b['native'].__setitem__('all_13_sources_complete',True),lambda b:b['native'].__setitem__('production_observer_factory_available',True),lambda b:b['native']['observer_cleanup'].__setitem__('cleanup_proved',False),lambda b:b['cleanup'].__setitem__('readerRemoved',False),lambda b:b.__setitem__('raw_token','forbidden')]:
   bad=copy.deepcopy(d);mutate(bad)
   with self.assertRaises(ValueError):gate.validate(bad,source_revision='a'*40,file_digest='b'*64)
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   root=Path(tmp)
   for n in set(gate.SOURCE_INPUTS)|set(gate.ADAPTER_SOURCE_INPUTS):
    p=root/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/n).read_bytes())
   complete_source_cohort(root)
   gate.SQL_obligations(root);n='support/governance/recovery/assessment-credential-cases.php';(root/n).write_bytes((root/n).read_bytes()+b'// corruption')
   with self.assertRaises(ValueError):gate.SQL_obligations(root)
 def test_public_consumer_requires_actual_independent_binary_bytes_and_rejects_duplicates(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   root=Path(tmp);binary=root/'file-data';binary.write_bytes(b'DATA only; never executed');binary.chmod(0o700);pin=hashlib.sha256(binary.read_bytes()).hexdigest();p=root/'receipt';p.touch(mode=0o600);body=json.dumps(self.template(pin));cmd=[sys.executable,'-B',str(ROOT/'scripts/governance/check_recovery_assessment_credentials_v2.py'),'--receipt',str(p),'--source-revision','a'*40,'--file-binary',str(binary),'--file-binary-sha256',pin]
   for raw,ok in [(body,True),(body.replace('"passed": true','"passed": true,"passed": true',1),False)]:
    p.write_text(raw);r=subprocess.run(cmd,capture_output=True,text=True,timeout=10);self.assertEqual(r.returncode==0,ok,r.stderr)
   p.write_text(body);binary.write_bytes(b'changed');self.assertNotEqual(subprocess.run(cmd,capture_output=True,timeout=10).returncode,0)
 def test_actual_whole_driver_unknown_and_early_failure_never_generic_cleanup(self):
  for mode in ['unknown','forged-flags','early','good','old']:
   p=subprocess.run(['php',str(ROOT/'support/governance/recovery/tests/assessment-credential-driver-cleanup-host.php'),str(ROOT),mode],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);d=json.loads(p.stdout)
   if mode in ['unknown','forged-flags','early']:
    self.assertEqual([0]*5,[d[k]for k in ['rollbacks','pool_closes','disposes','drops','control_closes']]);self.assertEqual(d['env'],'current')
   else:self.assertEqual(d['drops'],1)
 def test_production_and_all_previous_entry_bytes_remain_exact_a953(self):
  # Captured from the actually reviewed a953 tree and blobs. Development
  # commits need not be reachable in a fresh GitHub checkout; immutable pins do.
  self.assertEqual(normalized_source_tree(ROOT),'3944b8003bb59a9b67f685df67acbd2354820b1c')
  self.assertEqual(subprocess.check_output(['git','-C',str(ROOT),'rev-parse','4c2245300dc1d78d042b95190dd537eb6b1f9861:resources'],text=True).strip(),'fa7bf1dc07a23696de5fb22a9564f17259ecd33d')
  import project_description_admission_delta as n05_delta
  n05_delta.assert_production_cohort(ROOT)
  self.assertEqual(subprocess.check_output(['git','-C',str(ROOT),'diff','--name-only','4c2245300dc1d78d042b95190dd537eb6b1f9861','--','resources'],text=True).splitlines(),['resources/sql/autopatches/20261110.project.contentgrants.sql'])
  for relative,pin in {'support/governance/native-organization/test-oauth-access-owner-mysql.php':'9f0a879c8a93de3db6ac45e3d3034604ae614be808f07b6f4c88ceda7fceefed','src/__phutil_library_map__.php':'397bc1b1f86ecd09bae57c2be333ab11c79c3915eee1fff8f9f4eaf3271bc4d3'}.items():
   raw=(ROOT/relative).read_bytes()
   if relative=='src/__phutil_library_map__.php':
    n05_delta.assert_current_recipe(ROOT)
    raw=n05_delta.description.reconstruct_main(n05_delta.description.current_snapshot(ROOT))[relative]
   self.assertEqual(hashlib.sha256(raw).hexdigest(),pin)
  driver='support/governance/native-organization/test-native-organization-mysql.php'
  pin='adc0a4016c22c836942905c2072ded5e52975932699e58971597edb52056b42a'
  self.assertEqual(hashlib.sha256(gate.source_view(driver,driver_admission.admitted_native_driver_source(ROOT,(ROOT/driver).read_bytes()),pin)).hexdigest(),pin)
if __name__=='__main__':unittest.main()
