<?php

require_once dirname(__DIR__).'/bootstrap.php';
PhabricatorEnv::initializeScriptEnvironment(true, true);
function integrationCheck($value, $message) { if (!$value) { throw new Exception($message); } }
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) { throw new Exception($error); }
$address = stream_socket_get_name($listener, false); fclose($listener);
$directory = sys_get_temp_dir().'/gorge-integrations-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
file_put_contents($directory.'/router.php', <<<'ROUTER'
<?php
header('Content-Type: application/json');
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
file_put_contents(__DIR__.'/request.json', json_encode(array(
  'path' => $_SERVER['REQUEST_URI'], 'token' => $_SERVER['HTTP_X_SERVICE_TOKEN'] ?? null,
  'body' => json_decode($raw))));
if ($_SERVER['REQUEST_URI'] === '/api/integrations/capabilities') {
  echo json_encode(array('data'=>array('version'=>2,'inbound'=>array('sendgrid','raw'),
    'targets'=>array('native-twilio'=>'twilio','native-asana'=>'asana','native-github'=>'github','native-jira'=>'jira'),
    'authentication'=>array('asana','jira','github'),'fact'=>true)));
} else if (($body['text'] ?? '') === 'unknown') {
  http_response_code(409);
  echo json_encode(array('error' => array('code'=>'ERR_OUTCOME_UNKNOWN','message'=>'uncertain')));
} else if ($_SERVER['REQUEST_URI'] === '/api/integrations/auth') {
  $operation = $body['operation'] ?? '';
  if ($operation === 'token') {
    $result = array('access_token'=>'fresh-token','expires_in'=>3600);
  } else {
    $result = array('oauth_token'=>'exchanged','oauth_token_secret'=>'token-secret','oauth_callback_confirmed'=>'true');
  }
  echo json_encode(array('data'=>array('state'=>'auth','status'=>200,'result'=>$result)));
} else if ($_SERVER['REQUEST_URI'] === '/api/integrations/read') {
  if (($body['path'] ?? '') === 'user') {
    echo json_encode(array('data'=>array('state'=>'read','status'=>200,'result'=>array('id'=>456,'login'=>'actor'))));
  } else if (($body['target'] ?? '') === 'native-github') {
    echo json_encode(array('data'=>array('state'=>'read','status'=>304,'result'=>array(),
      'headers'=>array('etag'=>'"next"','x-poll-interval'=>'60','x-ratelimit-remaining'=>'0'))));
  } else if (($body['path'] ?? '') === 'users/me') {
    echo json_encode(array('data'=>array('state'=>'read','status'=>200,'result'=>array('gid'=>'123','name'=>'actor'))));
  } else if (($body['path'] ?? '') === 'rest/api/3/myself') {
    echo json_encode(array('data'=>array('state'=>'read','status'=>200,'result'=>array('accountId'=>'jira-id','name'=>'actor'))));
  } else {
    echo json_encode(array('data' => array('state'=>'read','status'=>404,'result'=>array())));
  }
} else if ($_SERVER['REQUEST_URI'] === '/api/integrations/inbound') {
  echo json_encode(array('data' => array('state'=>'accepted','id'=>'receipt')));
} else {
  echo json_encode(array('data'=>array('state'=>'accepted','status'=>201,'result'=>array('sid'=>'fixture'))));
}
ROUTER
);
$process = proc_open(array(PHP_BINARY, '-S', $address, $directory.'/router.php'),
  array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','w'),2=>array('file','/dev/null','w')), $pipes);
try {
  $ready = false;
  for ($i=0;$i<100;$i++) {
    $socket = @stream_socket_client('tcp://'.$address, $errno, $error, 0.1);
    if ($socket) { fclose($socket); $ready=true; break; } usleep(20000);
  }
  integrationCheck($ready, 'Fixture did not start.');
  $env = PhabricatorEnv::beginScopedEnv();
  $env->overrideEnvConfig('phabricator.base-uri', 'https://phorge.test');
  $env->overrideEnvConfig('gorge.integrations', array());
  integrationCheck(!PhabricatorGorgeIntegrationClient::enabled('fact'), 'Default fact takeover.');
  $env->overrideEnvConfig('gorge.integrations', array(
    'uri'=>'http://'.$address,'token'=>'contract-only',
    'sms'=>array('twilio-test'=>'native-twilio'),
    'connectors'=>array('asana'=>'native-asana','github'=>'native-github','jira:jira.test'=>'native-jira'),
    'inbound'=>array('sendgrid','raw'), 'fact'=>true));
  $env->overrideEnvConfig('cluster.mailers', array(array('key'=>'twilio-test','type'=>'twilio','options'=>array())));
  integrationCheck(PhabricatorGorgeIntegrationClient::newConfiguredClient()->checkConfiguration()['valid'], 'Capabilities configuration check failed.');
  integrationCheck(PhabricatorGorgeIntegrationClient::enabled('sms','twilio-test'), 'SMS mapping lost.');
  integrationCheck(!PhabricatorGorgeIntegrationClient::enabled('sms','other'), 'Unconfigured adapter enabled.');
  $adapter = id(new PhabricatorMailTwilioAdapter())->setKey('twilio-test');
  $adapter->setOptions(array());
  $sms = id(new PhabricatorMailSMSMessage())->setToNumber(new PhabricatorPhoneNumber('+15550000001'))
    ->setTextBody('hello')->setGorgeDeliveryID('42');
  $out = $adapter->sendMessage($sms);
  integrationCheck($out['sid']==='fixture', 'Provider response lost.');
  $first = json_decode(file_get_contents($directory.'/request.json'), true);
  integrationCheck($first['token']==='contract-only', 'Service token missing.');
  integrationCheck($first['path']==='/api/integrations/effect', 'Wrong native effect path.');
  integrationCheck($first['body']['target']==='native-twilio' && $first['body']['to']==='+15550000001', 'SMS serialization changed.');
  $adapter->sendMessage($sms);
  $again = json_decode(file_get_contents($directory.'/request.json'), true);
  integrationCheck($again['body']['id']===$first['body']['id'], 'SMS identity not stable.');
  $sms->setTextBody('unknown'); $stopped=false;
  try { $adapter->sendMessage($sms); } catch (PhabricatorMetaMTAPermanentFailureException $ex) { $stopped=true; }
  integrationCheck($stopped, 'Unknown outcome would be resent.');
  PhabricatorGorgeIntegrationClient::connector('asana','story','account',new PhutilOpaqueEnvelope('oauth'), 'tasks','POST',array('name'=>'title'));
  $request = json_decode(file_get_contents($directory.'/request.json'), true);
  integrationCheck($request['body']['secret']==='oauth' && $request['body']['principal']==='account', 'OAuth actor changed.');
  PhabricatorGorgeIntegrationClient::connector('asana','story','account','rotated', 'tasks','POST',array('name'=>'changed'));
  $changed = json_decode(file_get_contents($directory.'/request.json'), true);
  integrationCheck($changed['body']['id']===$request['body']['id'], 'Changed body silently acquired a new effect identity.');
  integrationCheck(PhabricatorGorgeIntegrationClient::readConnector('asana','account','oauth','tasks/1')===null, '404 visibility changed.');
  $request = json_decode(file_get_contents($directory.'/request.json'));
  integrationCheck(is_object($request->body->params), 'Empty API parameters serialized as a JSON list.');
  $github = PhabricatorGorgeIntegrationClient::readGitHub(
    'source', 'oauth', '/repos/owner/repo/events', array('page'=>2), '"old"');
  integrationCheck($github->getStatus()->getStatusCode()===304 &&
    $github->getHeaderValue('ETag')==='"next"' &&
    $github->getHeaderValue('X-RateLimit-Remaining')==='0', 'GitHub polling metadata changed.');
  $request = json_decode(file_get_contents($directory.'/request.json'), true);
  integrationCheck($request['body']['etag']==='"old"' && $request['body']['params']['page']===2, 'Conditional pagination not forwarded.');
  PhabricatorGorgeIntegrationClient::queueRawInbound("From: a@test\r\nTo: b@test\r\n\r\nbody", true);
  $request = json_decode(file_get_contents($directory.'/request.json'), true);
  integrationCheck($request['body']['provider']==='raw' && $request['body']['processDuplicates']===true &&
    strlen($request['body']['ingressID'])===32 && base64_decode($request['body']['raw'])!==false,
    'Raw MTA handoff lost message or duplicate option.');
  $asana = id(new PhutilAsanaAuthAdapter())->setClientID('client')
    ->setClientSecret(new PhutilOpaqueEnvelope('secret'))->setCode('code')
    ->setRedirectURI('https://phorge.test/auth/');
  integrationCheck($asana->getAccessToken()==='fresh-token', 'Native Asana code exchange failed.');
  integrationCheck($asana->getAccountID()==='123', 'Native Asana identity read failed.');
  $asana->refreshAccessToken('refresh');
  integrationCheck($asana->getAccessToken()==='fresh-token' && $asana->getRefreshToken()==='refresh', 'Refresh/expiry semantics changed.');
  $request = json_decode(file_get_contents($directory.'/request.json'), true);
  integrationCheck($request['body']['params']['grant_type']==='refresh_token', 'Refresh bypassed native transport.');
  $github_auth = id(new PhutilGitHubAuthAdapter())->setClientID('client')
    ->setClientSecret(new PhutilOpaqueEnvelope('secret'))->setCode('github-code')
    ->setRedirectURI('https://phorge.test/auth/');
  integrationCheck($github_auth->getAccessToken()==='fresh-token' &&
    $github_auth->getAccountID()===456, 'Native GitHub login exchange or identity failed.');
  $provider_config = id(new PhabricatorAuthProviderConfig())->setProviderDomain('jira.test')
    ->setProperty(PhabricatorJIRAAuthProvider::PROPERTY_JIRA_URI, 'https://jira.test');
  $provider = id(new PhabricatorJIRAAuthProvider())->attachProviderConfig($provider_config);
  integrationCheck($provider->getAdapter()->getPrivateKey()===null,
    'Native JIRA still loads a PHP signing key.');
  $jira = id(new PhutilJIRAAuthAdapter())->setAdapterDomain('jira.test')
    ->setJIRABaseURI('https://jira.test')->setCallbackURI('https://phorge.test/auth/');
  integrationCheck(strpos($jira->getClientRedirectURI(), 'oauth_token=exchanged')!==false, 'Native JIRA request token failed.');
  $jira->setVerifier('verified');
  integrationCheck(count($jira->getAccountIdentifiers())===1 && $jira->getToken()==='exchanged', 'Native JIRA handshake or user identity failed.');
  PhutilSignalRouter::initialize();
  $stopped=false;
  try { id(new PhabricatorFactDaemon(array()))->processIterator(array()); } catch (Exception $ex) { $stopped=strpos($ex->getMessage(),'Gorge')!==false; }
  integrationCheck($stopped, 'Legacy fact writer remained enabled.');
  $stopped = false;
  try { id(new PhabricatorFactDaemon(array()))->processIteratorWithCursor('source', array()); }
  catch (Exception $ex) { $stopped = strpos($ex->getMessage(), 'Gorge') !== false; }
  integrationCheck($stopped, 'Legacy cursor writer reached the database.');
  $cursor_args = new PhutilArgumentParser(array('fact', '--reset', 'source'));
  $cursor_args->parse(array(array('name'=>'reset','param'=>'cursor','repeat'=>true)));
  $stopped = false;
  try { id(new PhabricatorFactManagementCursorsWorkflow())->execute($cursor_args); }
  catch (Exception $ex) { $stopped = strpos($ex->getMessage(), 'Gorge') !== false; }
  integrationCheck($stopped, 'Native cursor could be reset concurrently by PHP.');

  $mail = id(new PhabricatorMetaMTAReceivedMail())->setHeaders(array('Message-ID'=>'<case>'));
  integrationCheck($mail->getHeader('message-id')==='<case>', 'Header normalization changed.');
  echo "Integrations PHP HTTP contract passed.\n";
} finally {
  if (is_resource($process)) { proc_terminate($process); proc_close($process); }
  foreach (array('router.php','request.json') as $name) { if (file_exists($directory.'/'.$name)) { unlink($directory.'/'.$name); } }
  rmdir($directory);
}
