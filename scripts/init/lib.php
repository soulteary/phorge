<?php

function init_phabricator_script(array $options) {
  error_reporting(E_ALL);
  ini_set('display_errors', 1);

  $runtime = dirname(__FILE__).'/../../support/runtime/bootstrap-cli.php';
  if (!is_file($runtime)) {
    fwrite(
      STDERR,
      'FATAL ERROR: Unable to load the bundled PHP runtime. '.
      'Restore "support/runtime/" from the same Phorge release.'."\n");
    exit(1);
  }

  require_once $runtime;

  phutil_load_library(dirname(__FILE__).'/../../src/');

  $config_optional = $options['config.optional'];
  $no_extensions = $options['no-extensions'] ?? false;
  PhabricatorEnv::initializeScriptEnvironment(
    $config_optional,
    $no_extensions);
}
