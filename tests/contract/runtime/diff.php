<?php

require_once dirname(__DIR__).'/bootstrap.php';
$cases = require __DIR__.'/diff_cases.php';
$expected = json_decode(file_get_contents(__DIR__.'/diff_expected.json'), true,
  512, JSON_THROW_ON_ERROR);
foreach ($cases as $name => $input) {
  $changes = id(new ArcanistDiffParser())->parseDiff($input);
  $dictionary = array_values(array_map(function($change) {
    return $change->toDictionary();
  }, $changes));
  $bundle = ArcanistBundle::newFromChanges($changes);
  if ($name === 'binary') {
    foreach ($changes as $change) {
      $change->setMetadata('old:binary-phid', 'old');
      $change->setMetadata('new:binary-phid', 'new');
    }
    $bundle->setLoadFileDataCallback(function($phid) {
      return $phid === 'old' ? "old\0bytes" : "new\0bytes";
    });
  }
  $actual = array(
    'changes' => $dictionary,
    'git' => $bundle->toGitPatch(),
    'unified' => $bundle->toUnifiedDiff(),
  );
  if ($actual !== $expected[$name]) {
    throw new RuntimeException('Diff behavior differs from source baseline: '.$name);
  }
}
foreach (array(
  array(ArcanistDiffParser::class, 'setRepositoryAPI'),
  array(ArcanistDiffParser::class, 'parseSubversionDiff'),
  array(ArcanistDiffChange::class, 'convertToBinaryChange'),
  array(ArcanistBundle::class, 'newFromArcBundle'),
  array(ArcanistBundle::class, 'writeToDisk'),
  array(ArcanistBundle::class, 'setConduit'),
) as $removed) {
  if (method_exists($removed[0], $removed[1])) {
    throw new RuntimeException('Client diff API remains: '.implode('::', $removed));
  }
}
$bundle = ArcanistBundle::newFromDiff($cases['text-change']);
$bundle->setByteLimit(1);
try {
  $bundle->toGitPatch();
  throw new RuntimeException('Patch byte limit was not enforced.');
} catch (ArcanistDiffByteSizeException $expected_exception) {
  // Export limits remain part of the retained server interface.
}
$deleted = id(new ArcanistDiffParser())->parseDiff($cases['delete']);
foreach ($deleted as $change) {
  $change->setCurrentPath(null);
}
set_error_handler(function($severity, $message, $file, $line) {
  throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
  $patch = ArcanistBundle::newFromChanges($deleted)->toGitPatch();
  if ($patch !== $expected['delete']['git']) {
    throw new RuntimeException('Deleted change with null current path changed export.');
  }
} finally {
  restore_error_handler();
}
echo sprintf("Bundled diff baseline: %d cases passed.\n", count($cases));
