<?php

// Contract tests exercise the runtime shipped with this Phorge checkout.
// Keep environment initialization in each contract: several use isolated or
// custom storage configuration and must choose their own initialization mode.
$phorge_root = dirname(__DIR__, 2);
require_once $phorge_root.'/support/runtime/bootstrap.php';
phutil_load_library($phorge_root.'/src');

if (!ini_get('date.timezone')) {
  date_default_timezone_set('UTC');
}
