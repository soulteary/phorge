"""Real native method/route admission tests, no database or fake Query."""
from pathlib import Path
import json, subprocess, unittest
ROOT=Path(__file__).resolve().parents[2]
class NativeDirectoryTransportTest(unittest.TestCase):
    def php(self, body):
        setup="require $argv[1].'/support/runtime/src/__phutil_library_init__.php';phutil_load_library($argv[1].'/src');require $argv[1].'/support/governance/native-organization/directory-transport-cases.php';$stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());$stack->pushSource(new PhabricatorConfigDictionarySource(array('phabricator.cache-namespace'=>'directory-unit-only')));(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());"
        result=subprocess.run(['php','-r',setup+body,str(ROOT)],capture_output=True,text=True,timeout=15)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(result.stderr,'')
        return json.loads(result.stdout)
    def test_actual_query_method_rejects_viewer_without_credential_context(self):
        self.assertEqual(self.php("$u=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('a',20))->setIsAdmin(1);$r=(new ConduitAPIRequest(array('mode'=>'count'),true))->setUser($u);$seen=false;try{(new PeopleOrganizationQueryConduitAPIMethod())->executeMethod($r);}catch(ConduitException $e){$seen=$e->getMessage()==='ERR-ORGANIZATION';}echo json_encode($seen);"),True)
    def test_actual_edit_method_rejects_malformed_payload_before_source(self):
        self.assertEqual(self.php("$u=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('a',20));$r=(new ConduitAPIRequest(array('action'=>'members.edit','payload'=>'not-array'),true))->setUser($u);$seen=false;try{(new PeopleOrganizationEditConduitAPIMethod())->executeMethod($r);}catch(ConduitException $e){$seen=$e->getMessage()==='ERR-ORGANIZATION';}echo json_encode($seen);"),True)
    def test_actual_Web_enabled_route_rejects_viewer_without_native_session(self):
        self.assertEqual(self.php("$stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());$stack->pushSource(new PhabricatorConfigDictionarySource(array('governance.organization.enabled'=>true)));(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());$_SERVER['REQUEST_METHOD']='GET';$_COOKIE[PhabricatorCookies::COOKIE_SESSION]=str_repeat('f',64);$u=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('a',20))->setIsAdmin(1);$request=(new AphrontRequest('fixture.invalid','/people/organization/'))->setUser($u)->setRequestData(array('json'=>'true','mode'=>'count'));$response=(new PhorgeOrganizationController())->setRequest($request)->handleRequest($request);$body=phutil_json_decode($response->setAddJSONShield(false)->buildResponseString());echo json_encode($response->getHTTPResponseCode()===403&&array_keys($body)===array('error'));"),True)
    def test_real_ConduitCall_rejects_caller_actor_parameter(self):
        self.assertEqual(self.php("$seen=false;try{new ConduitCall('people.organization.query',array('actor'=>'PHID-USER-'.str_repeat('a',20)));}catch(ConduitException $e){$seen=strpos($e->getMessage(),'does not define')!==false;}echo json_encode($seen);"),True)
    def test_fixed_fixture_helper_rejects_other_method_before_authentication(self):
        self.assertEqual(self.php("$seen=false;try{governance_directory_transport_api('user.whoami',array(),'not-a-token');}catch(InvalidArgumentException $e){$seen=$e->getMessage()==='directory_transport_method_refused';}echo json_encode($seen);"),True)
    def test_actual_Web_route_feature_off_is_404_without_source_connection(self):
        self.assertEqual(self.php("$stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDictionarySource(array('governance.organization.enabled'=>false)));(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());$request=(new AphrontRequest('fixture.invalid','/people/organization/'))->setUser(new PhabricatorUser());$response=(new PhorgeOrganizationController())->setRequest($request)->handleRequest($request);echo json_encode($response instanceof Aphront404Response);"),True)
if __name__=='__main__':unittest.main()
