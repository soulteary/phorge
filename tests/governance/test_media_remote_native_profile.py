"""Actual native origin protocol and fixture source boundary; no physical SQL."""
from pathlib import Path
import json,subprocess,unittest,sys
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_private_calendar_reminders as calendar_delta
LOADER=r'''$root=$argv[1];function phutil_register_library_map($map){foreach($map['class']as$c=>$p){$GLOBALS['paths'][$c]=$GLOBALS['base'].'/'.$p;}}foreach(['/support/runtime/src','/src']as$s){$GLOBALS['base']=$root.$s;require$root.$s.'/__phutil_library_map__.php';}spl_autoload_register(function($c){if(isset($GLOBALS['paths'][$c]))require_once$GLOBALS['paths'][$c];});require_once$root.'/support/runtime/src/utils/utils.php';require$root.'/support/governance/native-organization/media-remote-profile.php';'''
class MediaRemote(unittest.TestCase):
 def php(self,code,*args):
  p=subprocess.run(['php','-r',LOADER+code,str(ROOT),*args],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_actual_native_protocol_accepts_only_exact_two_HTTPS_same_site_origins(self):
  code=r'''$base=['scope'=>'owned-private-media-remote-native-fixture','app_origin'=>'https://app.synthetic.invalid','file_origin'=>'https://files.synthetic.invalid','origin_review_sha256'=>str_repeat('a',64)];$out=[];foreach([[],['app_origin'=>'http://app.synthetic.invalid'],['file_origin'=>'https://files.foreign.invalid'],['file_origin'=>$base['app_origin']],['file_origin'=>'https://files.media.localhost'],['app_origin'=>'https://app.synthetic.invalid/'],['origin_review_sha256'=>true],['extra'=>true]]as$change){try{governance_media_remote_validate_profile(array_replace($base,$change));$out[]=true;}catch(Throwable$e){$out[]=false;}}echo json_encode(['results'=>$out,'actual_class'=>PhorgeGovernanceMediaHTTPProtocol::class,'SQL_executed'=>false]);'''
  self.assertEqual(self.php(code),{'results':[True,False,False,False,False,False,False,False],'actual_class':'PhorgeGovernanceMediaHTTPProtocol','SQL_executed':False})
 def test_actual_release_helper_refuses_noncanonical_or_remaining_SQL_before_gate(self):
  code=r'''class Statement{public $n;function bind_param(...$v){}function execute(){}function get_result(){return$this;}function fetch_assoc(){return['n'=>$this->n];}function close(){$GLOBALS['closed']++;}}class Control{public $n;function prepare($sql){$s=new Statement();$s->n=$this->n;return$s;}}class Fixture{function getWriterConfig(){return['user'=>'gov_c_writer_aaaaaaaaaaaa'];}}$out=[];$control=new Control();foreach([0,'0',1,'1',false,true,null]as$n){$control->n=$n;$GLOBALS['governance_workbench_http_cleanup_permitted']=false;$closed=0;try{governance_media_remote_release_after_SQL_zero(new Fixture());$ok=true;}catch(Throwable$e){$ok=false;}$out[]=[$ok,$GLOBALS['governance_workbench_http_cleanup_permitted'],$closed];}echo json_encode($out);'''
  self.assertEqual(self.php(code),[[True,True,1],[True,True,1]]+[[False,False,1]]*5)
 def test_actual_native_Lisk_preclose_fault_never_opens_cleanup_or_ready(self):
  code=r'''class ThrowClose{function close(){throw new RuntimeException('owned_close_failure');}}class Fixture{function closeOwnedWriterConnectionForHTTP(){$GLOBALS['called']++;throw new RuntimeException('unexpected');}}$s=file_get_contents($root.'/support/governance/native-organization/media-wire-fixture.php');$a=strpos($s,'  $writer_close=null;if($remote!==null){');$b=strpos($s,'  $ready=array(',$a);$snippet=substr($s,$a,$b-$a);(new ReflectionProperty(LiskDAO::class,'connections'))->setValue(null,[new ThrowClose()]);$remote=[];$fixture=new Fixture();$GLOBALS['governance_workbench_http_cleanup_permitted']=true;$called=0;$check=function(){throw new RuntimeException('unexpectedcheck');};$error=null;try{eval($snippet);}catch(Throwable$e){$error=$e->getMessage();}echo json_encode(['error'=>$error,'cleanup'=>$GLOBALS['governance_workbench_http_cleanup_permitted'],'fixture_called'=>$called,'actual_Lisk'=>get_class(new ReflectionClass(LiskDAO::class)),'SQL_executed'=>false]);'''
  d=self.php(code);self.assertEqual(d['error'],'owned_close_failure');self.assertFalse(d['cleanup']);self.assertEqual(d['fixture_called'],0);self.assertFalse(d['SQL_executed'])
 def test_new_selector_is_explicit_and_legacy_remains_original_call(self):
  s=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text();block=s[s.index('$arguments='):s.index('$workbench_http=')]
  for args,want in [(['--http-wire-remote-hold'],'http-wire-remote-hold'),(['--http-wire-hold'],'http-wire-hold'),(['--http-wire-remote-hold=1'],'invalid'),(['--http-wire-remote-hold','--http-wire-hold'],'invalid')]:
   p=subprocess.run(['php','-r','$argv=array_merge(["driver"],json_decode($argv[1],true));'+block+'echo json_encode($phase);',json.dumps(args)],capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(json.loads(p.stdout),want)
  self.assertIn('else{$native=governance_native_media_wire_hold($fixture,$check);}',s)
if __name__=='__main__':unittest.main()

class LegacySourceInverse(unittest.TestCase):
 def previous(self,name):return subprocess.check_output(['git','-C',str(ROOT),'show','5a3d412be4496d1b739d484fc4cd778b79d80f72:'+name]).decode()
 def test_existing_localhost_driver_and_fixture_recover_exact_prior_bytes(self):
  name='support/governance/native-organization/test-native-organization-mysql.php';s=calendar_delta.reduce_reviewed_calendar_driver((ROOT/name).read_bytes()).decode();s=s.replace("'--http-wire-remote-hold'=>'http-wire-remote-hold',",'').replace("$media_remote=$phase==='http-wire-remote-hold';\n$wire_hold=$phase==='http-wire-hold'||$media_remote;","$wire_hold=$phase==='http-wire-hold';");s=s.replace("require_once __DIR__.'/media-wire-fixture.php';\n      if($media_remote){require_once __DIR__.'/media-remote-profile.php';$native=governance_native_media_wire_hold($fixture,$check,governance_media_remote_profile());}\n      else{$native=governance_native_media_wire_hold($fixture,$check);}","require_once __DIR__.'/media-wire-fixture.php';$native=governance_native_media_wire_hold($fixture,$check);").replace("  }elseif(($media_remote??false)&&($GLOBALS['governance_workbench_http_cleanup_permitted']??null)!==true){\n    $cleanup_failures[]=array('stage'=>'media-remote-parent-or-live-writer-cleanup-unproved','error_type'=>'RuntimeException');\n    // Retain this exact remote-only fixture until independent writerSQL0.\n",'');self.assertEqual(s,self.previous(name))
  name='support/governance/native-organization/media-wire-fixture.php';s=(ROOT/name).read_text().replace('function governance_native_media_wire_hold($fixture,callable $check,?array $remote=null){','function governance_native_media_wire_hold($fixture,callable $check){');s=s.replace("\n  if($remote!==null){governance_media_remote_validate_profile($remote);$app=$remote['app_origin'];$files=$remote['file_origin'];}",'')
  a=s.index("  if($remote!==null){$router['remote_origin_profile']");b=s.index('  $ready=array(',a);s=s[:a]+s[b:];s=s.replace("  $router=array(","  $write('router.json',array(").replace("'gorge_image_uri'=>$image_uri,'gorge_image_token'=>$image_token);","'gorge_image_uri'=>$image_uri,'gorge_image_token'=>$image_token));")
  a=s.index("  if($remote!==null){$ready['remote_origin_profile']");b=s.index("  $check(true,'wire-fixture-private-parent-manifest",a);s=s[:a]+s[b:];s=s.replace("  $ready=array(","  $write('fixture-ready.json',array(").replace("'browser_session_preseeded'=>false,'otp_tested'=>false);","'browser_session_preseeded'=>false,'otp_tested'=>false));")
  s=s.replace("file_get_contents($release)===$owner){\n      if($remote!==null){governance_media_remote_release_after_SQL_zero($fixture);}\n      $released=true;break;}","file_get_contents($release)===$owner){$released=true;break;}");self.assertEqual(s,self.previous(name))
 def test_existing_localhost_router_recovers_exact_prior_bytes(self):
  name='support/governance/native-organization/http-fixture/router.php';s=(ROOT/name).read_text();s=s.replace("  $remote=$config['remote_origin_profile']??null;\n  if($remote!==null){unset($config['remote_origin_profile']);}\n",'').replace("\n  if($remote!==null){require_once dirname(__DIR__).'/media-remote-profile.php';governance_media_remote_validate_profile($remote);}",'');s=s.replace("  if($remote!==null){\n    if($app!==$remote['app_origin']||$files!==$remote['file_origin']){throw new RuntimeException('owned_http_remote_origins_refused');}\n  }elseif(","  if(");self.assertEqual(s,self.previous(name))
 def test_actual_driver_remote_guard_retains_false_or_truthy_unknown(self):
  code=r'''$s=file_get_contents($root.'/support/governance/native-organization/test-native-organization-mysql.php');$a=strrpos($s,'  if($borrowed_unknown){');$b=strpos($s,'  if(!$borrowed_unknown){if ($control !== null)',$a);$block=substr($s,$a,$b-$a);class CleanupFixture{function cleanup(){$GLOBALS['drops']++;return[];}}$out=[];foreach([[true,false,false],[true,true,false],[true,1,false],[false,false,true]]as$case){[$media_remote,$proof,$notice_history]=$case;$phase="http-wire-remote-hold";$borrowed_unknown=false;$native_rr=false;$workbench_http=false;$GLOBALS['governance_workbench_http_cleanup_permitted']=$proof;$fixture=new CleanupFixture();$cleanup_failures=[];$checks=[];$drops=0;eval($block);$out[]=['drops'=>$drops,'failures'=>$cleanup_failures];}echo json_encode($out);'''
  p=subprocess.run(['php','-r',LOADER+code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');rows=json.loads(p.stdout);self.assertEqual([r['drops']for r in rows],[0,1,0,0]);self.assertEqual(rows[0]['failures'][0]['stage'],'media-remote-parent-or-live-writer-cleanup-unproved');self.assertEqual(rows[3]['failures'][0]['stage'],'workbench-http-parent-or-live-writer-cleanup-unproved')

class CurrentMainIntegration(MediaRemote):
 def test_main_native_close_and_release_faults_never_publish_ready_or_cleanup(self):
  code=r'''$s=file_get_contents($root.'/support/governance/native-organization/media-wire-fixture.php');$a=strpos($s,'  $writer_close=null;if($remote!==null){');$b=strpos($s,'  $ready=array(',$a);$snippet=substr($s,$a,$b-$a);class OwnedCloseFixture{public $mode;function closeOwnedWriterConnectionForHTTP(){if($this->mode==='throw')throw new RuntimeException('close_primary');return['owned_fixture_writer_connection_closed'=>$this->mode==='good','exact_original_thread_absent'=>$this->mode==='good'];}}$out=[];foreach(['good','throw','false']as$mode){$remote=[];$fixture=new OwnedCloseFixture();$fixture->mode=$mode;$GLOBALS['governance_workbench_http_cleanup_permitted']=true;$ready=false;$error=null;$check=function($v,$name){if($v!==true)throw new RuntimeException('writer_proof_refused');};try{eval($snippet);$ready=true;}catch(Throwable$e){$error=$e->getMessage();}$out[]=[$ready,$GLOBALS['governance_workbench_http_cleanup_permitted'],$error];}echo json_encode($out);'''
  self.assertEqual(self.php(code),[[True,False,None],[False,False,'close_primary'],[False,False,'writer_proof_refused']])
 def test_release_SQL_fault_closes_statement_and_keeps_gate_shut(self):
  code=r'''class QueryFailure{function bind_param(...$v){}function execute(){throw new RuntimeException('query_primary');}function close(){$GLOBALS['statement_closed']=true;}}class FailureControl{function prepare($sql){if($sql!=='SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE USER=?')throw new RuntimeException('wrong_query');return new QueryFailure();}}class OwnedConfig{function getWriterConfig(){return['user'=>'gov_c_writer_aaaaaaaaaaaa'];}}$control=new FailureControl();$statement_closed=false;$GLOBALS['governance_workbench_http_cleanup_permitted']=false;try{governance_media_remote_release_after_SQL_zero(new OwnedConfig());}catch(Throwable$e){$error=$e->getMessage();}echo json_encode([$error,$statement_closed,$GLOBALS['governance_workbench_http_cleanup_permitted']]);'''
  self.assertEqual(self.php(code),['query_primary',True,False])
 def test_strict_maps_pin_current_union_and_preserve_main_production(self):
  import ast,hashlib
  driver='support/governance/native-organization/test-native-organization-mysql.php'
  for suffix,count in [('file_parent_policy',12),('session_lease',19),('borrowed_session',30)]:
   p=ROOT/f'scripts/governance/check_recovery_{suffix}.py';tree=ast.parse(p.read_text())
   inputs=next(ast.literal_eval(n.value)for n in tree.body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='SOURCE_INPUTS'for t in n.targets))
   self.assertEqual(len(inputs),count)
   for name,pin in inputs.items():
    import recovery_worker_terminal_delta as worker_terminal
    import recovery_oauth_code_source_delta as code_delta
    self.assertEqual(hashlib.sha256(worker_terminal.reduce_if_selected(name,code_delta.reduce_if_selected(name,(ROOT/name).read_bytes()))).hexdigest(),pin,name)
  changed=subprocess.check_output(['git','-C',str(ROOT),'diff','--name-only','5a3d412be4496d1b739d484fc4cd778b79d80f72','--','src','resources'],text=True).splitlines()
  name='src/applications/calendar/notifications/PhabricatorCalendarNotificationEngine.php'
  policy='src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php'
  import recovery_conduit_source_delta as conduit_delta
  import recovery_assessment_source_delta as assessment_delta
  import recovery_oauth_authorization_source_delta as authorization_delta
  import recovery_oauth_client_lifecycle_delta as lifecycle_delta
  extra=set(conduit_delta.SOURCE_INPUTS)|set(assessment_delta.SOURCE_INPUTS)|set(code_delta.SOURCE_INPUTS)|set(authorization_delta.PRODUCTION_PATHS)|set(lifecycle_delta.PRODUCTION_PATHS)
  map_path='src/__phutil_library_map__.php'
  import project_description_admission_delta as n05_delta
  n05_paths=n05_delta.assert_production_cohort(ROOT)
  docs={
   'src/infrastructure/governance/content/README.description-main-integration.md':'0e5143a5c4354ace055e6adb17e752f19b99fbacb752525832ab918c6d40e7f2',
   'src/infrastructure/governance/content/README.description-executor.md':'f3bd3f62cb0d743d6b228d0b066f0eb34b7cb691601ec761ce0d581ebd0b2364',
   'src/infrastructure/governance/content/README.description-source-inverse.md':'7993e97f144b1af281f2fdee46a077aacfdc5a4f61da5a7551dd6598e2d340d6'}
  for path,pin in docs.items():
   raw=(ROOT/path).read_bytes()
   self.assertEqual(hashlib.sha256(raw).hexdigest(),pin)
   self.assertEqual(raw,subprocess.check_output(['git','-C',str(ROOT),'show','15d4f76d9c1c12256f82e53435be038422e205a5:'+path]))
   self.assertEqual((ROOT/path).lstat().st_mode & 0o177777,0o100644)
   self.assertEqual(subprocess.check_output(['git','-C',str(ROOT),'ls-tree','5a3d412be4496d1b739d484fc4cd778b79d80f72','--',path]),b'')
  expected={name,policy}|extra|n05_paths|set(docs)
  if map_path in changed:expected.add(map_path)
  from test_project_description_source_inverse import reviewed_i18n_sources,assert_reviewed_i18n_drift_controls
  i18n=reviewed_i18n_sources(ROOT)
  assert_reviewed_i18n_drift_controls(self)
  self.assertEqual(set(changed),expected|set(i18n))
  for path in authorization_delta.PRODUCTION_PATHS:
   self.assertEqual(authorization_delta.reduce_current(path,(ROOT/path).read_bytes()),subprocess.check_output(['git','-C',str(ROOT),'show','0436e865b8431b9f064fe7b8a92dcad5e6623997:'+path]))
  for path in conduit_delta.BASE_PINS:
   self.assertEqual(conduit_delta.reduce_owner(path,(ROOT/path).read_bytes()),subprocess.check_output(['git','-C',str(ROOT),'show','c496e2cf0ae25fb3e5153c6a1070eb53cf56b504:'+path]))
  # Only the exact separately reviewed Calendar addition is allowed. Its
  # inverse must recover the original product bytes; every other path remains
  # excluded rather than making the historical source check a blanket pass.
  old=subprocess.check_output(['git','-C',str(ROOT),'show','5a3d412be4496d1b739d484fc4cd778b79d80f72:'+name])
  self.assertEqual(calendar_delta.reduce_reviewed_calendar_engine((ROOT/name).read_bytes()),old)
  prior=subprocess.check_output(['git','-C',str(ROOT),'show','5a3d412be4496d1b739d484fc4cd778b79d80f72:'+policy])
  self.assertEqual(calendar_delta.reduce_reviewed_private_mail_settings((ROOT/policy).read_bytes()),prior)
