#!/usr/bin/env php
<?php

// Update only the generated map identity. Every other maintained file must
// still match the reviewed manifest; this cannot approve source changes.
$root = dirname(__DIR__, 2).'/support/runtime';
$manifest_path = $root.'/manifest.json';
$manifest = json_decode(file_get_contents($manifest_path), true, 512, JSON_THROW_ON_ERROR);
$records = array();
$previous_digest = hash_init('sha256');
foreach ($manifest['files'] as $record) {
  $records[$record['path']] = $record;
}
ksort($records, SORT_STRING);
foreach ($records as $path => $record) {
  hash_update($previous_digest, $path."\0".$record['sha256']."\n");
}
if (hash_final($previous_digest) !== $manifest['contentSHA256']) {
  throw new RuntimeException('Recorded runtime manifest identity is invalid.');
}
$map_path = 'src/__phutil_library_map__.php';
if (!isset($records[$map_path])) {
  throw new RuntimeException('Runtime map has no manifest record.');
}
$seen = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
  $root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
  if ($file->isLink()) {
    throw new RuntimeException('Runtime symlink is not allowed.');
  }
  if (!$file->isFile()) {
    continue;
  }
  $path = substr($file->getPathname(), strlen($root) + 1);
  if ($path === 'manifest.json') {
    continue;
  }
  if (!isset($records[$path])) {
    $generated = false;
    foreach ($manifest['generatedPaths'] as $pattern) {
      $generated = $generated || fnmatch($pattern, $path);
    }
    if ($generated) {
      continue;
    }
    throw new RuntimeException('Cannot seal map with an unrecorded runtime file: '.$path);
  }
  $seen[$path] = true;
  $hash = hash_file('sha256', $file->getPathname());
  if ($path !== $map_path && $hash !== $records[$path]['sha256']) {
    throw new RuntimeException('Cannot seal map with an unreviewed source change: '.$path);
  }
}
if (array_diff_key($records, $seen)) {
  throw new RuntimeException('Cannot seal map with missing maintained runtime files.');
}
$records[$map_path]['sha256'] = hash_file('sha256', $root.'/'.$map_path);
$digest = hash_init('sha256');
foreach ($records as $path => $record) {
  hash_update($digest, $path."\0".$record['sha256']."\n");
}
$manifest['files'] = array_values($records);
$manifest['contentSHA256'] = hash_final($digest);
$updated = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
if ($updated !== file_get_contents($manifest_path)) {
  file_put_contents($manifest_path, $updated);
}
