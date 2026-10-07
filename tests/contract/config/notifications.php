<?php

// The same file supplies a temporary HTTP fixture in a child process.
if (isset($argv[1]) && $argv[1] === '--serve') {
  $directory = $argv[2];
  $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
  if (!$socket) {
    fwrite(STDERR, 'Unable to listen for the notification fixture: '.$error."\n");
    exit(1);
  }
  file_put_contents($directory.'/address', stream_socket_get_name($socket, false));
  while ($connection = @stream_socket_accept($socket, 10)) {
    $line = fgets($connection);
    $parts = explode(' ', trim($line));
    $target = isset($parts[1]) ? $parts[1] : '/';
    while (($line = fgets($connection)) !== false && trim($line) !== '') {}
    file_put_contents($directory.'/requests', $target."\n", FILE_APPEND);

    parse_str((string)parse_url($target, PHP_URL_QUERY), $query);
    $instance = isset($query['instance']) ? $query['instance'] : 'prod';
    $status = array(
      'instance' => $instance,
      'version' => 8,
      'uptime' => 45000,
      'clients.active' => 2,
      'clients.total' => 3,
      'messages.in' => 4,
      'messages.out' => 5,
      'history.size' => 0,
      'history.age' => null,
      'future.field' => array('allowed' => true),
    );
    $code = 200;
    if ($instance === 'unavailable') {
      $code = 503;
    } else if ($instance === 'missing-version') {
      unset($status['version']);
    } else if ($instance === 'nested-counter') {
      $status['clients.active'] = array(2);
    } else if ($instance === 'bad-history') {
      $status['history.size'] = 2;
      $status['history.age'] = array(1);
    } else if ($instance === 'zero') {
      foreach ($status as $key => $value) {
        if (is_int($value)) {
          $status[$key] = 0;
        }
      }
    } else if ($instance === 'history') {
      $status['history.size'] = 2;
      $status['history.age'] = 4;
    }

    $body = json_encode($status);
    if ($instance === 'invalid-json') {
      $body = '{invalid';
    } else if ($instance === 'scalar') {
      $body = '1';
    } else if ($instance === 'overflow') {
      $body = str_replace('"uptime":45000', '"uptime":1e999', $body);
    }
    fwrite($connection,
      'HTTP/1.1 '.$code." Fixture\r\n".
      "Content-Type: application/json\r\nConnection: close\r\n".
      'Content-Length: '.strlen($body)."\r\n\r\n".$body);
    fclose($connection);
  }
  fclose($socket);
  exit(0);
}

require_once dirname(__DIR__).'/bootstrap.php';
$stack = id(new PhabricatorConfigStackSource())
  ->pushSource(new PhabricatorConfigDefaultSource());
$property = new ReflectionProperty('PhabricatorEnv', 'sourceStack');
$property->setValue(null, $stack);
$initial_env = PhabricatorEnv::beginScopedEnv();
PhabricatorEnv::setLocaleCode('en_US');

function config_notifications_assert($condition, $message) {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function config_notifications_render(AphrontRequest $request) {
  PhabricatorCaches::getRequestCache()->deleteKey(
    PhabricatorNotificationServerRef::KEY_REFS);
  $controller = id(new PhabricatorConfigClusterNotificationsController())
    ->setRequest($request);
  $table_method = new ReflectionMethod($controller,
    'buildClusterNotificationStatus');
  $browser_method = new ReflectionMethod($controller,
    'buildCurrentBrowserNotificationConnection');
  $table = (string)$table_method->invoke($controller)->render();
  $browser = (string)$browser_method->invoke($controller, $request)->render();
  return array('table' => $table, 'browser' => $browser,
    'html' => $table.$browser);
}

function config_notifications_rows($html) {
  preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $html, $matches);
  $rows = array();
  foreach ($matches[1] as $row) {
    preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $row, $cells);
    if (!$cells[1]) {
      continue;
    }
    $rows[] = array_map(function($cell) {
      return trim(html_entity_decode(strip_tags($cell), ENT_QUOTES, 'UTF-8'));
    }, $cells[1]);
  }
  return $rows;
}

