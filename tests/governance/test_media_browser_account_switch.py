"""Actual slice control flow; explicit browser/baseline-flow collaborators, no HTTP."""
import hashlib,importlib.util,json,subprocess,unittest
from pathlib import Path
from types import SimpleNamespace
ROOT=Path(__file__).resolve().parents[2]
S=importlib.util.spec_from_file_location('switch',Path(__file__).with_name('media_browser_account_switch.py'));s=importlib.util.module_from_spec(S);S.loader.exec_module(s)
APP='https://app.synthetic.invalid';FILES='https://files.synthetic.invalid'
def fixture():
 a={'schema_version':2,'scope':'owned-native-media-nonauthor-account-switch-v2','username':'mediaswitch'+'a'*12,'password':'synthetic-test-password-only','user_phid':'PHID-USER-'+'b'*20,'author_phid':'PHID-USER-'+'a'*20,'author_is_admin':False,'native_password_verified':True,'is_admin':False,'is_file_author':False,'browser_session_preseeded':False,'own_file_uri':FILES+'/file/data/ordinary/','own_authorize_uri':APP+'/auth/media/authorize/?path=%2Ffile%2Fdata%2Fordinary%2F','own_content_sha256':hashlib.sha256(b'ordinary').hexdigest()}
 return {'account_switch':a,'login_uri':APP+'/auth/login/password:synthetic/','manual_authorize_uri':APP+'/auth/media/authorize/?path=author','private_file_uri':FILES+'/file/data/author/','private_content_marker':'author-private'}
class Old:
 def __init__(self,cookies,p):self.v=[c['value']for c in cookies if c['name']=='media'][0]
 def assert_jar(self,cookies):s.need([c['value']for c in cookies if c['name']=='media']==[self.v])
 def assert_header(self,h):s.need(h=='media='+self.v)
class Observer:
 def __init__(self,app,files):self.posts=set();self.allow_file_probe_top_level=False
 def request(self,url,method,headers):
  if method=='POST':self.posts.add(url.split('?')[0].split('.invalid')[-1])
 def assert_complete(self):s.need(self.posts=={'/media/session/prepare/','/media/session/redeem/','/media/session/ready/','/auth/media/issue/'})
class Baseline:
 Observer=Observer
 EXPECTED_CHECKS=('baseline-native-ten-obligations',)
 OldMediaCookies=Old
 @staticmethod
 def validate_manifest(m,p):return APP,FILES
 @staticmethod
 def verify_cookies(c,p):s.need(any(x['name']=='media'for x in c))
 @staticmethod
 def cookie_values(raw):return dict(x.split('=',1)for x in raw.split('; ')if x)
 @staticmethod
 def run(c,m,approved_profile):
  c.steps.append('baseline-real-flow-collaborator');c.emit(m['manual_authorize_uri'],'phsid=author-session');c.jar=[{'name':'media','value':'old'}]
  return {'passed':True,'checks':list(Baseline.EXPECTED_CHECKS),'real_browser_flow_executed':True}
class Locator:
 def __init__(self,page,selector=''):self.p=page;self.selector=selector
 @property
 def first(self):return self
 def fill(self,v):self.p.c.steps.append('fill-password'if 'password' in self.selector else 'fill-username')
 def click(self):
  self.p.c.steps.append('native-login-submit');
  if self.p.c.fault!='missing-login-post':self.p.c.emit(self.p.c.m['login_uri'],'',method='POST')
  self.p.c.jar.append({'name':'phsid','domain':'app.synthetic.invalid','value':'author-session'if self.p.c.fault=='same-session'else'ordinary-session'})
class Page:
 def __init__(self,c):self.c=c
 def locator(self,s):return Locator(self,s)
 def get_by_role(self,*a,**k):return Locator(self)
 def wait_for_load_state(self,*a):pass
 def goto(self,url,**k):
  self.c.steps.append('goto')
  if url==self.c.m['account_switch']['own_authorize_uri']:
   if self.c.fault!='missing-protocol':
    for path in ['/media/session/prepare/','/media/session/redeem/','/media/session/ready/','/auth/media/issue/']:self.c.emit((APP if path.startswith('/auth/')else FILES)+path,'',method='POST')
   self.c.steps.append('ordinary-one-time-exchange');self.c.jar=[x for x in self.c.jar if x['name']!='media']+[{'name':'media','value':'new'}]
 def wait_for_url(self,url):pass
 def expect_response(self,predicate):
  c=self.c
  class Expect:
   def __enter__(self):
    self.value=SimpleNamespace(url=c.m['account_switch']['own_file_uri'],request=SimpleNamespace(method='GET'),status=200,body=lambda:b'wrong'if c.fault=='wrong-own-bytes'else b'ordinary',all_headers=lambda:{'cache-control':'no-store'})
    s.need(predicate(self.value));return self
   def __exit__(self,*a):pass
  return Expect()
 def evaluate(self,script,data):
  self.c.steps.append('native-file-fetch');self.c.fetches+=1
  value=[x['value']for x in self.c.jar if x['name']=='media'][0]
  if self.c.fault=='rotated-old'and self.c.fetches==1:value='foreign'
  if self.c.fault!='missing-request':self.c.emit(data['url'],'media='+value)
  return {'status':200 if self.c.fault=='author-leak'else 403,'body':'author-private'if self.c.fault=='author-leak'else''}
