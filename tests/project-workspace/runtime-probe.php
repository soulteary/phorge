<?php

// DB-free formatter regression for the same explicitly launched test server.
// This file is outside webroot and is never a production application route.
if (PHP_SAPI !== 'cli-server' ||
    getenv('ATOMIC_WORKSPACE_RUNTIME_PROBE') !== '1') {
  http_response_code(404);
  return;
}
$root = dirname(__DIR__, 2);
require_once $root.'/support/runtime/bootstrap.php';
phutil_load_library($root.'/src');

final class AtomicWorkspaceProbeEscaper extends Phobject
  implements PhutilQsprintfInterface {
  public function escapeBinaryString($value) { return addslashes($value); }
  public function escapeUTF8String($value) { return addslashes($value); }
  public function escapeColumnName($value) {
    return '`'.str_replace('`', '``', $value).'`';
  }
  public function escapeMultilineComment($value) { return $value; }
  public function escapeStringForLikeClause($value) { return $value; }
}

$escaper = new AtomicWorkspaceProbeEscaper();
$checks = 0;
try {
  // This nested callback mutates the pattern/length/argument by reference,
  // reproducing the actual web-startup failure without connecting to storage.
  $query = qsprintf($escaper, 'SELECT %C WHERE namespace = %s %Q',
    'column', 'default', qsprintf($escaper, ''));
  if ($query->getUnmaskedString() !==
      "SELECT `column` WHERE namespace = 'default' ") {
    throw new Exception('The query formatter changed its output.');
  }
  ++$checks;
  $query = qsprintf($escaper, 'SELECT %C WHERE id IN (%Ld)',
    'column', array(1, 2, 3));
  if ($query->getUnmaskedString() !== 'SELECT `column` WHERE id IN (1, 2, 3)') {
    throw new Exception('List formatting changed its output.');
  }
  ++$checks;
  foreach (array(array('SELECT 1', 'extra'), array('SELECT %s')) as $arguments) {
    try {
      call_user_func_array('qsprintf', array_merge(array($escaper), $arguments));
      throw new Exception('Invalid formatter argument counts were accepted.');
    } catch (BadFunctionCallException $expected) {
      ++$checks;
    }
  }
  header('Content-Type: application/json');
  echo json_encode(array('passed' => true, 'checks' => $checks,
    'runtime' => array('php' => PHP_VERSION, 'sapi' => PHP_SAPI,
      'jit' => ini_get('opcache.jit'),
      'opcacheEnabled' => ini_get('opcache.enable'),
      'opcacheCLIEnabled' => ini_get('opcache.enable_cli'),
      'jitBufferSize' => ini_get('opcache.jit_buffer_size'))));
} catch (Throwable $error) {
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(array('passed' => false, 'checks' => $checks,
    'errorClass' => get_class($error)));
}
