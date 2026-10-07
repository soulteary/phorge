<?php

require_once __DIR__.'/../../../scripts/setup/lib/installation.php';
foreach (array(
  array(0, 0, 0, 'needs_admin'),
  array(0, 0, 1, 'needs_admin'),
  array(1, 0, 1, 'needs_admin_recovery'),
  array(1, 1, 0, 'login_unavailable'),
  array(1, 1, 1, 'ready'),
) as $case) {
  $report = phorge_installation_status($case[0], $case[1], $case[2]);
  if ($report['state'] !== $case[3] ||
      $report['scope'] !== 'local_configuration') {
    throw new Exception('Incorrect installation readiness classification.');
  }
}
echo "Installation readiness contracts passed.\n";

// Exercise the real CLI against isolated bootstrap fixtures: no business DB.
$root = sys_get_temp_dir().'/installation-contract-'.bin2hex(random_bytes(6));
mkdir($root.'/setup/lib', 0700, true);
$source = dirname(__DIR__, 3).'/scripts/setup/';
copy($source.'check_installation.php', $root.'/setup/check_installation.php');
copy($source.'lib/installation.php', $root.'/setup/lib/installation.php');
file_put_contents($root.'/__init_script__.php', <<<'STUB'
<?php
if (getenv('INSTALL_CASE') === 'error') {
  throw new Exception('NEVER_PRINT_DATABASE_SECRET');
}
class PhabricatorUser {
  public function establishConnection($mode) { return null; }
  public function getTableName() { return 'fixture'; }
}
function queryfx_one($connection, $query, $table) {
  $case = getenv('INSTALL_CASE');
  return array('users' => $case === 'new' ? 0 : 1,
    'admins' => $case === 'new' || $case === 'no-admin' ? 0 : 1);
}
class PhabricatorAuthProvider {
  public static function getAllEnabledProviders() {
    return array(new self());
  }
  public function shouldAllowLogin() {
    return getenv('INSTALL_CASE') === 'ready';
  }
}
STUB
);
try {
  foreach (array(
    array('new', array(), 1, 'needs_admin'),
    array('no-admin', array(), 1, 'needs_admin_recovery'),
    array('no-login', array(), 1, 'login_unavailable'),
    array('ready', array(), 0, 'ready'),
    array('error', array(), 1, 'unavailable'),
    array('error', array('--warn-only'), 0, 'unavailable'),
    array('no-login', array('--warn-only'), 0, 'login_unavailable'),
    array('ready', array('--invalid'), 64, null),
  ) as $case) {
    $command = array_merge(array(PHP_BINARY, $root.'/setup/check_installation.php'), $case[1]);
    $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
      $pipes, null, array('INSTALL_CASE' => $case[0]));
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $report = json_decode($output, true);
    if ($exit !== $case[2] || ($case[3] !== null &&
        ($report['state'] ?? null) !== $case[3])) {
      throw new Exception('Incorrect installation CLI result: '.$case[0]);
    }
    if (strpos($output.$error, 'NEVER_PRINT') !== false) {
      throw new Exception('Installation diagnostics leaked an exception.');
    }
  }
} finally {
  unlink($root.'/setup/check_installation.php');
  unlink($root.'/setup/lib/installation.php');
  unlink($root.'/__init_script__.php');
  rmdir($root.'/setup/lib');
  rmdir($root.'/setup');
  rmdir($root);
}
echo "Installation CLI exit-code and redaction contracts passed.\n";
