"""Media source controls and native response units; no SQL, TLS or browser proof."""
import hashlib,hmac,importlib.util,json,os,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(os.environ.get('MEDIA_TASK_TEST_ROOT',Path(__file__).resolve().parents[2]))
CANDIDATE=Path(__file__).resolve().parents[2]
def load(name,path):
 spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
runtime=load('runtime',CANDIDATE/'scripts/governance/media_task_runtime_v2.py')
# Import genuine issuer module; pure filesystem controls never invoke it.
import sys
sys.path.insert(0,str(ROOT/'scripts/governance'))
archive=load('archive',CANDIDATE/'scripts/governance/media_task_archive_v2.py')
class MediaTaskRuntimeData(unittest.TestCase):
 def setUp(self):
  self.raw=(ROOT/runtime.PRODUCER).read_bytes();self.key=b'k'*32
  self.binding={'checkpoint':'a'*40,'physical_source_pins':json.loads((ROOT/runtime.PINS).read_bytes()),'historical_source_pins_sha256':runtime.PINS_SHA,'archive_sha256':'b'*64,'run_nonce':'c'*64}
 def test_source_inputs_rejects_unissued_or_data_only_owner(self):
  import types
  for operation in (None,object(),types.SimpleNamespace(code_root=ROOT,producer_root=ROOT,_task_contexts={ROOT:object()})):
   with self.assertRaises(ValueError):runtime.source_inputs(operation,ROOT)
 def test_adapter_preserves_every_native_check_cleanup_and_deadline(self):
  result=runtime.adapt_worker(self.raw,self.binding,self.key)
  self.assertEqual(result.count(b'$fixture->cleanup()'),self.raw.count(b'$fixture->cleanup()'))
  self.assertEqual(result.count(b'$deadline=microtime(true)+5'),1)
  self.assertIn(b'media_positive_cases($fixture);$passed=true;',result)
  self.assertIn(b"'browser_tested'=>false",result)
  self.assertIn(b"'production_changed'=>false",result)
  self.assertEqual(result.count(runtime.REPLACEMENT),1)
 def test_source_edits_empty_and_duplicate_loop_refused(self):
  for bad in (b'',self.raw+b'\n',self.raw.replace(runtime.LOOP,b''),self.raw+runtime.LOOP):
   with self.assertRaises(ValueError):runtime.adapt_worker(bad,self.binding,self.key)
 def test_binding_key_shape_and_nonce_refused(self):
  for key in (b'',b'k'*31,b'k'*33,'x'*32):
   with self.assertRaises(ValueError):runtime.adapt_worker(self.raw,self.binding,key)
  for update in ({'run_nonce':'x'*64},{'checkpoint':True},{'archive_sha256':'b'*63},{'extra':True}):
   with self.assertRaises(ValueError):runtime.adapt_worker(self.raw,dict(self.binding,**update),self.key)
 def test_unsafe_or_nonclosed_source_pin_map_refused(self):
  for key in ('../secret','/secret','a//b','a\\b','a/./b','a\x00b'):
   pins=dict(self.binding['physical_source_pins']);pins.pop(next(iter(pins)));pins[key]='a'*64
   with self.assertRaises(ValueError):runtime.adapt_worker(self.raw,dict(self.binding,physical_source_pins=pins),self.key)
 def frame(self,native='{}'):
  digest=runtime.sha(runtime.canonical(self.binding));return {'schema':'native-media-Task-selected-frame-v2','binding_sha256':digest,'native_json':native,'mac':hmac.new(self.key,digest.encode()+b'\n'+native.encode(),hashlib.sha256).hexdigest()}
 def test_frame_calls_original_consumer_only_after_exact_binding(self):
  calls=[];frame=self.frame();raw=json.dumps(frame).encode()
  self.assertEqual(runtime.accept_frame(raw,self.binding,self.key,lambda data:calls.append(data)or 'strict-native'), 'strict-native');self.assertEqual(calls,[b'{}'])
 def test_wrong_run_key_payload_and_duplicates_refused_before_consumer(self):
  def deny(_):self.fail('consumer reached for invalid frame')
  frame=self.frame()
  for raw,binding,key in [(json.dumps(frame).encode(),dict(self.binding,run_nonce='d'*64),self.key),(json.dumps(frame).encode(),self.binding,b'q'*32),(json.dumps(dict(frame,native_json='[]')).encode(),self.binding,self.key),(json.dumps(dict(frame,extra=True)).encode(),self.binding,self.key),(b'{"schema":1,"schema":2}',self.binding,self.key)]:
   with self.assertRaises(ValueError):runtime.accept_frame(raw,binding,key,deny)
 def owned(self):
  temp=tempfile.TemporaryDirectory();path=Path(temp.name).resolve();os.chmod(path,0o700);rows={'one.txt':('file',b'owned data',0o644)};_,manifest=archive.publish_data(path,rows,'a'*40);return temp,path,rows,manifest
 def test_held_archive_rechecks_whole_tar_and_exact_four_names(self):
  temp,path,rows,manifest=self.owned()
  with temp:
   held=runtime.HeldArchive(path,rows,manifest,archive)
   try:self.assertEqual(runtime.sha(held.read_bytes()),manifest['archive_sha256']);held.check()
   finally:held.close()
   with self.assertRaises(ValueError):held.check()
 def test_added_name_refused(self):
  temp,path,rows,manifest=self.owned()
  with temp:
   held=runtime.HeldArchive(path,rows,manifest,archive)
   try:
    (path/'extra').write_bytes(b'x')
    with self.assertRaises(ValueError):held.check()
   finally:held.close()
 def test_changed_tar_and_inventory_refused(self):
  for name in ('current-source.tar','current-source-inventory.private.json'):
   temp,path,rows,manifest=self.owned()
   with temp:
    held=runtime.HeldArchive(path,rows,manifest,archive)
    try:
     with (path/name).open('r+b')as f:f.seek(0);f.write(b'X')
     with self.assertRaises(ValueError):held.read_bytes()
    finally:held.close()
 def test_replaced_final_and_third_hardlink_refused(self):
  for action in ('replacement','hardlink'):
   temp,path,rows,manifest=self.owned()
   with temp:
    held=runtime.HeldArchive(path,rows,manifest,archive)
    try:
     p=path/'current-source.tar'
     if action=='replacement':raw=p.read_bytes();p.unlink();p.write_bytes(raw)
     else:os.link(p,path/'third')
     with self.assertRaises(ValueError):held.check()
    finally:held.close()
