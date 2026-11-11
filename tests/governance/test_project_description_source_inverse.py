"""Exact production inverse preparation; never replacement source admission."""
import hashlib,json,subprocess,sys,tempfile,unittest,zlib,base64
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_description_source_delta as delta
def tracked_src_inventory(root):
 import os,stat
 root=Path(root).resolve();rows=subprocess.check_output(['git','--no-replace-objects','-C',str(root),'ls-tree','-r','-z','HEAD','--','src']).split(b'\0');tracked={}
 for row in filter(None,rows):
  header,path=row.split(b'\t',1);mode,kind,oid=header.split()
  if kind!=b'blob' or mode not in (b'100644',b'100755',b'120000'):raise ValueError('unexpected tracked source type')
  tracked[path.decode()]=mode.decode()
 actual={}
 for path in (root/'src').rglob('*'):
  info=path.lstat()
  if stat.S_ISDIR(info.st_mode):continue
  mode='120000'if stat.S_ISLNK(info.st_mode)else'100755'if stat.S_ISREG(info.st_mode)and info.st_mode&0o111 else'100644'if stat.S_ISREG(info.st_mode)else'invalid'
  actual[str(path.relative_to(root))]=mode
 if not set(tracked)<=set(actual):raise ValueError('missing tracked source')
 for name,mode in tracked.items():
  info=(root/name).lstat()
  if mode=='120000':
   if not stat.S_ISLNK(info.st_mode):raise ValueError('tracked source kind changed')
  elif not stat.S_ISREG(info.st_mode)or stat.S_IMODE(info.st_mode)!=(0o755 if mode=='100755'else 0o644):raise ValueError('tracked source physical mode changed')
 return tracked,set(actual)-set(tracked)
def prepared_tracked_src_names(root,operation=None):
 root=Path(root).resolve();tracked,extra=tracked_src_inventory(root)
 if extra or operation is not None:import source_operation_snapshot as source
 if operation is not None:source.require_operation(operation,root)
 if extra:
  source.require_operation(operation,root)
  ledger=operation._prepared_ledgers[root]
  if type(ledger)is not source.PreparedLedger or ledger._root!=root or ledger._owner is not operation:raise ValueError('unissued prepared ledger')
  ledger.verify_exit(operation)
  allowed={name for name,role in ledger._layout.artifact.items()if name.startswith('src/')and role=='symbol-cache'}
  if extra!=allowed:raise ValueError('untracked source is not a verified cache artifact')
 return tuple(sorted(tracked))
from contextlib import contextmanager
@contextmanager
def verified_tracked_src_names(root):
 _,extra=tracked_src_inventory(root)
 if extra:import source_operation_snapshot as source
 if extra:
  with source.open_source_operation(root)as operation:
   yield prepared_tracked_src_names(root,operation)
 else:yield prepared_tracked_src_names(root)
