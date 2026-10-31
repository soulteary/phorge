<?php

// A loopback-only receiver for native client contract tests. Never handles an
// application route and never reads installation credentials or databases.
$directory = getenv('PHORGE_MAINTENANCE_RPC_FIXTURE');
if (PHP_SAPI !== 'cli-server' || !$directory) {
  exit;
}
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$body = file_get_contents('php://input');
file_put_contents($directory.'/trace.jsonl', json_encode(array(
  'path' => $_SERVER['REQUEST_URI'], 'headers' => $headers,
  'body' => base64_encode($body),
))."\n", FILE_APPEND | LOCK_EX);
$respond = static function($status, $value) {
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
$request = json_decode($body);
$key = file_get_contents($directory.'/key');
$timestamp = $headers['x-maintenance-timestamp'] ?? '';
$nonce = $headers['x-maintenance-nonce'] ?? '';
$signature = $headers['x-maintenance-signature'] ?? '';
$expected = hash_hmac('sha256',
  "POST\n/rpc\n".$timestamp."\n".$nonce."\n".hash('sha256', $body), $key);
$valid = $_SERVER['REQUEST_METHOD'] === 'POST' &&
  $_SERVER['REQUEST_URI'] === '/rpc' &&
  ($headers['content-type'] ?? '') === 'application/json' &&
  ($headers['accept'] ?? '') === 'application/json' &&
  !isset($headers['origin']) && !isset($headers['transfer-encoding']) &&
  (string)strlen($body) === ($headers['content-length'] ?? '') &&
  strlen($body) <= 16384 &&
  preg_match('/^[0-9]{10}$/D', $timestamp) && abs(time() - (int)$timestamp) <= 60 &&
  preg_match('/^[0-9a-f]{32}$/D', $nonce) && hash_equals($expected, $signature) &&
  $request instanceof stdClass &&
  array_keys(get_object_vars($request)) === array(
    'action', 'actor_phid', 'actor_principal', 'request_id', 'params') &&
  preg_match('/^PHID-USER-[a-zA-Z0-9]{20}$/D', $request->actor_phid) &&
  is_string($request->actor_principal) && $request->actor_principal !== '' &&
  preg_match('/^[0-9a-f]{32}$/D', $request->request_id) &&
  $request->params instanceof stdClass;
$required = array(
  'overview' => array(), 'list_users' => array(), 'list_audit' => array(),
  'list_registrations' => array(),
  'approve' => array('registration_id', 'confirmed_phone'),
  'reject' => array('registration_id', 'reason'),
  'set_user_status' => array('user_id', 'status', 'reason'),
  'set_password' => array('user_id', 'password', 'password_confirm', 'reason'),
);
if ($valid) {
  $valid = isset($required[$request->action]);
  if ($valid) {
    foreach ($required[$request->action] as $field) {
      $valid = $valid && property_exists($request->params, $field);
    }
  }
}
if (!$valid) {
  $respond(403, array('ok' => false, 'error' => array('code' => 'unauthorized')));
  exit;
}
$mode = json_decode(file_get_contents($directory.'/mode.json'), true);
$kind = $mode['kind'] ?? 'ok';
switch ($kind) {
  case 'entry-guard':
    // Exercise the actual non-CLI request hook before any provider lookup.
    // Cases deliberately stop at its opt-out, anonymous, or rejection gates.
    $root = dirname(__DIR__, 5);
    require_once $root.'/support/runtime/bootstrap-cli.php';
    phutil_load_library($root.'/src');
    require_once $root.'/scripts/init/lib.php';
    init_phabricator_script(array('config.optional' => true, 'no-extensions' => true));
    $scope = PhabricatorEnv::beginScopedEnv();
    foreach (array(
      'enabled' => $mode['enabled'], 'enforce-file-policy' => false,
      'trusted-proxies' => $mode['trusted-proxies'] ?? array('127.0.0.1'),
      'cookie' => 'configured_entry', 'principal-header' => 'X-Configured-Principal',
      'provider' => 'traefik:traefik', 'provider-login-uri' => '',
      'entry-login-uri' => '/entry/login/',
    ) as $name => $value) {
      $scope->overrideEnvConfig('integrations.entry-session.'.$name, $value);
    }
    unset($_SERVER['HTTP_X_AUTH_USER']);
    if (!empty($mode['principal'])) {
      $_SERVER['HTTP_X_CONFIGURED_PRINCIPAL'] = 'immutable-fixture-person';
    }
    $_COOKIE = !empty($mode['cookie'])
      ? array('configured_entry' => 'disposable-cookie') : array();
    $person = new PhabricatorUser();
    if (empty($mode['anonymous'])) {
      $person->setPHID('PHID-USER-'.str_repeat('a', 20))->setIsApproved(1)
        ->setIsDisabled(!empty($mode['disabled']));
    }
    try {
      id(new TeamEntrySessionGuard())->willServeRequestForUser($person);
      $outcome = 'allowed';
    } catch (TeamEntrySessionMismatchException $ex) {
      $outcome = $ex->getMessage();
    }
    $respond(200, array('ok' => true, 'data' => array('outcome' => $outcome)));
    break;
  case 'error':
    $respond($mode['status'], array('ok' => false, 'error' => array(
      'code' => $mode['code'], 'message' => 'private backend detail')));
    break;
  case 'json':
    http_response_code($mode['status']);
    header('Content-Type: application/json');
    echo $mode['body'];
    break;
  case 'redirect':
    header('Location: /must-not-follow', true, 303);
    $respond(303, array('ok' => true, 'data' => array()));
    break;
  case 'oversize':
    $respond(200, array('ok' => true,
      'data' => array('payload' => str_repeat('x', 1048577))));
    break;
  case 'truncated':
    header('Content-Type: application/json');
    header('Content-Length: 10000');
    echo '{';
    break;
  case 'stall':
    sleep(95);
    // Fall through: the native client must have timed out before this reply.
  default:
    $respond(200, array('ok' => true, 'data' => array(
      'received' => $request, 'nonce' => $nonce, 'signature_valid' => true)));
    break;
}
