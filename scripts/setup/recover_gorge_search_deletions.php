#!/usr/bin/env php
<?php
require_once dirname(__FILE__).'/../..'.'/scripts/__init_script__.php';
try {
  if (!PhabricatorEnv::getEnvConfig('gorge.search.projection-shadow')) {
    throw new Exception(pht('Search projection capture must be enabled.'));
  }
  echo PhabricatorSearchDeletionRecovery::recoverBatch()." deletions completed.\n";
} catch (Throwable $ex) {
  fwrite(STDERR, $ex->getMessage()."\n");
  exit(1);
}
