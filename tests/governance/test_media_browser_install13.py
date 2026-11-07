import base64,hashlib,json,os,stat,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
import media_browser_install13 as i
import media_browser13_artifacts as artifacts
class Install(unittest.TestCase):
 def raw(self):
  v={n:base64.b64encode(b''if n=='__init__.py'else b'# data\n').decode()for n in i.FILES};return json.dumps(v,sort_keys=True,separators=(',',':')).encode()
 def test_exact_actual_13_artifacts_and_installer_realFS(self):
  raw,pins=artifacts.build();self.assertEqual(len(pins),13)
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve();r=i.install(raw,root,expected_sha256=hashlib.sha256(raw).hexdigest(),nonce='a'*24);d=Path(r['directory']);self.assertEqual(r['files'],13);self.assertEqual(set(x.name for x in d.iterdir()),i.FILES)
   for n,pin in pins.items():self.assertEqual(hashlib.sha256((d/n).read_bytes()).hexdigest(),pin);self.assertEqual(stat.S_IMODE((d/n).stat().st_mode),0o600);self.assertEqual((d/n).stat().st_nlink,1)
   # Old nine-file base data is copied byte-exact; no old installer is mutated.
   base=json.loads(artifacts.BASE.with_name('media_browser_runtime_base_provenance.json').read_bytes());self.assertFalse(base['legacy_installer_modified'])
   for n,pin in base['source_sha256'].items():self.assertEqual(pins[n],pin)
 def test_missing_extra_noncanonical_and_wrong_digest_before_directory(self):
  raw=self.raw();v=json.loads(raw)
  variants=[raw]
  for mode in ['missing','extra','padding','empty-source']:
   x=dict(v)
   if mode=='missing':x.pop('flow.py')
   if mode=='extra':x['unknown.py']='eA=='
   if mode=='padding':x['flow.py']='eA==='
   if mode=='empty-source':x['flow.py']=''
   variants.append(json.dumps(x).encode())
  with tempfile.TemporaryDirectory()as tmp:
   p=Path(tmp).resolve()
   for n,x in enumerate(variants):
    with self.assertRaises((i.Unproved,ValueError)):i.install(x,p,expected_sha256='0'*64 if n==0 else hashlib.sha256(x).hexdigest(),nonce='b'*24)
    self.assertEqual(list(p.iterdir()),[])
 def test_existing_foreign_directory_never_adopt_or_overwrite(self):
  raw=self.raw()
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve();d=root/('phorge-browser-v3-source-'+'c'*24);d.mkdir();(d/'foreign').write_bytes(b'keep')
   with self.assertRaises(FileExistsError):i.install(raw,root,expected_sha256=hashlib.sha256(raw).hexdigest(),nonce='c'*24)
   self.assertEqual((d/'foreign').read_bytes(),b'keep')
 def test_actual_sameFD_mode_and_hardlink_postwrite_refused(self):
  for fault in ['mode','hardlink','replacement']:
   with self.subTest(fault=fault),tempfile.TemporaryDirectory()as tmp:
    root=Path(tmp).resolve();raw=self.raw();real=os.fsync;done=False
    def seam(fd):
     nonlocal done
     real(fd)
     if not done and stat.S_ISREG(os.fstat(fd).st_mode):
      done=True;d=next(root.glob('phorge-browser-v3-source-*'));p=next(x for x in d.iterdir()if x.stat().st_ino==os.fstat(fd).st_ino)
      if fault=='mode':os.fchmod(fd,0o644)
      elif fault=='hardlink':os.link(p,root/'extra-link')
      else:p.rename(root/'original-held');p.write_bytes(b'foreign');p.chmod(0o600)
    with patch.object(i.os,'fsync',seam):
     with self.assertRaises(i.Unproved):i.install(raw,root,expected_sha256=hashlib.sha256(raw).hexdigest(),nonce='d'*24)
    self.assertTrue(done);self.assertTrue(any(root.glob('phorge-browser-v3-source-*')))
 def test_zero_write_refused_and_all_created_state_retained(self):
  raw=self.raw()
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve()
   with patch.object(i.os,'write',return_value=0):
    with self.assertRaises(i.Unproved):i.install(raw,root,expected_sha256=hashlib.sha256(raw).hexdigest(),nonce='e'*24)
   self.assertTrue((root/('phorge-browser-v3-source-'+'e'*24)).exists())
 def test_null_role_before_stdin_or_filesystem(self):
  class Input:
   def read(self,*a):raise AssertionError('input')
  with patch.object(i.os,'open',side_effect=AssertionError('open')):
   with self.assertRaises(i.Unproved):i.execute_bound(Input(),'a'*24)
 def test_actual_thirteen_package_import_refuses_unbound_before_browser(self):
  import importlib.util,sys
  raw,_=artifacts.build()
  with tempfile.TemporaryDirectory()as tmp:
   r=i.install(raw,Path(tmp).resolve(),expected_sha256=hashlib.sha256(raw).hexdigest(),nonce='f'*24);d=Path(r['directory'])
   spec=importlib.util.spec_from_file_location('actual_thirteen_owned_test',d/'__init__.py',submodule_search_locations=[str(d)]);pkg=importlib.util.module_from_spec(spec);sys.modules[spec.name]=pkg;spec.loader.exec_module(pkg)
   import importlib
   v=importlib.import_module(spec.name+'.media_browser_account_switch_v3')
   with self.assertRaises(v.Unproved):v.execute_bound_guest(b'{}')
