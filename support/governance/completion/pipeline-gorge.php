<?php

/** Real paired binaries, owned loopback endpoints, no external delivery. */
function governance_completion_start_gorge($fixture, $kind, callable $check) {
  if (!is_object($fixture) ||
      get_class($fixture) !== PhorgeGovernanceCompletionFixture::class ||
      !in_array($kind, array('file', 'taskqueue'), true)) {
    throw new RuntimeException('completion_gorge_kind_refused');
  }
  $fixture->assertOwned();
  $key = 'PHORGE_GOVERNANCE_GORGE_'.strtoupper($kind);
  $binary = getenv($key.'_BINARY');
  $expected = getenv($key.'_SHA256');
  if (!is_string($binary) || !is_file($binary) || !is_executable($binary) ||
      !is_string($expected) || !preg_match('/\A[a-f0-9]{64}\z/', $expected) ||
      !hash_equals($expected, hash_file('sha256', $binary))) {
    throw new RuntimeException('completion_paired_gorge_binary_required');
  }
  $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
  if (!$socket) { throw new RuntimeException('completion_loopback_port_failed'); }
  $address = stream_socket_get_name($socket, false);
  fclose($socket);
  $private = sys_get_temp_dir().'/governance-service-'.bin2hex(random_bytes(12));
  if (!mkdir($private, 0700)) {
    throw new RuntimeException('completion_service_private_directory_failed');
  }
  $token = bin2hex(random_bytes(32));
  $nonce = bin2hex(random_bytes(24));
  file_put_contents($private.'/owner', $nonce, LOCK_EX);
  chmod($private.'/owner', 0600);
  // An allowlisted process environment prevents inherited S3/SMTP/DB endpoints.
  $env = array('PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    'GORGE_LISTEN_ADDR' => $address, 'GORGE_SERVICE_TOKEN' => $token);
  if ($kind === 'file') {
    mkdir($private.'/uploads', 0700);
    $env['GORGE_FILE_UPLOAD_ROOT'] = $private.'/uploads';
    $env['GORGE_FILE_LOCAL_DISK_PATH'] = $private.'/uploads';
    $env['GORGE_FILE_MYSQL_BLOB_MAX_SIZE'] = '0';
  } else {
    $config = $fixture->getWriterConfig();
    $env += array('GORGE_TASKQUEUE_BACKEND' => 'mysql',
      'GORGE_TASKQUEUE_MYSQL_HOST' => $config['host'],
      'GORGE_TASKQUEUE_MYSQL_PORT' => (string)$config['port'],
      'GORGE_TASKQUEUE_MYSQL_USER' => $config['user'],
      'GORGE_TASKQUEUE_MYSQL_PASS' => $config['password'],
      'GORGE_TASKQUEUE_NAMESPACE' => $config['namespace'],
      'GORGE_TASKQUEUE_SCHEDULER_ENABLED' => 'false');
  }
  $process = proc_open(array(realpath($binary)), array(
    0 => array('file', '/dev/null', 'r'),
    1 => array('file', $private.'/service.log', 'a'),
    2 => array('file', $private.'/service.log', 'a')), $pipes, null, $env);
  $service = array('process' => $process, 'private' => $private, 'nonce' => $nonce,
    'uri' => 'http://'.$address, 'token' => $token, 'kind' => $kind,
    'binary_sha256' => $expected);
  try {
    if (!is_resource($process)) {
      throw new RuntimeException('completion_service_process_failed');
    }
    $ready = false;
    $deadline = microtime(true) + 15;
    do {
      $status = proc_get_status($process);
      if (!$status['running']) { break; }
      $context = stream_context_create(array('http' => array(
        'timeout' => 0.5, 'ignore_errors' => true, 'proxy' => '')));
      $body = @file_get_contents($service['uri'].'/readyz', false, $context);
      $headers = http_get_last_response_headers();
      if ($body !== false && isset($headers[0]) &&
          str_contains($headers[0], ' 200 ')) {
        $ready = true;
        break;
      }
      usleep(50000);
    } while (microtime(true) < $deadline);
    $check($ready, 'completion-real-gorge-'.$kind.'-ready-loopback');
    return $service;
  } catch (Throwable $error) {
    governance_completion_stop_gorge($service);
    throw $error;
  }
}

function governance_completion_stop_gorge(array &$service) {
  if (isset($service['process']) && is_resource($service['process'])) {
    proc_terminate($service['process']);
    $deadline = microtime(true) + 3;
    while (proc_get_status($service['process'])['running'] &&
      microtime(true) < $deadline) { usleep(20000); }
    if (proc_get_status($service['process'])['running']) {
      proc_terminate($service['process'], 9);
    }
    proc_close($service['process']);
  }
  $path = $service['private'] ?? null;
  if (!is_string($path) || is_link($path) ||
      !str_starts_with($path, sys_get_temp_dir().'/governance-service-') ||
      !is_file($path.'/owner') ||
      !hash_equals($service['nonce'], file_get_contents($path.'/owner'))) {
    throw new RuntimeException('completion_service_cleanup_ownership_refused');
  }
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,
    FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) {
    if ($file->isLink()) { unlink($file->getPathname()); }
    else if ($file->isDir()) { rmdir($file->getPathname()); }
    else { unlink($file->getPathname()); }
  }
  rmdir($path);
  $result = array('kind' => $service['kind'],
    'binary_sha256' => $service['binary_sha256'],
    'process_stopped' => true, 'private_storage_removed' => true);
  $service = array();
  return $result;
}