class MediaCurrentControllerData(unittest.TestCase):
 def setUp(self):self.controller=load('controller',CANDIDATE/'scripts/governance/run_media_task_selected_v2.py')
 def test_cleanup_snapshot_matches_actual_fresh_baseline_only(self):
  d={'uuid':'actual-fresh-UUID','databases':0,'users':'mysql.sys@localhost,root@localhost','MDL':0};raw=json.dumps(d).encode()
  self.assertEqual(self.controller.check_cleanup_snapshot(raw,d['uuid']),d)
  self.assertEqual(self.controller.check_cleanup_snapshot(raw,d['uuid'],d),d)
 def test_cleanup_UUID_users_schema_MDL_and_shapes_refused(self):
  d={'uuid':'actual-fresh-UUID','databases':0,'users':'root@localhost','MDL':0}
  for update in ({'uuid':'other'},{'users':'extra@localhost'},{'databases':1},{'MDL':1},{'MDL':False},{'databases':False},{'users':''},{'extra':True}):
   with self.assertRaises(ValueError):self.controller.check_cleanup_snapshot(json.dumps(dict(d,**update)).encode(),d['uuid'],d)
  for raw in (b'{}',b'[]',b'{"uuid":"a","uuid":"b"}',b'bad json'):
   with self.assertRaises(ValueError):self.controller.check_cleanup_snapshot(raw,d['uuid'],d)
 def test_original_docker_flow_exact_inverse_and_guards_retained(self):
  raw=(ROOT/'support/governance/media-session-docker/run.py').read_bytes();body=self.controller.adapted_execute(raw)
  self.assertIn("timeout=15",body);self.assertIn(b"timeout=240",raw);self.assertIn("timeout=10",body)
  self.assertIn("current_SQL_cleanup(j,c,db,baseline,cleanup_baseline)",body)
  self.assertIn("source_readback(j,c,seed,manifest)",body)
  self.assertIn("inspect(j,c,worker,'worker'",body)
  self.assertIn("if j.unknown:raise",body)
 def test_missing_duplicate_seam_refused(self):
  raw=(ROOT/'support/governance/media-session-docker/run.py').read_bytes()
  for old,_ in self.controller.SEAMS:
   for candidate in (raw.replace(old.encode(),b'',1),raw.replace(old.encode(),old.encode()*2,1)):
    with self.assertRaises(ValueError):self.controller.adapted_execute(candidate)
 def test_helper_keeps_old_body_and_default600_deadline(self):
  original=(ROOT/'support/governance/media-session-docker/helper.php').read_bytes();body=self.controller.helper_program(original,'a'*64)
  self.assertIn(original[6:].decode(),body);self.assertEqual(body.count('microtime(true)+600'),2)
  self.assertIn("strlen($raw)<=131072",body);self.assertIn("hash('sha256',$raw)==='"+'a'*64+"'",body)
  self.assertIn("current-worker.php",body)
 def test_helper_tamper_and_digest_shape_refused(self):
  raw=(ROOT/'support/governance/media-session-docker/helper.php').read_bytes()
  for bad in (b'',raw+b'\n'):
   with self.assertRaises(ValueError):self.controller.helper_program(bad,'a'*64)
  for digest in ('a'*63,'z'*64,True):
   with self.assertRaises(ValueError):self.controller.helper_program(raw,digest)
