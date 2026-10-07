<?php

// No listeners, provider requests or database writes: exercise the actual
// response parsers and search service exception boundary in memory.
require_once dirname(__DIR__).'/bootstrap.php';
PhabricatorEnv::initializeScriptEnvironment(true, true);

function transport_check($value, $message) {
  if (!$value) { throw new RuntimeException($message); }
}
function transport_parse($method, $status, $body) {
  $parser = new ReflectionMethod('PhabricatorGorgeMailerClient', $method);
  return $parser->invoke(null, 'http://fixture/api/mailer/send',
    array($status, $body, array()));
}
function transport_response($code, $body) {
  return new HTTPFutureHTTPResponseStatus($code, $body, array());
}
function transport_failure($method, $code, $body, $unknown) {
  $caught = null;
  try { transport_parse($method, transport_response($code, $body), $body); }
  catch (Exception $ex) { $caught = $ex; }
  transport_check($caught !== null, 'Invalid response was accepted: '.$body);
  if ($unknown !== null) {
    transport_check(($caught instanceof PhabricatorMetaMTAUnknownOutcomeException)
      === $unknown, 'Submission uncertainty classification changed: '.$body);
  }
  return $caught;
}

foreach (array('{}', '[]', 'true', 'null', '"value"', '{invalid',
  '{"unexpected":"payload"}', '{"data":null}', '{"error":"failure"}',
  '{"error":{}}', '{"data":[],"error":{"code":"ERR_SEND_FAILED","message":"bad"}}') as $body) {
  transport_failure('parseResponseEnvelope', 200, $body, null);
  transport_failure('parseSendResponse', 200, $body, true);
}
foreach (array('[]' => array(), 'false' => false, '0' => 0) as $json => $expected) {
  $body = '{"data":'.$json.'}';
  transport_check(transport_parse('parseResponseEnvelope',
    transport_response(200, $body), $body) === $expected,
    'Valid empty/scalar data was rejected.');
}
foreach (array('{"data":{}}', '{"data":{"mailerKey":""}}',
  '{"data":{"mailerKey":1}}', '{"data":{"mailerKey":"smtp","messageId":1}}') as $body) {
  transport_failure('parseSendResponse', 200, $body, true);
}
$body = '{"data":{"mailerKey":"smtp","messageId":""}}';
transport_check(transport_parse('parseSendResponse',
  transport_response(200, $body), $body) === array('mailerKey' => 'smtp', 'messageId' => ''),
  'Valid provider acceptance receipt was rejected.');
transport_failure('parseSendResponse', 502,
  '{"error":{"code":"ERR_SEND_FAILED","message":"confirmed not accepted"}}', false);
transport_failure('parseSendResponse', 200,
  '{"error":{"code":"ERR_SEND_FAILED","message":"inconsistent receipt"}}', true);
transport_failure('parseSendResponse', 500,
  '{"error":{"code":"ERR_BAD_REQUEST","message":"inconsistent receipt"}}', true);
$unknown = transport_failure('parseSendResponse', 502,
  '{"error":{"code":"ERR_OUTCOME_UNKNOWN","message":"uncertain"}}', true);
transport_check($unknown instanceof PhabricatorMetaMTAUnknownOutcomeException,
  'Unknown outcome must remain distinct from recipient rejection.');
$permanent = transport_failure('parseSendResponse', 422,
  '{"error":{"code":"ERR_PERMANENT_FAILURE","message":"rejected"}}', false);
transport_check($permanent instanceof PhabricatorMetaMTAPermanentFailureException,
  'Permanent recipient rejection classification changed.');
transport_failure('parseSendResponse', 200,
  '{"error":{"code":"ERR_PERMANENT_FAILURE","message":"inconsistent receipt"}}', true);
transport_failure('parseSendResponse', 302, '{"data":{"mailerKey":"smtp"}}', true);
foreach (array(CURLE_COULDNT_CONNECT => false, CURLE_COULDNT_RESOLVE_HOST => false,
  CURLE_OPERATION_TIMEOUTED => true, CURLE_RECV_ERROR => true) as $code => $unknown) {
  $caught = null;
  try { transport_parse('parseSendResponse', new HTTPFutureCURLResponseStatus($code), ''); }
  catch (Exception $ex) { $caught = $ex; }
  transport_check($caught !== null &&
    ($caught instanceof PhabricatorMetaMTAUnknownOutcomeException) === $unknown,
    'Transport uncertainty classification changed.');
}
// Even direct adapter calls may not resend an unresolved mail.
$mail = id(new PhabricatorMetaMTAMail())->makeEphemeral()
  ->setStatus(PhabricatorMailOutboundStatus::STATUS_UNKNOWN);