function governance_completion_gorge_publication($fixture, callable $check) {
  $fixture->assertOwned();
  if (getenv('PHORGE_GOVERNANCE_COMPLETION_QUEUE_PROFILE') !== 'gorge') {
    throw new RuntimeException('completion_explicit_gorge_profile_required');
  }
  $runtime_profile = PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
  $service = governance_completion_start_gorge($fixture, 'taskqueue', $check);
  $stack = PhabricatorEnv::getConfigSourceStack();
  $sources = $stack->getStack();
  if (count($sources) !== 2 ||
      get_class($sources[0]) !== PhabricatorConfigDictionarySource::class) {
    governance_completion_stop_gorge($service);
    throw new RuntimeException('completion_gorge_static_configuration_required');
  }
  $dictionary = $sources[0];
  $old_values = $dictionary->getAllKeys();
  $cache = new ReflectionProperty(PhabricatorEnv::class, 'cache');
  $old_cache = $cache->getValue();
  $cleanup = null;
  try {
    $dictionary->setKeys(array('gorge.taskqueue.owner' => 'gorge',
      'gorge.taskqueue.uri' => $service['uri'],
      'gorge.taskqueue.token' => $service['token'],
      'metamta.gorge-delivery-mode' => 'native',
      'gorge.notification.outbox' => true,
      'gorge.service-policies' => array('file' => 'required',
        'taskqueue' => 'required')));
    $cache->setValue(null, array());
    $check(PhabricatorGorgeTaskQueueClient::isConfigured(),
      'completion-gorge-profile-requires-real-http-owner');
    $http = stream_context_create(array('http' => array('timeout' => 2,
      'ignore_errors' => true, 'proxy' => '')));
    @file_get_contents($service['uri'].'/api/queue/stats', false, $http);
    $headers = http_get_last_response_headers();
    $check(isset($headers[0]) && str_contains($headers[0], ' 401 '),
      'completion-gorge-queue-rejects-unauthenticated-http');
    $check(PhabricatorGorgeServiceRegistry::getService('taskqueue')->isRequired(),
      'completion-gorge-profile-forbids-native-fallback');
    $fallback_before = PhabricatorGorgeServiceSpec::getFallbackCounts();
    $client = new PhabricatorGorgeTaskQueueClient();
    $check(is_array($client->getStats()), 'completion-gorge-authenticated-http-stats');
    $suffix = substr($fixture->getNamespace(), -12);
    $users = (new PhabricatorPeopleQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withUsernames(array('gcactor'.$suffix, 'gcnextowner'.$suffix))->execute();
    $users = mpull($users, null, 'getUsername');
    $actor = $users['gcactor'.$suffix] ?? null;
    $recipient = $users['gcnextowner'.$suffix] ?? null;
    $check($actor && $recipient, 'completion-gorge-profile-owned-native-actors');
    $task = ManiphestTask::initializeNewTask($actor)
      ->setOwnerPHID($recipient->getPHID());
    $source = PhabricatorContentSource::newForSource('console', array());
    (new ManiphestTransactionEditor())->setActor($actor)->setContentSource($source)
      ->setIsSilent(false)->applyTransactions($task, array((new ManiphestTransaction())
        ->setTransactionType(ManiphestTaskTitleTransaction::TRANSACTIONTYPE)
        ->setNewValue('Synthetic Gorge Publication')));
    $admin = $fixture->getAdmin();
    $phid = "'".$admin->real_escape_string($task->getPHID())."'";
    $rows = $admin->query('SELECT id FROM '.$fixture->table('worker', 'worker_activetask').
      " WHERE taskClass='PhabricatorApplicationTransactionPublishWorker' AND objectPHID=".
      $phid.' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $check(count($rows) === 1, 'completion-real-editor-created-http-publication-task');
    $id = (int)$rows[0]['id'];
    $remote = $client->getTask($id);
    $check(is_array($remote), 'completion-real-publication-task-visible-via-gorge-http');
    $queued = (new PhabricatorWorkerActiveTask())->load($id);
    $check($queued && $queued->getTaskClass() ===
      'PhabricatorApplicationTransactionPublishWorker' &&
      $queued->getObjectPHID() === $task->getPHID(),
      'completion-only-owned-publication-worker-selected');
    // Native LeaseQuery hydrates task data separately from the active row.
    $data_row = (new PhabricatorWorkerTaskData())->load($queued->getDataID());
    $data = $data_row ? $data_row->getData() : null;
    $check(is_array($data) && ($data['objectPHID'] ?? null) === $task->getPHID() &&
      ($data['actorPHID'] ?? null) === $actor->getPHID() &&
      is_array($data['xactionPHIDs'] ?? null) && $data['xactionPHIDs'] &&
      count($data['xactionPHIDs']) <= 20,
      'completion-owned-publication-data-loaded-from-native-taskdata');
    $owned_transactions = (new ManiphestTransactionQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withObjectPHIDs(array($task->getPHID()))
      ->withPHIDs($data['xactionPHIDs'])->execute();
    $check(count($owned_transactions) === count(array_unique($data['xactionPHIDs'])),
      'completion-publication-transactions-belong-to-owned-object');
    $queued->setData($data);
    $before_mail = $fixture->countRows('metamta', 'metamta_gorgeoutbox');
    $before_feed = $fixture->countRows('feed', 'feed_gorgeoutbox');
    // Execute exactly this owned native publishing worker. It prepares local
    // mail/feed outboxes but no mail/notification/delivery worker is executed.
    (new PhabricatorApplicationTransactionPublishWorker($queued->getData()))
      ->executeTask();
    $check($fixture->countRows('metamta', 'metamta_gorgeoutbox') === $before_mail,
      'completion-create-hidden-transactions-produce-no-mail-outbox');

    // A title-only creation is hidden for mail by native transaction rules.
    // Generate a real visible comment update and select its own queue task.
    $comment = new ManiphestTransaction();
    $comment->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
      ->attachComment($comment->getApplicationTransactionCommentObject()
        ->setContent('Synthetic visible publication update'));
    (new ManiphestTransactionEditor())->setActor($actor)->setContentSource($source)
      ->setIsSilent(false)->applyTransactions($task, array($comment));
    $updates = $admin->query('SELECT id FROM '.$fixture->table('worker', 'worker_activetask').
      " WHERE taskClass='PhabricatorApplicationTransactionPublishWorker' AND objectPHID=".
      $phid.' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $check(count($updates) === 2 && (int)$updates[1]['id'] > $id,
      'completion-real-comment-update-created-second-http-publication-task');
    $update_id = (int)$updates[1]['id'];
    $check(is_array($client->getTask($update_id)),
      'completion-comment-publication-task-visible-via-gorge-http');
    $update_task = (new PhabricatorWorkerActiveTask())->load($update_id);
    $update_data_row = $update_task ?
      (new PhabricatorWorkerTaskData())->load($update_task->getDataID()) : null;
    $update_data = $update_data_row ? $update_data_row->getData() : null;
    $check($update_task && $update_task->getTaskClass() ===
      'PhabricatorApplicationTransactionPublishWorker' &&
      $update_task->getObjectPHID() === $task->getPHID() &&
      is_array($update_data) && ($update_data['objectPHID'] ?? null) ===
        $task->getPHID() && ($update_data['actorPHID'] ?? null) === $actor->getPHID() &&
      is_array($update_data['xactionPHIDs'] ?? null) &&
      $update_data['xactionPHIDs'] && count($update_data['xactionPHIDs']) <= 20,
      'completion-update-publication-data-bound-to-owned-actor-and-object');
    $update_transactions = (new ManiphestTransactionQuery())->setViewer($recipient)
      ->withObjectPHIDs(array($task->getPHID()))
      ->withPHIDs($update_data['xactionPHIDs'])->execute();
    $mailable = 0;
    foreach ($update_transactions as $transaction) {
      if (!$transaction->shouldHideForMail($update_transactions)) { $mailable++; }
    }
    $check(count($update_transactions) === count(array_unique($update_data['xactionPHIDs'])) &&
      $mailable > 0, 'completion-update-has-owned-native-mail-visible-transactions');
    (new PhabricatorApplicationTransactionPublishWorker($update_data))->executeTask();
    $check($fixture->countRows('metamta', 'metamta_gorgeoutbox') > $before_mail,
      'completion-native-publish-worker-creates-gorge-mail-outbox');
    $check($fixture->countRows('feed', 'feed_gorgeoutbox') > $before_feed,
      'completion-native-publish-worker-creates-gorge-feed-outbox');
    $pending = $admin->query('SELECT COUNT(*) FROM '.
      $fixture->table('metamta', 'metamta_gorgeoutbox').
      ' WHERE deliveredEpoch IS NOT NULL OR queueTaskID IS NOT NULL')->fetch_row()[0];
    $check((int)$pending === 0, 'completion-mail-outbox-not-delivered-or-dispatched');
    $check(is_array($client->getStats()),
      'completion-gorge-http-remains-live-after-native-publication');
    $check(PhabricatorGorgeServiceSpec::getFallbackCounts() === $fallback_before,
      'completion-gorge-publication-zero-native-fallbacks');
  } finally {
    $dictionary->deleteKeys(array_keys($dictionary->getAllKeys()));
    $dictionary->setKeys($old_values);
    $cache->setValue(null, $old_cache);
    $cleanup = governance_completion_stop_gorge($service);
  }
  $check(PhorgeGovernanceNativeRuntimeProfile::assertCurrent() === $runtime_profile,
    'completion-gorge-fixed-native-library-profile-unchanged');
  return array('profile' => 'gorge', 'editor_family' => 'ManiphestTask',
    'runtime_profile' => $runtime_profile,
    'real_http_queue' => true,
    'owned_publish_workers_executed' => 2,
    'create_hidden_mail_negative_and_comment_positive' => true,
    'delivery_workers_executed' => 0, 'external_mail_delivered' => false,
    'mail_and_feed_outbox_only' => true, 'service' => $cleanup,
    'production_configuration_changed' => false);
}
