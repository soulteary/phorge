"""Actual native class loading and inherited visibility; never SQL."""
import hashlib,json,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
def php(code):
 pre='require '+json.dumps(str(ROOT/'support/runtime/src/__phutil_library_init__.php'))+';phutil_load_library('+json.dumps(str(ROOT/'src'))+');'
 p=subprocess.run(['php','-r',pre+code],cwd=ROOT,capture_output=True,text=True,timeout=90)
 if p.returncode:raise AssertionError(p.stderr)
 return json.loads(p.stdout)
class StoreVisibility(unittest.TestCase):
 def test_exact_previous_store_fails_real_class_loading(self):
  raw=subprocess.check_output(['git','show','89260b8508e319a05a3cce476692ca1d55081d98:src/infrastructure/governance/content/PhorgeProjectContentGrantStore.php'],cwd=ROOT)
  self.assertEqual(hashlib.sha256(raw).hexdigest(),'3d1a6603c24bc1507c3447c087585d4e562b1cddb621cdf048c3780b22355400')
  with tempfile.TemporaryDirectory()as td:
   old=Path(td)/'old.php';old.write_bytes(raw)
   pre='require '+json.dumps(str(ROOT/'support/runtime/src/__phutil_library_init__.php'))+';phutil_load_library('+json.dumps(str(ROOT/'src'))+');require '+json.dumps(str(old))+';'
   r=subprocess.run(['php','-d','display_errors=stderr','-r',pre],cwd=ROOT,capture_output=True,text=True,timeout=20)
  self.assertNotEqual(r.returncode,0)
  self.assertIn('Access level to PhorgeProjectContentGrantStore::current() must be public',r.stderr)
 def test_actual_store_load_and_private_guard(self):
  d=php("$r=new ReflectionClass(PhorgeProjectContentGrantStore::class);$c=$r->getMethod('current');$g=$r->getMethod('assertCurrentGrantScope');echo json_encode(['public'=>$c->isPublic(),'parent'=>$c->getDeclaringClass()->getName(),'private'=>$g->isPrivate(),'declaring'=>$g->getDeclaringClass()->getName(),'connections'=>(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()]);")
  self.assertEqual(d,{'public':True,'parent':'Phobject','private':True,'declaring':'PhorgeProjectContentGrantStore','connections':[]})
 def test_actual_native_library_method_visibility_acceptance(self):
  d=php("$t=new class extends PhutilLibraryTestCase {protected function getLibraryName(){return 'phorge';}};$t->testMethodVisibility();echo json_encode(['completed'=>true,'connections'=>(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()]);")
  self.assertEqual(d,{'completed':True,'connections':[]})
if __name__=='__main__':unittest.main()
