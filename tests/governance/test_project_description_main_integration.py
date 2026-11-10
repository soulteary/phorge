"""Current-main integration boundaries; no SQL or successful live authorization."""
import hashlib,subprocess,sys,unittest
from test_project_description_execution import ROOT,php
class CurrentMainIntegration(unittest.TestCase):
 def test_real_autoload_and_native_visibility_preserve_inherited_Store_current(self):
  d=php(r'''$store=new ReflectionClass(PhorgeProjectContentGrantStore::class);$native=new class extends PhutilLibraryTestCase{protected function getLibraryName(){return 'phorge';}};$native->testMethodVisibility();echo json_encode(['native_visibility'=>true,'inherited_current_public'=>$store->getMethod('current')->isPublic(),'inherited_current_declared_by'=>$store->getMethod('current')->getDeclaringClass()->getName(),'private_guard'=>$store->getMethod('assertCurrentGrantScope')->isPrivate(),'default_off'=>head((new PhorgeProjectContentGrantConfigOptions())->getOptions())->getDefault()===false,'status'=>PhorgeProjectContentGrantService::executionStatus(),'connections'=>(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()]);''')
  self.assertTrue(d['native_visibility']);self.assertTrue(d['inherited_current_public']);self.assertEqual(d['inherited_current_declared_by'],'Phobject');self.assertTrue(d['private_guard']);self.assertTrue(d['default_off']);self.assertFalse(d['status']['can_execute']);self.assertFalse(d['status']['runtime_qualified']);self.assertEqual(d['connections'],[])
 def test_main_fixed_store_and_generic_editor_are_byteexact_with_strict_current_cohort(self):
  for rel in ['src/infrastructure/governance/content/PhorgeProjectContentGrantStore.php','src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php','scripts/governance/project_content_grant_source_delta.py','scripts/governance/project_content_grant_ci_source_delta.py']:
   expected=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','HEAD:'+rel]);self.assertEqual((ROOT/rel).read_bytes(),expected)
  sys.path.insert(0,str(ROOT/'scripts/governance'))
  import project_description_admission_delta as cohort
  self.assertEqual(len(cohort.assert_production_cohort(ROOT)),17)
  cohort.assert_current_recipe(ROOT)
 def test_unsigned_recipe_revision_stays_blocked_and_no_SQL_profile_is_admitted(self):
  rev=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'rev-parse','HEAD'],text=True).strip()
  q=subprocess.run([sys.executable,'-B',str(ROOT/'scripts/governance/check_directory_transports_v1.py'),'--source-only','--source-revision',rev],cwd=ROOT,capture_output=True,text=True)
  self.assertNotEqual(q.returncode,0)
  source=(ROOT/'support/governance/project-description-qualification/native.php').read_text();self.assertIn('const N05_DESCRIPTION_ROOT_PROFILE_SHA256=null;',source)
if __name__=='__main__':unittest.main()
