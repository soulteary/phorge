#!/usr/bin/env php
<?php

require_once __DIR__.'/lib/installation.php';
if ($argc > 2 || ($argc === 2 && $argv[1] !== '--warn-only')) {
  fwrite(STDERR, "Usage: check_installation.php [--warn-only]\n");
  exit(64);
}
$warn_only = $argc === 2;
try {
  require_once __DIR__.'/../__init_script__.php';
  $user = new PhabricatorUser();
  $counts = queryfx_one(
    $user->establishConnection('r'),
    'SELECT COUNT(*) users, SUM(isAdmin = 1 AND isDisabled = 0 '.
      'AND isSystemAgent = 0 AND isMailingList = 0) admins FROM %T',
    $user->getTableName());
  $providers = 0;
  foreach (PhabricatorAuthProvider::getAllEnabledProviders() as $provider) {
    if ($provider->shouldAllowLogin()) {
      $providers++;
    }
  }
  $report = phorge_installation_status(
    (int)$counts['users'], (int)$counts['admins'], $providers);
} catch (Throwable $ex) {
  // Database exceptions may contain credentials: never print raw exceptions.
  $report = array('state' => 'unavailable', 'scope' => 'local_configuration');
}
echo json_encode($report, JSON_UNESCAPED_SLASHES)."\n";
switch ($report['state']) {
  case 'needs_admin':
    fwrite(STDERR, "[installation] Setup is incomplete: create the first administrator at /auth/register/.\n");
    break;
  case 'needs_admin_recovery':
    fwrite(STDERR, "[installation] No active human administrator: recover administrator access before declaring installation complete.\n");
    break;
  case 'login_unavailable':
    fwrite(STDERR, "[installation] No enabled login provider: run bin/auth recover <username>, then configure a login provider at /auth/.\n");
    break;
  case 'unavailable':
    fwrite(STDERR, "[installation] Could not verify installation configuration; check database and application configuration.\n");
    break;
}
exit($report['state'] === 'ready' || $warn_only ? 0 : 1);
