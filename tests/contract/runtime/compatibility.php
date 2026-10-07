<?php

require_once dirname(__DIR__).'/bootstrap.php';
$root = dirname(__DIR__, 3);
if (realpath(phutil_get_library_root('arcanist')) !==
    realpath($root.'/support/runtime/src')) {
  throw new RuntimeException('Runtime loaded from an external path.');
}
foreach (array('PhutilLocale', 'PhutilTranslation',
  'PhutilHTTPEngineExtension', 'PhutilBinaryAnalyzer') as $ancestor) {
  $classes = id(new PhutilClassMapQuery())->setAncestorClass($ancestor)->execute();
  if (!$classes) {
    throw new RuntimeException('Dynamic family is empty: '.$ancestor);
  }
}
$valid = phutil_json_decode('{"ready":true}');
if ($valid !== array('ready' => true)) {
  throw new RuntimeException('JSON decode failed.');
}
try {
  phutil_json_decode('{"invalid":}');
  throw new RuntimeException('Invalid JSON was accepted.');
} catch (PhutilJSONParserException $expected) {
  // The retained JSONLint resource must serve the error path.
}
list($stdout) = execx('%s -r %s', PHP_BINARY, 'echo "runtime child";');
if ($stdout !== 'runtime child') {
  throw new RuntimeException('Process execution changed.');
}
try {
  execx('%s -r %s', PHP_BINARY, 'fwrite(STDERR,"failed child"); exit(7);');
  throw new RuntimeException('Failed process was accepted.');
} catch (CommandException $expected) {
  if ($expected->getError() !== 7) {
    throw new RuntimeException('Failed child status changed.');
  }
}
foreach (array('RepositoryAPI', 'GitAPI', 'MercurialAPI', 'SubversionAPI',
  'Workflow') as $removed_suffix) {
  $removed = 'Arcanist'.$removed_suffix;
  if (class_exists($removed)) {
    throw new RuntimeException('Client repository class remains: '.$removed);
  }
}
echo "Runtime resources, dynamic classes and process errors passed.\n";
