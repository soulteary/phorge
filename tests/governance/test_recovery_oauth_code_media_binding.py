"""Exact signed S1 media source metadata, no SQL or runtime execution."""
from pathlib import Path
import ast,hashlib,importlib.util,json,subprocess,sys,unittest
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import importlib.util
_spec=importlib.util.spec_from_file_location("N05_media_C_metadata",ROOT/"tests/governance/test_project_content_grant_media_source_delta.py")
media_binding=importlib.util.module_from_spec(_spec);_spec.loader.exec_module(media_binding)
BASE='0fff49b76fcb6852787e7f1408eca2a591e53ffb'
TREE='16cb24dbb0992f5e81d7fd9ec279f63fe90f9c6f'
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
 def test_exact_nineteen_pins_from_signed_Git_blobs_and_unchanged_eight_overlay(self):
  rel='support/governance/media-session-positive/source-pins.json';raw=(ROOT/rel).read_bytes();self.assertEqual(raw,self.git('show',BASE+':'+rel));pins=json.loads(raw);self.assertEqual(len(pins),19)
  for name,pin in pins.items():
   source=self.git('show',BASE+':'+name);self.assertEqual((ROOT/name).read_bytes(),source);self.assertEqual(hashlib.sha256(source).hexdigest(),pin)
  def overlay(raw):return next(ast.literal_eval(n.value)for n in ast.parse(raw).body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='OVERLAY'for t in n.targets))
  current=overlay((ROOT/PATH).read_bytes());self.assertEqual(len(current),8);self.assertEqual(current,overlay(self.git('show',BASE+':'+PATH)))
  for name,pin in current.items():self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),pin)
 def test_exact_signed_raw_Git_inventory_count_is_9238(self):
  spec=importlib.util.spec_from_file_location('partial_media_count_snapshot',ROOT/PATH);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
  entries,provenance=module.base_entries(ROOT)
  for name in module.OVERLAY:entries.setdefault(name,None)
  self.assertEqual(len(entries),9238);self.assertEqual(provenance['root_tree'],TREE)
 def test_all_other_fixture_runtime_and_source_guards_byteexact_signed_source(self):
  paths=self.git('ls-tree','-r','--name-only',BASE,'--','support/governance/media-session-ci','support/governance/media-session-docker','support/governance/media-session-positive').decode().splitlines();self.assertGreaterEqual(len(paths),17)
  for name in paths:
   raw=(ROOT/name).read_bytes()
   if name in METADATA:raw=media_binding.reduce_current(name,raw)
   self.assertEqual(raw,self.git('show',BASE+':'+name),name)
if __name__=='__main__':unittest.main()
