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
except (ValueError,OSError):
 print(json.dumps({'cohort':{'accepted':False},'authorization':{'accepted':False},'assessment':{'accepted':False}},sort_keys=True));raise SystemExit(0)
assert admission.ROOT==root and assessment.ROOT==root
out={}
for name,call in [('cohort',lambda:admission.assert_production_cohort(root)),('authorization',lambda:authorization.reduce_if_selected('src/__phutil_library_map__.php',(root/'src/__phutil_library_map__.php').read_bytes())),('assessment',lambda:assessment.SQL_obligations(root))]:
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
  subprocess.run(['git','-C',str(cls.clone),'checkout','--detach','15d4f76d9c1c12256f82e53435be038422e205a5'],check=True,capture_output=True)
  changed=subprocess.check_output(['git','-C',str(ROOT),'diff','--name-only','HEAD'],text=True).splitlines();added=subprocess.check_output(['git','-C',str(ROOT),'ls-files','--others','--exclude-standard'],text=True).splitlines()
  for name in set(changed)|set(added):
   p=cls.clone/name;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/name,p)
 @classmethod
 def tearDownClass(cls):cls.owned.cleanup()
 def observe(self):
  p=subprocess.run([sys.executable,'-B','-c',CHILD,str(self.clone)],capture_output=True,text=True,env={**os.environ,'PYTHONDONTWRITEBYTECODE':'1'},timeout=60)
  self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
 def test_valid_actual_recipe_reaches_exact17_and_selected8_not_restore_permission(self):
  d=self.observe();self.assertEqual(d['cohort'],{'accepted':True,'count':17});self.assertTrue(d['authorization']['accepted']);self.assertEqual(d['assessment'],{'accepted':True,'count':8})
 def test_every_current_metadata_mutation_refuses_all_real_consumer_entries(self):
  sys.path.insert(0,str(ROOT/'scripts/governance'));import project_description_admission_delta as admission
  self.assertIn('.github/workflows/project-description-focused.yml',admission.CURRENT_METADATA)
  for name in admission.CURRENT_METADATA:
   with self.subTest(path=name):
    p=self.clone/name;old=p.read_bytes();p.write_bytes(old+b'\n# unknown-current-metadata\n')
    try:
     result=self.observe();self.assertEqual(result,{'cohort':{'accepted':False},'authorization':{'accepted':False},'assessment':{'accepted':False}})
    finally:p.write_bytes(old)
 def test_pure_native_inverse_is_separate_from_current_recipe_consumer(self):
  # Immutable T3 uses the separate exact Git clean/source/48-inventory path.
  # Its C1 inverse has no metadata admission API and never grants execution.
  sys.path.insert(0,str(ROOT/'scripts/governance'));import project_description_source_delta as pure
  d=pure.audit(ROOT);self.assertTrue(d['inverse_verified']);self.assertFalse(d['full_source_admission']);self.assertFalse(d['can_execute'])
if __name__=='__main__':unittest.main()
