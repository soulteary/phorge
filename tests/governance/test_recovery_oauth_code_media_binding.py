"""Signed-source metadata binding only, no Docker, SQL or native matrix run."""
from pathlib import Path
import ast,hashlib,importlib.util,json,subprocess,sys,unittest
ROOT=Path(__file__).resolve().parents[2]
BASE='4d111b8cba5648f6a82f683e7ab1c75a43deef51'
TREE='0cbf96a3f8c380f7c6fead393c885001e37bbc1b'
PATH='support/governance/media-session-docker/snapshot.py'
class SignedSourceBinding(unittest.TestCase):
 def git(self,*args):return subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),*args])
 def test_exact_literal_sites_restore_signed_source_bytes(self):
  for name in [PATH,'support/governance/media-session-ci/project.py','support/governance/media-session-docker/README.md']:
   raw=(ROOT/name).read_bytes();old=self.git('show',BASE+':'+name)
   for new,prior in [(BASE,'9bc725ea3643cf24b6df0e806738b55496d8b388'),(TREE,'3b0f19730d8175fe71c4e5c486dbb17d4d39a790')]:
    self.assertEqual(raw.count(new.encode()),1);raw=raw.replace(new.encode(),prior.encode(),1)
   if name=='support/governance/media-session-ci/project.py':
    self.assertEqual(raw.count(b"len(inventory['entries'])!=9178"),1);raw=raw.replace(b"len(inventory['entries'])!=9178",b"len(inventory['entries'])!=9140",1)
   self.assertEqual(raw,old)
  self.assertEqual(self.git('rev-parse',BASE+'^{tree}').decode().strip(),TREE)
 def test_exact_nineteen_pins_from_signed_Git_blobs_and_unchanged_eight_overlay(self):
  rel='support/governance/media-session-positive/source-pins.json';raw=(ROOT/rel).read_bytes();self.assertEqual(raw,self.git('show',BASE+':'+rel));pins=json.loads(raw);self.assertEqual(len(pins),19)
  for name,pin in pins.items():
   source=self.git('show',BASE+':'+name);self.assertEqual((ROOT/name).read_bytes(),source);self.assertEqual(hashlib.sha256(source).hexdigest(),pin)
  def overlay(raw):return next(ast.literal_eval(n.value)for n in ast.parse(raw).body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='OVERLAY'for t in n.targets))
  current=overlay((ROOT/PATH).read_bytes());self.assertEqual(len(current),8);self.assertEqual(current,overlay(self.git('show',BASE+':'+PATH)))
  for name,pin in current.items():self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),pin)
 def test_exact_signed_raw_Git_inventory_count_is_9178(self):
  path=ROOT/'support/governance/media-session-docker/snapshot.py'
  spec=importlib.util.spec_from_file_location('code_media_count_snapshot',path);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
  entries,provenance=module.base_entries(ROOT)
  for name in module.OVERLAY:entries.setdefault(name,None)
  self.assertEqual(len(entries),9178);self.assertEqual(provenance['root_tree'],TREE)
 def test_all_other_fixture_runtime_and_source_guards_byteexact_signed_source(self):
  prefixes=['support/governance/media-session-ci','support/governance/media-session-docker','support/governance/media-session-positive']
  paths=self.git('ls-tree','-r','--name-only',BASE,'--',*prefixes).decode().splitlines();self.assertGreaterEqual(len(paths),17)
  for name in paths:
   if name=='support/governance/media-session-ci/test_ci.py':
    raw=(ROOT/name).read_bytes().replace(b'range(9178)',b'range(9140)',1)
    added=b"  # Metadata collaborators: recompute the receipt hash to isolate the exact-count gate.\n  original_entries=dict(inv['entries'])\n  for count in (9140,9177,9179):\n   inv['entries']={str(i):{} for i in range(count)};ib=write('public-source-inventory.json',inv)\n   accepted=json.loads((root/'accepted.json').read_bytes());accepted['source_inventory_sha256']=hashlib.sha256(ib).hexdigest();write('accepted.json',accepted)\n   with self.assertRaisesRegex(ValueError,'source inventory binding'):project.project(root,fresh)\n  inv['entries']=original_entries;ib=write('public-source-inventory.json',inv)\n  accepted['source_inventory_sha256']=hashlib.sha256(ib).hexdigest();write('accepted.json',accepted)\n  self.assertTrue(project.project(root,fresh)['strict_selected_accepted'])\n  inv['entries']['0']={'changed':True};write('public-source-inventory.json',inv)\n  with self.assertRaisesRegex(ValueError,'source inventory binding'):project.project(root,fresh)\n  inv['entries']=original_entries;write('public-source-inventory.json',inv)\n"
    self.assertEqual(raw.count(added),1);self.assertEqual(raw.replace(added,b'',1),self.git('show',BASE+':'+name));continue
   if name not in {PATH,'support/governance/media-session-ci/project.py','support/governance/media-session-docker/README.md'}:self.assertEqual((ROOT/name).read_bytes(),self.git('show',BASE+':'+name),name)
if __name__=='__main__':unittest.main()
