<?php

require_once dirname(__DIR__).'/bootstrap.php';
$project = dirname(__DIR__, 3);
$identity_paths = array(
  $project.'/src/__phutil_library_map__.php',
  $project.'/support/runtime/src/__phutil_library_map__.php',
  $project.'/support/runtime/manifest.json',
);
$before = array();
foreach ($identity_paths as $path) {
  $before[$path] = hash_file('sha256', $path);
}
$cwd = getcwd();
try {
  chdir(sys_get_temp_dir());
  execx('%s %s', PHP_BINARY, $project.'/bin/rebuild-library-map');
} finally {
  chdir($cwd);
}
foreach ($before as $path => $hash) {
  if (hash_file('sha256', $path) !== $hash) {
    throw new RuntimeException('Repeat map generation changed '.$path);
  }
}
$temporary = Filesystem::createTemporaryDirectory('gorge-runtime-import-');
try {
  Filesystem::createDirectory($temporary.'/source/src', 0755, true);
  Filesystem::createDirectory($temporary.'/candidate', 0755, true);
  Filesystem::writeFile($temporary.'/candidate/preserved', 'reviewed content');
  list($err) = exec_manual('%s %s %s --destination %s', PHP_BINARY,
    $project.'/scripts/runtime/import.php', $temporary.'/source',
    $temporary.'/candidate');
  if (!$err || Filesystem::readFile($temporary.'/candidate/preserved') !== 'reviewed content') {
    throw new RuntimeException('Importer overwrote a nonempty candidate directory.');
  }
} finally {
  Filesystem::remove($temporary);
}
$sample = '<?php class RuntimeNamedProbe extends Phobject {} '.
  '$probe = new class extends RuntimeAnonymousParent implements RuntimeAnonymousInterface {};';
$php_ast = PhutilPHPParserLibrary::getParser()->parse($sample);
if (!$php_ast) {
  throw new RuntimeException('Prepared PHP-Parser did not parse valid PHP.');
}
$xhp_ast = XHPASTTree::newFromData($sample);
if (!$xhp_ast->getRootNode()) {
  throw new RuntimeException('Prepared XHPAST did not parse valid PHP.');
}
$fixture = new TempFile('gorge-runtime-symbols.php');
Filesystem::writeFile($fixture, $sample);
foreach (array('extract-symbols.php', 'extract-symbols-with-php-parser.php') as $extractor) {
  list($json) = execx('%s %s --ugly %s', PHP_BINARY,
    $project.'/support/runtime/support/lib/'.$extractor, $fixture);
  $symbols = phutil_json_decode($json);
  if (isset($symbols['have']['class']['']) || isset($symbols['xmap']['']) ||
      !isset($symbols['have']['class']['RuntimeNamedProbe']) ||
      array_keys($symbols['xmap']) !== array('RuntimeNamedProbe')) {
    throw new RuntimeException('Anonymous class was registered as a loadable symbol: '.$extractor);
  }
  $needed = array();
  foreach ($symbols['need'] as $names) {
    $needed += $names;
  }
  foreach ($needed as $name => $offset) {
    if (strpos($name, ' ') !== false || $name === '') {
      throw new RuntimeException('Invalid anonymous dependency registered by '.$extractor);
    }
  }
  foreach (array('RuntimeAnonymousParent', 'RuntimeAnonymousInterface') as $name) {
    if (!array_key_exists($name, $needed)) {
      throw new RuntimeException('Anonymous class dependency was omitted by '.$extractor);
    }
  }
}
$cache_fixture = Filesystem::createTemporaryDirectory('gorge-runtime-map-cache-');
try {
  Filesystem::createDirectory($cache_fixture.'/example');
  Filesystem::writeFile($cache_fixture.'/__phutil_library_init__.php', "<?php\n");
  Filesystem::writeFile($cache_fixture.'/example/RuntimeNamedProbe.php', $sample);
  $expected_map = id(new PhutilLibraryMapBuilder($cache_fixture))->buildMap();
  $cache_path = $cache_fixture.'/.phutil_module_cache';
  $cache = phutil_json_decode(Filesystem::readFile($cache_path));
  $cache['__symbol_cache_version__'] = 11;
  foreach ($cache as $key => $value) {
    if ($key !== '__symbol_cache_version__') {
      $cache[$key] = array('have' => array('class' => array('' => 0)),
        'need' => array(), 'xmap' => array('' => array('Phobject')));
    }
  }
  Filesystem::writeFile($cache_path, json_encode($cache));
  $rebuilt_map = id(new PhutilLibraryMapBuilder($cache_fixture))->buildMap();
  if ($rebuilt_map !== $expected_map || isset($rebuilt_map['class']['']) ||
      isset($rebuilt_map['xmap'][''])) {
    throw new RuntimeException('Outdated anonymous-class symbol cache was reused.');
  }
} finally {
  Filesystem::remove($cache_fixture);
}
list($err) = exec_manual('%s %s --unknown-option', PHP_BINARY,
  $project.'/scripts/runtime/prune.php');
if (!$err) {
  throw new RuntimeException('Prune accepted an unknown option.');
}
foreach ($before as $path => $hash) {
  if (hash_file('sha256', $path) !== $hash) {
    throw new RuntimeException('Invalid prune arguments changed '.$path);
  }
}
list($unit_output) = execx('%s %s --no-coverage %s', PHP_BINARY,
  $project.'/bin/unit',
  'src/aphront/headerparser/__tests__/AphrontHTTPHeaderParserTestCase.php');
if (strpos($unit_output, 'Executed ') === false ||
    strpos($unit_output, 'PASS') === false) {
  throw new RuntimeException('PHP unit entry point did not execute real tests.');
}
echo "Runtime maintenance is reproducible from another cwd and protects nonempty import destinations.\n";
