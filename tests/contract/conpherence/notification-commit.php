<?php

// Only use a disposable MySQL server. The HTTP consumer uses an independent
// connection, just like a browser fetching a room after a notification.
function notification_commit_database($database = '') {
  return new mysqli(
    '127.0.0.1', 'root', getenv('GORGE_TEST_MYSQL_PASSWORD'), $database,
    (int)getenv('GORGE_TEST_MYSQL_PORT'));
}

if (isset($argv[1]) && $argv[1] === '--serve') {
  $directory = $argv[2];
  $database = $argv[3];
  $reader = notification_commit_database($database);
  $socket = stream_socket_server('tcp://127.0.0.1:0');
  file_put_contents($directory.'/address', stream_socket_get_name($socket, false));
  while ($connection = @stream_socket_accept($socket, 10)) {
    $length = 0;
    while (($line = fgets($connection)) !== false && trim($line) !== '') {
      if (preg_match('/^Content-Length: (\d+)/i', $line, $matches)) {
        $length = (int)$matches[1];
      }
    }
    $body = '';
    while (strlen($body) < $length) {
      $part = fread($connection, $length - strlen($body));
      if ($part === false || $part === '') {
        break;
      }
      $body .= $part;
    }
    $visible = (int)$reader->query('SELECT COUNT(*) AS n FROM committed_message')
      ->fetch_assoc()['n'];
    file_put_contents($directory.'/messages', json_encode(array(
      'visible' => $visible,
      'message' => json_decode($body, true),
    ))."\n", FILE_APPEND);
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n".
      "Connection: close\r\n\r\n{}");
    fclose($connection);
  }
  exit(0);
}

if (!getenv('GORGE_TEST_MYSQL_PORT') ||
    getenv('GORGE_TEST_MYSQL_PASSWORD') === false) {
  throw new RuntimeException('Configure a disposable MySQL server.');
}

require_once dirname(__DIR__).'/bootstrap.php';
$stack = id(new PhabricatorConfigStackSource())
  ->pushSource(new PhabricatorConfigDefaultSource());
id(new ReflectionProperty('PhabricatorEnv', 'sourceStack'))->setValue(null, $stack);
$env = PhabricatorEnv::beginScopedEnv();
if (in_array('--file-origin', $argv, true)) {
  $env->overrideEnvConfig('security.alternate-file-domain',
    'https://files.example.invalid/');
}
$database = 'notification_commit_'.bin2hex(random_bytes(8));
$writer = notification_commit_database();
$writer->query('CREATE DATABASE `'.$database.'`');
$writer->select_db($database);
$directory = Filesystem::createTemporaryDirectory('notification-commit-');
$process = null;

function notification_commit_messages($directory) {
  $path = $directory.'/messages';
  if (!file_exists($path)) {
    return array();
  }
  return array_map(function($line) {
    return json_decode($line, true);
  }, file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

function notification_commit_assert($condition, $message) {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

try {
  $writer->query('CREATE TABLE committed_message (id INT PRIMARY KEY) ENGINE=InnoDB');
  $process = proc_open(
    array(PHP_BINARY, __FILE__, '--serve', $directory, $database),
    array(0 => array('file', '/dev/null', 'r'),
      1 => array('file', $directory.'/stdout', 'w'),
      2 => array('file', $directory.'/stderr', 'w')),
    $pipes);
  notification_commit_assert(is_resource($process), 'HTTP fixture did not start.');
  $deadline = microtime(true) + 5;
  while (!file_exists($directory.'/address') && microtime(true) < $deadline) {
    usleep(10000);
  }
  notification_commit_assert(file_exists($directory.'/address'),
    'HTTP fixture did not become ready.');
  list($host, $port) = explode(':', trim(file_get_contents($directory.'/address')));
  $env->overrideEnvConfig('notification.servers', array(array(
    'type' => 'admin', 'host' => $host, 'port' => (int)$port,
    'protocol' => 'http')));
  $env->overrideEnvConfig('cluster.instance', null);

  $editor = id(new ConpherenceEditor())->setActingAsPHID('PHID-USER-contract');
  $thread = id(new ConpherenceThread())
    ->setPHID('PHID-CONP-contract')->attachParticipants(array());
  $transactions = array(
    id(new ConpherenceTransaction())->setID(41),
    id(new ConpherenceTransaction())->setID(42));
  $before_commit = new ReflectionMethod($editor, 'applyFinalEffects');
  $after_commit = new ReflectionMethod($editor, 'didApplyTransactions');

  // Hold the database transaction open for the entire synchronous HTTP
  // publication. No timing sleeps can conceal a premature notification.
  $writer->begin_transaction();
  $writer->query('INSERT INTO committed_message VALUES (42)');
  $before_commit->invoke($editor, $thread, $transactions);
  notification_commit_assert(!notification_commit_messages($directory),
    'Room notification escaped before commit; its consumer cannot read the message.');
  $writer->commit();
  $result = $after_commit->invoke($editor, $thread, $transactions);
  $messages = notification_commit_messages($directory);
  notification_commit_assert(count($messages) === 1 &&
    $messages[0]['visible'] === 1, 'Consumer did not observe committed data.');
  $message = $messages[0]['message'];
  notification_commit_assert($message['type'] === 'message' &&
    $message['threadPHID'] === $thread->getPHID() &&
    $message['messageID'] === 42 &&
    $message['subscribers'] === array($thread->getPHID()) &&
    strlen($message['uniqueID']) === 32 && $result === $transactions,
    'Post-commit publication changed the notification wire contract.');

  // A failed edit never reaches the post-commit hook.
  $writer->begin_transaction();
  $writer->query('INSERT INTO committed_message VALUES (43)');
  $before_commit->invoke($editor, $thread, $transactions);
  $writer->rollback();
  $before_commit->invoke($editor, $thread, array());
  $after_commit->invoke($editor, $thread, array());
  notification_commit_assert(count(notification_commit_messages($directory)) === 1,
    'Rolled-back or empty edits published a notification.');

  $env->overrideEnvConfig('notification.servers', array());
  PhabricatorCaches::destroyRequestCache();
  $after_commit->invoke($editor, $thread, $transactions);
  notification_commit_assert(count(notification_commit_messages($directory)) === 1,
    'Disabled notification service received a publication.');
  echo "Conpherence committed notification contract passed.\n";
} finally {
  $writer->rollback();
  if (is_resource($process)) {
    proc_terminate($process);
    proc_close($process);
  }
  $writer->query('DROP DATABASE `'.$database.'`');
  Filesystem::remove($directory);
}
