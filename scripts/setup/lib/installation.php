<?php

// Pure classification: no credentials, account identities, or writes.
function phorge_installation_status($users, $admins, $login_providers) {
  if ($users === 0) {
    $state = 'needs_admin';
  } else if ($admins === 0) {
    $state = 'needs_admin_recovery';
  } else if ($login_providers === 0) {
    $state = 'login_unavailable';
  } else {
    $state = 'ready';
  }
  return array(
    'state' => $state,
    'users' => $users,
    'activeAdministrators' => $admins,
    'loginProviders' => $login_providers,
    'scope' => 'local_configuration',
  );
}