class MediaCurrentProjectionData(unittest.TestCase):
 def setUp(self):
  self.projector=load('projector',CANDIDATE/'scripts/governance/project_media_task_selected_v2.py')
  self.data={'schema':'media-Task-current-selected-runtime-v2','selected_SQL_accepted':True,'checkpoint':'a'*40,'archive_sha256':'b'*64,'worker_program_sha256':'c'*64,'binding_sha256':'d'*64,'independent_SQL_cleanup_verified':True,'owned_Docker_resources_disposed':True,'source_operation_exit_verified':True,'browser_TLS_or_file_authorization_proved':False,'production_changed':False}
 def test_projection_has_no_runtime_authority_or_browser_claim(self):
  d=self.projector.project(json.dumps(self.data).encode(),'a'*40)
  self.assertIs(d['projector_independently_verifies_process_or_source_authority'],False)
  self.assertIs(d['report_is_future_runtime_authorization'],False)
  self.assertIs(d['browser_TLS_or_file_authorization_proved'],False)
 def test_false_or_numeric_gates_extra_missing_wrong_run_refused(self):
  for field in ('selected_SQL_accepted','independent_SQL_cleanup_verified','owned_Docker_resources_disposed','source_operation_exit_verified'):
   for bad in (False,1,None):
    with self.assertRaises(ValueError):self.projector.project(json.dumps(dict(self.data,**{field:bad})).encode(),'a'*40)
  for data in (dict(self.data,extra=True),dict(self.data,checkpoint='b'*40),dict(self.data,production_changed=True)):
   with self.assertRaises(ValueError):self.projector.project(json.dumps(data).encode(),'a'*40)
 def test_private_report_alias_and_hardlink_refused(self):
  with tempfile.TemporaryDirectory()as temp:
   path=Path(temp).resolve()/'report';path.write_bytes(json.dumps(self.data).encode());path.chmod(0o600)
   self.assertEqual(self.projector.read_private(path),path.read_bytes())
   alias=path.parent/'alias';alias.symlink_to(path)
   with self.assertRaises(ValueError):self.projector.read_private(alias)
   alias.unlink();os.link(path,alias)
   with self.assertRaises(ValueError):self.projector.read_private(path)



