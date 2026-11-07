"""Real unchanged FixedPodman constructor; no Podman or other command calls."""
import hashlib,importlib,importlib.util,json,sys,tempfile,types,unittest
from pathlib import Path
from unittest.mock import patch
import media_browser13_artifacts as a
class Dependencies(unittest.TestCase):
 def package(self,root):
  p=root/'host';p.mkdir();(p/'__init__.py').write_bytes(b'')
  data=a.build_host_engine_inputs()
  for n in ['engine.py','owned_container.py']:(p/n).write_bytes(data[n])
  name='own_host_constructor_'+str(id(root));spec=importlib.util.spec_from_file_location(name,p/'__init__.py',submodule_search_locations=[str(p)]);pkg=importlib.util.module_from_spec(spec);sys.modules[name]=pkg;spec.loader.exec_module(pkg)
  engine=importlib.import_module(name+'.engine');tool=Path(sys.executable).resolve()
  obs={'canonical_executable':str(tool),'executable_sha256':hashlib.sha256(tool.read_bytes()).hexdigest(),'version':{},'selected_connection':{'name':'own-test','default':True,'URI_sha256':'a'*64},'host':{},'existing_root_owned_container':{'Id':'b'*64,'Name':'own-test','Image':'sha256:'+'c'*64,'Running':True,'NetworkMode':'own-metadata-only','namespace':{'device':1,'inode':2,'boot_id_sha256':'d'*64}},'new_container_created':False,'browser_started':False}
  runtime={'schema_version':1,'profile':'Root-observed-isolated-media-browser-runtime-v1','image_reference':'sha256:'+'e'*64,'image_id':'sha256:'+'e'*64,'image_labels':{},'platform':'linux/arm64','source_manifest':engine.BASE_IMAGE.split('@')[1],'source_recipe_sha256':'f'*64,'chromium_revision':'1248','chromium_version':'156.0.8078.4','browser_sha256':'1'*64,'certutil_sha256':'2'*64,'dependencies_lock_sha256':hashlib.sha256(data['requirements-linux-arm64-py312.lock']).hexdigest()}
  op=root/'observation.json';rp=root/'runtime.json'
  for f,v in [(op,obs),(rp,runtime)]:f.write_text(json.dumps(v));f.chmod(0o600)
  engine.ROOT_OBSERVATION_SHA256=hashlib.sha256(op.read_bytes()).hexdigest();engine.ROOT_RUNTIME_OBSERVATION_SHA256=hashlib.sha256(rp.read_bytes()).hexdigest()
  return engine,p,data,op,rp
 def test_actual_missing_lock_then_missing_seccomp_then_constructor_passes(self):
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve();engine,p,data,obs,runtime=self.package(root)
   with patch.object(engine.subprocess,'run',side_effect=AssertionError('Podman forbidden')),patch.object(engine.subprocess,'Popen',side_effect=AssertionError('process forbidden')):
    with self.assertRaises(FileNotFoundError)as first:engine.FixedPodman(obs,runtime,nonce='3'*24,owner='4'*64,source='5'*40)
    self.assertTrue(str(first.exception.filename).endswith('requirements-linux-arm64-py312.lock'))
    (p/'requirements-linux-arm64-py312.lock').write_bytes(data['requirements-linux-arm64-py312.lock'])
    with self.assertRaises(FileNotFoundError)as second:engine.FixedPodman(obs,runtime,nonce='3'*24,owner='4'*64,source='5'*40)
    self.assertTrue(str(second.exception.filename).endswith('seccomp-playwright-v1.64.1.json'))
    (p/'seccomp-playwright-v1.64.1.json').write_bytes(data['seccomp-playwright-v1.64.1.json'])
    made=engine.FixedPodman(obs,runtime,nonce='3'*24,owner='4'*64,source='5'*40)
   self.assertEqual(made.seccomp,p/'seccomp-playwright-v1.64.1.json');self.assertIsNone(made.identity)
 def test_fixed_host_set_and_guest13_unchanged(self):
  data=a.build_host_engine_inputs();self.assertEqual(set(data),{'engine.py','owned_container.py','requirements-linux-arm64-py312.lock','seccomp-playwright-v1.64.1.json'})
  bundle,pins=a.build();self.assertEqual(len(pins),13);self.assertTrue(set(data).isdisjoint(pins))
  provenance=json.loads(Path(a.__file__).with_name('media_browser_host_dependencies_provenance.json').read_bytes())
  for n,v in data.items():self.assertEqual(hashlib.sha256(v).hexdigest(),provenance['source_sha256'][n])
  self.assertFalse(provenance['engine_policy_changed']);self.assertFalse(provenance['runtime_authority'])
 def test_changed_dependency_refused_before_consumer(self):
  real=a.read_source
  def corrupt(p):
   raw=real(p);return raw+b'changed'if Path(p).name=='seccomp-playwright-v1.64.1.json'else raw
  with patch.object(a,'read_source',corrupt):
   with self.assertRaisesRegex(RuntimeError,'fixed_host_dependency_changed'):a.build_host_engine_inputs()
