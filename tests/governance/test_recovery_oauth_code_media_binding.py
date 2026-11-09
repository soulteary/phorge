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
   self.assertEqual(raw,old)
  self.assertEqual(self.git('rev-parse',BASE+'^{tree}').decode().strip(),TREE)
 def test_exact_nineteen_pins_from_signed_Git_blobs_and_unchanged_eight_overlay(self):
  rel='support/governance/media-session-positive/source-pins.json';raw=(ROOT/rel).read_bytes();self.assertEqual(raw,self.git('show',BASE+':'+rel));pins=json.loads(raw);self.assertEqual(len(pins),19)
  for name,pin in pins.items():
   source=self.git('show',BASE+':'+name);self.assertEqual((ROOT/name).read_bytes(),source);self.assertEqual(hashlib.sha256(source).hexdigest(),pin)
  def overlay(raw):return next(ast.literal_eval(n.value)for n in ast.parse(raw).body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='OVERLAY'for t in n.targets))
  current=overlay((ROOT/PATH).read_bytes());self.assertEqual(len(current),8);self.assertEqual(current,overlay(self.git('show',BASE+':'+PATH)))
  for name,pin in current.items():self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),pin)
 def test_all_other_fixture_runtime_and_source_guards_byteexact_signed_source(self):
  prefixes=['support/governance/media-session-ci','support/governance/media-session-docker','support/governance/media-session-positive']
  paths=self.git('ls-tree','-r','--name-only',BASE,'--',*prefixes).decode().splitlines();self.assertGreaterEqual(len(paths),17)
  for name in paths:
   if name not in {PATH,'support/governance/media-session-ci/project.py','support/governance/media-session-docker/README.md'}:self.assertEqual((ROOT/name).read_bytes(),self.git('show',BASE+':'+name),name)
if __name__=='__main__':unittest.main()
