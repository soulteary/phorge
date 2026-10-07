#!/usr/bin/env php
<?php

// Explicit maintenance operation: import a reviewed local upstream snapshot.
// This script never downloads Arcanist or runs its client entry point.
if (!in_array(count($argv), array(2, 4), true) ||
    !is_dir($argv[1].'/src') ||
    (count($argv) === 4 && $argv[2] !== '--destination')) {
  fwrite(STDERR, "Usage: php scripts/runtime/import.php /path/to/snapshot [--destination /empty/staging/directory]\n");
  exit(1);
}

$source = realpath($argv[1]);
$project = dirname(__DIR__, 2);
$target = $project.'/support/runtime';
if (count($argv) === 4) {
  $target = $argv[3];
  if ($target[0] !== '/') {
    $target = getcwd().'/'.$target;
  }
}
if (is_dir($target) && count(scandir($target)) > 2) {
  fwrite(STDERR, "Import refuses to overwrite a nonempty directory. Use --destination with an empty staging directory, review the candidate and reapply all recorded local patches before updating the maintained runtime.\n");
  exit(1);
}
$captured_map = null;
function phutil_register_library_map($map) {
  global $captured_map;
  $captured_map = $map;
}
require $source.'/src/__phutil_library_map__.php';
$map = $captured_map;
$symbols = $map['class'] + $map['function'];

function runtime_import_files($root) {
  $files = array();
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
  foreach ($iterator as $file) {
    if (!$file->isFile() || $file->isLink()) {
      continue;
    }
    $relative = substr($file->getPathname(), strlen($root) + 1);
    if (strpos($relative, '.git/') === 0) {
      continue;
    }
    $files[$relative] = $file->getPathname();
  }
  ksort($files, SORT_STRING);
  return $files;
}

function runtime_import_symbols($file, $symbols) {
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
    if ($token[0] === T_STRING) {
      $name = $token[1];
    } else if (defined('T_NAME_FULLY_QUALIFIED') && $token[0] === T_NAME_FULLY_QUALIFIED) {
      $name = ltrim($token[1], '\\');
    } else if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
      $name = substr($token[1], 1, -1);
    } else {
      continue;
    }
    $name = $canonical[strtolower($name)] ?? null;
    if ($name !== null) {
      $found[$name] = true;
    }
  }
  return array_keys($found);
}

$roots = array();
foreach (array('src', 'scripts', 'tests', 'support', 'resources', 'bin', 'webroot', '.github') as $scope) {
  foreach (runtime_import_files($project.'/'.$scope) as $path => $file) {
    if ((substr($path, -4) !== '.php' && strpos(file_get_contents($file), '<?php') === false) ||
        strpos($path, 'runtime/') === 0 ||
        strpos($path, 'docs/') === 0) {
      continue;
    }
    foreach (runtime_import_symbols($file, $symbols) as $name) {
      $roots[$name] = true;
    }
  }
}
// Functions are eagerly loaded by PhutilBootloader. Preserve that contract.
foreach ($map['function'] as $name => $path) {
  $roots[$name] = true;
}
foreach (array('src/init', 'support/init', 'support/lib',
  'support/php-parser', 'support/xhpast', 'scripts/hgdaemon') as $scope) {
  foreach (runtime_import_files($source.'/'.$scope) as $path => $file) {
    if (substr($path, -4) !== '.php' || strpos($file, '/__tests__/') !== false ||
        substr($file, -17) === 'init-arcanist.php') {
      continue;
    }
    foreach (runtime_import_symbols($file, $symbols) as $name) {
      $roots[$name] = true;
    }
  }
}
foreach (array(
  'PhutilLibraryMapBuilder',
  'PhutilUnitTestEngine',
  'PhutilTestCase',
  'ArcanistWorkingCopyIdentity',
  'PhutilPHPParserLibrary',
  'PhutilXHPASTBinary',
) as $name) {
  $roots[$name] = true;
}

// These are discovered through maps rather than explicit construction.
$dynamic_ancestors = array(
  'PhutilLocale',
  'PhutilTranslation',
  'PhutilHTTPEngineExtension',
  'PhutilBinaryAnalyzer',
);
$dynamic = array_fill_keys($dynamic_ancestors, true);
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
$reasons = array();
$queue = array_keys($roots);
while ($queue) {
  $name = array_pop($queue);
  if (!isset($symbols[$name])) {
    continue;
  }
  $path = 'src/'.$symbols[$name];
  if (isset($selected[$path])) {
    continue;
  }
  // Upstream test products and fixtures are not part of this runtime.
  if (strpos($path, '/__tests__/') !== false && !isset($roots[$name])) {
    continue;
  }
  $selected[$path] = true;
  $reasons[$path] = isset($roots[$name])
    ? (isset($dynamic[$name]) ? 'dynamic-discovery' : 'project-and-tool-root')
    : 'conservative-file-dependency';
  foreach (runtime_import_symbols($source.'/'.$path, $symbols) as $dependency) {
    $queue[] = $dependency;
  }
  foreach ((array)($map['xmap'][$name] ?? array()) as $parent) {
    $queue[] = $parent;
  }
}