class Context:
 def __init__(self,m,fault=None):self.m=m;self.fault=fault;self.jar=[];self.listeners=[];self.steps=[];self.fetches=0
 def cookies(self):return self.jar
 def on(self,name,fn):self.listeners.append(fn)
 def remove_listener(self,name,fn):self.listeners.remove(fn)
 def new_page(self):return Page(self)
 def emit(self,url,cookie,method='GET'):
  r=SimpleNamespace(url=url,method=method,all_headers=lambda:{'cookie':cookie})
  for f in list(self.listeners):f(r)
class BrowserSlice(unittest.TestCase):
 def test_actual_run_sequence_and_obligation_set(self):
  m=fixture();c=Context(m);r=s.run(c,m,Baseline,approved_profile=None);self.assertTrue(r['passed']);self.assertEqual(tuple(r['checks']),s.CHECKS);self.assertEqual(c.fetches,2);self.assertLess(c.steps.index('native-login-submit'),c.steps.index('ordinary-one-time-exchange'));self.assertEqual(c.listeners,[]);self.assertFalse(r['TLS_provenance_proved'])
 def test_actual_run_failure_controls_cleanup_listener(self):
  for fault in ['same-session','missing-login-post','wrong-own-bytes','rotated-old','missing-request','author-leak','missing-protocol']:
   with self.subTest(fault=fault):
    m=fixture();c=Context(m,fault)
    with self.assertRaises(s.Unproved):s.run(c,m,Baseline,approved_profile=None)
    self.assertEqual(c.listeners,[])
 def test_private_account_strict_native_identity_and_origin(self):
  for key,value in [('author_is_admin',True),('schema_version',True),('is_admin',True),('is_file_author',True),('browser_session_preseeded',True),('native_password_verified',1),('user_phid','PHID-USER-'+'a'*20),('own_file_uri','https://foreign.invalid/file/data/x')]:
   with self.subTest(key=key):
    m=fixture();m['account_switch'][key]=value
    with self.assertRaises(s.Unproved):s.private_account(m,APP,FILES)
 def test_no_cookie_or_identity_injection_api(self):
  text=Path(s.__file__).read_text()
  for bad in ['add_cookies(', 'set_extra_http_headers(', 'storage_state=', 'APIRequestContext', 'ignore_https_errors=True']:self.assertNotIn(bad,text)
 def test_actual_native_unsaved_user_is_nonauthor_nonadmin(self):
  # Actual nativeUser methods and validation; no fake native identity class/SQL.
  loader=(ROOT/'tests/governance/test_media_remote_native_profile.py').read_text();import ast
  tree=ast.parse(loader);php=next(ast.literal_eval(n.value)for n in tree.body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='LOADER'for t in n.targets))
  code=r'''require$root.'/support/governance/native-organization/media-wire-fixture.php';$u=governance_media_wire_new_switch_user('governance_native_aaaaaaaaaaaaaaaa');$bad=false;try{governance_media_wire_new_switch_user('../bad');}catch(Throwable$e){$bad=true;}echo json_encode(['class'=>get_class($u),'username'=>$u->getUsername(),'admin'=>$u->getIsAdmin(),'approved'=>$u->getIsApproved(),'email'=>$u->getIsEmailVerified(),'unsaved'=>$u->getPHID()===null,'bad'=>$bad]);'''
  p=subprocess.run(['php','-r',php+code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');r=json.loads(p.stdout);self.assertEqual(r['class'],'PhabricatorUser');self.assertFalse(bool(r['admin']));self.assertTrue(bool(r['approved']));self.assertTrue(bool(r['email']));self.assertTrue(r['unsaved']);self.assertTrue(r['bad'])
 def test_real_persistence_and_zero_session_before_ready(self):
  text=(ROOT/'support/governance/native-organization/media-wire-fixture.php').read_text();a=text.index('  $switch_account=null;');b=text.index("  $text='Synthetic",a);block=text[a:b]
  for wanted in ['if($remote!==null)', '->save()', 'PhabricatorAuthPassword::initializeNewPassword($ordinary', '->setPassword(new PhutilOpaqueEnvelope($ordinary_password),$ordinary)->save()', '$ordinary_engine->isValidPassword(', 'new PhabricatorAuthSession()', "(int)$ordinary_row['n']!==0"]:self.assertIn(wanted,block)
  self.assertLess(a,text.index('  $writer_close=null;'));self.assertIn("'authorPHID'=>$ordinary->getPHID(),'viewPolicy'=>PhabricatorPolicies::POLICY_NOONE",text)

class SourceInverse(unittest.TestCase):
 def test_exact_9710_fixture_inverse_and_unknown_delta_refusal(self):
  from media_switch_source_inverse import reduce_fixture
  source=(ROOT/'support/governance/native-organization/media-wire-fixture.php').read_text()
  old=subprocess.check_output(['git','-C',str(ROOT),'show','9710b9edd56b9acf99b7c79b8c7d84669f7f2559:support/governance/native-organization/media-wire-fixture.php']).decode()
  self.assertEqual(reduce_fixture(source),old)
  with self.assertRaises(ValueError):reduce_fixture(source.replace('->setIsAdmin(0)','->setIsAdmin(1)',1))
