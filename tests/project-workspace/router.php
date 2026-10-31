<?php

// Explicitly launched loopback test router. Nothing here is registered with the
// production application or reachable through its normal document root.
$private = getenv('ATOMIC_WORKSPACE_PRIVATE');
$root = dirname(__DIR__, 2);
$owner_file = $private.'/owner.json';
if (PHP_SAPI !== 'cli-server' || getenv('ATOMIC_TEST_DISPOSABLE') !== '1' ||
    !is_string($private) || !is_file($owner_file)) {
  http_response_code(404);
  return;
}
$owner = json_decode(file_get_contents($owner_file), true);
$config_path = $root.'/conf/local/local.json';
if (!is_array($owner) || $owner['root'] !== $root ||
    !hash_equals($owner['configurationHash'], hash_file('sha256', $config_path))) {
  http_response_code(503);
  echo 'Disposable configuration ownership changed.';
  return;
}
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($uri, '/__atomic/') === 0) {
  header('Cache-Control: private, no-store');
  $key = isset($_GET['key']) ? $_GET['key'] : '';
  if (!is_string($key) || !hash_equals($owner['key'], $key)) {
    http_response_code(404);
    return;
  }
  if ($uri === '/__atomic/ready') {
    header('Content-Type: application/json');
    echo '{"ready":true}';
    return;
  }
  if (preg_match('@\A/__atomic/session/(owner|reader|observer|chinese)/\z@', $uri, $m)) {
    $fixture = json_decode(file_get_contents($private.'/fixture.json'), true);
    $user = $fixture['users'][$m[1]];
    setcookie('phsid', $user['session'], array('path' => '/', 'httponly' => true, 'samesite' => 'Lax'));
    setcookie('phusr', $user['username'], array('path' => '/', 'httponly' => true, 'samesite' => 'Lax'));
    header('Location: '.$fixture['projects']['main']['board'], true, 303);
    return;
  }
  if ($uri === '/__atomic/state' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $config = json_decode(file_get_contents($config_path), true);
    if ($config['storage.default-namespace'] !== $owner['namespace']) {
      http_response_code(503);
      return;
    }
    $spec = array(
      'workspace' => array('project.workspace-mode', array('native', 'unified')),
      'history' => array('project.history-state', array('off', 'active', 'read-only')),
      'drafts' => array('project.history-task-drafts', array('0', '1')),
    );
    foreach ($spec as $key => $rule) {
      if (!isset($_POST[$key])) { continue; }
      if (!is_string($_POST[$key]) || !in_array($_POST[$key], $rule[1], true)) {
        http_response_code(400);
        return;
      }
      $config[$rule[0]] = $key === 'drafts' ? $_POST[$key] === '1' : $_POST[$key];
    }
    $bytes = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    file_put_contents($config_path, $bytes, LOCK_EX);
    $owner['configurationHash'] = hash('sha256', $bytes);
    file_put_contents($owner_file, json_encode($owner), LOCK_EX);
    header('Content-Type: application/json');
    echo '{"configured":true}';
    return;
  }
  http_response_code(404);
  return;
}
// Let the native front controller serve real Celerity, authentication,
// permissions, CSRF, forms, transactions and workboard endpoints.
require $root.'/webroot/index.php';
