"""Actual versioned consumer/entry plus actual unchanged legacy consumer.

Only provider context, origin approval and browser transport are collaborators;
module capture, source hashes, legacy/v3 consumers and switch.run are actual.
"""
from pathlib import Path
import ast,contextlib,copy,hashlib,importlib.util,json,sys,unittest
from unittest.mock import patch
import media_browser_account_switch_v3 as v3
from test_media_browser_account_switch import Context,fixture,Baseline
ROOT=Path(__file__).resolve().parents[2]
import media_browser_legacy_consumer as legacy
from media_browser_legacy_obligations import EXPECTED_CHECKS
from types import SimpleNamespace
# Explicit normal-TLS/browser collaborators with real held-file module pins.
flow=SimpleNamespace(__file__=__file__,EXPECTED_CHECKS=EXPECTED_CHECKS,
    validate_manifest=Baseline.validate_manifest,run=Baseline.run,
    OldMediaCookies=Baseline.OldMediaCookies,verify_cookies=Baseline.verify_cookies,
    Observer=Baseline.Observer,cookie_values=Baseline.cookie_values)
provider=SimpleNamespace(__file__=__file__,origins=SimpleNamespace(approve=lambda *a:None),open_owned_context=lambda *a:None)
def sha(p):return hashlib.sha256(Path(p).read_bytes()).hexdigest()
def expectation():return {'files':9046,'aliases':36,'manifest_sha256':'c'*64,'aliases_sha256':'d'*64,'source_root_identity':{'dev':1,'ino':2},'native_tuple':{'source_head':'a'*40,'source_tree':'b'*40,'app_origin':'https://app.synthetic.invalid','file_origin':'https://files.synthetic.invalid','transport_proof_sha256':'e'*64},'local_forward_port':29091,'receiver_sha256':'f'*64}
def measurement(pins):
 n=expectation();return {'scope':'owned-native-media-full-source-measurement-v1','passed':True,**{k:n[k]for k in ('files','aliases','manifest_sha256','aliases_sha256','source_root_identity','native_tuple','local_forward_port','receiver_sha256')},'actual_fixture_sha256':pins['fixture_sha256'],'measurement_nonce':'1'*64,'native_owner_sha256':'2'*64,'guest_receipt_sha256':'3'*64}
def review():
 pins={'source_revision':'a'*40,'fixture_sha256':sha(ROOT/'support/governance/native-organization/media-wire-fixture.php'),'switch_sha256':sha(v3.switch.__file__),'baseline_flow_sha256':sha(flow.__file__),'legacy_consumer_sha256':sha(legacy.__file__),'normal_TLS_provider_sha256':sha(provider.__file__),'legacy_obligations_sha256':sha(v3.obligations.__file__)}
 raw=json.dumps(dict(scope=v3.SCOPE,approved=True,source_pins=pins,normal_TLS_required=True,public_route_authorized=False,deployment=False,native_source=expectation()),sort_keys=True).encode();return raw,pins
