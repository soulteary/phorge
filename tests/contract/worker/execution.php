<?php

// Run with GORGE_TEST_ARCANIST_DIR=/path/to/arcanist php execution.php.
$arcanist = getenv('GORGE_TEST_ARCANIST_DIR');
if (!$arcanist) {
  fwrite(STDERR, "Set GORGE_TEST_ARCANIST_DIR to an Arcanist checkout.\n");
  exit(1);
}
require $arcanist.'/src/init/init-library.php';
phutil_load_library($arcanist.'/src');
phutil_load_library(dirname(__FILE__).'/../../../src');
PhabricatorEnv::initializeScriptEnvironment(true, true);
$env = PhabricatorEnv::beginScopedEnv();
$env->overrideEnvConfig('gorge.conduit.token', 'execution-contract-test');
$_SERVER['HTTP_X_SERVICE_TOKEN'] = 'execution-contract-test';
$params = array(
  'taskID' => 1,
  'taskClass' => 'PhabricatorTestWorker',
  'phase' => 'execute',
  'executionVersion' => 1,
  'failureCount' => 2,
  'priority' => 1000,
  'leaseOwner' => 'contract-test',
  'leaseExpires' => time() + 3600,
);
function check_execution($condition, $message) {
  if (!$condition) {
    throw new Exception($message);
  }
}
function call_execution(array $params, array $data) {
  $params['data'] = phutil_json_encode($data);
  return id(new ConduitCall('worker.execute', $params))->execute();
}
// Mail jobs store a scalar message ID, not a JSON object. Preparation is
// read-only and must accept that payload without attempting mail delivery.
$mail_probe = $params;
$mail_probe['taskClass'] = 'PhabricatorMetaMTAWorker';
$mail_probe['phase'] = 'prepare';
foreach (array('2', '"2"') as $raw) {
  $mail_probe['data'] = $raw;
  $result = id(new ConduitCall('worker.execute', $mail_probe))->execute();
  check_execution($result['result'] === 'prepared',
    'Scalar mail task payload rejected.');
}
$mail_probe['data'] = '{invalid';
$result = id(new ConduitCall('worker.execute', $mail_probe))->execute();
check_execution($result['result'] === 'permanent-failure',
  'Malformed JSON payload accepted.');

$probe = $params;
$probe['phase'] = 'capabilities';
$probe['taskClass'] = '';
$result = call_execution($probe, array());
check_execution($result['executionVersion'] === 1 &&
  $result['result'] === 'capabilities', 'Capability negotiation failed.');
$result = call_execution($params, array(
  'queueFollowup' => true,
  'followupCount' => 2,
));
check_execution($result['result'] === 'success', 'Task failed.');
check_execution(count($result['followups']) === 2, 'Followups lost.');
check_execution(
  phutil_json_decode($result['followups'][1]['data'])['followupIndex'] === 1,
  'Followup payload lost.');
$result = call_execution($params, array(
  'doWork' => 'fail-temporary',
  'getWaitBeforeRetry' => 123,
));
check_execution($result['result'] === 'failure' && $result['retry'] === 123,
  'Custom retry policy lost.');
$result = call_execution($params, array('getMaximumRetryCount' => 1));
check_execution($result['result'] === 'permanent-failure',
  'Maximum retry count ignored.');
$retired = $params;
$retired['taskClass'] = 'GorgeRetiredWorkerContract';
$result = call_execution($retired, array());
check_execution($result['result'] === 'permanent-failure',
  'Retired task would retry forever.');
$params['phase'] = 'prepare';
$result = call_execution($params, array('getRequiredLeaseTime' => 9000));
check_execution($result['result'] === 'prepared' &&
  $result['leaseDuration'] === 9000, 'Custom lease lost.');
// Snapshot generation preserves the complete, nested PHP form contract.
$story_data = id(new PhabricatorFeedStoryData())
  ->makeEphemeral()
  ->setID(17)
  ->setStoryType('ContractStory')
  ->setStoryData(array('nested' => array('title' => '中文 & title')))
  ->setAuthorPHID('PHID-USER-contract')
  ->setChronologicalKey((string)(1700000000 << 32));
$story = new class($story_data) extends PhabricatorFeedStory {
  public function renderView() { return null; }
  public function renderText() { return '故事正文'; }
};
$snapshot = FeedPublisherHTTPWorker::newGorgeTaskData(
  $story, 'https://example.test/feed');
parse_str($snapshot['body'], $form);
check_execution($form['storyID'] === '17' &&
  $form['storyData']['nested']['title'] === '中文 & title' &&
  $form['storyAuthorPHID'] === 'PHID-USER-contract' &&
  $form['storyText'] === '故事正文' &&
  $form['epoch'] === '1700000000', 'Feed form contract lost.');
$feed_params = $params;
$feed_params['taskClass'] = 'FeedPublisherHTTPWorker';
$env->overrideEnvConfig('feed.http-hooks', array($snapshot['uri']));
$result = call_execution($feed_params, $snapshot);
check_execution($result['result'] === 'prepared', 'Configured feed rejected.');
$env->overrideEnvConfig('phabricator.silent', true);
$result = call_execution($feed_params, $snapshot);
check_execution($result['result'] === 'skipped', 'Silent mode ignored.');
$env->overrideEnvConfig('phabricator.silent', false);
$env->overrideEnvConfig('feed.http-hooks', array());
$result = call_execution($feed_params, $snapshot);
check_execution($result['result'] === 'permanent-failure',
  'Removed feed hook accepted.');
$expired = $params;
$expired['leaseExpires'] = time() - 1;
try {
  call_execution($expired, array());
  throw new Exception('Expired execution accepted.');
} catch (ConduitException $ex) {
  check_execution($ex->getMessage() === 'ERR-WORKER-CONTEXT',
    'Wrong expired execution error.');
}
foreach (array('', 'incorrect') as $token) {
  $_SERVER['HTTP_X_SERVICE_TOKEN'] = $token;
  try {
    call_execution($params, array());
    throw new Exception('Unauthenticated execution accepted.');
  } catch (ConduitException $ex) {
    check_execution($ex->getMessage() === 'ERR-WORKER-AUTH',
      'Wrong authentication error.');
  }
}
$_SERVER['HTTP_X_SERVICE_TOKEN'] = 'execution-contract-test';
$env->overrideEnvConfig('gorge.conduit.token', '');
try {
  call_execution($params, array());
  throw new Exception('Unconfigured service token accepted.');
} catch (ConduitException $ex) {
  check_execution($ex->getMessage() === 'ERR-WORKER-AUTH',
    'Missing token did not fail closed.');
}
echo "Worker execution contract passed.\n";
