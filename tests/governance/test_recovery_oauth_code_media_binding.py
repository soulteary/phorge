"""Exact signed S1 media source metadata, no SQL or runtime execution."""
from pathlib import Path
import ast,hashlib,importlib.util,json,subprocess,sys,unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import recovery_oauth_client_lifecycle_media_provider_delta as original_media_binding
from test_project_content_grant_media_source_delta import reduce_stageB
class CurrentMediaBinding:
 SOURCE_INPUTS=original_media_binding.SOURCE_INPUTS
 @staticmethod
 def reduce_current(name,raw):
  # Exact current Stage C bytes first return to signed Stage B, then historical provider.
  return original_media_binding.reduce_current(name,reduce_stageB(name,raw))
media_binding=CurrentMediaBinding()
BASE='cc39f46941cb8f06eb3e7648f5da38db79be83dc'
TREE='1fe79ccc366ce45e863bf7fa3ac5d1b7b03a7a5a'
PATH='support/governance/media-session-docker/snapshot.py'
METADATA={PATH,'support/governance/media-session-ci/project.py','support/governance/media-session-docker/README.md','support/governance/media-session-ci/test_ci.py'}
class SignedSourceBinding(unittest.TestCase):
 def git(self,*args):return subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),*args])
 def test_exact_literal_sites_restore_signed_source_bytes(self):
  for name in METADATA:
   raw=(ROOT/name).read_bytes();self.assertEqual(media_binding.reduce_current(name,raw),self.git('show',BASE+':'+name))
   with self.assertRaises(ValueError):media_binding.reduce_current(name,raw+b'\n# unknown edit')
  self.assertEqual(self.git('rev-parse',BASE+'^{tree}').decode().strip(),TREE)
  for name in [PATH,'support/governance/media-session-ci/project.py','support/governance/media-session-docker/README.md']:
   self.assertEqual((ROOT/name).read_text().count('5665cf16663b0e44da2aed5a800835dc9cb563cf'),1);self.assertEqual((ROOT/name).read_text().count('0edb85b33cd7554e8653c1c310d2978e8c32ef3d'),1)
 def test_all_current_metadata_and_hook_inverses_restore_exact_signed_S1(self):
  self.assertEqual(len(media_binding.SOURCE_INPUTS),10)
  for name in media_binding.SOURCE_INPUTS:
   raw=(ROOT/name).read_bytes();self.assertEqual(media_binding.reduce_current(name,raw),self.git('show',BASE+':'+name))
   bad=raw+b'\n# unknown metadata edit'
   with self.assertRaises(ValueError):media_binding.reduce_current(name,bad)
   with patch.object(media_binding,'SOURCE_INPUTS',{**media_binding.SOURCE_INPUTS,name:hashlib.sha256(bad).hexdigest()}):
    with self.assertRaises(ValueError):media_binding.reduce_current(name,bad)
 def test_snapshot_changes_only_base_and_tree_not_any_object_or_IO_guard(self):
  current=reduce_stageB(PATH,(ROOT/PATH).read_bytes());old=self.git('show',BASE+':'+PATH)
  def overlay(raw):return next(ast.literal_eval(n.value)for n in ast.parse(raw).body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='OVERLAY'for t in n.targets))
  now=overlay(current);before=overlay(old);restored=current.replace(BASE.encode(),b'2d3aa2db8a89185d81fbdb235f5a26c347da5551').replace(TREE.encode(),b'6a4fd9d008ce2fc16d2c259a1145e798bc20ae7e')
  for name in now:
   if now[name]!=before[name]:self.assertEqual(restored.count(now[name].encode()),1);restored=restored.replace(now[name].encode(),before[name].encode(),1)
  self.assertEqual(restored,old)
 def test_exact_nineteen_pins_from_signed_Git_blobs_and_unchanged_eight_overlay(self):
  rel='support/governance/media-session-positive/source-pins.json';raw=(ROOT/rel).read_bytes();pins=json.loads(raw);self.assertEqual(len(pins),19)
  self.assertEqual(hashlib.sha256(raw).hexdigest(),'5d708748e4bf47af83ae97edac0fb9f37ac89d41ce11b1d7d371fb595e24d222')
  previous=json.loads(self.git('show',BASE+':'+rel));self.assertEqual(set(pins),set(previous));self.assertEqual({n for n in pins if pins[n]!=previous[n]},{'support/governance/completion/fixture.php'})
  for name,pin in pins.items():
   actual=(ROOT/name).read_bytes();self.assertEqual(hashlib.sha256(actual).hexdigest(),pin)
   if name!='support/governance/completion/fixture.php':self.assertEqual(actual,self.git('show',BASE+':'+name))
   else:
    self.assertEqual(pin,'8c69fdbc292b6b42f7764c0258e1edb671190d97f9977cf6aee57eac9e5ae09b');self.assertEqual(actual.replace(b",\n      '20261110.project.contentgrants.sql');",b');'),self.git('show',BASE+':'+name))
  self.assertEqual(hashlib.sha256((ROOT/'resources/sql/autopatches/20261110.project.contentgrants.sql').read_bytes()).hexdigest(),'16f043e830f29b246efd12c22569e66bf0d2b629f4b9dbd062ab0f30a487d27e')
  def overlay(raw):return next(ast.literal_eval(n.value)for n in ast.parse(raw).body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='OVERLAY'for t in n.targets))
  current=overlay((ROOT/PATH).read_bytes());self.assertEqual(len(current),8);before=overlay(self.git('show',BASE+':'+PATH));self.assertEqual(set(current),set(before));self.assertEqual({n for n in current if current[n]!=before[n]},{'support/governance/media-session-positive/source-pins.json','support/governance/media-session-positive/run.php'})
  for name,pin in current.items():self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),pin)
 def test_exact_signed_raw_Git_inventory_count_is_9276(self):
  spec=importlib.util.spec_from_file_location('partial_media_count_snapshot',ROOT/PATH);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
  entries,provenance=module.base_entries(ROOT)
  for name in module.OVERLAY:entries.setdefault(name,None)
  self.assertEqual(len(entries),9276);self.assertEqual(provenance['root_tree'],'0edb85b33cd7554e8653c1c310d2978e8c32ef3d');self.assertEqual(provenance['commit'],'5665cf16663b0e44da2aed5a800835dc9cb563cf')
 def test_all_other_fixture_runtime_and_source_guards_byteexact_signed_source(self):
  paths=self.git('ls-tree','-r','--name-only',BASE,'--','support/governance/media-session-ci','support/governance/media-session-docker','support/governance/media-session-positive').decode().splitlines();self.assertGreaterEqual(len(paths),17)
  for name in paths:
   raw=(ROOT/name).read_bytes()
   if name in media_binding.SOURCE_INPUTS:raw=media_binding.reduce_current(name,raw)
   self.assertEqual(raw,self.git('show',BASE+':'+name),name)
 def test_previous_signed_recipe_literals_are_exact_test_only_and_unknown_refused(self):
  import project_content_grant_source_delta as n05
  m=n05.media_source_delta()
  self.assertEqual(set(m.PREVIOUS_RECIPE_BYTES),set(m.SOURCE_INPUTS))
  for path,raw in m.PREVIOUS_RECIPE_BYTES.items():
   actual=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','3e02336621298f5877e30e05690f57e9a499bdda:'+path])
   self.assertEqual(raw,actual)
   self.assertEqual(m.reduce_previous_recipe(path,raw),m.BASE_BYTES[path])
   with self.assertRaises(ValueError):m.reduce_previous_recipe(path,raw+b'unknown')
 def test_repair_signed_recipe_predecessor_is_exact_and_unknown_refused(self):
  import project_content_grant_source_delta as n05
  m=n05.media_source_delta();self.assertEqual(set(m.REPAIR_RECIPE_BYTES),set(m.SOURCE_INPUTS))
  for path,raw in m.REPAIR_RECIPE_BYTES.items():
   actual=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','5665cf16663b0e44da2aed5a800835dc9cb563cf:'+path]);self.assertEqual(raw,actual)
   self.assertEqual(m.reduce_repair_recipe(path,raw),m.BASE_BYTES[path])
   with self.assertRaises(ValueError):m.reduce_repair_recipe(path,raw+b'unknown')
if __name__=='__main__':unittest.main()
