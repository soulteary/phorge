<?php

// Run only against a disposable server. Creates and drops a random database.
$port = getenv('GORGE_TEST_MYSQL_PORT');
$password = getenv('GORGE_TEST_MYSQL_PASSWORD');
if (!$port || $password === false) {
  fwrite(STDERR, "Set GORGE_TEST_MYSQL_PORT and GORGE_TEST_MYSQL_PASSWORD.\n");
  exit(1);
}
require_once dirname(__DIR__).'/bootstrap.php';
PhabricatorEnv::initializeScriptEnvironment(true, true);

$admin = new mysqli('127.0.0.1', 'root', $password, '', (int)$port);
$namespace = 'gorge_contract_'.getmypid().'_'.bin2hex(random_bytes(4));
$name = $namespace.'_metamta';
$admin->query('CREATE DATABASE `'.$name.'`');
$admin->select_db($name);
$env = PhabricatorEnv::beginScopedEnv();
$env->overrideEnvConfig('mysql.host', '127.0.0.1');
$env->overrideEnvConfig('mysql.port', (string)$port);
$env->overrideEnvConfig('mysql.user', 'root');
$env->overrideEnvConfig('mysql.pass', $password);
$env->overrideEnvConfig('cluster.databases', array());
PhabricatorEnv::setReadOnly(false, null);
PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_REFS);
PhabricatorCaches::getRequestCache()->deleteKey(PhabricatorDatabaseRef::KEY_INDIVIDUAL);
$env->overrideEnvConfig('storage.default-namespace', $namespace);
$env->overrideEnvConfig('cluster.instance', null);
$env->overrideEnvConfig('gorge.taskqueue.uri', 'http://127.0.0.1:1');
$env->overrideEnvConfig('gorge.service-policy', array('taskqueue' => 'required'));