$source_files = runtime_import_files($source);
foreach ($source_files as $path => $file) {
  if (strpos($path, '/__tests__/') !== false) {
    continue;
  }
  if (in_array($path, array('LICENSE', 'NOTICE', 'src/__phutil_library_init__.php'), true) ||
      strpos($path, 'src/init/') === 0 ||
      strpos($path, 'support/init/init-script.php') === 0 ||
      strpos($path, 'support/lib/') === 0 ||
      strpos($path, 'support/unit/') === 0 ||
      strpos($path, 'support/php-parser/') === 0 ||
      strpos($path, 'support/xhpast/') === 0 ||
      strpos($path, 'resources/ssl/') === 0 ||
      strpos($path, 'resources/php/') === 0 ||
      strpos($path, 'externals/') === 0 ||
      strpos($path, 'scripts/repository/') === 0 ||
      strpos($path, 'scripts/hgdaemon/') === 0 ||
      strpos($path, 'scripts/__init_script__.php') === 0 ||
      strpos($path, 'src/parser/xhpast/bin/') === 0) {
    $selected[$path] = true;
    $reasons[$path] = 'bootstrap-resource-or-tool';
  }
}

foreach (array('class', 'function') as $kind) {
  $map[$kind] = array_filter(
    $map[$kind],
    function($path) use ($selected) { return isset($selected['src/'.$path]); });
}
$map['xmap'] = array_intersect_key($map['xmap'], $map['class']);
ksort($selected, SORT_STRING);
@mkdir($target, 0755, true);
file_put_contents($target.'/source-symbols.json',
  json_encode($symbols, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
foreach ($selected as $path => $ignored) {
  @mkdir(dirname($target.'/'.$path), 0755, true);
  copy($source.'/'.$path, $target.'/'.$path);
  chmod($target.'/'.$path, fileperms($source.'/'.$path) & 0777);
}
$map_header = "<?php\n\n// Generated by the project runtime importer. Rebuild with bin/rebuild-library-map.\n";
file_put_contents(
  $target.'/src/__phutil_library_map__.php',
  $map_header.'phutil_register_library_map('.var_export($map, true).");\n");

$snapshot = hash_init('sha256');
foreach ($source_files as $path => $file) {
  hash_update($snapshot, $path."\0".hash_file('sha256', $file)."\n");
}
$manifest = array(
  'schemaVersion' => 1,
  'runtimeVersion' => '1',
  'libraryName' => 'arcanist',
  'upstreamRevision' => null,
  'upstreamSnapshotSHA256' => hash_final($snapshot),
  'contentSHA256' => '',
  'generatedPaths' => array(
    'src/.phutil_module_cache',
    'src/parser/xhpast/bin/xhpast',
    'support/php-parser/lib/**',
    'support/php-parser/php-parser-*.zip',
    'support/php-parser/php-parser-*.tar.gz',
    'support/xhpast/xhpast',
    'support/xhpast/node_names.hpp',
    'support/xhpast/libxhpast.a',
    'support/xhpast/*.o',
    'support/xhpast/parser.yacc.output',
  ),
  'patches' => array(),
  'files' => array(),
);
foreach ($selected as $path => $ignored) {
  $manifest['files'][] = array(
    'path' => $path,
    'sha256' => hash_file('sha256', $target.'/'.$path),
    'sourceSHA256' => hash_file('sha256', $source.'/'.$path),
    'reason' => $reasons[$path],
  );
}
$manifest['files'][] = array(
  'path' => 'src/__phutil_library_map__.php',
  'sha256' => hash_file('sha256', $target.'/src/__phutil_library_map__.php'),
  'sourceSHA256' => hash_file('sha256', $source.'/src/__phutil_library_map__.php'),
  'reason' => 'filtered-map',
);
$manifest['files'][] = array(
  'path' => 'source-symbols.json',
  'sha256' => hash_file('sha256', $target.'/source-symbols.json'),
  'sourceSHA256' => null,
  'reason' => 'upstream-symbol-catalog',
);
usort($manifest['files'], function($left, $right) {
  return strcmp($left['path'], $right['path']);
});
$content = hash_init('sha256');
foreach ($manifest['files'] as $record) {
  hash_update($content, $record['path']."\0".$record['sha256']."\n");
}
$manifest['contentSHA256'] = hash_final($content);
file_put_contents($target.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo sprintf("Imported %d source/resource files, %d classes and %d functions.\n", count($selected), count($map['class']), count($map['function']));
