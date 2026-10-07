#!/usr/bin/env php
<?php

// Recompute the conservative source closure after a reviewed local API cut.
// Only mapped runtime source files can be removed by this explicit operation.
if (count($argv) === 2 && $argv[1] === '--help') {
  echo "Usage: php scripts/runtime/prune.php [--dry-run]\n";
  exit(0);
}
$dry_run = count($argv) === 2 && $argv[1] === '--dry-run';
if (count($argv) > 2 || (count($argv) === 2 && !$dry_run)) {
  fwrite(STDERR, "Usage: php scripts/runtime/prune.php [--dry-run]\n");
  exit(1);
}
$project = dirname(__DIR__, 2);
$runtime = $project.'/support/runtime';
require_once $runtime.'/bootstrap.php';
$map = PhutilBootloader::getInstance()->getLibraryMapWithoutExtensions('arcanist');
$symbols = $map['class'] + $map['function'];

function runtime_prune_symbols($file, $symbols) {
  static $canonical = null;
  if ($canonical === null) {
    $canonical = array();
    foreach ($symbols as $name => $path) {
      $canonical[strtolower($name)] = $name;
    }
  }
  $found = array();
  foreach (token_get_all(file_get_contents($file)) as $token) {
    if (!is_array($token)) {
      continue;
    }
    $name = $token[0] === T_STRING ? $token[1] :
      ($token[0] === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : null);
    if (defined('T_NAME_FULLY_QUALIFIED') && $token[0] === T_NAME_FULLY_QUALIFIED) {
      $name = ltrim($token[1], '\\');
    }
    $name = $name !== null ? ($canonical[strtolower($name)] ?? null) : null;
    if ($name !== null) {
      $found[$name] = true;
    }
  }
  return $found;
}

$roots = array_fill_keys(array_keys($map['function']), true);
foreach (array('src', 'scripts', 'tests', 'support', 'resources', 'bin', 'webroot', '.github') as $scope) {
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $project.'/'.$scope, FilesystemIterator::SKIP_DOTS));
  foreach ($files as $file) {
    if (!$file->isFile()) {
      continue;
    }
    $path = substr($file->getPathname(), strlen($project) + 1);
    if (strpos($path, 'support/runtime/') === 0 ||
        strpos($path, 'scripts/runtime/') === 0 ||
        strpos($path, 'src/docs/') === 0) {
      continue;
    }
    if (substr($path, -4) === '.php' ||
        strpos(file_get_contents($file->getPathname()), '<?php') !== false) {
      $roots += runtime_prune_symbols($file->getPathname(), $symbols);
    }
  }
}
foreach (array('src/init', 'support/init', 'support/lib', 'support/php-parser', 'support/xhpast') as $scope) {
  if (!is_dir($runtime.'/'.$scope)) {
    continue;
  }
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $runtime.'/'.$scope, FilesystemIterator::SKIP_DOTS));
  foreach ($files as $file) {
    if ($file->isFile() && substr($file->getFilename(), -4) === '.php' &&
        strpos($file->getPathname(), '/__tests__/') === false) {
      $roots += runtime_prune_symbols($file->getPathname(), $symbols);
    }
  }
}
foreach (array('PhutilLibraryMapBuilder', 'PhutilUnitTestEngine', 'PhutilTestCase',
  'ArcanistWorkingCopyIdentity', 'PhutilPHPParserLibrary', 'PhutilXHPASTBinary') as $name) {
  $roots[$name] = true;
}
$dynamic = array_fill_keys(array('PhutilLocale', 'PhutilTranslation',
  'PhutilHTTPEngineExtension', 'PhutilBinaryAnalyzer'), true);
do {
  $before = count($dynamic);
  foreach ($map['xmap'] as $child => $parents) {
    foreach ((array)$parents as $parent) {
      if (isset($dynamic[$parent])) {
        $dynamic[$child] = true;
      }
    }
  }
} while ($before !== count($dynamic));
$roots += $dynamic;
$selected = array();
$queue = array_keys($roots);
while ($queue) {
  $name = array_pop($queue);
  if (!isset($symbols[$name])) {
    continue;
  }
  $path = $symbols[$name];
  if (isset($selected[$path])) {
    continue;
  }
  $selected[$path] = true;
  foreach (runtime_prune_symbols($runtime.'/src/'.$path, $symbols) as $dependency => $ignored) {
    $queue[] = $dependency;
  }
  foreach ((array)($map['xmap'][$name] ?? array()) as $parent) {
    $queue[] = $parent;
  }
}
$removed = array_diff(array_unique(array_values($symbols)), array_keys($selected));
foreach ($removed as $path) {
  if (!$dry_run) {
    unlink($runtime.'/src/'.$path);
  }
  echo ($dry_run ? 'Would remove src/' : 'Removed src/').$path."\n";
}
foreach (array('class', 'function') as $kind) {
  $map[$kind] = array_filter($map[$kind], function($path) use ($selected) {
    return isset($selected[$path]);
  });
}
$map['xmap'] = array_intersect_key($map['xmap'], $map['class']);
if (!$dry_run) {
  file_put_contents($runtime.'/src/__phutil_library_map__.php',
    "<?php\n\n// Rebuild with bin/rebuild-library-map.\n".
    'phutil_register_library_map('.var_export($map, true).");\n");
}
echo sprintf(($dry_run ? 'Would prune' : 'Pruned')." %d files; retained %d classes and %d functions.\n",
  count($removed), count($map['class']), count($map['function']));
