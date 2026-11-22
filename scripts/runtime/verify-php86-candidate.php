<?php

require_once dirname(__DIR__, 2).'/support/runtime/php-version.php';
if (getenv('PHORGE_PHP_COMPATIBILITY_PROFILE') !== 'php86-isolated-v1' ||
    PHP_VERSION !== '8.6.0') {
  throw new RuntimeException('Exact PHP 8.6.0 isolated candidate required.');
}
phorge_assert_supported_php_version(PHP_VERSION);
if (phorge_php_version_is_supported(PHP_VERSION) ||
    ini_get('opcache.jit') !== 'disable' ||
    (int)ini_get('opcache.jit_buffer_size') !== 0 ||
    ini_get('session.use_strict_mode') !== '1' ||
    filter_var(ini_get('session.upload_progress.enabled'), FILTER_VALIDATE_BOOLEAN)) {
  throw new RuntimeException('Candidate runtime security predicate refused.');
}
foreach (array('mysqli','gd','curl','mbstring','pcntl','posix','iconv','zip','apcu') as $extension) {
  if (!extension_loaded($extension)) {
    throw new RuntimeException('Missing candidate extension: '.$extension);
  }
}
echo json_encode(array('schema'=>'php86-isolated-runtime-v1',
  'runtime'=>PHP_VERSION,'profile'=>'php86-isolated-v1','passed'=>true,
  'production_supported'=>false,'JIT_enabled'=>false,'deployment_proved'=>false),
  JSON_THROW_ON_ERROR)."\n";
