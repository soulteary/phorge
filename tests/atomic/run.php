<?php

// This entry point must run through run.py for configured storage tests.
$root = dirname(__DIR__, 2);
require_once $root.'/support/runtime/bootstrap-cli.php';
phutil_load_library($root.'/src');
require_once $root.'/scripts/init/lib.php';
require_once __DIR__.'/gate.php';

$args = new PhutilArgumentParser($argv);
$args->parseStandardArguments();
$args->parse(array(
  array('name' => 'package', 'param' => 'id', 'repeat' => true),
  array('name' => 'report', 'param' => 'path'),
  array('name' => 'list', 'help' => 'Discover methods without executing tests.'),
));
$report_path = $args->getArg('report');
$report = array('schemaVersion' => 1, 'php' => PHP_VERSION,
  'packages' => array(), 'discovered' => array(), 'results' => array(),
  'errors' => array(), 'passed' => false);

try {
  $ids = $args->getArg('package');
  if (!$ids) {
    $ids = array_map(function($p) { return basename($p, '.json'); },
      glob(__DIR__.'/packages/*.json'));
  }
  $ids = array_values(array_unique($ids));
  sort($ids);
  if (!$ids) { throw new Exception('No atomic package manifests were selected.'); }
  $paths = array();
  $classes = array();
  $expected = array();
  $skips = array();
  foreach ($ids as $id) {
    if (!preg_match('/\AD[0-9]{2}\z/', $id)) {
      throw new Exception('Invalid atomic package identifier.');
    }
    $path = __DIR__.'/packages/'.$id.'.json';
    $manifest = phutil_json_decode(Filesystem::readFile($path));
    if (idx($manifest, 'schemaVersion') !== 1 || idx($manifest, 'id') !== $id ||
        !is_array(idx($manifest, 'tests')) || !$manifest['tests'] ||
        !is_array(idx($manifest, 'allowedSkips'))) {
      throw new Exception('Invalid or empty package manifest: '.$id);
    }
    $report['packages'][$id] = hash('sha256', Filesystem::readFile($path));
    $skips += $manifest['allowedSkips'];
    foreach ($manifest['tests'] as $test) {
      $class = idx($test, 'class');
      $relative = idx($test, 'path');
      $methods = idx($test, 'methods');
      if (!is_string($class) || !is_string($relative) ||
          !preg_match('@\Asrc/(?:[A-Za-z0-9_.-]+/)*__tests__/[A-Za-z0-9_.-]+\.php\z@', $relative) ||
          !is_array($methods) || !$methods) {
        throw new Exception('Invalid test declaration in '.$id);
      }
      $absolute = Filesystem::resolvePath($relative, $root);
      if (!Filesystem::pathExists($absolute) ||
          !Filesystem::isDescendant($absolute, $root.'/src')) {
        throw new Exception('Declared test file is missing: '.$relative);
      }
      $reflection = new ReflectionClass($class);
      if ($reflection->isAbstract() || !$reflection->isSubclassOf('PhutilTestCase') ||
          Filesystem::resolvePath($reflection->getFileName()) !== $absolute) {
        throw new Exception('Library map does not discover '.$class.' at '.$relative);
      }
      if (isset($classes[$class]) && $classes[$class] !== $absolute) {
        throw new Exception('Conflicting declaration for '.$class);
      }
      $classes[$class] = $absolute;
      $paths[$absolute] = $absolute;
      $actual_methods = array();
      foreach ($reflection->getMethods() as $method) {
        if (preg_match('/\Atest/', $method->getName())) {
          if (!$method->isPublic() || $method->getNumberOfRequiredParameters()) {
            throw new Exception('Non-runnable test method in '.$class);
          }
          $name = $method->getName();
          $actual_methods[$name] = true;
          $expected[$class.'::'.$name] = true;
        }
      }
      foreach ($methods as $method) {
        if (!is_string($method) || !isset($actual_methods[$method])) {
          throw new Exception('Required method is missing: '.$class.'::'.(string)$method);
        }
      }
      if (!$actual_methods) { throw new Exception('No methods discovered for '.$class); }
    }
  }
  $report['discovered'] = array_keys($expected);
  sort($report['discovered']);
  if ($args->getArg('list')) {
    echo phutil_json_encode($report['discovered'])."\n";
  } else {
    if (getenv('ATOMIC_TEST_DISPOSABLE') !== '1' ||
        getenv('ATOMIC_TEST_PREPARED') !== '1') {
      throw new Exception('Use tests/atomic/run.py with explicit disposable services.');
    }
    init_phabricator_script(array('config.optional' => true, 'no-extensions' => true));
    $engine = id(new PhutilUnitTestEngine())
      ->setWorkingCopy(ArcanistWorkingCopyIdentity::newFromPath($root))
      ->setPaths(array_values($paths))->setRunAllTests(false)
      ->setEnableCoverage(false);
    $renderer = new ArcanistUnitConsoleRenderer();
    foreach ($engine->run() as $result) {
      $row = array('id' => $result->getNamespace().'::'.$result->getName(),
        'result' => $result->getResult(), 'duration' => $result->getDuration(),
        'detail' => $result->getUserData());
      foreach (array('GORGE_TEST_MYSQL_PASSWORD', 'GORGE_TEST_FILE_TOKEN',
        'GORGE_TEST_IMAGE_TOKEN', 'GORGE_TEST_RENDER_TOKEN') as $key) {
        $secret = getenv($key);
        if (is_string($secret) && strlen($secret)) {
          $row['detail'] = str_replace($secret, '[redacted]', $row['detail']);
        }
      }
      $report['results'][] = $row;
      echo $renderer->renderUnitResult($result);
    }
    $report['errors'] = phorge_atomic_validate_results($expected,
      $report['results'], $skips);
    $report['passed'] = !$report['errors'];
  }
} catch (Throwable $ex) {
  $report['errors'][] = get_class($ex).': '.$ex->getMessage();
}
foreach ($report['errors'] as $index => $error) {
  foreach (array('GORGE_TEST_MYSQL_PASSWORD', 'GORGE_TEST_FILE_TOKEN',
    'GORGE_TEST_IMAGE_TOKEN', 'GORGE_TEST_RENDER_TOKEN') as $key) {
    $secret = getenv($key);
    if (is_string($secret) && strlen($secret)) {
      $error = str_replace($secret, '[redacted]', $error);
    }
  }
  $report['errors'][$index] = $error;
}
if ($report_path) {
  Filesystem::writeFile($report_path,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}
foreach ($report['errors'] as $error) { fwrite(STDERR, $error."\n"); }
echo sprintf("Discovered %d methods; received %d results.\n",
  count($report['discovered']), count($report['results']));
exit($report['errors'] ? 1 : 0);
