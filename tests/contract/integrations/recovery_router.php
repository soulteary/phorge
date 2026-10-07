<?php
// Actual PHP Conduit method over HTTP; only the response loss is injected.
if ($_SERVER['REQUEST_URI'] === '/readyz') { echo '{}'; return; }
require_once dirname(__DIR__).'/bootstrap.php';
PhabricatorEnv::initializeScriptEnvironment(true, true);
$env = PhabricatorEnv::beginScopedEnv();
foreach (array('mysql.host'=>'127.0.0.1','mysql.port'=>getenv('GORGE_TEST_MYSQL_PORT'),
  'mysql.user'=>'root','mysql.pass'=>getenv('GORGE_TEST_MYSQL_PASSWORD'),
  'cluster.databases'=>array(),'cluster.instance'=>null,
  'storage.default-namespace'=>'gorge_paired_test','gorge.conduit.token'=>'paired-source-only',
  'gorge.integrations'=>array('inbound'=>array('mailgun'))) as $key=>$value) {
  $env->overrideEnvConfig($key,$value);
}
PhabricatorEnv::setReadOnly(false,null);
PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_REFS);
PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_INDIVIDUAL);
try {
  $method = new PhabricatorIntegrationInboundConduitAPIMethod();
  $execute = new ReflectionMethod($method,'execute');
  $params = json_decode($_POST['params'],true);
  $result = $execute->invoke($method,new ConduitAPIRequest($params,true));
  $marker = getenv('GORGE_TEST_RESPONSE_MARKER');
  if ($result['state']==='done' && !file_exists($marker)) {
    file_put_contents($marker,'business committed; response not sent');
    // Driver SIGKILLs both processes at this boundary, then restarts them.
    sleep(60);
  }
  header('Content-Type: application/json');
  echo json_encode(array('result'=>$result,'error_code'=>null,'error_info'=>null));
} catch (Throwable $ex) {
  error_log(get_class($ex).': '.$ex->getMessage().' '.$ex->getTraceAsString());
  http_response_code(500); echo '{"error_code":"ERR_FIXTURE"}';
}