class DescriptionSourceInverse(unittest.TestCase):
 def git_bytes(self,revision,path):
  q=subprocess.run(['git','--no-replace-objects','-C',str(ROOT),'show',revision+':'+path],capture_output=True)
  return None if q.returncode else q.stdout
 def test_exact18_reconstructs_all_real_main14_and_original_historical_chain_bytes(self):
  snapshot=delta.current_snapshot(ROOT);self.assertEqual(len(snapshot),19)
  main=delta.reconstruct_main(snapshot);prior=delta.reconstruct_historical(snapshot,ROOT);self.assertEqual(len(main),14)
  for path in delta.MAIN_BASELINE['production_pins']:
   self.assertEqual(main.get(path),self.git_bytes(delta.MAIN_BASELINE['git_revision'],path),path)
   self.assertEqual(prior.get(path),self.git_bytes(delta.HISTORICAL_BASELINE['git_revision'],path),path)
  self.assertEqual(set(snapshot)-{delta.NATIVE_STATE_BASELINE['path'],delta.NATIVE_EDITOR_BASELINE['path']}-set(main),{'src/applications/project/xaction/PhorgeProjectDescriptionTransaction.php','src/infrastructure/governance/content/PhorgeProjectDescriptionExecutor.php','src/infrastructure/governance/content/PhorgeProjectDescriptionRequest.php'})
 def test_native_Editor_independent_inverse_matches_real_c4_and_refuses_unknown(self):
  snapshot=delta.current_snapshot(ROOT);row=delta.NATIVE_EDITOR_BASELINE
  self.assertEqual(delta.reconstruct_native_editor(snapshot),self.git_bytes(row['git_revision'],row['path']))
  actual=subprocess.check_output(['git','-C',str(ROOT),'ls-tree',row['git_revision'],'--',row['path']]).split()
  self.assertEqual(actual[:3],[row['git_mode'].encode(),b'blob',row['git_blob'].encode()])
  self.assertEqual(subprocess.check_output(['git','-C',str(ROOT),'rev-parse',row['git_revision']+'^{tree}']).decode().strip(),row['git_tree'])
  for change in ('unknown','missing'):
   bad=dict(snapshot)
   if change=='missing':bad.pop(row['path'])
   else:bad[row['path']]+=b'unknown'
   with self.assertRaises(ValueError):delta.reconstruct_native_editor(bad)
  with patch.dict(row,{'git_blob':'0'*40}),self.assertRaises(ValueError):delta.reconstruct_native_editor(snapshot)
 def test_native_Editor_mode_and_whole_old_inverse_literals_are_closed(self):
  import shutil
  with tempfile.TemporaryDirectory()as td:
   root=Path(td).resolve()
   for name in delta.CURRENT_PINS:
    path=root/name;path.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(ROOT/name,path)
   delta.current_snapshot(root)
   (root/delta.NATIVE_EDITOR_BASELINE['path']).chmod(0o600)
   with self.assertRaises(ValueError):delta.current_snapshot(root)
  old=subprocess.check_output(['git','-C',str(ROOT),'show','5c22ca0d0b6acab2889fac7ddda03e630f858e64:scripts/governance/project_description_source_delta.py'])
  import ast
  def constants(raw):
   result={}
   for node in ast.parse(raw).body:
    if isinstance(node,ast.Assign)and len(node.targets)==1 and isinstance(node.targets[0],ast.Name):
     try:result[node.targets[0].id]=ast.literal_eval(node.value)
     except (ValueError,TypeError):pass
   return result
  prior=constants(old);now=constants((ROOT/'scripts/governance/project_description_source_delta.py').read_bytes())
  for name in ('MAIN_BASELINE','HISTORICAL_BASELINE','LEGACY_REDUCER','NATIVE_STATE_BASELINE','MAIN_INVERSE_ZLIB_BASE64'):self.assertEqual(now[name],prior[name])
 def test_Editor_normalizer_uses_independent_baseline_and_q50_is_exact_one_addition(self):
  import ast
  bridge=ROOT/'scripts/governance/project_description_admission_delta.py'
  node=next(n for n in ast.parse(bridge.read_bytes()).body if isinstance(n,ast.FunctionDef)and n.name=='_normalize_production')
  namespace={'description':delta,'ROOT':ROOT,'sha':lambda b:hashlib.sha256(b).hexdigest(),'_exact':delta.exact,'zlib':zlib,'base64':base64}
  exec(compile(ast.Module(body=[node],type_ignores=[]),'<unadmitted-normalizer-function>','exec'),namespace)
  path=delta.NATIVE_EDITOR_BASELINE['path'];raw=(ROOT/path).read_bytes()
  self.assertEqual(namespace['_normalize_production'](path,raw),self.git_bytes(delta.NATIVE_EDITOR_BASELINE['git_revision'],path))
  with self.assertRaises(ValueError):namespace['_normalize_production'](path,raw+b'unknown')
  name='support/governance/project-content-grants-ci/qualification-template.json';old=json.loads(self.git_bytes('5c22ca0d0b6acab2889fac7ddda03e630f858e64',name));current=json.loads((ROOT/name).read_bytes())
  inventory=current['native_source_inventory']
  self.assertEqual(set(inventory),set(old['native_source_inventory'])|{path})
  self.assertEqual(len(inventory),50)
  import importlib.util
  ci_path=ROOT/'support/governance/project-content-grants-ci/ci.py'
  spec=importlib.util.spec_from_file_location('q50_actual_native_authority',ci_path);authority=importlib.util.module_from_spec(spec);spec.loader.exec_module(authority)
  def verify_declared_native(template):
   import posixpath,re
   revision=template['native_git_revision'];tree=template['native_git_tree']
   self.assertEqual(revision,authority.TARGET);self.assertEqual(tree,authority.TREE)
   self.assertRegex(revision,r'^[0-9a-f]{40}$');self.assertRegex(tree,r'^[0-9a-f]{40}$')
   actual_tree=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'rev-parse',revision+'^{tree}']).decode().strip()
   self.assertEqual(tree,actual_tree)
   for original,pin in template['native_source_inventory'].items():
    self.assertEqual(set(pin),{'bytes','sha256'})
    self.assertTrue(type(original)is str and not original.startswith('/')and all(part not in ('','.','..')for part in original.split('/')))
    name=original;seen=set()
    for depth in range(16):
     self.assertNotIn(name,seen);seen.add(name)
     entry=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'ls-tree','-z',revision,'--',name])
     self.assertTrue(entry.endswith(b'\0'))
     header,actual_name=entry[:-1].split(b'\t');mode,kind,oid=header.split()
     self.assertEqual(actual_name,name.encode());self.assertEqual(kind,b'blob');self.assertIn(mode,(b'100644',b'100755',b'120000'))
     native=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'cat-file','blob',oid.decode()])
     self.assertEqual(hashlib.sha1(b'blob '+str(len(native)).encode()+b'\0'+native).hexdigest(),oid.decode())
     if mode!=b'120000':break
     target=native.decode();self.assertFalse(target.startswith('/'))
     name=posixpath.normpath(posixpath.join(posixpath.dirname(name),target))
     self.assertTrue(name and name!='..'and not name.startswith('../'))
    else:self.fail('native dependency symlink depth exceeded')
    self.assertEqual(pin,{'bytes':len(native),'sha256':hashlib.sha256(native).hexdigest()},original)
  verify_declared_native(current)
  import copy
  wrong=copy.deepcopy(current);wrong['native_git_tree']='0'*40
  with self.assertRaises(AssertionError):verify_declared_native(wrong)
  wrong=copy.deepcopy(current);wrong['native_source_inventory'][path]['sha256']='0'*64
  with self.assertRaises(AssertionError):verify_declared_native(wrong)
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
  with verified_tracked_src_names(ROOT)as names:
   for name in names:
    path=ROOT/name
    name=str(path.relative_to(ROOT));raw=os.readlink(path).encode() if path.is_symlink() else path.read_bytes()
    mode='120000' if path.is_symlink() else ('100755' if path.stat().st_mode&0o111 else '100644')
    if name in snapshot:
     if name==delta.NATIVE_EDITOR_BASELINE['path']:raw=delta.reconstruct_native_editor(snapshot)
     elif name==delta.NATIVE_STATE_BASELINE['path']:raw=delta.reconstruct_native_state(snapshot)
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
   self.assertEqual(set(metadata['production_pins']),set(delta.CURRENT_PINS)-{delta.NATIVE_STATE_BASELINE['path'],delta.NATIVE_EDITOR_BASELINE['path']})
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

