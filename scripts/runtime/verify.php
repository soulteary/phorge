#!/usr/bin/env php
<?php

$project = dirname(__DIR__, 2);
$runtime = $project.'/support/runtime';
$refresh = in_array('--refresh-manifest', $argv, true);
if (count($argv) > 2 || (count($argv) === 2 && !$refresh)) {
  fwrite(STDERR, "Usage: php scripts/runtime/verify.php [--refresh-manifest]\n");
  exit(1);
}
$manifest_file = $runtime.'/manifest.json';
$manifest = json_decode(file_get_contents($manifest_file), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['schemaVersion'] ?? null) !== 1 ||
    ($manifest['libraryName'] ?? null) !== 'arcanist') {
  throw new RuntimeException('Unsupported runtime manifest.');
}

function runtime_verify_generated($path, $patterns) {
  foreach ($patterns as $pattern) {
    if (fnmatch($pattern, $path)) {
      return true;
    }
  }
  return false;
}

$previous = array();
foreach ($manifest['files'] as $record) {
  $path = $record['path'];
  if (isset($previous[$path]) || $path === 'manifest.json' ||
      !is_string($path) || $path === '' ||
      strpos($path, '..') !== false || $path[0] === '/' ||
      strpos($path, '\\') !== false ||
      !preg_match('/^[a-f0-9]{64}$/', $record['sha256'] ?? '')) {
    throw new RuntimeException('Invalid or duplicate manifest path: '.$path);
  }
  $previous[$path] = $record;
}
$current = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
  $runtime, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
  if ($file->isLink()) {
    throw new RuntimeException('Runtime paths may not be symlinks: '.$file->getPathname());
  }
  if (!$file->isFile()) {
    continue;
  }
  $path = substr($file->getPathname(), strlen($runtime) + 1);
  if ($path === 'manifest.json' ||
      (!isset($previous[$path]) && runtime_verify_generated(
        $path, $manifest['generatedPaths'] ?? array()))) {
    continue;
  }
  $record = $previous[$path] ?? array(
    'path' => $path,
    'sourceSHA256' => null,
    'reason' => 'project-runtime-maintenance',
  );
  $record['sha256'] = hash_file('sha256', $file->getPathname());
  if (!$refresh && (!isset($previous[$path]) ||
      $previous[$path]['sha256'] !== $record['sha256'])) {
    throw new RuntimeException('Runtime file is unrecorded or modified: '.$path);
  }
  $current[$path] = $record;
}
if (!$refresh && array_diff_key($previous, $current)) {
  throw new RuntimeException('Recorded runtime files are missing.');
}
ksort($current, SORT_STRING);
$digest = hash_init('sha256');
foreach ($current as $path => $record) {
  hash_update($digest, $path."\0".$record['sha256']."\n");
}
$content = hash_final($digest);
if (!$refresh && $manifest['contentSHA256'] !== $content) {
  throw new RuntimeException('Runtime content digest does not match manifest.');
}

require_once $runtime.'/bootstrap.php';
$map = PhutilBootloader::getInstance()->getLibraryMap('arcanist');
if (isset($map['xmap'][''])) {
  throw new RuntimeException('Invalid anonymous symbol in runtime inheritance map.');
}
foreach (array('class', 'function') as $kind) {
  foreach ($map[$kind] as $name => $path) {
    if (!is_string($name) || $name === '') {
      throw new RuntimeException('Invalid empty symbol in runtime library map.');
    }
    if (!isset($current['src/'.$path])) {
      throw new RuntimeException('Mapped symbol has no maintained source: '.$name);
    }
    $loaded = $kind === 'function'
      ? function_exists($name)
      : (class_exists($name) || interface_exists($name) || trait_exists($name));
    if (!$loaded) {
      throw new RuntimeException('Mapped symbol failed to load: '.$name);
    }
  }
}
if (is_file($project.'/src/__phutil_library_init__.php')) {
  phutil_load_library($project.'/src');
  $project_library = phutil_get_library_name_for_root($project.'/src');
  $project_map = PhutilBootloader::getInstance()->getLibraryMap($project_library);
  foreach (array('class', 'function', 'xmap') as $kind) {
    if (isset($project_map[$kind][''])) {
      throw new RuntimeException('Invalid anonymous symbol in project '.$kind.' map.');
    }
  }
}
$snapshot_symbols_path = $runtime.'/source-symbols.json';
if (file_exists($snapshot_symbols_path)) {
  $known = json_decode(file_get_contents($snapshot_symbols_path), true, 512, JSON_THROW_ON_ERROR);
  $canonical = array();
  foreach ($known as $name => $path) {
    $canonical[strtolower($name)] = $name;
  }
  foreach (array('src', 'scripts', 'tests', 'support', 'resources', 'bin', 'webroot', '.github') as $scope) {
    $scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
      $project.'/'.$scope, FilesystemIterator::SKIP_DOTS));
    foreach ($scan as $file) {
      if (!$file->isFile()) {
        continue;
      }
      if (substr($file->getFilename(), -4) !== '.php' &&
          strpos(file_get_contents($file->getPathname()), '<?php') === false) {
        continue;
      }
      $path = substr($file->getPathname(), strlen($project) + 1);
      if (strpos($path, 'support/runtime/') === 0 ||
          strpos($path, 'scripts/runtime/') === 0 ||
          strpos($path, 'src/docs/') === 0) {
        continue;
      }
      foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
        if (!is_array($token)) {
          continue;
        }
        $name = $token[0] === T_STRING ? $token[1] :
          ($token[0] === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : null);
        if (defined('T_NAME_FULLY_QUALIFIED') && $token[0] === T_NAME_FULLY_QUALIFIED) {
          $name = ltrim($token[1], '\\');
        }
        $name = $name !== null ? ($canonical[strtolower($name)] ?? null) : null;
        if ($name !== null &&
            !isset($map['class'][$name]) && !isset($map['function'][$name])) {
          throw new RuntimeException('Project references excluded upstream symbol '.$name.' in '.$path);
        }
      }
    }
  }
}
if ($refresh) {
  $manifest['files'] = array_values($current);
  $manifest['contentSHA256'] = $content;
  file_put_contents($manifest_file,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}
echo sprintf("Verified runtime: %d files, %d classes, %d functions, SHA-256 %s.\n",
  count($current), count($map['class']), count($map['function']), $content);
