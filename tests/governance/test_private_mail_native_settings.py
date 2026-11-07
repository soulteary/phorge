"""Native settings attachment/Calendar query regression, no DB or network proof."""
from pathlib import Path
import hashlib,json,subprocess,sys,unittest
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_private_calendar_reminders as gate
import project_calendar_failure as diagnostic
POLICY='src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php'

class NativeSettings(unittest.TestCase):
 def test_actual_policy_query_requests_native_preferences_and_timezone_requires_attachment(self):
  code=r'''require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');$source=file_get_contents($argv[1].'/src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php');if(!preg_match('/\$viewer=(\(new PhabricatorPeopleQuery\(\)\).*?)->executeOne\(\);/s',$source,$m))throw new RuntimeException('query absent');$phid='PHID-USER-'.str_repeat('u',20);$query=eval('return '.$m[1].';');$key=PhabricatorUserPreferencesCacheType::KEY_PREFERENCES;$requested=(new ReflectionProperty(PhabricatorPeopleQuery::class,'cacheKeys'))->getValue($query);$without=(new ReflectionProperty(PhabricatorPeopleQuery::class,'cacheKeys'))->getValue(new PhabricatorPeopleQuery());$user=(new PhabricatorUser())->setPHID($phid);$event=new PhabricatorCalendarEvent();$before=null;try{$event->applyViewerTimezone($user);}catch(Throwable$e){$before=['type'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine()];}$row=phutil_json_encode(['timezone'=>'UTC']);$user->attachRawCacheData([$key=>$row]);$after=$event->applyViewerTimezone($user)===$event&&$user->getTimezoneIdentifier()==='UTC';$inline=(new ReflectionProperty(PhabricatorUser::class,'allowInlineCacheGeneration'))->getValue($user);echo json_encode(['query_type'=>get_class($query),'settings_requested'=>($requested[$key]??null)===true,'default_settings_not_requested'=>!isset($without[$key]),'before'=>$before,'after_native_attachment'=>$after,'inline_fallback_enabled'=>$inline===true,'mysqli_disabled'=>!function_exists('mysqli_init')]);'''
  p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect','-r',code,str(ROOT)],capture_output=True,text=True,timeout=15)
  self.assertEqual(0,p.returncode,p.stderr);self.assertEqual('',p.stderr)
  self.assertEqual({'query_type':'PhabricatorPeopleQuery','settings_requested':True,'default_settings_not_requested':True,'before':{'type':'PhabricatorDataNotAttachedException','file':'PhabricatorUser.php','line':1393},'after_native_attachment':True,'inline_fallback_enabled':False,'mysqli_disabled':True},json.loads(p.stdout))
 def test_exact_settings_inverse_rejects_missing_duplicate_other_context_or_comment_even_after_repin(self):
  raw=(ROOT/POLICY).read_bytes();old=subprocess.check_output(['git','-C',str(ROOT),'show','33e707eaf21297b0e626278469c7e9d19cb66a83:'+POLICY])
  self.assertEqual(gate.reduce_reviewed_private_mail_settings(raw),old)
  viewer=b"      $viewer=(new PhabricatorPeopleQuery())->setViewer(PhabricatorUser::getOmnipotentUser())\n        ->withPHIDs(array($phid))->needUserSettings(true)->executeOne();"
  security=b"$user=(new PhabricatorPeopleQuery())->setViewer(PhabricatorUser::getOmnipotentUser())->withPHIDs(array($phid))->executeOne();"
  wrong_security=old.replace(security,security.replace(b'->executeOne()',b'->needUserSettings(true)->executeOne()'))
  self.assertNotEqual(wrong_security,old)
  for changed in [old,raw+viewer,wrong_security,raw+b'\n// unrelated comment\n',raw.replace(b'->needUserSettings(true)',b'->needUserSettings(false)',1)]:
   prior=gate.SOURCE_INPUTS[POLICY]
   try:
    gate.SOURCE_INPUTS[POLICY]=hashlib.sha256(changed).hexdigest()
    with self.assertRaises(ValueError):gate.reduce_reviewed_private_mail_settings(changed)
   finally:gate.SOURCE_INPUTS[POLICY]=prior
  self.assertEqual(gate.PRIVATE_MAIL_SETTINGS_BASE_SHA256,'c402b587fecc63af7d558b37b6f3dd7a46d7343bb7d8fa3c34d7a8d35d913225')
 def test_data_not_attached_fixed_error_projection_never_copies_exception_message(self):
  out=diagnostic.failure_fields({'stage':'calendar-private-each-original-event-current-query-and-egress-allow','error_type':'PhabricatorDataNotAttachedException','source_file':'PhabricatorUser.php','source_line':1393,'message':'PRIVATE_CACHE_DATA','trace':['PRIVATE_CACHE_DATA']})
  self.assertEqual(out['error_type'],'PhabricatorDataNotAttachedException');self.assertNotIn('PRIVATE_CACHE_DATA',json.dumps(out));self.assertNotIn('trace',out)
