<?php

// Each entry point must load the shipped runtime, even when legacy paths point
// to a trap and the current directory contains an unrelated Arcanist checkout.
$root = realpath(dirname(__DIR__, 3));
$directory = sys_get_temp_dir().'/gorge-bundled-startup-'.bin2hex(random_bytes(6));
mkdir($directory.'/arcanist/src/init', 0700, true);
mkdir($directory.'/arcanist/support/init', 0700, true);
$trap = '<?php throw new Exception("External Arcanist trap was loaded.");';
$library_trap = $directory.'/arcanist/src/init/init-library.php';
$script_trap = $directory.'/arcanist/support/init/init-script.php';
file_put_contents($library_trap, $trap);
file_put_contents($script_trap, $trap);
$script = $directory.'/probe.php';

try {
  foreach (array('web', 'cli', 'contract') as $mode) {
    $source = '<?php'."\n";
    $source .= '$root = '.var_export($root, true).';'."\n";
    switch ($mode) {
      case 'web':
        $source .= 'require $root."/support/startup/PhabricatorStartup.php";'."\n";
        $source .= 'PhabricatorStartup::loadCoreLibraries();'."\n";
        break;
      case 'cli':
        $source .= 'require $root."/scripts/init/lib.php";'."\n";
        $source .= 'init_phabricator_script(array("config.optional" => true, '.
          '"no-extensions" => true));'."\n";
        $source .= <<<'PROBE'
if (ini_get('memory_limit') !== '-1' ||
    ini_get('zend.exception_ignore_args') !== '0' ||
    date_default_timezone_get() !== 'UTC') {
  throw new Exception('CLI PHP initialization changed.');
}
if (!isset($_ENV['GORGE_BUNDLED_ENV_PROBE'])) {
  throw new Exception('CLI environment repair did not run.');
}
PROBE;
        $source .= "\n";
        break;
      case 'contract':
        $source .= 'require $root."/tests/contract/bootstrap.php";'."\n";
        $source .= <<<'PROBE'
$controller = new PhabricatorConfigConsoleController();
$method = new ReflectionMethod($controller, 'loadVersions');
$versions = $method->invoke($controller, new PhabricatorUser());
$manifest = json_decode(file_get_contents($root.'/support/runtime/manifest.json'), true);
if (isset($versions['arcanist']) ||
    !isset($versions['Gorge PHP Runtime']) ||
    $versions['Gorge PHP Runtime']['hash'] !== $manifest['contentSHA256'] ||
    $versions['Gorge PHP Runtime']['branchpoint'] !== null) {
  throw new Exception('Version console misidentified the bundled runtime.');
}
PROBE;
        $source .= "\n";
        break;
    }
    $source .= <<<'PROBE'
$loaded = realpath(phutil_get_library_root('arcanist'));
if ($loaded !== realpath($root.'/support/runtime/src')) {
  throw new Exception('An external runtime was registered: '.$loaded);
}
if (!class_exists('PhabricatorGorgeServiceClient') ||
    !function_exists('phutil_json_decode')) {
  throw new Exception('Bundled library symbols are missing.');
}
echo "Bundled entry point passed.\n";
PROBE;
    file_put_contents($script, $source);
    $env = getenv();
    $env['PHUTIL_LIBRARY_ROOT'] = $directory.'/';
    $env['GORGE_TEST_ARCANIST_DIR'] = $directory.'/arcanist';
    $env['GORGE_BUNDLED_ENV_PROBE'] = 'bundled-entry-test';
    unset($env['PHABRICATOR_ENV']);
    $process = proc_open(
      array(PHP_BINARY, '-d', 'include_path='.$directory,
        '-d', 'date.timezone=UTC', '-d', 'variables_order=GPCS', $script),
      array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
      $pipes,
      $directory,
      $env);
    if (!is_resource($process)) {
      throw new Exception('Unable to start '.$mode.' entry point probe.');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 ||
        strpos($output, 'Bundled entry point passed.') === false) {
      throw new Exception($mode.' entry point failed: '.$output.$error);
    }
    echo ucfirst($mode)." bundled startup passed.\n";
  }
} finally {
  unlink($script);
  unlink($library_trap);
  unlink($script_trap);
  rmdir($directory.'/arcanist/src/init');
  rmdir($directory.'/arcanist/src');
  rmdir($directory.'/arcanist/support/init');
  rmdir($directory.'/arcanist/support');
  rmdir($directory.'/arcanist');
  rmdir($directory);
}
