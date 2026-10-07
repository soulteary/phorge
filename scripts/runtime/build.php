#!/usr/bin/env php
<?php

// This is the only project entry point which prepares optional parser tools.
// Invoke explicitly during image construction or development setup.
require_once dirname(__DIR__, 2).'/support/runtime/bootstrap-cli.php';
$choice = $argv[1] ?? 'all';
if (!in_array($choice, array('all', 'php-parser', 'xhpast'), true) ||
    count($argv) > 2) {
  fwrite(STDERR, "Usage: php scripts/runtime/build.php [all|php-parser|xhpast]\n");
  exit(1);
}
if ($choice === 'all' || $choice === 'php-parser') {
  if (!PhutilPHPParserLibrary::isAvailable()) {
    PhutilPHPParserLibrary::build();
  }
  echo 'Prepared PHP-Parser '.PhutilPHPParserLibrary::getVersion()."\n";
}
if ($choice === 'all' || $choice === 'xhpast') {
  if (!PhutilXHPASTBinary::isAvailable()) {
    PhutilXHPASTBinary::build();
  }
  echo 'Prepared XHPAST '.PhutilXHPASTBinary::getVersion()."\n";
}
