<?php

require_once dirname(__DIR__).'/bootstrap.php';

// Keep this UI contract independent of installed deployment configuration,
// databases and notification services.
$stack = id(new PhabricatorConfigStackSource())
  ->pushSource(new PhabricatorConfigDefaultSource());
$property = new ReflectionProperty('PhabricatorEnv', 'sourceStack');
$property->setValue(null, $stack);
$initial_env = PhabricatorEnv::beginScopedEnv();
PhabricatorEnv::setLocaleCode('en_US');

function notification_status_assert($condition, $message) {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function notification_status_behaviors() {
  $property = new ReflectionProperty(
    'CelerityStaticResourceResponse', 'behaviors');
  return $property->getValue(CelerityAPI::getStaticResourceResponse());
}

function notification_status_reset_resources() {
  $property = new ReflectionProperty('CelerityAPI', 'response');
  $property->setValue(null, new CelerityStaticResourceResponse());
}

function notification_status_server($type, $protocol, $disabled = false) {
  return array(
    'type' => $type,
    'host' => $type === 'admin'
      ? 'notification-internal'
      : ($disabled ? 'disabled-browser.example' : 'browser.example'),
    'port' => $type === 'admin' ? 22281 : ($protocol === 'https' ? 443 : 22280),
    'protocol' => $protocol,
    'disabled' => $disabled,
  );
}

function notification_status_check(
  $name,
  array $servers,
  $https,
  $logged_in,
  $state,
  $message,
  $global_policy = 'required',
  array $policies = array(),
  $context = 'both') {

  $env = PhabricatorEnv::beginScopedEnv();
  $env->overrideEnvConfig('notification.servers', $servers);
  $env->overrideEnvConfig('gorge.service-policy', $global_policy);
  $env->overrideEnvConfig('gorge.service-policies', $policies);
  $env->overrideEnvConfig('cluster.instance', null);
  PhabricatorCaches::destroyRequestCache();
  notification_status_reset_resources();
  if ($https) {
    $_SERVER['HTTPS'] = 'on';
  } else {
    $_SERVER['HTTPS'] = 'off';
  }

  $viewer = new PhabricatorUser();
  if ($logged_in) {
    $viewer->setPHID('PHID-USER-notificationstatus');
  }
  $request = id(new AphrontRequest('page.example', '/'))
    ->setUser($viewer);
  $view = new PhabricatorNotificationStatusView();
  if ($context === 'both' || $context === 'request') {
    $view->setRequest($request);
  }
  if ($context === 'both' || $context === 'viewer') {
    // Preserve the inherited compatibility alias used by existing views.
    $view->setUser($viewer);
  }

  $html = (string)$view->render();
  $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
  notification_status_assert(
    strpos($html, 'aphlict-connection-status-'.$state) !== false,
    $name.': incorrect connection state.');
  notification_status_assert(strpos($text, $message) !== false,
    $name.': incorrect visible explanation: '.$text);

  $behaviors = notification_status_behaviors();
  $configs = idx($behaviors, 'aphlict-status', array());
  $dynamic = ($state === 'connecting');
  notification_status_assert(count($configs) === ($dynamic ? 1 : 0),
    $name.': incorrect number of status bindings.');
  notification_status_assert(!isset($behaviors['aphlict-listen']),
    $name.': the status view started another notification connection.');

  if ($dynamic) {
    notification_status_assert(
      idx(head($configs), 'nodeID') === $view->getID(),
      $name.': status binding does not target its rendered view.');
    notification_status_assert(
      strpos($html, 'id="'.$view->getID().'"') !== false,
      $name.': status binding has no corresponding DOM node.');
    $view->render();
    notification_status_assert(
      count(idx(notification_status_behaviors(), 'aphlict-status')) === 1,
      $name.': rendering the view again duplicated its status binding.');
  } else {
    notification_status_assert(strpos($text, 'Connecting...') === false,
      $name.': an inactive view remains permanently connecting.');
  }

  // Verify these conditions against the real page footer which starts Aphlict,
  // rather than mirroring its implementation in the fixture.
  notification_status_reset_resources();
  $page = id(new PhabricatorStandardPageView())->setRequest($request);
  $tail = new ReflectionMethod('PhabricatorStandardPageView', 'getTail');
  // getTail renders and consumes behavior registrations, so inspect its output.
  $tail_html = (string)hsprintf('%s', $tail->invoke($page));
  $has_client = strpos($tail_html, 'websocketURI') !== false;
  if ($context === 'both') {
    notification_status_assert($has_client === $dynamic,
      $name.': status activation disagrees with the actual page footer.');
  }

  unset($env);
}

$original_https = idx($_SERVER, 'HTTPS');
$env = null;
try {
  $admin = notification_status_server('admin', 'http');
  $http = notification_status_server('client', 'http');
  $https = notification_status_server('client', 'https');
  $disabled = notification_status_server('client', 'http', true);
  $pair = array($admin, $http);

  notification_status_check('global off', $pair, false, true,
    'disabled', 'Notifications are disabled.', 'off');
  notification_status_check('notification override off', $pair, false, true,
    'disabled', 'Notifications are disabled.', 'required',
    array('notification' => 'off'));
  notification_status_check('unconfigured', array(), false, true,
    'notenabled', 'Notification server not enabled');
  notification_status_check('disabled clients', array($admin, $disabled),
    false, true, 'noclients', 'No enabled browser notification servers.');
  notification_status_check('all servers disabled',
    array(notification_status_server('admin', 'http', true), $disabled),
    false, true, 'noclients', 'No enabled browser notification servers.');
  notification_status_check('admin only', array($admin), false, true,
    'noclients', 'No enabled browser notification servers.');
  notification_status_check('HTTPS page and HTTP endpoint', $pair, true, true,
    'protocol', 'No notification server supports this page protocol (https).');
  notification_status_check('HTTP page and HTTPS endpoint',
    array($admin, $https), false, true, 'protocol',
    'No notification server supports this page protocol (http).');
  notification_status_check('anonymous', $pair, false, false,
    'signin', 'Sign in to connect to notifications.');
  notification_status_check('no context', $pair, false, true,
    'unavailable', 'Notification connection status is unavailable.',
    'required', array(), 'none');
  notification_status_check('viewer only', $pair, false, true,
    'unavailable', 'Notification connection status is unavailable.',
    'required', array(), 'viewer');
  notification_status_check('request only', $pair, false, true,
    'unavailable', 'Notification connection status is unavailable.',
    'required', array(), 'request');
  notification_status_check('HTTP client', $pair, false, true,
    'connecting', 'Connecting...');
  notification_status_check('HTTPS client', array($admin, $https), true, true,
    'connecting', 'Connecting...');
  notification_status_check('fallback policy', $pair, false, true,
    'connecting', 'Connecting...', 'fallback');
  notification_status_check('notification override required', $pair, false,
    true, 'connecting', 'Connecting...', 'off',
    array('notification' => 'required'));
  notification_status_check('disabled sibling', array($admin, $disabled, $http),
    false, true, 'connecting', 'Connecting...');
  notification_status_check('mixed protocols', array($admin, $http, $https),
    true, true, 'connecting', 'Connecting...');

  // Test the durable chat header with a real view and caller context.
  $env = PhabricatorEnv::beginScopedEnv();
  $env->overrideEnvConfig('notification.servers', $pair);
  $env->overrideEnvConfig('gorge.service-policy', 'required');
  $env->overrideEnvConfig('gorge.service-policies', array());
  PhabricatorCaches::destroyRequestCache();
  $_SERVER['HTTPS'] = 'off';
  notification_status_reset_resources();
  $viewer = id(new PhabricatorUser())
    ->setPHID('PHID-USER-notificationstatus');
  $request = id(new AphrontRequest('page.example', '/'))
    ->setUser($viewer);
  $column = id(new ConpherenceDurableColumnView())
    ->setViewer($viewer)
    ->setRequest($request);
  notification_status_assert($column->getRequest() === $request,
    'Durable chat view did not preserve its request.');
  $header = new ReflectionMethod('ConpherenceDurableColumnView', 'buildHeader');
  $html = (string)hsprintf('%s', $header->invoke($column));
  notification_status_assert(strpos($html, 'Connecting...') !== false,
    'Durable chat header did not pass context to its notification status.');
  notification_status_assert(
    count(idx(notification_status_behaviors(), 'aphlict-status')) === 1,
    'Durable chat header did not bind exactly one notification status.');
  unset($env);

  // The remaining creators require application data to render. Verify that
  // their construction chains supply both context values, including the two
  // entry points which create durable chat views.
  foreach (array(
    array('PhabricatorNotificationPanelController',
      'PhabricatorNotificationStatusView'),
    array('ConpherenceViewController', 'PhabricatorNotificationStatusView'),
    array('PhabricatorStandardPageView', 'ConpherenceDurableColumnView'),
    array('ConpherenceColumnViewController', 'ConpherenceDurableColumnView'),
  ) as $creator) {
    list($class, $view_class) = $creator;
    $source = file_get_contents(id(new ReflectionClass($class))->getFileName());
    $pattern = '/new '.preg_quote($view_class, '/').
      '\(\)\)[^;]*->set(?:Viewer|User)\([^;]*->setRequest\(/s';
    notification_status_assert(preg_match($pattern, $source) === 1,
      $class.' omitted viewer or request context for '.$view_class.'.');
  }

  echo "Notification status contracts passed: policy, enabled clients, ".
    "protocol, login, caller context, page startup and single binding.\n";
} finally {
  if ($original_https === null) {
    unset($_SERVER['HTTPS']);
  } else {
    $_SERVER['HTTPS'] = $original_https;
  }
  PhabricatorCaches::destroyRequestCache();
  unset($env);
  unset($initial_env);
}
