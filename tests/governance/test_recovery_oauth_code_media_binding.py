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
  self.assertEqual(len(media_binding.SOURCE_INPUTS),7)
  for name in media_binding.SOURCE_INPUTS:
   raw=(ROOT/name).read_bytes();self.assertEqual(media_binding.reduce_current(name,raw),self.git('show',BASE+':'+name))
   bad=raw+b'\n# unknown metadata edit'
   with self.assertRaises(ValueError):media_binding.reduce_current(name,bad)
   with patch.object(media_binding,'SOURCE_INPUTS',{**media_binding.SOURCE_INPUTS,name:hashlib.sha256(bad).hexdigest()}):
    with self.assertRaises(ValueError):media_binding.reduce_current(name,bad)
 def test_snapshot_changes_only_base_and_tree_not_any_object_or_IO_guard(self):
  current=(ROOT/PATH).read_bytes();old=self.git('show',BASE+':'+PATH)
  self.assertEqual(current.replace(BASE.encode(),b'2d3aa2db8a89185d81fbdb235f5a26c347da5551').replace(TREE.encode(),b'6a4fd9d008ce2fc16d2c259a1145e798bc20ae7e'),old)
 def test_exact_nineteen_pins_from_signed_Git_blobs_and_unchanged_eight_overlay(self):
  rel='support/governance/media-session-positive/source-pins.json';raw=(ROOT/rel).read_bytes();self.assertEqual(raw,self.git('show',BASE+':'+rel));pins=json.loads(raw);self.assertEqual(len(pins),19)
  for name,pin in pins.items():
   source=self.git('show',BASE+':'+name);self.assertEqual((ROOT/name).read_bytes(),source);self.assertEqual(hashlib.sha256(source).hexdigest(),pin)
  def overlay(raw):return next(ast.literal_eval(n.value)for n in ast.parse(raw).body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='OVERLAY'for t in n.targets))
  current=overlay((ROOT/PATH).read_bytes());self.assertEqual(len(current),8);self.assertEqual(current,overlay(self.git('show',BASE+':'+PATH)))
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
   if name in METADATA:raw=media_binding.reduce_current(name,raw)
   self.assertEqual(raw,self.git('show',BASE+':'+name),name)
if __name__=='__main__':unittest.main()