class TrackedSourceArtifacts(unittest.TestCase):
 def test_bare_tracked_domain_and_fake_operation_are_exact(self):
  names=prepared_tracked_src_names(ROOT)
  expected=set(subprocess.check_output(['git','-C',str(ROOT),'ls-tree','-r','--name-only','HEAD','--','src'],text=True).splitlines())
  self.assertEqual(set(names),expected)
  with self.assertRaises(ValueError):prepared_tracked_src_names(ROOT,object())
 def test_prepared_cache_components_are_not_source_or_issued_proof(self):
  import source_operation_snapshot as source
  with tempfile.TemporaryDirectory()as td:
   root=Path(td).resolve();path=root/'src/owned.php';path.parent.mkdir();path.write_bytes(b'<?php owned');path.chmod(0o644)
   cache=root/'src/.phutil_module_cache';cache.write_text('{"__symbol_cache_version__":12}');cache.chmod(0o644)
   expected={'src/owned.php':'100644'};layout=source._prepared_layout(root,expected)
   self.assertEqual(dict(layout.artifact),{'src/.phutil_module_cache':'symbol-cache'})
   owner=object();ledger=source.PreparedLedger(source._PREPARED_KEY,owner,root,layout);ledger._verify_data()
   with self.assertRaises(ValueError):ledger.verify_exit(owner)
   cache.write_text('{"__symbol_cache_version__":11}')
   with self.assertRaises(ValueError):source.PreparedLedger(source._PREPARED_KEY,owner,root,source._prepared_layout(root,expected))
   cache.write_text('{"__symbol_cache_version__":12}')
   unknown=root/'src/unreviewed.php';unknown.write_bytes(b'<?php unknown');unknown.chmod(0o644)
   with self.assertRaises(ValueError):source._prepared_layout(root,expected)
