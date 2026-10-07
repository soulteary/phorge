<?php

// Standalone deployment contracts, runnable without Arcanist or a database.
require_once __DIR__.'/../../../scripts/setup/lib/gorge_startup.php';

function startup_assert($value, $message) {
  if (!$value) {
    throw new Exception($message);
  }
}

function startup_build($directory, array $env, $expect_success = true) {
  $root = dirname(__DIR__, 3);
  $process = proc_open(array(PHP_BINARY,
    $root.'/scripts/setup/build_deployment_config.php', 'collaboration',
    $directory.'/deployment.json', $directory.'/local.json'),
    array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $env);
  $output = stream_get_contents($pipes[1]);
  $error = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $status = proc_close($process);
  startup_assert(($status === 0) === $expect_success,
    'Unexpected deployment status: '.$output.$error);
  return json_decode(file_get_contents($directory.'/deployment.json'), true);
}

$directory = sys_get_temp_dir().'/gorge-startup-'.bin2hex(random_bytes(6));
mkdir($directory, 0700);
try {
  $local = array(
    'storage.local-disk.path' => '/historical/files',
    'storage.s3.bucket' => 'historical-bucket',
    'cluster.mailers' => array(
      array('key' => 'smtp', 'type' => 'smtp', 'options' => array('host' => 'old')),
      array('key' => 'sms', 'type' => 'sns', 'options' => array()),
    ),
    'cluster.search' => array(
      array('type' => 'mysql'), array('type' => 'elasticsearch', 'hosts' => array()),
    ),
  );
  file_put_contents($directory.'/local.json', json_encode($local));
  $env = array('GORGE_MAILER_MODE' => 'enable',
    'GORGE_MAILER_URI' => 'http://mailer:8110',
    'GORGE_SEARCH_MODE' => 'enable', 'GORGE_SEARCH_HOST' => 'search',
    'GORGE_RENDER_URI' => 'http://render:8140',
    'GORGE_FILE_URI' => 'http://file:8100',
    'GORGE_TASKQUEUE_URI' => 'http://queue:8090');
  $config = startup_build($directory, $env);
  startup_assert($config['cluster.mailers'][0]['outbound'] === false,
    'Historical email provider remained writable.');
  startup_assert($config['cluster.mailers'][0]['options']['host'] === 'old',
    'Inbound provider credentials changed.');
  startup_assert(!isset($config['cluster.mailers'][1]['outbound']),
    'SMS provider changed.');
  startup_assert($config['gorge.mailer.exclusive'] === true,
    'Exclusive outbound email missing.');
  startup_assert(count($config['cluster.search']) === 1 &&
    $config['cluster.search'][0]['type'] === 'gorge', 'Search was not exclusive.');
  startup_assert($config['phd.taskmasters'] === 0 &&
    $config['gorge.taskqueue.owner'] === 'gorge', 'Queue ownership split.');
  startup_assert(!isset($config['gorge.diff.enabled']), 'Retired diff switch returned.');
  startup_assert(file_get_contents($directory.'/local.json') === json_encode($local),
    'Historical reader/inbound configuration was modified.');
  $env['GORGE_SEARCH_KEEP_MYSQL'] = '1';
  startup_build($directory, $env, false);
  $env['GORGE_SEARCH_EXCLUSIVE'] = 'false';
  $config = startup_build($directory, $env);
  startup_assert(count($config['cluster.search']) === 3,
    'Explicit nonexclusive search did not retain engines.');
  $env['GORGE_SEARCH_EXCLUSIVE'] = 'typo';
  startup_build($directory, $env, false);

  startup_assert(gorge_startup_valid('execution', array('data' => array(
    'executionVersion' => 1, 'leaseOutcomes' => true))), 'Valid protocol rejected.');
  foreach (array(null, array('data' => array('executionVersion' => 0)),
    array('data' => array('executionVersion' => '1', 'leaseOutcomes' => true)))
    as $invalid) {
    startup_assert(!gorge_startup_valid('execution', $invalid),
      'Invalid protocol accepted.');
  }
  startup_assert(!gorge_startup_valid('diff', array('data' => array('parts' => array()))),
    'Missing diff routes accepted.');
  $probes = gorge_startup_probes($config, 'http://worker:8170', 'test-token');
  startup_assert(count(array_filter($probes, function($probe) {
    return $probe[0] === 'diff/generate' || $probe[0] === 'diff/prose' ||
      $probe[0] === 'queue protocol' || $probe[0] === 'worker protocol';
  })) === 4, 'Bootstrap protocol/diff probes missing.');
  $caps = array('protocolVersion'=>1, 'maxBytes'=>16777216,
    'publicOnly'=>true, 'headerTokenOnly'=>true, 'pinnedDNS'=>true);
  startup_assert(gorge_startup_valid('file-fetch', array('data'=>$caps)),
    'Download protocol rejected.');
  foreach (array('protocolVersion', 'publicOnly', 'headerTokenOnly', 'pinnedDNS')
    as $key) {
    $broken = $caps;
    unset($broken[$key]);
    startup_assert(!gorge_startup_valid('file-fetch', array('data'=>$broken)),
      'Incomplete download capability accepted.');
  }
  startup_assert(count(array_filter($probes, function($probe) {
    return $probe[0] === 'file download protocol' && $probe[5] === true;
  })) === 1, 'Required download probe missing.');
  $upload_caps = array('protocolVersion' => 1, 'integrityVersion' => 1, 'enabled' => true,
    'chunkSize' => 4 * 1024 * 1024, 'maxSize' => 64 * 1024 * 1024 * 1024,
    'storageFormat' => 'raw', 'durability' => 'posix-volume');
  startup_assert(gorge_startup_valid('file-upload-protocol', array('data' => $upload_caps)),
    'Upload protocol rejected.');
  foreach (array('chunkSize' => 1, 'storageFormat' => 'encrypted',
    'durability' => 'memory', 'maxSize' => '64') as $key => $value) {
    $bad = $upload_caps; $bad[$key] = $value;
    startup_assert(!gorge_startup_valid('file-upload-protocol', array('data' => $bad)),
      'Invalid upload protocol accepted.');
  }
  $file_config = $config;
  $file_config['gorge.file.uploads'] = true;
  $file_config['gorge.file.deletion-outbox'] = true;
  $file_probes = gorge_startup_probes($file_config, null, null);
  startup_assert(count(array_filter($file_probes, function($probe) {
    return $probe[0] === 'file upload protocol' && $probe[5] === true &&
      $probe[1] === 'http://file:8100/api/file/uploads/meta';
  })) === 1, 'Required upload protocol probe missing.');
  $optional = array('optional' , 'http://unused', null, null, 'ready', false);
  $required = array('required', 'http://unused', null, null, 'ready', true);
  $result = gorge_startup_wait(array($optional, $required), .01,
    function() { return false; });
  startup_assert($result['optional'] === array('optional') &&
    $result['failed'] === array('required'), 'Failure policy ignored.');
  $calls = 0;
  $result = gorge_startup_wait(array($required), 2, function() use (&$calls) {
    return ++$calls === 2;
  });
  startup_assert(!$result['failed'] && $calls === 2, 'Transient startup did not recover.');
  echo "Startup contracts passed: ownership, exclusive routing, retained readers, probes.\n";
} finally {
  foreach (glob($directory.'/*') as $file) {
    unlink($file);
  }
  rmdir($directory);
}
