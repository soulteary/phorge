"""Exact signed S1 media source metadata, no SQL or runtime execution."""
from pathlib import Path
import ast,hashlib,importlib.util,json,subprocess,sys,unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import recovery_oauth_client_lifecycle_media_provider_delta as media_binding
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
   self.assertEqual((ROOT/name).read_text().count(BASE),1);self.assertEqual((ROOT/name).read_text().count(TREE),1)
 def test_all_current_metadata_and_hook_inverses_restore_exact_signed_S1(self):
  self.assertEqual(len(media_binding.SOURCE_INPUTS),10)
  for name in media_binding.SOURCE_INPUTS:
   raw=(ROOT/name).read_bytes();self.assertEqual(media_binding.reduce_current(name,raw),self.git('show',BASE+':'+name))
   bad=raw+b'\n# unknown metadata edit'
   with self.assertRaises(ValueError):media_binding.reduce_current(name,bad)
   with patch.object(media_binding,'SOURCE_INPUTS',{**media_binding.SOURCE_INPUTS,name:hashlib.sha256(bad).hexdigest()}):
    with self.assertRaises(ValueError):media_binding.reduce_current(name,bad)
 def test_snapshot_changes_only_base_and_tree_not_any_object_or_IO_guard(self):
  current=(ROOT/PATH).read_bytes();old=self.git('show',BASE+':'+PATH)
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
  self.assertEqual(hashlib.sha256((ROOT/'resources/sql/autopatches/20261110.project.contentgrants.sql').read_bytes()).hexdigest(),'2dbbd2c1305a467c164470fd9e7b2830e32ad282fa2cc6a1ec74e8bef0d7a249')
  def overlay(raw):return next(ast.literal_eval(n.value)for n in ast.parse(raw).body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='OVERLAY'for t in n.targets))
  current=overlay((ROOT/PATH).read_bytes());self.assertEqual(len(current),8);before=overlay(self.git('show',BASE+':'+PATH));self.assertEqual(set(current),set(before));self.assertEqual({n for n in current if current[n]!=before[n]},{'support/governance/media-session-positive/source-pins.json','support/governance/media-session-positive/run.php'})
  for name,pin in current.items():self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),pin)
 def test_exact_signed_raw_Git_inventory_count_is_9237(self):
  spec=importlib.util.spec_from_file_location('partial_media_count_snapshot',ROOT/PATH);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
  entries,provenance=module.base_entries(ROOT)
  for name in module.OVERLAY:entries.setdefault(name,None)
  self.assertEqual(len(entries),9237);self.assertEqual(provenance['root_tree'],TREE)
 def test_all_other_fixture_runtime_and_source_guards_byteexact_signed_source(self):
  paths=self.git('ls-tree','-r','--name-only',BASE,'--','support/governance/media-session-ci','support/governance/media-session-docker','support/governance/media-session-positive').decode().splitlines();self.assertGreaterEqual(len(paths),17)
  for name in paths:
   raw=(ROOT/name).read_bytes()
   if name in media_binding.SOURCE_INPUTS:raw=media_binding.reduce_current(name,raw)
   self.assertEqual(raw,self.git('show',BASE+':'+name),name)
if __name__=='__main__':unittest.main()
