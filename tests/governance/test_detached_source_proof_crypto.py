"""Opt-in real signature checks; absence is explicit, never claimed acceptance."""
import importlib.util,os,pathlib,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
s=importlib.util.spec_from_file_location('proof_crypto',ROOT/'scripts/governance/detached_source_proof.py');p=importlib.util.module_from_spec(s);s.loader.exec_module(p)
class Crypto(unittest.TestCase):
 def setUp(self):
  required=('P0_PROOF_ROOT','P0_PROOF_HOME','P0_PROOF_FILE','P0_PROOF_SIGNATURE','P0_PROOF_COMMIT')
  if not all(os.environ.get(x)for x in required):self.skipTest('real project-signed proof fixture required')
  self.root=pathlib.Path(os.environ['P0_PROOF_ROOT']);self.home=os.environ['P0_PROOF_HOME'];self.file=pathlib.Path(os.environ['P0_PROOF_FILE']);self.sig=pathlib.Path(os.environ['P0_PROOF_SIGNATURE']);self.commit=os.environ['P0_PROOF_COMMIT']
 def verify(self,file=None,sig=None,commit=None):return p.verify(self.root,commit or self.commit,file or self.file,sig or self.sig,self.home)
 def test_real_signature(self):self.assertEqual(self.verify()['commit'],self.commit)
 def test_tampered_proof(self):
  with tempfile.TemporaryDirectory()as d:
   file=pathlib.Path(d).resolve()/'proof';raw=self.file.read_bytes();file.write_bytes(raw.replace(b'"pull_request":146',b'"pull_request":147'));self.assertNotEqual(raw,file.read_bytes())
   with self.assertRaises(ValueError):self.verify(file=file)
 def test_tampered_signature(self):
  with tempfile.TemporaryDirectory()as d:
   sig=pathlib.Path(d).resolve()/'signature';raw=bytearray(self.sig.read_bytes());raw[len(raw)//2]^=1;sig.write_bytes(raw)
   with self.assertRaises(ValueError):self.verify(sig=sig)
 def test_wrong_checkpoint(self):
  with self.assertRaises(ValueError):self.verify(commit='f'*40)
 def test_lease_replacement_and_terminal_close(self):
  with tempfile.TemporaryDirectory()as d:
   d=pathlib.Path(d).resolve();proof=d/'proof';sig=d/'signature';proof.write_bytes(self.file.read_bytes());sig.write_bytes(self.sig.read_bytes())
   lease=p.ProofLease(self.root,self.commit,proof,sig,self.home);lease.check()
   replacement=d/'replacement';replacement.write_bytes(proof.read_bytes());replacement.replace(proof)
   with self.assertRaises(ValueError):lease.check()
   with self.assertRaises(ValueError):lease.close()
   self.assertTrue(lease.closed)
 def test_closed_lease_refuses_reuse(self):
  lease=p.ProofLease(self.root,self.commit,self.file,self.sig,self.home);lease.close()
  with self.assertRaises(ValueError):lease.check()
if __name__=='__main__':unittest.main()
