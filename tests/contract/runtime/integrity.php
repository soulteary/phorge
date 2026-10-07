<?php

require_once dirname(__DIR__).'/bootstrap.php';
$project = dirname(__DIR__, 3);
$temporary = Filesystem::createTemporaryDirectory('gorge-runtime-integrity-');
try {
  Filesystem::createDirectory($temporary.'/support/runtime', 0755, true);
  Filesystem::createDirectory($temporary.'/scripts/runtime', 0755, true);
  foreach (array('src', 'tests', 'resources', 'bin', 'webroot', '.github') as $scope) {
    Filesystem::createDirectory($temporary.'/'.$scope);
  }
  $manifest = phutil_json_decode(Filesystem::readFile(
    $project.'/support/runtime/manifest.json'));
  foreach ($manifest['files'] as $record) {
    $to = $temporary.'/support/runtime/'.$record['path'];
    Filesystem::createDirectory(dirname($to), 0755, true);
    Filesystem::writeFile($to, Filesystem::readFile(
      $project.'/support/runtime/'.$record['path']));
  }
  Filesystem::writeFile($temporary.'/support/runtime/manifest.json',
    Filesystem::readFile($project.'/support/runtime/manifest.json'));
  Filesystem::writeFile($temporary.'/scripts/runtime/verify.php',
    Filesystem::readFile($project.'/scripts/runtime/verify.php'));
  Filesystem::writeFile($temporary.'/scripts/runtime/seal-map.php',
    Filesystem::readFile($project.'/scripts/runtime/seal-map.php'));
  $check = function() use ($temporary) {
    return exec_manual('%s %s', PHP_BINARY,
      $temporary.'/scripts/runtime/verify.php');
  };
  list($err, $stdout, $stderr) = $check();
  if ($err) {
    throw new RuntimeException('Clean staged runtime failed verification: '.$stdout.$stderr);
  }
  $mutations = array(
    'modified' => function() use ($temporary) {
      $path = $temporary.'/support/runtime/bootstrap.php';
      $original = Filesystem::readFile($path);
      Filesystem::writeFile($path, $original."\n// unexpected change\n");
      return function() use ($path, $original) {
        Filesystem::writeFile($path, $original);
      };
    },
    'unrecorded' => function() use ($temporary) {
      $path = $temporary.'/support/runtime/unrecorded.php';
      Filesystem::writeFile($path, "<?php\n");
      return function() use ($path) { Filesystem::remove($path); };
    },
    'missing' => function() use ($temporary) {
      $path = $temporary.'/support/runtime/bootstrap.php';
      $original = Filesystem::readFile($path);
      Filesystem::remove($path);
      return function() use ($path, $original) {
        Filesystem::writeFile($path, $original);
      };
    },
    'symlink-directory' => function() use ($temporary) {
      $path = $temporary.'/support/runtime/extensions';
      symlink($temporary.'/src', $path);
      return function() use ($path) { unlink($path); };
    },
  );
  foreach ($mutations as $name => $mutate) {
    $restore = $mutate();
    list($err) = $check();
    $restore();
    if (!$err) {
      throw new RuntimeException('Integrity check accepted '.$name.' runtime.');
    }
  }
  $map_path = $temporary.'/support/runtime/src/__phutil_library_map__.php';
  Filesystem::writeFile($map_path, Filesystem::readFile($map_path)."\n// rebuilt map\n");
  list($err) = $check();
  if (!$err) {
    throw new RuntimeException('Changed generated map was accepted before sealing.');
  }
  execx('%s %s', PHP_BINARY, $temporary.'/scripts/runtime/seal-map.php');
  list($err, $stdout, $stderr) = $check();
  if ($err) {
    throw new RuntimeException('Map-only seal failed: '.$stdout.$stderr);
  }
  $restore = $mutations['modified']();
  list($err) = exec_manual('%s %s', PHP_BINARY,
    $temporary.'/scripts/runtime/seal-map.php');
  $restore();
  if (!$err) {
    throw new RuntimeException('Map seal approved an unrelated source change.');
  }
  $excluded_reference = $temporary.'/src/qualified-excluded.php';
  Filesystem::writeFile($excluded_reference, "<?php new \\ArcanistGitAPI();\n");
  list($err, $stdout, $stderr) = $check();
  if (!$err || strpos($stdout.$stderr, 'excluded upstream symbol') === false) {
    throw new RuntimeException('Qualified excluded dependency escaped the guard.');
  }
  Filesystem::writeFile($excluded_reference, "<?php new \\arcAnistGitAPI();\n");
  list($err, $stdout, $stderr) = $check();
  if (!$err || strpos($stdout.$stderr, 'excluded upstream symbol') === false) {
    throw new RuntimeException('Case-insensitive excluded dependency escaped the guard.');
  }
} finally {
  Filesystem::remove($temporary);
}
echo "Runtime integrity rejects modified, unrecorded, missing and symlink files; map sealing approves only map changes.\n";
