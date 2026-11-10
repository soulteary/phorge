"""Actual source admission graph and real Git bindings; never SQL execution."""
import copy,hashlib,importlib.util,json,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_description_admission_delta as admission
import check_directory_transports_v1 as directory
def ci_module():
 p=ROOT/'support/governance/project-content-grants-ci/ci.py';s=importlib.util.spec_from_file_location('actual_T3_admission_CI',p);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
class DescriptionAdmission(unittest.TestCase):
 def copy_recipe(self,root):
  paths=set(admission.CURRENT_METADATA)|set(admission.description.CURRENT_PINS)|{admission.MANIFEST,admission.BRIDGE,admission.PREPARATION,admission.description.LEGACY_REDUCER['path'],'scripts/governance/project_content_grant_ci_source_delta.py'}
  for p in paths:
   target=root/p;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes((ROOT/p).read_bytes())
 def test_actual_current_recipe_and_full_production_inventory_pass_before_revision_binding(self):
  self.assertEqual(admission.assert_current_recipe(ROOT)['production_paths'],17)
  data=directory.source(ROOT);self.assertEqual(data,admission.expected_manifest(ROOT));self.assertEqual(len(data['pins']),76)
  tree=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'ls-tree','-r',admission.TARGET,'--','src','resources']);self.assertEqual(hashlib.sha256(tree).hexdigest(),data['production_git_tree_inventory_sha256'])
 def test_clean_exact_T3_native_Git_source_and_actual48_manifest_bind(self):
  ci=ci_module();self.assertEqual(ci.TARGET,admission.TARGET)
  with tempfile.TemporaryDirectory()as t:
   native=Path(t).resolve()/'native';subprocess.run(['git','clone','--shared','--no-checkout','--',str(ROOT),str(native)],check=True,capture_output=True);subprocess.run(['git','-C',str(native),'checkout','--detach',ci.TARGET],check=True,capture_output=True)
   self.assertEqual(ci.source(native,ci.TARGET),ci.TREE)
   manifest=ci.bind_manifest(ROOT/'support/governance/project-content-grants-ci/qualification-template.json',ROOT/'support/governance/project-content-grants-qualification','qualification',native);self.assertEqual(len(manifest['native_source_inventory']),48)
   # A native target is not the later reviewed recipe; no borrowed current-source approval.
   with self.assertRaises((ValueError,OSError)):ci.current_source(native,ci.TARGET)
 def test_real_clean_and_dirty_recipes_are_checked_without_mocking_Git(self):
  # These source consumers do not verify cryptographic signatures. The release
  # verify-commit gate is separate; never infer "unsigned" from a dirty checkout.
  revision=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'rev-parse','HEAD'],text=True).strip()
  probe="""import importlib.util,json,subprocess,sys
from pathlib import Path
root=Path(sys.argv[1]);revision=sys.argv[2];expected=sys.argv[3]
sys.path.insert(0,str(root/'scripts/governance'))
import check_directory_transports_v1 as directory
spec=importlib.util.spec_from_file_location('clean_clone_actual_CI',root/'support/governance/project-content-grants-ci/ci.py')
ci=importlib.util.module_from_spec(spec);spec.loader.exec_module(ci)
results=[]
for call in (lambda:ci.current_source(root,revision),lambda:directory.source(root,revision=revision)):
 try:
  value=call()
 except ValueError:
  if expected!='dirty':raise
  results.append('refused')
 except subprocess.CalledProcessError as error:
  # Directory's actual dirty src/resources contract is git diff --quiet exit 1.
  if expected!='dirty' or error.returncode!=1 or error.cmd!=['git','-C',str(root),'diff','--quiet','HEAD','--','src','resources']:raise
  results.append('refused')
 else:
  if expected!='clean':raise AssertionError('dirty clone was admitted')
  if not (value==revision or isinstance(value,dict) and len(value['pins'])==76):raise AssertionError('unexpected clean binding')
  results.append('accepted')
print(json.dumps(results))
"""
  with tempfile.TemporaryDirectory()as t:
   root=Path(t).resolve()/'recipe'
   subprocess.run(['git','--no-replace-objects','-c','core.hooksPath=/dev/null','clone','--shared','--no-checkout','--',str(ROOT),str(root)],check=True,capture_output=True)
   subprocess.run(['git','--no-replace-objects','-c','core.hooksPath=/dev/null','-C',str(root),'checkout','--detach',revision],check=True,capture_output=True)
   def check(expected):
    status=subprocess.check_output(['git','--no-replace-objects','-C',str(root),'status','--porcelain','--untracked-files=no'])
    self.assertEqual(bool(status),expected=='dirty')
    result=subprocess.run([sys.executable,'-B','-c',probe,str(root),revision,expected],check=True,capture_output=True,text=True)
    self.assertEqual(result.stderr,'')
    self.assertEqual(json.loads(result.stdout),['accepted','accepted']if expected=='clean'else['refused','refused'])
   check('clean')
   path=root/'src/applications/maniphest/storage/ManiphestTask.php';original=path.read_bytes()
   path.write_bytes(original+b'\n// Synthetic dirty Git negative, never runtime code.\n')
   check('dirty')
   path.write_bytes(original);check('clean')
 def test_unknown_metadata_mutation_and_tampered_pin_refuse(self):
  for path in admission.CURRENT_METADATA:
   with self.assertRaises(ValueError):admission._normalize_metadata(path,(ROOT/path).read_bytes()+b'\n# unknown')
  path=next(iter(admission.CURRENT_METADATA));fake=copy.deepcopy(admission.CURRENT_METADATA[path]);fake['sha256']='0'*64
  with patch.dict(admission.CURRENT_METADATA,{path:fake}),self.assertRaises(ValueError):admission.assert_current_recipe(ROOT)
 def test_real_complete_recipe_refuses_each_mixed_old_metadata_file(self):
  with tempfile.TemporaryDirectory()as t:
   root=Path(t).resolve();self.copy_recipe(root);admission.assert_current_recipe(root)
   for path,pin in admission.CURRENT_METADATA.items():
    if pin['target_sha256']is None or pin['target_sha256']==pin['sha256']:continue
    p=root/path;good=p.read_bytes();p.write_bytes(admission._target_bytes(path))
    with self.assertRaises(ValueError):admission.assert_current_recipe(root)
    p.write_bytes(good)
 def test_old_reducers_and_entire36_baseline_identity_preserved(self):
  for path in [admission.description.LEGACY_REDUCER['path'],'scripts/governance/project_content_grant_ci_source_delta.py']:
   actual=(ROOT/path).read_bytes();old=subprocess.check_output(['git','-C',str(ROOT),'show',admission.description.MAIN_BASELINE['git_revision']+':'+path]);self.assertEqual(actual,old)
  path='support/governance/project-content-grants-ci/prerequisites-template.json';old=json.loads(subprocess.check_output(['git','-C',str(ROOT),'show',admission.description.MAIN_BASELINE['git_revision']+':'+path]));now=json.loads((ROOT/path).read_bytes());keys=['native_git_revision','native_git_tree','native_public_source_dependencies'];self.assertEqual({k:old[k]for k in keys},{k:now[k]for k in keys});self.assertEqual(len(now['native_public_source_dependencies']),36)
 def test_current_offline_exceptions_are_guarded_and_SQL_profile_still_null(self):
  ci=ci_module();self.assertEqual(set(ci.OFFLINE_METADATA),{'scripts/governance/check_recovery_oauth_authorization_producer_v1.py',admission.description.LEGACY_REDUCER['path'],'scripts/governance/project_content_grant_ci_source_delta.py',admission.BRIDGE,'scripts/governance/recovery_oauth_authorization_source_delta.py',admission.CHECKER})
  source=(ROOT/'support/governance/project-description-qualification/native.php').read_text();self.assertIn('const N05_DESCRIPTION_ROOT_PROFILE_SHA256=null;',source)
  self.assertFalse(admission.assert_current_recipe(ROOT)['runtime_qualified'])
if __name__=='__main__':unittest.main()
