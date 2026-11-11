"""Real public Git source identity and pure exact-fixture audit controls; never SQL."""
import hashlib,importlib.util,json,subprocess,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
def load(name,path):
 spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
CI=load('stageB_CI',ROOT/'support/governance/project-content-grants-ci/ci.py')
Q=load('stageB_qualification',ROOT/'support/governance/project-content-grants-qualification/qualify.py')
class ActualTargetBinding(unittest.TestCase):
 def test_actual_36_baseline_and48_T3_target_Git_pins_and_typed_symlinks(self):
  for kind,key,count,revision,tree in [('prerequisites','native_public_source_dependencies',36,CI.BASE,CI.BASE_TREE),('qualification','native_source_inventory',49,CI.TARGET,CI.TREE)]:
   f=json.loads((ROOT/'support/governance/project-content-grants-ci'/(kind+'-template.json')).read_bytes());self.assertEqual(f['schema'],'N05-CI-reviewed-source-template-v2');self.assertEqual((f['native_git_revision'],f['native_git_tree']),(revision,tree));self.assertEqual(len(f[key]),count)
   for n,pin in f[key].items():
    name='scripts/sql/manage_storage.php'if kind=='qualification'and n=='bin/storage'else n
    raw=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show',revision+':'+name]);self.assertEqual({'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()},{k:pin[k]for k in ('bytes','sha256')})
   if kind=='prerequisites':self.assertEqual(subprocess.check_output(['git','-C',str(ROOT),'show',revision+':bin/storage']),b'../scripts/sql/manage_storage.php')
 def test_real_qualification_manifest_and_old_schema_or_identity_fail_closed(self):
  folder=ROOT/'support/governance/project-content-grants-qualification';path=ROOT/'support/governance/project-content-grants-ci/qualification-template.json'
  # Recipe HEAD legitimately advances; qualification must use a real exact-target checkout.
  with tempfile.TemporaryDirectory()as td:
   native=Path(td).resolve()/'native'
   subprocess.run(['/usr/bin/git','clone','--shared','--no-checkout','--',str(ROOT),str(native)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
   subprocess.run(['/usr/bin/git','-C',str(native),'checkout','--detach',CI.TARGET],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
   bound=CI.bind_manifest(path,folder,'qualification',native);self.assertEqual(bound['schema'],'N05-CI-bound-reviewed-source-v2');self.assertEqual(bound['native_git_revision'],CI.TARGET)
   subprocess.run(['/usr/bin/git','-C',str(native),'checkout','--detach',CI.TARGET+'^'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
   with self.assertRaises(ValueError):CI.bind_manifest(path,folder,'qualification',native)
  if subprocess.check_output(['/usr/bin/git','-C',str(ROOT),'rev-parse','HEAD']).decode().strip()!=CI.TARGET:
   with self.assertRaises(ValueError):CI.bind_manifest(path,folder,'qualification',ROOT)
  f=json.loads(path.read_bytes())
  for key,value in [('native_git_revision','ef7be9c5c62e1fd93e358c556706261e85558960'),('schema','N05-CI-reviewed-source-template-v1'),('native_git_revision','79987a76658150e77fc5266fae13c2eaed934ff2'),('native_git_tree','0'*40)]:
   with tempfile.TemporaryDirectory()as td:
    p=Path(td)/'template.json';p.write_text(json.dumps(dict(f,**{key:value})))
    with self.assertRaises(ValueError):CI.bind_manifest(p,folder,'qualification',ROOT)
 def test_qualification_rejects_old_target_wrong_tree_and_dirty_source_before_SQL(self):
  for values in [(b'79987a76658150e77fc5266fae13c2eaed934ff2\n',),(Q.TARGET.encode(),b'0'*40)]:
   with patch.object(Q.subprocess,'check_output',side_effect=values),patch.object(Q.subprocess,'run')as run,self.assertRaises(ValueError):Q.source_root_identity(Path('/synthetic'))
   run.assert_not_called()
  with patch.object(Q.subprocess,'check_output',side_effect=[Q.TARGET.encode(),Q.TARGET_TREE.encode()]),patch.object(Q.subprocess,'run',side_effect=subprocess.CalledProcessError(1,['git'])),self.assertRaises(subprocess.CalledProcessError):Q.source_root_identity(Path('/synthetic'))
  with patch.object(Q.subprocess,'check_output',side_effect=[Q.TARGET.encode(),Q.TARGET_TREE.encode()]),patch.object(Q.subprocess,'run')as run:
   Q.source_root_identity(Path('/synthetic'));run.assert_called_once_with(['/usr/bin/git','--no-replace-objects','-C','/synthetic','diff','--quiet','HEAD'],check=True)
 def test_signed_repair_target_inventory_changes_only_reviewed_DDL_and_Store(self):
  path=ROOT/'support/governance/project-content-grants-ci/qualification-template.json'
  repaired=json.loads(subprocess.check_output(['git','-C',str(ROOT),'show','c4b2ae7cc72a91ff942f08f9b4725104f114640c:support/governance/project-content-grants-ci/qualification-template.json']));current=repaired;previous=json.loads(subprocess.check_output(['git','-C',str(ROOT),'show','05a45fa349d7afc63a89846ad663538bf994ef38:support/governance/project-content-grants-ci/qualification-template.json']))
  key='native_source_inventory';self.assertEqual(set(current[key]),set(previous[key]));self.assertEqual(len(current[key]),45)
  changed={n for n in current[key]if current[key][n]!=previous[key][n]}
  self.assertEqual(changed,{'resources/sql/autopatches/20261110.project.contentgrants.sql','src/infrastructure/governance/content/PhorgeProjectContentGrantStore.php'})
  self.assertEqual(current[key]['resources/sql/autopatches/20261110.project.contentgrants.sql']['sha256'],'16f043e830f29b246efd12c22569e66bf0d2b629f4b9dbd062ab0f30a487d27e')
  store='src/infrastructure/governance/content/PhorgeProjectContentGrantStore.php';actual=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show',CI.TARGET+':'+store]);self.assertEqual(current[key][store],{'bytes':len(actual),'sha256':hashlib.sha256(actual).hexdigest()});self.assertEqual(current[key][store]['sha256'],'983e7885bf7dcb7335437dc7b203c5b1a1605385a220a6242461a3ee43cb5612')
  self.assertEqual(subprocess.check_output(['git','-C',str(ROOT),'rev-parse',CI.TARGET+'^{tree}']).decode().strip(),CI.TREE)
  baseline='support/governance/project-content-grants-ci/prerequisites-template.json'
  old=json.loads(subprocess.check_output(['git','-C',str(ROOT),'show','05a45fa349d7afc63a89846ad663538bf994ef38:'+baseline]));now=json.loads((ROOT/baseline).read_bytes())
  self.assertEqual(now['native_public_source_dependencies'],old['native_public_source_dependencies']);self.assertEqual(len(now['native_public_source_dependencies']),36)
 def test_T3_adds_only_three_description_classes_and_four_reviewed_native_deltas(self):
  path='support/governance/project-content-grants-ci/qualification-template.json';current=json.loads((ROOT/path).read_bytes());previous=json.loads(subprocess.check_output(['git','-C',str(ROOT),'show','c4b2ae7cc72a91ff942f08f9b4725104f114640c:'+path]));key='native_source_inventory'
  added=set(current[key])-set(previous[key]);self.assertEqual(added,{'src/applications/project/xaction/PhorgeProjectDescriptionTransaction.php','src/infrastructure/governance/content/PhorgeProjectDescriptionExecutor.php','src/infrastructure/governance/content/PhorgeProjectDescriptionRequest.php','src/infrastructure/storage/connection/AphrontDatabaseTransactionState.php','src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php'})
  changed={n for n in set(previous[key])&set(current[key])if previous[key][n]!=current[key][n]};self.assertEqual(changed,{'src/__phutil_library_map__.php','src/infrastructure/governance/content/PhorgeProjectContentGrantService.php','src/infrastructure/governance/content/PhorgeProjectContentSource.php','src/infrastructure/governance/execution/PhorgeProjectNativeExecutionScope.php'})
  path='support/governance/project-content-grants-ci/prerequisites-template.json';old=json.loads(subprocess.check_output(['git','-C',str(ROOT),'show','c4b2ae7cc72a91ff942f08f9b4725104f114640c:'+path]));now=json.loads((ROOT/path).read_bytes())
  self.assertEqual({k:now[k]for k in ['native_git_revision','native_git_tree','native_public_source_dependencies']},{k:old[k]for k in ['native_git_revision','native_git_tree','native_public_source_dependencies']})
 def test_four_fixture_metadata_paths_need_complete_exact_root_and_historical_bytes(self):
  d=load('stageB_N05_delta',ROOT/'scripts/governance/project_content_grant_source_delta.py');raw={n:(ROOT/n).read_bytes()for n in d.FIXTURE_METADATA_PINS};changed,out=d.fixture_historical_inputs(ROOT,'060ac7f2b631cc5964f28cd783082dd6e2fb1565',set(raw),raw);self.assertEqual(changed,set());self.assertEqual(out,{})
  bad=dict(raw);name=next(iter(raw));bad[name]+=b'unknown'
  with self.assertRaises(ValueError):d.fixture_historical_inputs(ROOT,CI.BASE,set(bad),bad)
  with self.assertRaises(ValueError):d.fixture_historical_inputs(ROOT,CI.TARGET,set(raw),raw)
  with tempfile.TemporaryDirectory()as td:
   root=Path(td)
   for n,b in raw.items():p=root/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b)
   (root/name).write_bytes(raw[name]+b'unknown')
   with self.assertRaises(ValueError):d.fixture_historical_inputs(root,CI.BASE,set(raw),raw)
