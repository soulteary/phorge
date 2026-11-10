"""Exact production inverse preparation; never replacement source admission."""
import hashlib,json,subprocess,sys,tempfile,unittest,zlib,base64
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_description_source_delta as delta
class DescriptionSourceInverse(unittest.TestCase):
 def git_bytes(self,revision,path):
  q=subprocess.run(['git','--no-replace-objects','-C',str(ROOT),'show',revision+':'+path],capture_output=True)
  return None if q.returncode else q.stdout
 def test_exact17_reconstructs_all_real_main14_and_original_historical_chain_bytes(self):
  snapshot=delta.current_snapshot(ROOT);self.assertEqual(len(snapshot),17)
  main=delta.reconstruct_main(snapshot);prior=delta.reconstruct_historical(snapshot,ROOT);self.assertEqual(len(main),14)
  for path in snapshot:
   self.assertEqual(main.get(path),self.git_bytes(delta.MAIN_BASELINE['git_revision'],path),path)
   self.assertEqual(prior.get(path),self.git_bytes(delta.HISTORICAL_BASELINE['git_revision'],path),path)
  self.assertEqual(set(snapshot)-set(main),{'src/applications/project/xaction/PhorgeProjectDescriptionTransaction.php','src/infrastructure/governance/content/PhorgeProjectDescriptionExecutor.php','src/infrastructure/governance/content/PhorgeProjectDescriptionRequest.php'})
 def test_complete_baseline_metadata_has_actual_git_revision_tree_and_every_pin(self):
  for metadata in [delta.MAIN_BASELINE,delta.HISTORICAL_BASELINE]:
   tree=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'rev-parse',metadata['git_revision']+'^{tree}'],text=True).strip();self.assertEqual(metadata['git_tree'],tree)
   self.assertEqual(set(metadata['production_pins']),set(delta.CURRENT_PINS))
   for path,pin in metadata['production_pins'].items():
    raw=self.git_bytes(metadata['git_revision'],path)
    if pin is None:self.assertIsNone(raw)
    else:self.assertEqual(pin,{'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()})
 def test_every_missing_mutated_unknown_and_four_mixed_old_paths_refuse(self):
  good=delta.current_snapshot(ROOT)
  for path in good:
   missing=dict(good);missing.pop(path)
   with self.assertRaises(ValueError):delta.reconstruct_main(missing)
   bad=dict(good);bad[path]+=b'unknown'
   with self.assertRaises(ValueError):delta.reconstruct_main(bad)
  bad=dict(good);bad['src/infrastructure/governance/content/Unknown.php']=b'<?php'
  with self.assertRaises(ValueError):delta.reconstruct_main(bad)
  self.assertEqual(len(delta.MAIN_INVERSE_ZLIB_BASE64),4)
  for path in delta.MAIN_INVERSE_ZLIB_BASE64:
   mixed=dict(good);mixed[path]=self.git_bytes(delta.MAIN_BASELINE['git_revision'],path)
   with self.assertRaises(ValueError):delta.reconstruct_main(mixed)
 def test_modified_current_pin_and_complete_baseline_object_refuse(self):
  good=delta.current_snapshot(ROOT);path=next(iter(good));bad=dict(good);bad[path]+=b'unknown';fake={'bytes':len(bad[path]),'sha256':hashlib.sha256(bad[path]).hexdigest()}
  with patch.dict(delta.CURRENT_PINS,{path:fake}),self.assertRaises(ValueError):delta.reconstruct_main(bad)
  for field in ['git_revision','git_tree']:
   with patch.dict(delta.MAIN_BASELINE,{field:'0'*40}),self.assertRaises(ValueError):delta.reconstruct_main(good)
  with patch.dict(delta.LEGACY_REDUCER,{'sha256':'0'*64}),self.assertRaises(ValueError):delta.reconstruct_historical(good,ROOT)
 def test_inverse_payload_corruption_and_unreviewed_legacy_reducer_refuse(self):
  good=delta.current_snapshot(ROOT);path=next(iter(delta.MAIN_INVERSE_ZLIB_BASE64));changed=base64.b64encode(zlib.compress(b'unknown')).decode()
  with patch.dict(delta.MAIN_INVERSE_ZLIB_BASE64,{path:changed}),self.assertRaises(ValueError):delta.reconstruct_main(good)
  with tempfile.TemporaryDirectory()as t:
   root=Path(t).resolve();p=root/delta.LEGACY_REDUCER['path'];p.parent.mkdir(parents=True);p.write_bytes((ROOT/delta.LEGACY_REDUCER['path']).read_bytes()+b'\n# unknown')
   with self.assertRaises(ValueError):delta.reconstruct_historical(good,root)
 def test_read_alias_refuses_and_existing_main_guards_generic_editor_are_unchanged(self):
  with tempfile.TemporaryDirectory()as t:
   p=Path(t).resolve()/'alias';p.symlink_to(ROOT/next(iter(delta.CURRENT_PINS)))
   with self.assertRaises(ValueError):delta.checked_bytes(p)
  for path in [delta.LEGACY_REDUCER['path'],'scripts/governance/project_content_grant_ci_source_delta.py','src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php','src/infrastructure/governance/content/PhorgeProjectContentGrantStore.php']:
   self.assertEqual((ROOT/path).read_bytes(),self.git_bytes(delta.MAIN_BASELINE['git_revision'],path),path)
 def test_preparation_success_does_not_replace_current_full_source_gate(self):
  result=delta.audit(ROOT);self.assertTrue(result['inverse_verified'])
  for flag in ['full_source_admission','runtime_qualified','can_execute','activation_authorized']:self.assertFalse(result[flag])
  import project_content_grant_source_delta as old
  with self.assertRaisesRegex(ValueError,'unknown or mixed N05 production cohort'):old.assert_production_cohort(ROOT)
  q=subprocess.run([sys.executable,'-B',str(ROOT/'scripts/governance/check_directory_transports_v1.py'),'--source-only','--source-revision',delta.MAIN_BASELINE['git_revision']],cwd=ROOT,capture_output=True)
  self.assertNotEqual(q.returncode,0)
if __name__=='__main__':unittest.main()
