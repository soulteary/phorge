"""Actual current Phorge bootstrap/sites/maps; no SQL or controller execution."""
import json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[3]
SETUP=r'''require $argv[1].'/support/runtime/src/__phutil_library_init__.php';phutil_load_library($argv[1].'/src');
$stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());$source=new PhabricatorConfigDictionarySource(array('phabricator.base-uri'=>'https://app.example.com/','security.alternate-file-domain'=>'https://files.example.com/','governance.media-exchange.http-enabled'=>true));$stack->pushSource($source);(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());require $argv[1].'/support/governance/media-session-positive/cases.php';'''
class Routes(unittest.TestCase):
 def php(self,body):
  p=subprocess.run(['php','-r',SETUP+body,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_actual_resource_and_app_routes_exact_without_SQL(self):
  d=self.php("$out=array();foreach(array('app'=>array('authorize','issue'),'files'=>array('bridge','prepare','redeem','ready'))as$side=>$actions){foreach($actions as$action){list($m,$q)=media_positive_route($side,$action,$side==='app'?'app.example.com':'files.example.com');$out[]=array(get_class($m->getController()),$m->getURIData()['mediaAction']);}}echo json_encode(array('rows'=>$out,'connections'=>(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()));")
  self.assertEqual(d['rows'],[['PhorgeGovernanceMediaAuthorizeController',a]for a in ['authorize','issue']]+[['PhorgeGovernanceMediaResourceController',a]for a in ['bridge','prepare','redeem','ready']]);self.assertEqual(d['connections'],[])
 def test_actual_sites_refuse_wrong_hosts(self):
  self.assertEqual(self.php("$out=array();foreach(array(array('files','prepare','app.example.com'),array('app','issue','files.example.com'),array('files','ready','unknown.example.com'))as$p){$ok=false;try{media_positive_route(...$p);}catch(RuntimeException$e){$ok=$e->getMessage()==='media_positive_site_refused';}$out[]=$ok;}echo json_encode($out);"),[True]*3)
 def test_original_wrong_route_family_is_reproduced(self):
  self.assertEqual(self.php("$app=new PhabricatorFilesApplication();$wrong=(new AphrontRoutingMap())->setSite(new PhabricatorPlatformSite())->setApplication($app)->setRoutes($app->getRoutes());$resource=new PhabricatorResourceSite();echo json_encode(array($wrong->routePath('/media/session/prepare/')===null,$resource->newSiteForRequest(new AphrontRequest('files.example.com','/media/session/prepare/'))instanceof PhabricatorResourceSite));"),[True,True])
 def test_disabled_HTTP_profile_does_not_fallback_construct(self):
  self.assertTrue(self.php("$source->setKeys(array('governance.media-exchange.http-enabled'=>false));(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());$ok=false;try{media_positive_route('files','prepare','files.example.com');}catch(RuntimeException$e){$ok=$e->getMessage()==='media_positive_exact_route_required';}echo json_encode($ok);"))
if __name__=='__main__':unittest.main()
