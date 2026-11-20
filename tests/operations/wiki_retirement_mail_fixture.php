<?php
// No application bootstrap, credentials, database, network or message delivery.
class Phobject {}
class PhabricatorEnv {
  public static $enabled = false;
  public static function getEnvConfig($key) { return self::$enabled; }
}
class PhabricatorMailEmailMessage { const MESSAGETYPE = 'email'; }
class PhabricatorMetaMTAMail {
  public function __construct(private $producer, private $related) {}
  public function getPrivateMailPolicyValue($key) { return $this->producer; }
  public function getRelatedPHID() { return $this->related; }
  public function getMessageType() { return 'email'; }
}
require dirname(__DIR__, 2).
  '/src/infrastructure/governance/notification/PhorgePrivateMailPolicy.php';
$count = 0;
foreach (array(false, true) as $enabled) {
  PhabricatorEnv::$enabled = $enabled;
  foreach (array(
    array('PhrictionTransactionEditor', null),
    array(null, 'PHID-WIKI-aaaaaaaaaaaaaaaaaaaa'),
    array(null, 'PHID-WRDS-aaaaaaaaaaaaaaaaaaaa'),
  ) as $case) {
    if (PhorgePrivateMailPolicy::authorize(new PhabricatorMetaMTAMail(...$case))) {
      throw new RuntimeException('Retired notification was accepted.');
    }
    $count++;
  }
}
PhabricatorEnv::$enabled = false;
if (!PhorgePrivateMailPolicy::authorize(
  new PhabricatorMetaMTAMail('ManiphestTransactionEditor',
    'PHID-TASK-aaaaaaaaaaaaaaaaaaaa'))) {
  throw new RuntimeException('Native task behavior changed.');
}
echo json_encode(array('passed' => $count + 1, 'network_used' => false))."\n";
