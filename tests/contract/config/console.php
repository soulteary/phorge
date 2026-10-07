<?php

require_once dirname(__DIR__).'/bootstrap.php';

function config_console_assert($condition, $message) {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function config_console_binary_rows() {
  $method = new ReflectionMethod(
    'PhabricatorConfigConsoleController', 'newBinaryVersionTable');
  $box = $method->invoke(new PhabricatorConfigConsoleController());
  $html = (string)$box->render();
  preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $html, $matches);
  $rows = array();
  foreach ($matches[1] as $row) {
    preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $row, $cells);
    if (!$cells[1]) {
      continue;
    }
    $values = array_map(function($cell) {
      return html_entity_decode(strip_tags($cell), ENT_QUOTES, 'UTF-8');
    }, $cells[1]);
    $rows[$values[0]] = $values;
  }
  return $rows;
}

$original_path = getenv('PATH');
$directory = Filesystem::createTemporaryDirectory('gorge-config-console-');
try {
  // Keep executable discovery deterministic without relying on host packages.
  file_put_contents($directory.'/which', <<<'SH'
#!/bin/sh
directory=${0%/*}
if [ -x "$directory/$1" ]; then
  printf '%s\n' "$directory/$1"
else
  exit 1
fi
SH
  );
  chmod($directory.'/which', 0700);
  putenv('PATH='.$directory);

  $rows = config_console_binary_rows();
  config_console_assert(isset($rows['php']), 'PHP version row disappeared.');
  config_console_assert(
    idx($rows, 'git') === array('git', pht('Not Available'), ''),
    'An unavailable active binary must remain visible with its diagnostic.');
  foreach (array('hg', 'pygmentize', 'svn') as $binary) {
    config_console_assert(!isset($rows[$binary]),
      'Unavailable retired binary remains visible: '.$binary);
  }

  file_put_contents($directory.'/git',
    "#!/bin/sh\nprintf 'git version 2.45.7\\n'\n");
  chmod($directory.'/git', 0700);
  foreach (array('hg', 'pygmentize', 'svn') as $binary) {
    // The sentinel catches version execution, in addition to unwanted rows.
    file_put_contents($directory.'/'.$binary,
      "#!/bin/sh\nprintf 'unexpected probe' > \"\${0%/*}/retired-probed\"\n");
    chmod($directory.'/'.$binary, 0700);
    config_console_assert(Filesystem::binaryExists($binary),
      'Retired binary fixture is unavailable: '.$binary);
    config_console_assert(
      PhutilBinaryAnalyzer::getForBinary($binary)->getBinaryKey() === $binary,
      'Console filtering changed the compatibility analyzer registry.');
  }

  $rows = config_console_binary_rows();
  config_console_assert(
    idx($rows, 'git') === array('git', '2.45.7', $directory.'/git'),
    'Available active binary lost its version or path.');
  foreach (array('hg', 'pygmentize', 'svn') as $binary) {
    config_console_assert(!isset($rows[$binary]),
      'Installed retired binary remains visible: '.$binary);
  }
  config_console_assert(!file_exists($directory.'/retired-probed'),
    'The console executed a retired binary version probe.');
  echo "Configuration console binary availability and retirement checks ".
    "passed.\n";
} finally {
  if ($original_path === false) {
    putenv('PATH');
  } else {
    putenv('PATH='.$original_path);
  }
  Filesystem::remove($directory);
}