def baseline():return dict(schema_version=2,scope='two-host-same-site-native-media-browser-approved-origins-v2',passed=True,approved_origin_profile_bound=True,real_browser_flow_executed=True,check_count=10,checks=list(flow.EXPECTED_CHECKS),SQL_cleanup_proved=False,process_cleanup_proved=False,TLS_trust_and_binary_provenance_proved=False,deployment=False)
def envelope(pins):return dict(scope=v3.SCOPE,passed=True,baseline=baseline(),account_switch=dict(scope='owned-native-media-account-switch-browser-slice',passed=True,checks=list(v3.switch.CHECKS),check_count=6,cookie_injection=False,TLS_provenance_proved=False,SQL_process_cleanup_proved=False,deployment=False),source_pins=pins,normal_TLS_negatives_observed=True,context_browser_driver_closed=True,global_trust_changed=False,public_route_authorized=False,deployment=False,native_measurement=measurement(pins))
class Consumer(unittest.TestCase):
 def setUp(self):
  raw,self.pins=review();self.bound=patch.multiple(v3,ROOT_REVIEW_BYTES=raw,ROOT_REVIEW_SHA256=hashlib.sha256(raw).hexdigest());self.bound.start();self.addCleanup(self.bound.stop)
 def test_actual_legacy10_then_new6_projection_and_secret_exclusion(self):
  x=envelope(self.pins);x['baseline']['private_canary']='do-not-project';result=v3.consume(x,legacy)
  self.assertEqual(result['baseline_check_count'],10);self.assertEqual(result['account_switch_check_count'],6);self.assertNotIn('do-not-project',json.dumps(result));self.assertNotIn('baseline',result);self.assertNotIn('account_switch',result)
 def test_old_consumer_remains_exact_shape_and_checks(self):
  with self.assertRaises(RuntimeError):legacy.guest_receipt(envelope(self.pins))
  for changed in [[],list(flow.EXPECTED_CHECKS)+['new-switch-obligation']]:
   x=envelope(self.pins);x['baseline']['checks']=changed
   with self.assertRaises(RuntimeError):v3.consume(x,legacy)
 def test_strict_six_obligations_types_scope_and_source_pins(self):
  for mutate in [lambda x:x['account_switch'].update(check_count=True),lambda x:x['account_switch'].update(checks=list(v3.switch.CHECKS)[::-1]),lambda x:x['account_switch'].update(secret='unknown'),lambda x:x.update(normal_TLS_negatives_observed=1),lambda x:x['source_pins'].update(fixture_sha256='b'*64),lambda x:x['account_switch'].update(cookie_injection=True)]:
   with self.subTest(mutate=mutate):
    x=copy.deepcopy(envelope(self.pins));mutate(x)
    with self.assertRaises(v3.Unproved):v3.consume(x,legacy)
 def test_actual_module_pin_mismatch_refuses_before_context(self):
  with self.assertRaises(v3.Unproved):v3.module_pin(provider,'0'*64)
 def test_null_review_before_input_or_module_or_context(self):
  with patch.multiple(v3,ROOT_REVIEW_BYTES=None,ROOT_REVIEW_SHA256=None),patch.object(v3,'module_pin',side_effect=AssertionError('IO')):
   with self.assertRaises(v3.Unproved):v3.execute_guest(b'{}',provider=provider,baseline_flow=flow,legacy_consumer=legacy)
 def test_real_entry_calls_actual_switch_and_consumers_context_closes(self):
  m=fixture();m['remote_origin_profile']={};c=Context(m);events=[]
  @contextlib.contextmanager
  def opened(profile,tuple,**kw):
   events.append('opened')
   try:yield c
   finally:events.append('closed')
  # Actual switch flow uses declared host BrowserContext/ten-flow collaborators;
  # module hashes and both consumers still execute from actual source bytes.
  def prior(context,manifest,approved_profile):
   Baseline.run(context,manifest,approved_profile);return baseline()
  d=dict(native_tuple=expectation()['native_tuple'],manifest=m,origin_review='{}',nonce='a'*24,owner='b'*64,native_measurement=measurement(self.pins))
  with patch.object(provider,'open_owned_context',opened),patch.object(provider.origins,'approve',return_value='approved-profile'),patch.object(flow,'validate_manifest',Baseline.validate_manifest),patch.object(flow,'run',prior),patch.object(flow,'OldMediaCookies',Baseline.OldMediaCookies),patch.object(flow,'verify_cookies',Baseline.verify_cookies),patch.object(flow,'Observer',Baseline.Observer):
   result=v3.execute_guest(json.dumps(d).encode(),provider=provider,baseline_flow=flow,legacy_consumer=legacy)
  self.assertTrue(result['passed']);self.assertEqual(events,['opened','closed']);self.assertEqual(c.listeners,[])
 def test_real_entry_context_failure_never_public_success(self):
  @contextlib.contextmanager
  def opened(*a,**k):
   try:yield None
   finally:raise RuntimeError('context-close-failure')
  d=dict(native_tuple=expectation()['native_tuple'],manifest={'remote_origin_profile':{}},origin_review='{}',nonce='a'*24,owner='b'*64,native_measurement=measurement(self.pins))
  with patch.object(provider,'open_owned_context',opened),patch.object(provider.origins,'approve',return_value='approved'),patch.object(v3.switch,'run',return_value={'baseline_flow':baseline()}):
   with self.assertRaisesRegex(RuntimeError,'context-close-failure'):v3.execute_guest(json.dumps(d).encode(),provider=provider,baseline_flow=flow,legacy_consumer=legacy)
 def test_actual_native_author_expression_nonadmin_remote_localhost_original(self):
  old=ast.parse((ROOT/'tests/governance/test_media_remote_native_profile.py').read_text());loader=next(ast.literal_eval(n.value)for n in old.body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='LOADER'for t in n.targets))
  code=r'''$s=file_get_contents($root.'/support/governance/native-organization/media-wire-fixture.php');$a=strpos($s,'  $user=(new PhabricatorUser())');$b=strpos($s,'->save();',$a);$fragment=substr($s,$a,$b-$a).';';class NamespaceOnly{function getNamespace(){return'governance_native_aaaaaaaaaaaaaaaa';}}$fixture=new NamespaceOnly();$out=[];foreach([null,[]]as$remote){eval($fragment);$out[]=['admin'=>(bool)$user->getIsAdmin(),'native'=>get_class($user),'unsaved'=>$user->getPHID()===null];}echo json_encode($out);'''
  import subprocess
  p=subprocess.run(['php','-r',loader+code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');self.assertEqual(json.loads(p.stdout),[{'admin':True,'native':'PhabricatorUser','unsaved':True},{'admin':False,'native':'PhabricatorUser','unsaved':True}])

 def test_legacy_body_inverse_exact_original_function_pin(self):
  provenance=json.loads((ROOT/'tests/governance/media_browser_legacy_provenance.json').read_bytes())
  raw=(ROOT/'tests/governance/media_browser_legacy_consumer.py').read_text();tree=ast.parse(raw)
  f=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='guest_receipt')
  body=ast.get_source_segment(raw,f).replace(provenance['local_import'],provenance['original_import'])
  self.assertEqual(hashlib.sha256(body.encode()).hexdigest(),provenance['original_guest_receipt_function_sha256'])

 def test_fixed_guest_dispatch_and_null_before_dependency_import(self):
  with patch.multiple(v3,ROOT_REVIEW_BYTES=None,ROOT_REVIEW_SHA256=None):
   with self.assertRaises(v3.Unproved):v3.execute_bound_guest(b'{}')
  node=next(n for n in ast.parse(Path(v3.__file__).read_text()).body if isinstance(n,ast.FunctionDef)and n.name=='execute_bound_guest')
  self.assertEqual([x.name for x in next(y for y in node.body if isinstance(y,ast.ImportFrom)).names],['normal_tls_provider','flow','media_browser_legacy_consumer'])

 def test_actual_module_capture_regular_fifo_symlink_hardlink_and_mode(self):
  import tempfile,os,types,time
  with tempfile.TemporaryDirectory()as directory:
   root=Path(directory).resolve();p=root/'module.py';p.write_bytes(b'x=1\n');p.chmod(0o600);m=types.SimpleNamespace(__file__=str(p));pin=sha(p)
   v3.module_pin(m,pin)
   p.chmod(0o622)
   with self.assertRaises(v3.Unproved):v3.module_pin(m,pin)
   p.chmod(0o600);os.link(p,root/'hardlink')
   with self.assertRaises(v3.Unproved):v3.module_pin(m,pin)
   (root/'hardlink').unlink();p.unlink();os.mkfifo(p,0o600);start=time.monotonic()
   with self.assertRaises(v3.Unproved):v3.module_pin(m,pin)
   self.assertLess(time.monotonic()-start,1);p.unlink();(root/'target').write_bytes(b'x=1\n');p.symlink_to(root/'target')
   with self.assertRaises(v3.Unproved):v3.module_pin(m,pin)

 def test_missing_or_forged_actual_measurement_before_module_or_browser(self):
  for change in [None,{'actual_fixture_sha256':'0'*64},{'files':True},{'local_forward_port':True},{'manifest_sha256':'0'*64}]:
   d=dict(native_tuple=expectation()['native_tuple'],manifest=fixture(),origin_review='{}',nonce='a'*24,owner='b'*64,native_measurement=measurement(self.pins))
   if change is None:d.pop('native_measurement')
   else:d['native_measurement'].update(change)
   with patch.object(v3,'module_pin',side_effect=AssertionError('before-module')),patch.object(provider,'open_owned_context',side_effect=AssertionError('before-browser')):
    with self.assertRaises(v3.Unproved):v3.execute_guest(json.dumps(d).encode(),provider=provider,baseline_flow=flow,legacy_consumer=legacy)
