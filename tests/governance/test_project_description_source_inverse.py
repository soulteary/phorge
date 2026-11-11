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
 def test_exact18_reconstructs_all_real_main14_and_original_historical_chain_bytes(self):
  snapshot=delta.current_snapshot(ROOT);self.assertEqual(len(snapshot),18)
  main=delta.reconstruct_main(snapshot);prior=delta.reconstruct_historical(snapshot,ROOT);self.assertEqual(len(main),14)
  for path in delta.MAIN_BASELINE['production_pins']:
   self.assertEqual(main.get(path),self.git_bytes(delta.MAIN_BASELINE['git_revision'],path),path)
   self.assertEqual(prior.get(path),self.git_bytes(delta.HISTORICAL_BASELINE['git_revision'],path),path)
  self.assertEqual(set(snapshot)-{delta.NATIVE_STATE_BASELINE['path']}-set(main),{'src/applications/project/xaction/PhorgeProjectDescriptionTransaction.php','src/infrastructure/governance/content/PhorgeProjectDescriptionExecutor.php','src/infrastructure/governance/content/PhorgeProjectDescriptionRequest.php'})
 def test_native_State_inverse_is_exact_real_c4_and_old_current_or_unknown_refuse(self):
  good=delta.current_snapshot(ROOT);row=delta.NATIVE_STATE_BASELINE
  self.assertEqual(delta.reconstruct_native_state(good),self.git_bytes(row['git_revision'],row['path']))
  for raw in [self.git_bytes(row['git_revision'],row['path']),b'<?php unknown']:
   bad=dict(good);bad[row['path']]=raw
   with self.assertRaises(ValueError):delta.reconstruct_native_state(bad)
  with patch.dict(delta.NATIVE_STATE_BASELINE,{'git_revision':'0'*40}),self.assertRaises(ValueError):delta.reconstruct_native_state(good)
 def test_entire_current_src_inverse_matches_real_c4_tree_including_State_mode_blob(self):
  import os,stat
  snapshot=delta.current_snapshot(ROOT);main=delta.reconstruct_main(snapshot);actual={}
  for path in (ROOT/'src').rglob('*'):
   if path.is_dir() and not path.is_symlink():continue
   name=str(path.relative_to(ROOT));raw=os.readlink(path).encode() if path.is_symlink() else path.read_bytes()
   mode='120000' if path.is_symlink() else ('100755' if path.stat().st_mode&0o111 else '100644')
   if name in snapshot:
    if name==delta.NATIVE_STATE_BASELINE['path']:raw=delta.reconstruct_native_state(snapshot)
    elif name not in main:continue
    else:raw=main[name]
   actual[name]=(mode,hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest())
  # Three previously reviewed documentation leaves are source, not ignored.
  for leaf in ['README.description-executor.md','README.description-main-integration.md','README.description-source-inverse.md']:
   name='src/infrastructure/governance/content/'+leaf
   self.assertIsNone(self.git_bytes(delta.MAIN_BASELINE['git_revision'],name))
   self.assertEqual((ROOT/name).read_bytes(),self.git_bytes('20b138b4cd9a2cf61522613b4a8e5e21eca7341b',name))
   self.assertEqual(actual.pop(name)[0],'100644')
  output=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'ls-tree','-r',delta.MAIN_BASELINE['git_revision'],'--','src'],text=True)
  expected={}
  for line in output.splitlines():
   info,path=line.split('\t',1);mode,kind,blob=info.split();expected[path]=(mode,blob)
  self.assertEqual(actual,expected)
  row=delta.NATIVE_STATE_BASELINE;self.assertEqual(expected[row['path']],(row['git_mode'],row['git_blob']))
 def test_operation_registry_real20b_baseline_successor18_and_six_new_paths(self):
  registry=json.loads((ROOT/'support/governance/source-operation/registry-v1.json').read_bytes())
  self.assertEqual(registry['source_revision'],'20b138b4cd9a2cf61522613b4a8e5e21eca7341b')
  entries=dict(registry['entries'])
  for path,pin in entries.items():
   raw=self.git_bytes(registry['source_revision'],path)
   self.assertIsNotNone(raw,path);self.assertEqual(pin,{'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()},path)
  for path,row in registry['successor_entries'].items():
   self.assertEqual(row['baseline'],entries.get(path),path)
   # The bridge row is the exact signed native predecessor; current held bytes are separately admitted.
   raw=self.git_bytes('43ba912021d1fd05e04eae0b5225ad3502926e51',path) if path=='scripts/governance/project_description_admission_delta.py' else (ROOT/path).read_bytes()
   self.assertEqual(row['current'],{'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()},path)
   entries[path]=row['current']
  for path,pin in delta.CURRENT_PINS.items():self.assertEqual(entries[path],pin,path)
  self.assertEqual(len(registry['new_paths']),6)
  for path in registry['new_paths']:self.assertIsNone(self.git_bytes(registry['source_revision'],path))
 def test_complete_baseline_metadata_has_actual_git_revision_tree_and_every_pin(self):
  for metadata in [delta.MAIN_BASELINE,delta.HISTORICAL_BASELINE]:
   tree=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'rev-parse',metadata['git_revision']+'^{tree}'],text=True).strip();self.assertEqual(metadata['git_tree'],tree)
   self.assertEqual(set(metadata['production_pins']),set(delta.CURRENT_PINS)-{delta.NATIVE_STATE_BASELINE['path']})
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

class PrivateStateInverse(unittest.TestCase):
 """Real graph builder on explicitly UNADMITTED test harness, never issued op."""
 def graph(self):
  import source_operation_snapshot as source
  class UnadmittedGraphHarness:
   def __init__(self):
    self.code_root=ROOT;self._modules={};self._auxiliary_modules=[]
   def require(self,*args):return self
   def read_source(self,path):return Path(path).read_bytes()
   def _admitted_root(self,*args):raise ValueError('unadmitted_graph_harness')
   @property
   def coordinator(self):return self._coordinator
  harness=UnadmittedGraphHarness()
  source.SourceOperation._build_graph(harness)
  return harness,harness._modules['project_description_source_delta']
 def test_real_private_graph_State_inverse_matches_actual_c4_without_constants_patch(self):
  from types import MappingProxyType
  harness,private=self.graph()
  self.assertIs(type(private.CURRENT_PINS),MappingProxyType)
  snapshot=private.current_snapshot(ROOT)
  raw=private.reconstruct_native_state(snapshot)
  row=delta.NATIVE_STATE_BASELINE
  expected=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show',row['git_revision']+':'+row['path']])
  self.assertEqual(raw,expected)
  self.assertIsNot(private.constants,delta.constants)
  with self.assertRaises(ValueError):harness._admitted_root()
 def test_private_frozen_constants_reject_mutation_replacement_unknown_hash_and_fake_mapping(self):
  from collections import UserDict
  from types import MappingProxyType
  harness,private=self.graph();snapshot=private.current_snapshot(ROOT)
  path=private.NATIVE_STATE_BASELINE['path']
  with self.assertRaises(TypeError):private.CURRENT_PINS[path]['sha256']='0'*64
  bad=dict(snapshot);bad[path]+=b'unknown'
  with self.assertRaises(ValueError):private.reconstruct_native_state(bad)
  for fake in (UserDict({'bytes':1,'sha256':'0'*64}),type('FakeDict',(dict,),{})({'bytes':1,'sha256':'0'*64})):
   with self.assertRaises(ValueError):private.exact(b'x',fake)
   with self.assertRaises(ValueError):private.canonical_constant(fake)
  original=private.CURRENT_PINS
  replacement={k:dict(v)for k,v in original.items()};replacement[path]['sha256']='0'*64
  private.CURRENT_PINS=replacement
  with self.assertRaises(ValueError):private.constants()
  private.CURRENT_PINS=original
  with self.assertRaises(ValueError):private.exact(bytearray(b'x'),{'bytes':1,'sha256':hashlib.sha256(b'x').hexdigest()})
 def test_actual_admission_State_normalizer_is_exact_c4_and_keeps_old_MAIN17(self):
  import project_description_admission_delta as admission
  snapshot=delta.current_snapshot(ROOT);path=delta.NATIVE_STATE_BASELINE['path']
  expected=delta.reconstruct_native_state(snapshot)
  self.assertEqual(admission._normalize_production(path,snapshot[path]),expected)
  self.assertEqual(admission._normalize_production(path,expected),expected)
  with self.assertRaises(ValueError):admission._normalize_production(path,b'unknown')
  self.assertNotIn(path,delta.MAIN_BASELINE['production_pins'])
  self.assertEqual(len(delta.MAIN_BASELINE['production_pins']),17)

if __name__=='__main__':unittest.main()
