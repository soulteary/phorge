<?php

// A pure native rule adapter, on a clean source checkout without deployment
// configuration. It does not load projects/users from a database.
try {
  $root = dirname(__DIR__, 2);
  if (file_exists($root.'/conf/local/local.json')) {
    throw new Exception('deployed-configuration-refused');
  }
  $input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
  if (!is_bool($input['allowPublic'] ?? null)) {
    throw new Exception('invalid-input');
  }
  // Load native classes without initializing environment/config/database sources.
  require_once $root.'/support/runtime/bootstrap-cli.php';
  phutil_load_library($root.'/src/');
  $users = array();
  foreach ($input['users'] as $row) {
    $users[$row['phid']] = $row;
  }
  $users['anonymous'] = array('phid' => null, 'isAdmin' => 0);
  $users['nonmember'] = array('phid' => 'AUDIT-NONMEMBER', 'isAdmin' => 0);
  $policies = array('public' => true, 'users' => true, 'admin' => true, 'no-one' => true);
  foreach ($input['projects'] as $project) {
    foreach (array('viewPolicy', 'editPolicy', 'joinPolicy') as $field) {
      $policies[$project[$field] ?: 'users'] = true;
    }
  }
  if (count($users) * count($policies) * 3 > ($input['maxCells'] ?? 200000)) {
    throw new Exception('native-rule-budget');
  }
  $global = new PhorgeGlobalPolicyRule();
  $specific = new PhabricatorUsersPolicyRule();
  $object = new PhabricatorProject();
  $table = array();
  foreach ($users as $identity => $row) {
    $viewer = id(new PhabricatorUser())->setPHID($row['phid'])->setIsAdmin((bool)$row['isAdmin']);
    foreach ($policies as $policy => $_) {
      foreach (array('view', 'edit', 'join') as $capability) {
        if (in_array($policy, array('public', 'users', 'admin', 'no-one'), true)) {
          // The native filter first converts disallowed public policies to
          // users. Use the captured setting; never read a deployment Env here.
          $cap = PhabricatorPolicyCapability::getCapabilityByKey($capability);
          if ($policy === 'public' && $input['allowPublic'] &&
              $cap && $cap->shouldAllowPublicPolicySetting()) {
            $value = true;
          } else {
            $effective = $policy === 'public' ? 'users' : $policy;
            $value = $global->applyRule($viewer, $effective, $object);
          }
        } else if (isset($users[$policy]) && str_starts_with($policy, 'PHID-USER-')) {
          $value = $specific->applyRule($viewer, array($policy), $object);
        } else {
          $value = null;
        }
        $table[$identity][$policy][$capability] = $value;
      }
    }
  }
  echo json_encode($table, JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
  fwrite(STDERR, "Native rule evaluation refused or incomplete.\n");
  exit(2);
}