PHP = r'''

// Native response hardening only: no HTTP, identity, SQL or cache-hit proof.
require_once $argv[1].'/tests/contract/bootstrap.php';
$stack = new PhabricatorConfigStackSource();
$stack->pushSource(new PhabricatorConfigDefaultSource());
(new ReflectionProperty(PhabricatorEnv::class, 'sourceStack'))->setValue(null, $stack);
(new ReflectionProperty(PhabricatorEnv::class, 'cache'))->setValue(null, array());

$flags = array('governance.media-exchange.enabled',
  'governance.media-exchange.http-enabled');
$before = array();
foreach ($flags as $flag) {
  $before[$flag] = PhabricatorEnv::getEnvConfig($flag);
}
$checks = array();
$check = static function($ok, $name) use (&$checks) {
  if (!$ok) {
    throw new RuntimeException($name);
  }
  $checks[] = $name;
};

foreach (array(
  array('text/html', 'image/png'),
  array('image/png', 'text/html'),
  array('application/pdf', 'text/plain'),
  array('text/plain', 'application/pdf'),
) as $index => $types) {
  $file = (new PhabricatorFile())
    ->setName('synthetic.txt')->setMimeType($types[0]);
  $response = (new AphrontFileResponse())
    ->setMimeType($types[1])->setContent('synthetic');
  PhorgeGovernanceMediaFileGuard::secureResponse($response, $file);
  $check($response->getMimeType() === 'application/octet-stream',
    'unsafe-mime-'.$index.'-download-type');
  $check($response->getDownload() === 'synthetic.txt',
    'unsafe-mime-'.$index.'-download-name');
  $check($response->buildResponseString() === 'synthetic',
    'unsafe-mime-'.$index.'-content-preserved');
}

$file = (new PhabricatorFile())
  ->setName('synthetic.png')->setMimeType('image/png');
$response = (new AphrontFileResponse())
  ->setMimeType('image/png')->setContent('synthetic');
PhorgeGovernanceMediaFileGuard::secureResponse($response, $file);
$check($response->getMimeType() === 'image/png', 'safe-inline-type-preserved');
$check($response->getDownload() === null, 'safe-inline-not-forced-to-download');

foreach (array(302, 304) as $code) {
  $response = $code === 302
    ? (new AphrontRedirectResponse())->setIsExternal(true)
      ->setURI('https://files.example.invalid/file/data/synthetic/')
    : new Aphront304Response();
  $response->setHTTPResponseCode($code)
    ->setCanCDN(true)->setCacheDurationInSeconds(86400);
  $check(PhorgeGovernanceMediaFileGuard::secureResponse($response) === $response,
    'response-'.$code.'-identity-preserved');
  $check($response->getHTTPResponseCode() === $code,
    'response-'.$code.'-status-preserved');
  $headers = array();
  foreach ($response->getCacheHeaders() as $header) {
    $headers[strtolower($header[0])] = $header[1];
  }
  $check($headers['cache-control'] === 'no-store',
    'response-'.$code.'-public-cache-cleared');
  $check($headers['expires'] === 'Sat, 01 Jan 2000 00:00:00 GMT',
    'response-'.$code.'-expiration-cleared');
  $headers = array();
  foreach ($response->getHeaders() as $header) {
    $headers[strtolower($header[0])] = $header[1];
  }
  $check($headers['cache-control'] === 'no-store, private',
    'response-'.$code.'-private-header');
  $check($headers['referrer-policy'] === 'no-referrer',
    'response-'.$code.'-no-referrer');
}

foreach ($flags as $flag) {
  $check($before[$flag] === false &&
    PhabricatorEnv::getEnvConfig($flag) === $before[$flag],
    'default-off-preserved-'.$flag);
}

echo json_encode(array(
  'php' => PHP_VERSION,
  'passed' => true,
  'checks' => $checks,
  'http_or_browser_proof' => false,
  'authenticated_conditional_cache_hit_proof' => false,
  'application_flags_changed' => false,
), JSON_THROW_ON_ERROR)."\n";
'''


class MediaResponseContractTest(unittest.TestCase):
    def test_native_mime_and_cache_response_boundaries(self):
        result = subprocess.run(
            ['php', '-d', 'disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen',
             '-r', PHP, str(ROOT)],
            capture_output=True, text=True, timeout=15)
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        self.assertEqual(result.stderr, '')
        report = json.loads(result.stdout)
        self.assertTrue(report['passed'])
        self.assertEqual(len(report['checks']), 28)
        self.assertEqual(len(set(report['checks'])), 28)
        self.assertFalse(report['http_or_browser_proof'])
        self.assertFalse(report['authenticated_conditional_cache_hit_proof'])
        self.assertFalse(report['application_flags_changed'])


if __name__ == '__main__':
    unittest.main()
