"""Exact source-only historical test helper negatives; no runtime authority."""
import pathlib,unittest
from unittest.mock import patch
import n05_r3_historical_test_view as view
class R3HistoricalView(unittest.TestCase):
 def test_reviewed_directory_snapshot_projection_retains_exact_static_semantics(self):
  import copy,hashlib,json,runpy,subprocess
  name='docs/governance/native-directory-callers.json';raw=(view.ROOT/name).read_bytes()
  prior=view._policy_ack_directory_to_ed66(name,raw)
  self.assertEqual(prior,subprocess.check_output(['git','--no-replace-objects','-C',str(view.ROOT),'show','f1c157e44a1eeca49529929f099fb5c57bcbbddc:'+name]))
  expected=json.loads(prior);actual=json.loads(raw)
  for row in expected['sources']+expected['scan']['files']:row['sha256']=hashlib.sha256((view.ROOT/row['path']).read_bytes()).hexdigest()
  self.assertEqual(actual,expected)
  checker=runpy.run_path(str(view.ROOT/'scripts/governance/check_native_directory.py'),run_name='reviewed_directory_snapshot_test')
  self.assertEqual(checker['validate'](actual,view.ROOT),{'passed':True,'sources':167,'links':164,'runtime_files':138,'coverage':'partial','activation_blocked':True,'runtime_certified':False,'authority_proof':False})
  for path,value in ((name,raw+b'unknown'),(name,prior),('docs/unknown.json',raw)):
   with self.assertRaises(ValueError):view._policy_ack_directory_to_ed66(path,value)
  with patch.dict(view._DIRECTORY_POLICY_ACK_SOURCE,{'sha256':'0'*64}),self.assertRaises(ValueError):view._policy_ack_directory_to_ed66(name,raw)
 def test_reviewed_pipeline_and_notice_fingerprints_preserve_all_old_stages(self):
  import hashlib,subprocess
  old_editor=b'b52470c03f5090d368c4cc0bb70ca146feaa1ece705bdb28231270ee9afeca54'
  new_editor=hashlib.sha256((view.ROOT/'src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php').read_bytes()).hexdigest().encode()
  self.assertEqual(set(view._LIFECYCLE_POLICY_ACK_SOURCES),set(view._LIFECYCLE_REVIEWED_ED66))
  for path in view._LIFECYCLE_POLICY_ACK_SOURCES:
   old=subprocess.check_output(['git','--no-replace-objects','-C',str(view.ROOT),'show','f1c157e44a1eeca49529929f099fb5c57bcbbddc:'+path])
   self.assertEqual(old.count(old_editor),1);new=old.replace(old_editor,new_editor,1)
   self.assertEqual(view._policy_ack_lifecycle_to_ed66(path,new),old)
   for value in (new+b'unknown',old):
    with self.assertRaises(ValueError):view._policy_ack_lifecycle_to_ed66(path,value)
  with self.assertRaises(ValueError):view._policy_ack_lifecycle_to_ed66('docs/unknown.json',new)
  with patch.object(view,'_LIFECYCLE_POLICY_ACK_SOURCES',{}),self.assertRaises(ValueError):view._policy_ack_lifecycle_to_ed66(path,new)
 def test_reviewed_policy_ack_caller_snapshot_is_exact_public_projection_only(self):
  import copy,hashlib,json,runpy,subprocess
  name='docs/governance/batch-2-callers.json';raw=(view.ROOT/name).read_bytes()
  old=view._policy_ack_batch_to_ed66(name,raw)
  self.assertEqual(old,subprocess.check_output(['git','--no-replace-objects','-C',str(view.ROOT),'show','f1c157e44a1eeca49529929f099fb5c57bcbbddc:'+name]))
  prior=json.loads(old);actual=json.loads(raw);expected=copy.deepcopy(prior)
  row=next(x for x in expected['consumers']if x['id']=='TRANSACTION.LEGACY')
  row['sha256']=hashlib.sha256((view.ROOT/row['path']).read_bytes()).hexdigest()
  checker=runpy.run_path(str(view.ROOT/'scripts/governance/check_callers.py'),run_name='reviewed_caller_snapshot_test')
  expected['scan']=checker['scan_sources'](view.ROOT)
  self.assertEqual(actual,expected)
  self.assertEqual(checker['validate'](actual,view.ROOT,expected['scan']),{'schema_valid':True,'consumers':47,'static_links':40,'scanner_candidates':277,'unsupported_candidates':241,'activation_blocked':True,'runtime_certified':False})
  self.assertEqual({x['id']for x in prior['scan']['candidates']},{x['id']for x in actual['scan']['candidates']})
  for bad_path,bad in ((name,raw+b'unknown'),(name,old),('docs/unknown.json',raw),(name,raw.replace(b'"activation_blocked": true',b'"activation_blocked": false',1))):
   with self.subTest(path=bad_path),self.assertRaises(ValueError):view._policy_ack_batch_to_ed66(bad_path,bad)
  with patch.dict(view._BATCH_POLICY_ACK_SOURCE,{'sha256':'0'*64}),self.assertRaises(ValueError):view._policy_ack_batch_to_ed66(name,raw)
  # The real entry still rejects stale native identity or current forward seals;
  # this pure document projection never substitutes for those admission gates.
 def test_exact_actual_modules_load_and_unknown_pins_do_not(self):
  self.assertEqual(len(view.PINS),9);self.assertEqual(len(view.checked_modules().SOURCE_INPUTS),16)
  name=next(iter(view.PINS))
  with patch.dict(view.PINS,{name:'0'*64}),self.assertRaises(ValueError):view.checked_modules()
  with patch.dict(view.PINS,{'unreviewed.py':'0'*64}),self.assertRaises(ValueError):view.checked_modules()
 def test_unknown_path_or_mutated_bytes_cannot_be_historical(self):
  name='tests/governance/n05_r3_historical_test_view.py'
  with self.assertRaises(ValueError):view.historical_coordinator_source(name,(view.ROOT/name).read_bytes())
  name=next(iter(view.checked_modules().SOURCE_INPUTS));raw=(view.ROOT/name).read_bytes()
  with self.assertRaises(ValueError):view.historical_coordinator_source(name,raw+b'unknown')
 def test_signed_R3_media_predecessor_rejects_unknown_or_unselected_payload(self):
  import subprocess
  name='support/governance/media-session-docker/snapshot.py'
  raw=subprocess.check_output(['git','--no-replace-objects','-C',str(view.ROOT),'show','432bd0166880b4a6ab4820cfebcd4482cf89bb27:'+name])
  self.assertIsInstance(view.historical_R3_media_recipe_source(name,raw),bytes)
  with self.assertRaises(ValueError):view.historical_R3_media_recipe_source(name,raw+b'unknown')
  with self.assertRaises(ValueError):view.historical_R3_media_recipe_source('tests/unknown.py',raw)
 def test_signed_F4_media_predecessor_is_exact_and_unknown_refused(self):
  import subprocess
  name='support/governance/media-session-docker/snapshot.py'
  raw=subprocess.check_output(['git','--no-replace-objects','-C',str(view.ROOT),'show','0496112f7d6706bb3d854117b7fd94a759978987:'+name])
  self.assertIsInstance(view.historical_F4_media_recipe_source(name,raw),bytes)
  with self.assertRaises(ValueError):view.historical_F4_media_recipe_source(name,raw+b'unknown')
  with self.assertRaises(ValueError):view.historical_F4_media_recipe_source('tests/unknown.py',raw)
 def test_signed_D95_media_predecessor_is_exact_and_unknown_refused(self):
  import subprocess
  name='support/governance/media-session-docker/snapshot.py'
  raw=subprocess.check_output(['git','--no-replace-objects','-C',str(view.ROOT),'show','d95b3bd2253afb6fdce615f4041920bdce5df625:'+name])
  self.assertIsInstance(view.historical_D95_media_recipe_source(name,raw),bytes)
  with self.assertRaises(ValueError):view.historical_D95_media_recipe_source(name,raw+b'unknown')
  with self.assertRaises(ValueError):view.historical_D95_media_recipe_source('tests/unknown.py',raw)
 def test_native577_batch_scan_view_is_exact_and_current_unknown_old_mode_missing_refused(self):
  import subprocess,tempfile
  from pathlib import Path
  from recovery_oauth_code_test_cohort import complete_source_cohort
  name='docs/governance/batch-2-callers.json';raw=(view.ROOT/name).read_bytes()
  prior=subprocess.check_output(['git','--no-replace-objects','-C',str(view.ROOT),'show',view.REVISION+':'+name])
  self.assertEqual(view._historical_batch_scan_source(name,raw),prior)
  for value in (raw+b'unknown',prior):
   with self.assertRaises(ValueError):view._historical_batch_scan_source(name,value)
  with self.assertRaises(ValueError):view._historical_batch_scan_source('docs/unknown.json',raw)
  for mode in ('executable','special-mode','missing','unknown-bytes'):
   with self.subTest(mode=mode),tempfile.TemporaryDirectory()as td:
    root=Path(td).resolve();complete_source_cohort(root);p=root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(raw);p.chmod(0o644)
    if mode=='executable':p.chmod(0o755)
    elif mode=='special-mode':p.chmod(0o1644)
    elif mode=='missing':p.unlink()
    else:p.write_bytes(raw+b'unknown')
    with self.assertRaises((ValueError,OSError)):view._historical_batch_scan_source(name,raw,root)
 def test_two_current_legacy_metadata_paths_are_exact_and_not_double_normalized(self):
  import hashlib
  import n05_legacy_audit_test_view as original
  module=view.checked_modules()
  self.assertEqual(len(original.METADATA_PATHS),2)
  for path in original.METADATA_PATHS:
   raw=(view.ROOT/path).read_bytes();prior=original.historical_metadata_source(path,raw)
   self.assertEqual(hashlib.sha256(prior).hexdigest(),original.METADATA_PRIOR_PINS[path])
   previous=original.git_bytes(original.CURRENT_REVISION,path)
   for bad in (raw+b'unknown',previous,prior):
    with self.subTest(path=path),self.assertRaises(ValueError):original.historical_metadata_source(path,bad)
   if path in module.SOURCE_INPUTS:
    self.assertEqual(hashlib.sha256(view.historical_coordinator_source(path,raw)).hexdigest(),module.BASE_PINS[path])
    for bad in (raw+b'unknown',previous,prior):
     with self.assertRaises(ValueError):view.historical_coordinator_source(path,bad)
   if path in module.authorization_delta.SOURCE_INPUTS:
    expected=module.authorization_delta.reduce_current(path,prior)
    self.assertEqual(view.historical_authorization_source(path,raw),expected)
    for bad in (raw+b'unknown',previous,prior):
     with self.assertRaises(ValueError):view.historical_authorization_source(path,bad)
 def test_authorization_and_lifecycle_document_stages_keep_current_inputs_strict(self):
  import hashlib,subprocess,shutil,tempfile
  from pathlib import Path
  from unittest.mock import patch
  module=view.checked_modules()
  families=((view.historical_authorization_source,module.authorization_delta),(view.historical_lifecycle_source,module.authorization_delta.lifecycle_delta))
  for path in sorted(view._DOCUMENT_PATHS):
   raw=(view.ROOT/path).read_bytes();prior=view._historical_document_source(path,raw)
   for function,current_module in families:
    if path not in current_module.SOURCE_INPUTS:continue
    self.assertEqual(function(path,raw),current_module.reduce_current(path,prior))
    for bad in (raw+b'unknown',prior):
     with self.subTest(path=path,family=function.__name__),self.assertRaises(ValueError):function(path,bad)
    with patch.object(view.current,'TARGET',view.REVISION),self.assertRaises(ValueError):function(path,raw)
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as td:
   root=Path(td)/'source';shutil.copytree(view.ROOT,root,symlinks=True,ignore=shutil.ignore_patterns('.git','__pycache__'))
   for path in sorted(view._DOCUMENT_PATHS):
    target=root/path;raw=target.read_bytes()
    for mode in (0o755,0o1644):
     target.chmod(mode)
     try:
      with self.subTest(path=path,mode=mode),self.assertRaises(ValueError):view._historical_document_source(path,raw,root)
     finally:target.chmod(0o644)
 def test_reviewed_ed66_stage_is_exact_git_and_preserves_old_stage_objects(self):
  import json,subprocess
  name=view._BATCH_PATH;stage=view._BATCH_REVIEWED_ED66
  reviewed=view._batch_stage_bytes(view.ROOT,stage)
  actual=(view.ROOT/name).read_bytes()
  view.current.assert_current_recipe(view.ROOT)
  self.assertNotEqual(reviewed,actual)
  self.assertEqual(view._policy_ack_batch_to_ed66(name,actual),reviewed)
  for bad in (actual+b'unknown',reviewed):
   with self.subTest(stage='policy-ack-current',mutated=bad!=reviewed),self.assertRaises(ValueError):
    view._policy_ack_batch_to_ed66(name,bad)
  mainline=view._batch_stage_bytes(view.ROOT,view._BATCH_MAINLINE)
  self.assertNotEqual(reviewed,mainline)
  before=json.loads(mainline);after=json.loads(reviewed)
  # The exact reviewed source snapshot and one Editor candidate changed;
  # candidate classification, consumer membership and all other fields did not.
  old_snapshot=after['scan']['source_snapshot_sha256']
  self.assertNotEqual(old_snapshot,before['scan']['source_snapshot_sha256'])
  after['scan']['source_snapshot_sha256']=before['scan']['source_snapshot_sha256']
  candidate=after['scan']['candidates'][266];prior=before['scan']['candidates'][266]
  self.assertEqual(candidate['path'],view.current.description.NATIVE_EDITOR_BASELINE['path'])
  self.assertNotEqual(candidate['sha256'],prior['sha256'])
  candidate['sha256']=prior['sha256']
  for current_match,old_match in zip(candidate['matches'],prior['matches']):
   for i,(line,old_line)in enumerate(zip(current_match['lines'],old_match['lines'])):
    if line!=old_line:self.assertEqual(line,old_line+4);current_match['lines'][i]=old_line
  self.assertNotEqual(after['consumers'][39]['sha256'],before['consumers'][39]['sha256'])
  after['consumers'][39]['sha256']=before['consumers'][39]['sha256']
  self.assertEqual(after,before)
  for key,value in [('revision','0'*40),('tree','0'*40),('blob','0'*40),('sha256','0'*64),('bytes',stage['bytes']+1)]:
   wrong=dict(stage);wrong[key]=value
   with self.subTest(key=key),self.assertRaises((ValueError,subprocess.CalledProcessError)):
    view._batch_stage_bytes(view.ROOT,wrong)
  for old in (mainline,view._batch_stage_bytes(view.ROOT,view._BATCH_LEGACY)):
   with self.assertRaises(ValueError):view._historical_batch_scan_source(name,old)
 def test_three_reviewed_lifecycle_inputs_have_exact_current_roles_and_old_git_phase(self):
  import hashlib,subprocess
  from unittest.mock import patch
  module=view.checked_modules().authorization_delta.lifecycle_delta
  self.assertEqual(set(view._LIFECYCLE_REVIEWED_ED66),{'docs/governance/batch-5-pipeline.json','docs/governance/private-notification-producers.json','scripts/governance/check_pipeline_boundaries.py'})
  for path,stage in view._LIFECYCLE_REVIEWED_ED66.items():
   raw=(view.ROOT/path).read_bytes()
   prior=subprocess.check_output(['git','--no-replace-objects','-C',str(view.ROOT),'show',view.REVISION+':'+path])
   self.assertEqual(view._historical_reviewed_lifecycle_source(path,raw),prior)
   self.assertEqual(hashlib.sha256(prior).hexdigest(),module.SOURCE_INPUTS[path])
   for bad in (raw+b'unknown',prior,'not bytes'):
    with self.subTest(path=path),self.assertRaises(ValueError):view._historical_reviewed_lifecycle_source(path,bad)
   with patch.dict(stage,{'sha256':'0'*64}),self.assertRaises(ValueError):view._historical_reviewed_lifecycle_source(path,raw)
   with patch.object(view.current,'TARGET_TREE','0'*40),self.assertRaises(ValueError):view._historical_reviewed_lifecycle_source(path,raw)
  with self.assertRaises(ValueError):view._historical_reviewed_lifecycle_source('docs/unknown.json',b'{}')
  import tempfile,shutil
  from pathlib import Path
  from recovery_oauth_code_test_cohort import complete_source_cohort
  with tempfile.TemporaryDirectory()as td:
   root=Path(td).resolve();complete_source_cohort(root)
   for path in view._LIFECYCLE_REVIEWED_ED66:
    target=root/path;target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(view.ROOT/path,target);raw=target.read_bytes()
    for mode in (0o600,0o755,0o1644):
     target.chmod(mode)
     try:
      with self.subTest(path=path,mode=mode),self.assertRaises(ValueError):view._historical_reviewed_lifecycle_source(path,raw,root)
     finally:target.chmod(0o644)
    target.unlink()
    with self.assertRaises(OSError):view._historical_reviewed_lifecycle_source(path,raw,root)
 def test_no_runtime_source_imports_test_view(self):
  for p in (view.ROOT/'scripts/governance').glob('*.py'):
   self.assertNotIn(b'import n05_r3_historical_test_view',p.read_bytes())
if __name__=='__main__':unittest.main()
