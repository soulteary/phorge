import importlib.util,json,os,pathlib,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('proof',ROOT/'scripts/governance/detached_source_proof.py');p=importlib.util.module_from_spec(spec);spec.loader.exec_module(p)
def data():return dict(schema=p.SCHEMA,repository='soulteary/phorge',commit='a'*40,tree='b'*40,parents=['c'*40,'d'*40],reviewed_head='d'*40,pull_request=146,ci_head='d'*40,ci_runs=[43990515249])
def encode(d):return (json.dumps(d,sort_keys=True,separators=(',',':'))+'\n').encode()
class Proof(unittest.TestCase):
 def test_canonical_data(self):self.assertEqual(p.decode(encode(data())),data())
 def test_bound_fields(self):
  for key,value in [('schema','other'),('repository','other'),('commit','bad'),('tree','0'),('parents',['c'*40]),('reviewed_head','e'*40),('ci_head','e'*40),('pull_request',True),('ci_runs',[1,1]),('ci_runs',[]),('ci_runs',[True])]:
   with self.subTest(key=key,value=value):
    d=data();d[key]=value
    with self.assertRaises(ValueError):p.decode(encode(d))
 def test_unknown_field(self):
  d=data();d['grant']=True
  with self.assertRaises(ValueError):p.decode(encode(d))
 def test_duplicate_and_noncanonical(self):
  raw=encode(data())
  for bad in [raw.replace(b'{',b'{"schema":"bad",',1),json.dumps(data()).encode(),raw+b' ']:
   with self.assertRaises(ValueError):p.decode(bad)
 def test_owned_file_and_aliases(self):
  with tempfile.TemporaryDirectory() as d:
   file=pathlib.Path(d).resolve()/'proof';file.write_bytes(b'proof');file.chmod(0o600);self.assertEqual(p.read_owned(file,10),b'proof')
   alias=pathlib.Path(d).resolve()/'alias';alias.symlink_to(file)
   with self.assertRaises(ValueError):p.read_owned(alias,10)
   alias.unlink();os.link(file,alias)
   with self.assertRaises(ValueError):p.read_owned(file,10)
   alias.unlink();file.chmod(0o666)
   with self.assertRaises(ValueError):p.read_owned(file,10)
 def test_oversize_and_empty(self):
  with tempfile.TemporaryDirectory() as d:
   file=pathlib.Path(d).resolve()/'proof'
   for raw in [b'',b'1234']:
    file.write_bytes(raw)
    with self.assertRaises(ValueError):p.read_owned(file,3)
if __name__=='__main__':unittest.main()