$caught = null;
try { $mail->sendWithMailers(array()); }
catch (PhabricatorMetaMTAUnknownOutcomeException $ex) { $caught = $ex; }
transport_check($caught !== null, 'Unknown mail was allowed to send again.');

$env = PhabricatorEnv::beginScopedEnv();
$env->overrideEnvConfig('gorge.service-policy', 'fallback');
$events = new ArrayObject();
$engine = new class extends PhabricatorFulltextStorageEngine {
  public $failure;
  public function getHostType() { return new PhabricatorMySQLSearchHost($this); }
  public function getEngineIdentifier() { return 'gorge'; }
  public function reindexAbstractDocument(PhabricatorSearchAbstractDocument $doc) {
    throw $this->failure;
  }
  public function executeSearch(PhabricatorSavedQuery $query) { return array(); }
  public function indexExists() { return true; }
  public function getIndexStats() { return array(); }
};
$native = new class($events) extends PhabricatorFulltextStorageEngine {
  private $events;
  public function __construct($events) { $this->events = $events; }
  public function getHostType() { return new PhabricatorMySQLSearchHost($this); }
  public function getEngineIdentifier() { return 'fixture'; }
  public function reindexAbstractDocument(PhabricatorSearchAbstractDocument $doc) {
    $this->events[] = 'native-write';
  }
  public function executeSearch(PhabricatorSavedQuery $query) { return array(); }
  public function indexExists() { return true; }
  public function getIndexStats() { return array(); }
};
$services = array();
foreach (array('gorge' => $engine, 'fixture' => $native) as $type => $provider) {
  $service = new PhabricatorSearchService($provider);
  $service->setConfig(array('type' => $type, 'roles' => array('write' => true)));
  $services[] = $service;
}
$cache = PhabricatorCaches::getRequestCache();
$before = $cache->getKey(PhabricatorSearchService::KEY_REFS);
try {
  $cache->setKey(PhabricatorSearchService::KEY_REFS, $services);
  $engine->failure = new PhabricatorSearchProjectionException('capture failed');
  $caught = null;
  try { PhabricatorSearchService::reindexAbstractDocument(
    id(new PhabricatorSearchAbstractDocument())->setPHID('PHID-TASK-capture')); }
  catch (Exception $ex) { $caught = $ex; }
  transport_check($caught === $engine->failure && count($events) === 0,
    'Capture failure was aggregated or hidden by native backend success.');
  $env->overrideEnvConfig('gorge.service-policy', 'required');
  $engine->failure = new Exception('backend unavailable');
  $caught = null;
  try { PhabricatorSearchService::reindexAbstractDocument(
    id(new PhabricatorSearchAbstractDocument())->setPHID('PHID-TASK-backend')); }
  catch (Exception $ex) { $caught = $ex; }
  transport_check($caught instanceof PhutilAggregateException,
    'Backend aggregation diagnostics were lost.');
  foreach (array($caught, new PhabricatorSearchProjectionException('capture failed'),
    new Exception('backend failed')) as $failure) {
    $rethrown = null;
    try { PhabricatorSearchWorker::rethrowIndexingFailure($failure, false); }
    catch (Exception $ex) { $rethrown = $ex; }
    transport_check($rethrown === $failure, 'Transient indexing failure became task success.');
  }
  $permanent = new PhabricatorWorkerPermanentFailureException('object deleted');
  PhabricatorSearchWorker::rethrowIndexingFailure($permanent, false);
  $caught = null;
  try { PhabricatorSearchWorker::rethrowIndexingFailure($permanent, true); }
  catch (Exception $ex) { $caught = $ex; }
  transport_check($caught === $permanent, 'Strict indexing ignored permanent failure.');
} finally {
  $cache->setKey(PhabricatorSearchService::KEY_REFS, $before);
}
echo "Transport contracts passed: envelope, mail receipt/uncertainty and search retries.\n";
