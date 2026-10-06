#!/usr/bin/env php
<?php

// Explicit bounded backfill. No deletion and no synchronous delivery.
require_once dirname(__FILE__).'/../../scripts/__init_script__.php';
$args = new PhutilArgumentParser($argv);
$args->parseStandardArguments();
$args->parse(array(
  array('name' => 'apply', 'help' => 'Claim queued email for Gorge; default is dry run.'),
  array('name' => 'after-id', 'param' => 'id', 'default' => 0, 'help' => 'Continue after the last inspected mail ID.'),
  array('name' => 'limit', 'param' => 'count', 'default' => 100, 'help' => 'Maximum mail records to inspect.'),
));
$limit = (int)$args->getArg('limit');
if ($limit < 1 || $limit > 1000) { throw new Exception('Limit must be 1..1000.'); }
if (!PhabricatorGorgeTaskQueueClient::isConfigured()) {
  throw new Exception('Gorge queue must be configured before mail migration.');
}
$table = new PhabricatorMetaMTAMail();
$ids = queryfx_all($table->establishConnection('r'),
  'SELECT id FROM %T WHERE status = %s AND id > %d ORDER BY id LIMIT %d',
  $table->getTableName(), PhabricatorMailOutboundStatus::STATUS_QUEUE, (int)$args->getArg('after-id'), $limit);
foreach ($ids as $row) {
  $id = (int)$row['id'];
  $lock = PhabricatorGlobalLock::newLock('gorge-mail', array('id' => $id));
  $lock->lock(5);
  try {
    $mail = id(new PhabricatorMetaMTAMail())->load($id);
    if (!$mail || $mail->getStatus() !== 'queued' ||
        $mail->getMessageType() !== 'email' ||
        idx($mail->getParameters(), 'gorge.delivery-owner') === 'gorge') {
      continue;
    }
    if ($args->getArg('apply')) { $mail->adoptGorgeDelivery(); }
    echo ($args->getArg('apply') ? 'claimed ' : 'would claim ').$id."\n";
  } finally { $lock->unlock(); }
}

if ($ids) { echo 'next after-id: '.(int)last($ids)['id']."\n"; }
