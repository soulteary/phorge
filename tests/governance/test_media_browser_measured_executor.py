"""Actual host sequencing/validators; declared engine/transport collaborators."""
import copy,hashlib,json,types,unittest
from unittest.mock import patch
import media_browser_measured_executor as e
import media_browser13_artifacts as artifacts
import media_browser_account_switch_v3 as c
from test_media_browser_account_switch_v3 import review,expectation,measurement,envelope,legacy
class Executor(unittest.TestCase):
 def bind(self,install_only=False):
  raw,pins=review();bundle,_=artifacts.build();events=[];contract=expectation()['native_tuple'];nonce='1'*24;owner='2'*64;cid='3'*64
  lease={'scope':'owned-measured13-source-install-probe'if install_only else'owned-measured13-normal-TLS-browser-attempt','native_tuple':contract,'local_forward_port':expectation()['local_forward_port'],'nonce':nonce,'owner':owner,'native_container_id':cid,'public_route_authorized':False}
  pub=None
  class Failure(RuntimeError):
   def __init__(self,primary,cleanup):self.primary=primary;self.cleanup=cleanup
  class Engine:
   def __init__(self,*a,**kw):events.append('engine');self.observation={'existing_root_owned_container':{'Id':cid}};self.actual_image='image';self.image_reference='ref';self.command=lambda *a:None
   def start(self):events.append('start')
   def _raw(self,args,input):
    if e.INSTALL13 in args:
     events.append('install13');assert input==bundle and args[0:4]==['exec','-i','--user','1000:1000'];assert '/opt/phorge-media-browser/source_install13.py'in e.INSTALL13
     r={'scope':'owned-browser-versioned13-data-install','directory':'/tmp/phorge-browser-v3-source-'+nonce,'device':1,'inode':2,'source_bundle_sha256':hashlib.sha256(bundle).hexdigest(),'files':13,'host_execution':False}
    else:
     events.append('execute13');assert e.EXECUTE13 in args;v=json.loads(input);assert v['native_measurement']==measurement(pins);assert v['native_tuple']==contract;r=pub
    return types.SimpleNamespace(returncode=0,stderr=b'',stdout=json.dumps(r).encode())
  class Lifecycle:
   def __init__(self,*a,**kw):events.append('lifecycle')
   def create(self):events.append('create');return'4'*64
   def cleanup(self):events.append('cleanup');return{'removed':True}
  def capture(name,pin):
   if name==('media-browser-measured13-install-probe-lease-root.private.json'if install_only else'media-browser-measured13-execution-lease-root.private.json'):return json.dumps(lease).encode()
   if name=='media-browser-measured13-source-bundle-root.private.json':return bundle
   if name=='media-native-measured13-origin-review-root.private.json':return b'{}'
   raise AssertionError('unknown role')
  deps={'engine':types.SimpleNamespace(FixedPodman=Engine),'owned_container':types.SimpleNamespace(OwnedContainer=Lifecycle,LifecycleFailure=Failure,hex_value=lambda v,n:type(v)is str and len(v)==n),'origins':types.SimpleNamespace(verified=lambda p:(contract['app_origin'],contract['file_origin']),ROOT_APPROVED_ORIGIN_REVIEW_SHA256='5'*64),'remote_browser_stage':types.SimpleNamespace(capture=capture,AUTHORITY_DIRECTORY='/private/tmp/source-only-test'),'EXECUTION_LEASE_SHA256':'6'*64,'ROOT_ENGINE_OBSERVATION_SHA256':'7'*64,'ROOT_RUNTIME_OBSERVATION_SHA256':'8'*64,'SOURCE_BUNDLE_SHA256':hashlib.sha256(bundle).hexdigest(),'INSTALLER_SHA256':'9'*64}
  record=patch.multiple(c,ROOT_REVIEW_BYTES=raw,ROOT_REVIEW_SHA256=hashlib.sha256(raw).hexdigest());record.start();self.addCleanup(record.stop);pub=c.consume(envelope(pins),legacy)
  roles=patch.multiple(e,**deps);roles.start();self.addCleanup(roles.stop)
  native=types.SimpleNamespace(b={'container':cid},ready={'private-canary':'never-public'})
  packet={'local_native_contract':contract,'local_forward_port':expectation()['local_forward_port']}
  return native,packet,{},pins,events,pub
 def test_actual_measured_before_create_install_and_fixed_dispatch(self):
  n,p,proof,pins,events,pub=self.bind()
  def collect(*a):events.append('measure-native');return measurement(pins)
  with patch.object(e.measurement,'collect',collect):r=e.execute_owned_flow(None,p,proof,n)
  self.assertEqual(r,pub);self.assertLess(events.index('measure-native'),events.index('engine'));self.assertLess(events.index('measure-native'),events.index('install13'));self.assertEqual(events[-1],'cleanup');self.assertNotIn('private-canary',json.dumps(r))
 def test_measurement_failure_prevents_engine_install_and_browser(self):
  n,p,proof,pins,events,pub=self.bind()
  with patch.object(e.measurement,'collect',side_effect=RuntimeError('measurement-unproved')):
   with self.assertRaisesRegex(RuntimeError,'measurement-unproved'):e.execute_owned_flow(None,p,proof,n)
  self.assertEqual(events,[])
 def test_nonzero_install_retains_primary_and_attempts_known_cleanup(self):
  n,p,proof,pins,events,pub=self.bind();real=e.checked
  def fail(executor,args,data):
   if e.INSTALL13 in args:raise RuntimeError('install-private-primary')
   return real(executor,args,data)
  with patch.object(e.measurement,'collect',return_value=measurement(pins)),patch.object(e,'checked',fail):
   with self.assertRaises(e.owned_container.LifecycleFailure)as out:e.execute_owned_flow(None,p,proof,n)
  self.assertEqual(str(out.exception.primary),'install-private-primary');self.assertEqual(events[-1],'cleanup');self.assertNotIn('execute13',events)
 def test_all_null_roles_before_native_or_capture(self):
  with patch.multiple(e,EXECUTION_LEASE_SHA256=None,ROOT_ENGINE_OBSERVATION_SHA256=None,ROOT_RUNTIME_OBSERVATION_SHA256=None,SOURCE_BUNDLE_SHA256=None,INSTALLER_SHA256=None),patch.object(e.measurement,'collect',side_effect=AssertionError('native')):
   with self.assertRaises(RuntimeError):e.execute_owned_flow(None,{}, {},None)
 def test_public_typed_counts_and_actual_digest_match(self):
  n,p,proof,pins,events,pub=self.bind()
  for key,value in [('baseline_check_count',True),('native_source_verified',1),('actual_fixture_sha256','0'*64),('full_source_manifest_sha256','0'*64)]:
   x=copy.deepcopy(pub);x[key]=value
   with self.assertRaises(c.Unproved):c.validate_public(x)
 def test_installer_bootstrap_and_guest_dispatch_compile_fixed(self):
  compile(e.INSTALL13,'installer13','exec');compile(e.EXECUTE13,'dispatch13','exec')
  self.assertIn('O_NOFOLLOW|os.O_NONBLOCK',e.INSTALL13);self.assertIn("media_browser_account_switch_v3",e.EXECUTE13);self.assertIn('execute_bound_guest',e.EXECUTE13)

 def test_remote_runtime13_exact_inverse_fixed_stage_and_new_roles(self):
  import ast
  from pathlib import Path
  root=Path(__file__).parent;v=json.loads((root/'media_browser_remote_runtime13_provenance.json').read_bytes());source=(root/'media_browser_remote_runtime13.py').read_text()
  for old,new in reversed(list(v['metadata_replacements'].items())):source=source.replace(new,old)
  self.assertEqual(hashlib.sha256(source.encode()).hexdigest(),v['predecessor_source_sha256'])
  node=ast.parse((root/'media_browser_remote_runtime13.py').read_bytes());run=next(x for x in node.body if isinstance(x,ast.FunctionDef)and x.name=='run_bound')
  self.assertEqual(run.body[0].names[0].name,'media_browser_measured_executor');self.assertIn("write('browser-flow-v4-measured',browser_result)",(root/'media_browser_remote_runtime13.py').read_text())
 def test_bound_stage_null_before_remote_role_or_adapter_IO(self):
  with patch.multiple(e,EXECUTION_LEASE_SHA256=None,ROOT_ENGINE_OBSERVATION_SHA256=None,ROOT_RUNTIME_OBSERVATION_SHA256=None,SOURCE_BUNDLE_SHA256=None,INSTALLER_SHA256=None):
   with self.assertRaises(RuntimeError):e.bound_stage()

 def test_separate_install_only_lease_actual_workflow_never_browser(self):
  n,p,proof,pins,events,pub=self.bind(True)
  with patch.object(e.measurement,'collect',return_value=measurement(pins)):
   r=e.execute_install_probe(p,proof,n)
  self.assertTrue(r['passed']);self.assertFalse(r['browser_executed']);self.assertFalse(r['TLS_proved']);self.assertIn('install13',events);self.assertNotIn('execute13',events);self.assertEqual(events[-1],'cleanup')
