"""Actual route/native-controller controls. No SQL, SMTP, HTTP listener or browser."""
from pathlib import Path
import json,subprocess,unittest,tempfile,os
ROOT=Path(__file__).resolve().parents[2]
HELPER=ROOT/'support/governance/notification/notice-history-browser.php'
BOOT=r'''
$root=$argv[1];function phutil_register_library_map($map){foreach($map['class']as$c=>$p){$GLOBALS['paths'][$c]=$GLOBALS['base'].'/'.$p;}}
foreach(['/support/runtime/src','/src']as$b){$GLOBALS['base']=$root.$b;require$root.$b.'/__phutil_library_map__.php';}
spl_autoload_register(function($c){if(isset($GLOBALS['paths'][$c]))require_once$GLOBALS['paths'][$c];});
require_once$root.'/support/runtime/src/utils/utils.php';
$stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());$stack->pushSource(new PhabricatorConfigDictionarySource(['governance.organization.enabled'=>true,'governance.media-exchange.http-enabled'=>false]));
(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,[]);
'''
class NoticeHistoryBrowser(unittest.TestCase):
 def php(self,code,native=False):
  p=subprocess.run(['php','-r',(BOOT if native else '')+'require '+json.dumps(str(HELPER))+';'+code,str(ROOT)],capture_output=True,text=True,timeout=8)
  self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_actual_route_positive_native_login_history_assets_and_query(self):
  paths=['/','/people/organization/history/','/people/organization/history/?json=1&limit=5','/auth/start/','/auth/login/password:self/','/auth/validate/','/auth/finish/','/auth/loggedout/','/logout/','/res/abc/core.js','/res/abc/init.js','/file/data/abc/PHID-FILE-abc/avatar0x.png']
  code='$rows='+json.dumps(paths)+';$out=[];foreach($rows as$p){$out[]=governance_notice_history_route("127.0.0.1:23456",$p,"http://127.0.0.1:23456","GET");}echo json_encode($out);'
  self.assertEqual(self.php(code),[p.split('?')[0] for p in paths])
 def test_actual_route_refuses_mutation_service_setup_traversal_wronghost_and_methods(self):
  values=[['/people/organization/history/','POST'],['/people/organization/','GET'],['/people/organization/approvals/','GET'],['/api/mail.delivery','GET'],['/api/worker.execute','POST'],['/auth/register/','POST'],['/auth/config/new/','GET'],['/res/a/../b.js','GET'],['/res/%2e%2e/b.js','GET'],['/res/%252e/b.js','GET'],['/res/a%5cb.js','GET'],['/res/a\x00b.js','GET'],['//elsewhere/res/a.js','GET'],['/res/init.js','HEAD']]
  code='$rows='+json.dumps(values)+';$out=[];foreach($rows as[$p,$m]){try{governance_notice_history_route("127.0.0.1:23456",$p,"http://127.0.0.1:23456",$m);$out[]=false;}catch(RuntimeException$e){$out[]=true;}}try{governance_notice_history_route("localhost:23456","/logout/","http://127.0.0.1:23456","GET");$out[]=false;}catch(RuntimeException$e){$out[]=true;}echo json_encode($out);'
  self.assertEqual(self.php(code),[True]*(len(values)+1))
 def test_actual_native_people_auth_routes_are_used_without_authority_replacement(self):
  out=self.php('''$people=(new PhabricatorPeopleApplication())->getRoutes();$auth=(new PhabricatorAuthApplication())->getRoutes();echo json_encode(['history'=>$people['/people/']['organization/history/'],'login'=>$auth['/auth/']['login/(?P<pkey>[^/]+)/(?:(?P<extra>[^/]+)/)?'],'validate'=>$auth['/auth/']['validate/'],'finish'=>$auth['/auth/']['finish/'],'logout'=>$auth['/logout/']]);''',True)
  self.assertEqual(out,{'history':'PhorgeOrganizationHistoryController','login':'PhabricatorAuthLoginController','validate':'PhabricatorAuthValidateController','finish':'PhabricatorAuthFinishController','logout':'PhabricatorLogoutController'})
 def test_actual_native_actor_context_refuses_user_object_without_native_session_cookie(self):
  out=self.php('''$out=[];foreach([false,true]as$admin){$user=(new PhabricatorUser())->setID(1)->setPHID('PHID-USER-'.str_repeat('a',20))->setIsAdmin($admin?1:0);$request=(new AphrontRequest('127.0.0.1:23456','/people/organization/history/'))->setUser($user);try{PhorgeGovernanceNativeActorContext::newFromWebRequest($request);$out[]=false;}catch(RuntimeException$e){$out[]=$e->getMessage()==='governance_web_credential_required';}}echo json_encode($out);''',True)
  self.assertEqual(out,[True,True])
 def test_existing_real_producer_worker_retry_and_per_attempt_policy_are_retained(self):
  hold=(ROOT/'support/governance/notification/notice-wire-hold.php').read_text()
  self.assertIn("$service=PhorgeOrganizationService::newForActor($auth['context']);",hold)
  self.assertIn("$prepare_browser===null?null:$prepare_browser($fixture,$user,$check)",hold)
  source=(ROOT/'support/governance/notification/notice-history-browser-router.php').read_text()
  self.assertIn('->processRequest($request,',source);self.assertIn('->buildRequest()',source)
  self.assertNotIn('setUser(',source);self.assertNotIn('setCookie(',source)
  self.assertIn('$request->validateCSRF()',source);self.assertIn("require __DIR__.'/notice-wire-router.php'",source)
  self.assertIn("!isset($_SERVER['HTTP_X_SERVICE_TOKEN'])",source)
  self.assertIn('PhabricatorAuthPassword::initializeNewPassword',HELPER.read_text())
  self.assertIn('->setShouldAllowRegistration(0)',HELPER.read_text())
  for p in ['src/infrastructure/governance/notification/PhorgeGovernanceNoticeSource.php','src/infrastructure/governance/notification/PhorgeGovernanceNoticeWorker.php','src/infrastructure/governance/notification/PhorgeGovernanceNoticeMailPolicy.php','src/applications/people/governance/controller/PhorgeOrganizationHistoryController.php']:
   result=subprocess.run(['git','diff','e22dae3288320edc07a47d241134b72e8823833b','--',p],cwd=ROOT,capture_output=True,text=True,check=True);self.assertEqual(result.stdout,'')
 def test_actual_private_manifest_requires_regular_owned_single_link_and_matching_scope(self):
  with tempfile.TemporaryDirectory() as tmp:
   directory=Path(tmp).resolve();path=directory/'history-browser.json';base={'owner':'a'*64,'callback_origin':'http://127.0.0.1:23456'}
   value={'scope':'owned-notice-native-history-browser','owner':base['owner'],'origin':base['callback_origin'],'history_path':'/people/organization/history/','browser_cookie_injected':False,'producer_native_session_is_separate':True,'SMTP_proved_by_preparation':False,'browser_proved_by_preparation':False}
   raw=json.dumps(value).encode();path.write_bytes(raw);path.chmod(0o600)
   code='try{$v=governance_notice_history_manifest('+json.dumps(str(directory))+','+'json_decode('+json.dumps(json.dumps(base))+',true));echo json_encode($v["scope"]==="owned-notice-native-history-browser");}catch(Throwable$e){echo "false";}'
   self.assertTrue(self.php(code))
   for mutation in ('mode','hardlink','symlink','fifo','wrong-owner-marker','forged-browser-proof'):
    path.unlink()
    for extra in ('retained','hardlink'):
     x=directory/extra
     if x.exists():x.unlink()
    path.write_bytes(raw);path.chmod(0o600)
    if mutation=='mode':path.chmod(0o644)
    elif mutation=='hardlink':os.link(path,directory/'hardlink')
    elif mutation=='symlink':path.rename(directory/'retained');path.symlink_to(directory/'retained')
    elif mutation=='fifo':path.unlink();os.mkfifo(path,0o600)
    else:
     bad=dict(value)
     if mutation=='wrong-owner-marker':bad['owner']='b'*64
     else:bad['browser_proved_by_preparation']=True
     path.write_text(json.dumps(bad))
    self.assertFalse(self.php(code),mutation)
