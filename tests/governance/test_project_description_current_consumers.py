"""Fresh real Git clone and actual __file__ roots; no mocked ROOT or SQL."""
import json,os,shutil,subprocess,sys,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
CHILD=r'''
import json,sys
from pathlib import Path
root=Path(sys.argv[1]).resolve();sys.path.insert(0,str(root/'scripts/governance'))
try:
 import project_description_admission_delta as admission
 import recovery_oauth_authorization_source_delta as authorization
 import check_recovery_assessment_credentials_v2 as assessment
 import check_recovery_oauth_authorization_producer_v1 as auth_producer
except (ValueError,OSError):
 print(json.dumps({'cohort':{'accepted':False},'authorization':{'accepted':False},'assessment':{'accepted':False},'auth_producer':{'accepted':False}},sort_keys=True));raise SystemExit(0)
assert admission.ROOT==root and assessment.ROOT==root
out={}
for name,call in [('cohort',lambda:admission.assert_production_cohort(root)),('authorization',lambda:authorization.reduce_if_selected('src/__phutil_library_map__.php',(root/'src/__phutil_library_map__.php').read_bytes())),('assessment',lambda:assessment.SQL_obligations(root)),('auth_producer',lambda:auth_producer.SQL_obligations(root))]:
 try:
  value=call();out[name]={'accepted':True,'count':len(value)}
 except (ValueError,OSError):out[name]={'accepted':False}
print(json.dumps(out,sort_keys=True))
'''
class CurrentRecipeConsumerClosure(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.owned=tempfile.TemporaryDirectory();cls.clone=Path(cls.owned.name).resolve()/'native'
  subprocess.run(['git','clone','--shared','--no-checkout','--',str(ROOT),str(cls.clone)],check=True,capture_output=True)
  revision=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD'],text=True).strip()
  subprocess.run(['git','-C',str(cls.clone),'checkout','--detach',revision],check=True,capture_output=True)
  changed=subprocess.check_output(['git','-C',str(ROOT),'diff','--name-only','HEAD'],text=True).splitlines();added=subprocess.check_output(['git','-C',str(ROOT),'ls-files','--others','--exclude-standard'],text=True).splitlines()
  for name in set(changed)|set(added):
   p=cls.clone/name;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/name,p)
 @classmethod
 def tearDownClass(cls):cls.owned.cleanup()
 def observe(self):
  p=subprocess.run([sys.executable,'-B','-c',CHILD,str(self.clone)],capture_output=True,text=True,env={**os.environ,'PYTHONDONTWRITEBYTECODE':'1'},timeout=60)
  self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
 def test_valid_actual_recipe_reaches_exact17_and_selected8_not_restore_permission(self):
  d=self.observe();self.assertEqual(d['cohort'],{'accepted':True,'count':17});self.assertTrue(d['authorization']['accepted']);self.assertEqual(d['assessment'],{'accepted':True,'count':8});self.assertEqual(d['auth_producer'],{'accepted':True,'count':20})
 def test_every_current_metadata_mutation_refuses_all_real_consumer_entries(self):
  sys.path.insert(0,str(ROOT/'scripts/governance'));import project_description_admission_delta as admission
  self.assertIn('.github/workflows/project-description-focused.yml',admission.CURRENT_METADATA)
  for name in admission.CURRENT_METADATA:
   with self.subTest(path=name):
    p=self.clone/name;old=p.read_bytes();p.write_bytes(old+b'\n# unknown-current-metadata\n')
    try:
     result=self.observe();self.assertEqual(result,{'cohort':{'accepted':False},'authorization':{'accepted':False},'assessment':{'accepted':False},'auth_producer':{'accepted':False}})
    finally:p.write_bytes(old)
 def test_auth_producer_rejects_unlisted_selected_native_source_change(self):
  name='src/applications/oauthserver/storage/PhabricatorOAuthClientAuthorization.php';p=self.clone/name;raw=p.read_bytes();p.write_bytes(raw+b'\n// unknown actual selected native source')
  try:self.assertFalse(self.observe()['auth_producer']['accepted'])
  finally:p.write_bytes(raw)
 def test_actual_auth_producer_preserves_exact_c4_native_source_branch(self):
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve()/'baseline'
   subprocess.run(['git','clone','--shared','--no-checkout','--',str(ROOT),str(root)],check=True,capture_output=True)
   subprocess.run(['git','-C',str(root),'checkout','--detach','c4b2ae7cc72a91ff942f08f9b4725104f114640c'],check=True,capture_output=True)
   sys.path.insert(0,str(ROOT/'scripts/governance'));import check_recovery_oauth_authorization_producer_v1 as native
   self.assertEqual(len(native.SQL_obligations(root)),20)
 def test_mixed_R3_native_to_main_projection_plus_unknown_metadata_refuses(self):
  sys.path.insert(0,str(ROOT/'scripts/governance'));import project_description_admission_delta as current
  import check_recovery_oauth_authorization_producer_v1 as native
  snapshot=current.description.current_snapshot(ROOT);prior=current.description.reconstruct_main(snapshot)
  names=set(snapshot)|{'.github/workflows/project-description-focused.yml'};saved={n:(self.clone/n).read_bytes()for n in names}
  try:
   for n in snapshot:
    p=self.clone/n
    if n in prior:p.write_bytes(prior[n])
    else:p.unlink()
   p=self.clone/'.github/workflows/project-description-focused.yml';p.write_bytes(p.read_bytes()+b'\n# unknown mixed R3 metadata')
   with self.assertRaises((ValueError,FileNotFoundError)):current.assert_current_recipe(self.clone)
   with self.assertRaises(ValueError):native.SQL_obligations(self.clone)
  finally:
   for n,raw in saved.items():p=self.clone/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(raw)
 def test_historical_c4_dirty_and_untracked_metadata_cannot_bypass(self):
  sys.path.insert(0,str(ROOT/'scripts/governance'));import check_recovery_oauth_authorization_producer_v1 as native
  for mode in ('dirty','untracked'):
   with self.subTest(mode=mode),tempfile.TemporaryDirectory()as tmp:
    root=Path(tmp).resolve()/'baseline'
    subprocess.run(['git','clone','--shared','--no-checkout','--',str(ROOT),str(root)],check=True,capture_output=True)
    subprocess.run(['git','-C',str(root),'checkout','--detach','c4b2ae7cc72a91ff942f08f9b4725104f114640c'],check=True,capture_output=True)
    p=root/('.github/workflows/atomic-feature-regressions.yml'if mode=='dirty'else '.github/workflows/unknown-description.yml')
    p.write_bytes((p.read_bytes()if p.exists()else b'')+b'\n# unknown metadata')
    with self.assertRaises(ValueError):native.SQL_obligations(root)
 def test_historical_skip_worktree_cannot_hide_unknown_executable_bytes(self):
  sys.path.insert(0,str(ROOT/'scripts/governance'));import check_recovery_oauth_authorization_producer_v1 as native
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve()/'baseline'
   subprocess.run(['git','clone','--shared','--no-checkout','--',str(ROOT),str(root)],check=True,capture_output=True)
   subprocess.run(['git','-C',str(root),'checkout','--detach','c4b2ae7cc72a91ff942f08f9b4725104f114640c'],check=True,capture_output=True)
   name='bin/rebuild-library-map';subprocess.run(['git','-C',str(root),'update-index','--skip-worktree',name],check=True,capture_output=True)
   p=root/name;p.write_bytes(p.read_bytes()+b'\n# unknown executable bytes')
   self.assertEqual(subprocess.check_output(['git','-C',str(root),'status','--porcelain']),b'')
   with self.assertRaises(ValueError):native.SQL_obligations(root)
 def test_pure_native_inverse_is_separate_from_current_recipe_consumer(self):
  # Immutable T3 uses the separate exact Git clean/source/48-inventory path.
  # Its C1 inverse has no metadata admission API and never grants execution.
  sys.path.insert(0,str(ROOT/'scripts/governance'));import project_description_source_delta as pure
  d=pure.audit(ROOT);self.assertTrue(d['inverse_verified']);self.assertFalse(d['full_source_admission']);self.assertFalse(d['can_execute'])
if __name__=='__main__':unittest.main()
