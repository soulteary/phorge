#!/usr/bin/env php
<?php
// Bootstrap reads effective configuration, including database overrides.
$root = dirname(__FILE__).'/../..';
require_once $root.'/scripts/__init_script__.php';
try {
  echo phutil_json_encode(PhabricatorGorgeCleanup::exportPolicies())."\n";
} catch (Throwable $ex) {
  fwrite(STDERR, $ex->getMessage()."\n");
  exit(1);
}
