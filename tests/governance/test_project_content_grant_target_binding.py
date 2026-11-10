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
 def test_actual_36_baseline_and45_target_Git_pins_and_typed_symlinks(self):
  for kind,key,count,revision,tree in [('prerequisites','native_public_source_dependencies',36,CI.BASE,CI.BASE_TREE),('qualification','native_source_inventory',45,CI.TARGET,CI.TREE)]:
   f=json.loads((ROOT/'support/governance/project-content-grants-ci'/(kind+'-template.json')).read_bytes());self.assertEqual(f['schema'],'N05-CI-reviewed-source-template-v2');self.assertEqual((f['native_git_revision'],f['native_git_tree']),(revision,tree));self.assertEqual(len(f[key]),count)
   for n,pin in f[key].items():
    name='scripts/sql/manage_storage.php'if kind=='qualification'and n=='bin/storage'else n
    raw=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show',revision+':'+name]);self.assertEqual({'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()},{k:pin[k]for k in ('bytes','sha256')})
   if kind=='prerequisites':self.assertEqual(subprocess.check_output(['git','-C',str(ROOT),'show',revision+':bin/storage']),b'../scripts/sql/manage_storage.php')
 def test_real_qualification_manifest_and_old_schema_or_identity_fail_closed(self):
  folder=ROOT/'support/governance/project-content-grants-qualification';path=ROOT/'support/governance/project-content-grants-ci/qualification-template.json'
  bound=CI.bind_manifest(path,folder,'qualification',ROOT);self.assertEqual(bound['schema'],'N05-CI-bound-reviewed-source-v2');self.assertEqual(bound['native_git_revision'],CI.TARGET)
  f=json.loads(path.read_bytes())
  for key,value in [('schema','N05-CI-reviewed-source-template-v1'),('native_git_revision','79987a76658150e77fc5266fae13c2eaed934ff2'),('native_git_tree','0'*40)]:
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