$env->overrideEnvConfig('metamta.gorge-delivery-mode', 'native');
try {
  $admin->query('CREATE TABLE metamta_mail (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, phid VARCHAR(64) NOT NULL, actorPHID VARCHAR(64) NULL, parameters LONGTEXT NOT NULL, status VARCHAR(32) NOT NULL, message LONGTEXT NULL, relatedPHID VARCHAR(64) NULL, dateCreated BIGINT NOT NULL, dateModified BIGINT NOT NULL) ENGINE=InnoDB');
  $sql = file_get_contents(dirname(__FILE__).'/../../../resources/sql/autopatches/20261006.metamta.01.gorgeoutbox.sql');
  $admin->query(str_replace('{$NAMESPACE}_metamta.', '', $sql));
  $mail = id(new PhabricatorMetaMTAMail())->setMessageType('email')->save();
  $event = $admin->query('SELECT payload FROM metamta_gorgeoutbox')->fetch_assoc();
  $payload = json_decode($event['payload'], true);
  if (idx($mail->getParameters(), 'gorge.delivery-owner') !== 'gorge' ||
      $payload['task']['taskClass'] !== 'GorgeMailDeliveryWorker' ||
      json_decode($payload['task']['data']) !== (int)$mail->getID()) {
    throw new Exception('Native producer did not atomically create the event.');
  }
  $snapshot = array('schemaVersion' => 1, 'deliveryID' => 'mail/'.$mail->getPHID().'/1',
    'mailID' => (int)$mail->getID(), 'deadline' => time() + 3600,
    'adapterKey' => 'php-gorge-mailer',
    'message' => array('from' => array('address' => 'from@example.test'),
      'to' => array(array('address' => 'to@example.test')), 'subject' => 'contract'));
  $params = $mail->getParameters();
  $params['gorge.delivery-snapshot'] = $snapshot;
  $params['gorge.delivery-audit'] = array('actors' => array('contract-recipient'), 'routing' => array('contract-route'));
  if (isset($params['actors.sent'])) { throw new Exception('Preparation populated sent audit before provider acceptance.'); }
  $mail->setParameters($params)->save();
  if ($mail->prepareGorgeDelivery() !== $snapshot) {
    throw new Exception('Preparation retry changed the frozen snapshot.');
  }
  $mail->applyGorgeDelivery(array('deliveryID' => $snapshot['deliveryID'], 'state' => 'accepted', 'revision' => 4, 'mailerKey' => 'go-provider'));
  $mail->applyGorgeDelivery(array('deliveryID' => $snapshot['deliveryID'], 'state' => 'retry_wait', 'revision' => 3));
  if (idx($mail->getParameters(), 'mailer.key') !== 'php-gorge-mailer' ||
      idx($mail->getParameters(), 'gorge.provider-key') !== 'go-provider' ||
      idx($mail->getParameters(), 'actors.sent') !== array('contract-recipient')) {
    throw new Exception('Accepted audit lost adapter/provider identity.');
  }
  if ($mail->getStatus() !== 'sent') { throw new Exception('Stale projection reverted sent mail.'); }
  $failed = false;
  try { $mail->sendNow(); } catch (PhabricatorMetaMTAPermanentFailureException $ex) { $failed = true; }
  if (!$failed) { throw new Exception('Legacy sender accepted native-owned mail.'); }
  $failed = false;
  try { $mail->sendWithMailers(array()); } catch (PhabricatorMetaMTAPermanentFailureException $ex) { $failed = true; }
  if (!$failed) { throw new Exception('Direct legacy dispatch bypassed native ownership.'); }
  $failed = false;
  try { $mail->applyGorgeDelivery(array('deliveryID' => $snapshot['deliveryID'], 'state' => 'accepted', 'revision' => 0)); }
  catch (Exception $ex) { $failed = true; }
  if (!$failed) { throw new Exception('Malformed terminal acknowledgment was accepted.'); }
  $env->overrideEnvConfig('gorge.conduit.token', 'mail-internal-contract');
  $_SERVER['HTTP_X_SERVICE_TOKEN'] = 'wrong-token';
  $denied = false;
  try {
    id(new ConduitCall('mail.delivery', array('mailID' => (int)$mail->getID(), 'phase' => 'prepare')))->execute();
  } catch (ConduitException $ex) {
    $denied = ($ex->getMessage() === 'ERR-MAIL-AUTH');
  }
  if (!$denied) { throw new Exception('Mail domain endpoint bypassed service authentication.'); }
  $patch_dir = dirname(__FILE__).'/../../../resources/sql/autopatches/';
  foreach (array('20261006.metamta.02.gorgedelivery.sql', '20261006.metamta.03.gorgerecovery.sql') as $patch) {
    $ddl = str_replace('{$NAMESPACE}_metamta.', '', file_get_contents($patch_dir.$patch));
    foreach (explode(';', $ddl) as $statement) {
      if (trim($statement) !== '') { $admin->query($statement); }
    }
    if ($patch === '20261006.metamta.02.gorgedelivery.sql') {
      $admin->query("INSERT INTO gorge_mail_delivery(deliveryID,payloadHash,payload,state,revision,attempt,nextAttempt,startedEpoch,result) VALUES ('upgrade-fixture','hash','{\"deadline\":42}','prepared',0,0,0,0,'{}')");
    }
  }
  $upgraded = $admin->query("SELECT deadline,projectionNextAttempt,projectionAttempts FROM gorge_mail_delivery WHERE deliveryID='upgrade-fixture'")->fetch_assoc();
  if ((int)$upgraded['deadline'] !== 42 || (int)$upgraded['projectionNextAttempt'] !== 0) {
    throw new Exception('Existing ledger deadline migration failed.');
  }
  $admin->query('DROP TABLE metamta_gorgeoutbox');
  $failed = false;
  try { id(new PhabricatorMetaMTAMail())->setMessageType('email')->save(); }
  catch (Throwable $ex) { $failed = true; }
  $count = $admin->query('SELECT COUNT(*) AS n FROM metamta_mail')->fetch_assoc();
  if (!$failed || (int)$count['n'] !== 1) { throw new Exception('Mail escaped failed outbox transaction.'); }
  $env->overrideEnvConfig('gorge.integrations', array('sms'=>array('native-sms'=>'target')));
  $sms_mail = id(new PhabricatorMetaMTAMail())->setMessageType('sms');
  // Persist the fixture without scheduling the intentionally unavailable queue.
  $storage_save = new ReflectionMethod('PhabricatorLiskDAO', 'save');
  $storage_save->invoke($sms_mail);
  $sms_message = id(new PhabricatorMailSMSMessage())
    ->setToNumber(new PhabricatorPhoneNumber('+15550000001'))->setTextBody('first body');
  $sms_mail->prepareGorgeSMSMessage($sms_message, 'native-sms');
  $retry = id(new PhabricatorMailSMSMessage())
    ->setToNumber(new PhabricatorPhoneNumber('+15550000002'))->setTextBody('changed body');
  $reloaded = id(new PhabricatorMetaMTAMail())->load($sms_mail->getID());
  $reloaded->prepareGorgeSMSMessage($retry, 'native-sms');
  if ($retry->getToNumber()->toE164() !== '+15550000001' ||
      $retry->getTextBody() !== 'first body' ||
      $retry->getGorgeDeliveryID() !== (string)$sms_mail->getID()) {
    throw new Exception('SMS retry changed destination, content or identity.');
  }
  $wrong_adapter = id(new PhabricatorMailTwilioAdapter())->setKey('different-provider');
  $blocked = false;
  try { $reloaded->sendWithMailers(array($wrong_adapter)); }
  catch (PhabricatorMetaMTAPermanentFailureException $ex) { $blocked = true; }
  if (!$blocked) { throw new Exception('Prepared SMS fell back to another provider.'); }
  $env->overrideEnvConfig('gorge.integrations', array());
  $blocked = false;
  try { $reloaded->sendWithMailers(array(id(new PhabricatorMailTwilioAdapter())->setKey('native-sms'))); }
  catch (PhabricatorMetaMTAPermanentFailureException $ex) { $blocked = true; }
  if (!$blocked) { throw new Exception('Disabled native SMS reverted to a legacy send.'); }
  echo "Mail ownership, projection and outbox rollback contracts passed.\n";
} finally {
  $admin->query('DROP DATABASE `'.$name.'`');
}