$directory = Filesystem::createTemporaryDirectory('gorge-config-notifications-');
$fixture = null;
$env = null;
$test_exit = 0;
$original_https = idx($_SERVER, 'HTTPS');
try {
  $fixture = new ExecFuture('%s %s --serve %s', PHP_BINARY, __FILE__, $directory);
  $fixture->start();
  $deadline = microtime(true) + 5;
  while (!file_exists($directory.'/address')) {
    if ($fixture->isReady() || microtime(true) >= $deadline) {
      list($exit, $stdout, $stderr) = $fixture->resolveKill();
      throw new RuntimeException('Notification fixture did not start: '.$stderr);
    }
    usleep(10000);
  }
  $address = trim(file_get_contents($directory.'/address'));
  $port = (int)substr($address, strrpos($address, ':') + 1);

  $env = PhabricatorEnv::beginScopedEnv();
  $env->overrideEnvConfig('gorge.service-policy', 'required');
  $env->overrideEnvConfig('gorge.service-policies', array());
  $env->overrideEnvConfig('cluster.instance', 'prod');
  $servers = array(
    array('type' => 'admin', 'host' => '127.0.0.1',
      'port' => $port, 'protocol' => 'http'),
    array('type' => 'admin', 'host' => 'localhost',
      'port' => $port, 'protocol' => 'http', 'disabled' => true),
    array('type' => 'client', 'host' => '127.0.0.1',
      'port' => 1, 'protocol' => 'http', 'path' => '/notifications/'),
    array('type' => 'client', 'host' => 'browser.example.invalid',
      'port' => 443, 'protocol' => 'https', 'path' => '/proxy/socket/'),
    array('type' => 'client', 'host' => 'disabled.example.invalid',
      'port' => 443, 'protocol' => 'https', 'disabled' => true),
  );
  $env->overrideEnvConfig('notification.servers', $servers);
  $_SERVER['HTTPS'] = 'off';
  $viewer = id(new PhabricatorUser())->setPHID('PHID-USER-notifications-test');
  $request = id(new AphrontRequest('phorge.example.invalid',
    '/config/cluster/notifications/'))->setUser($viewer);

  $http_calls = array();
  PhutilServiceProfiler::getInstance()->addListener(
    function($event, $id, array $data) use (&$http_calls) {
      if ($event === 'begin' && idx($data, 'type') === 'http') {
        $http_calls[] = $data['uri'];
      }
    });

  $render = config_notifications_render($request);
  $rows = config_notifications_rows($render['table']);
  config_notifications_assert(count($rows) === 5,
    'Configured active and disabled entries must remain visible.');
  config_notifications_assert($rows[0][4] === 'Protocol Version 8' &&
    $rows[0][5] === '45 s' &&
    $rows[0][6] === '2 Active / 3 Total' &&
    $rows[0][7] === '4 In / 5 Out' && $rows[0][8] === 'No Messages',
    'The healthy admin lost its protocol version or real service statistics.');
  config_notifications_assert($rows[1][4] === 'Disabled' &&
    $rows[4][4] === 'Disabled', 'Disabled entries lost their disabled status.');
  config_notifications_assert($rows[2][4] === 'Browser Connection Address' &&
    $rows[3][4] === 'Browser Connection Address',
    'Browser addresses must not claim a server-side connection result.');
  config_notifications_assert($rows[2][9] ===
    'ws://127.0.0.1:1/notifications/~prod/?instance=prod' && $rows[3][9] ===
    'wss://browser.example.invalid:443/proxy/socket/~prod/?instance=prod',
    'The public protocol, host, port, proxy path or instance URI changed.');
  config_notifications_assert($http_calls ===
    array('http://'.$address.'/status/?instance=prod'),
    'Only an enabled admin may trigger an HTTP probe.');
  config_notifications_assert(trim(file_get_contents($directory.'/requests')) ===
    '/status/?instance=prod', 'The fixture received an unexpected probe.');
  config_notifications_assert(preg_match_all(
    '/\bclass="(?:[^"\n]*\s)?aphlict-connection-status(?:\s[^"\n]*)?"/',
    $render['html']) === 1 &&
    strpos($render['browser'], 'Current Browser Notification Connection') !== false,
    'Multiple client entries must share one separate browser status view.');
  config_notifications_assert(strpos($render['table'], '>Protocol<') !== false &&
    strpos($render['table'], '>Proto<') === false &&
    strpos($render['table'], 'grey') !== false,
    'The protocol heading or neutral/disabled display regressed.');

  foreach (array('unavailable', 'invalid-json', 'scalar', 'overflow',
    'missing-version', 'nested-counter', 'bad-history') as $instance) {
    $env->overrideEnvConfig('cluster.instance', $instance);
    $http_calls = array();
    $render = config_notifications_render($request);
    $rows = config_notifications_rows($render['table']);
    config_notifications_assert($rows[0][4] === 'Connection Error' &&
      $rows[0][5] === '' && $rows[0][6] === '' && $rows[0][9] !== '',
      'An invalid or unavailable admin appeared healthy: '.$instance);
    config_notifications_assert($http_calls ===
      array('http://'.$address.'/status/?instance='.$instance),
      'A client or disabled node was probed during an admin error: '.$instance);
  }

  foreach (array('zero', 'history') as $instance) {
    $env->overrideEnvConfig('cluster.instance', $instance);
    $render = config_notifications_render($request);
    $rows = config_notifications_rows($render['table']);
    $version = ($instance === 'zero') ? '0' : '8';
    config_notifications_assert($rows[0][4] === 'Protocol Version '.$version,
      'Valid zero fields or a returned protocol version were rejected.');
    config_notifications_assert($rows[0][8] ===
      (($instance === 'zero') ? 'No Messages' : '2 Held / 4ms'),
      'Empty or populated history statistics changed.');
    if ($instance === 'zero') {
      config_notifications_assert($rows[0][5] === 'now' &&
        $rows[0][6] === '0 Active / 0 Total' &&
        $rows[0][7] === '0 In / 0 Out',
        'A valid zero uptime or counter disappeared.');
    }
  }

  $env->overrideEnvConfig('gorge.service-policy', 'off');
  $http_calls = array();
  $render = config_notifications_render($request);
  config_notifications_assert(strpos($render['table'],
    'Notification service is disabled.') !== false && !$http_calls,
    'An off service must be distinct from unconfigured and must not be probed.');

  $env->overrideEnvConfig('gorge.service-policy', 'required');
  $env->overrideEnvConfig('notification.servers', array());
  $http_calls = array();
  $render = config_notifications_render($request);
  config_notifications_assert(strpos($render['table'],
    'No notification servers are configured.') !== false && !$http_calls,
    'An unconfigured service must remain distinct from a disabled service.');
  echo "Notification configuration admin diagnostics, disabled/client zero ".
    "probes, public URIs and single browser status checks passed.\n";
} catch (Throwable $ex) {
  fwrite(STDERR, $ex->getMessage()."\n");
  $test_exit = 1;
} finally {
  if ($fixture) {
    $fixture->resolveKill();
  }
  PhabricatorCaches::getRequestCache()->deleteKey(
    PhabricatorNotificationServerRef::KEY_REFS);
  if ($original_https === null) {
    unset($_SERVER['HTTPS']);
  } else {
    $_SERVER['HTTPS'] = $original_https;
  }
  unset($env);
  unset($initial_env);
  Filesystem::remove($directory);
}
exit($test_exit);
