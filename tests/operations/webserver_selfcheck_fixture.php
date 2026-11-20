<?php
// Isolated control-flow fixture: no application bootstrap, DB or network.
function id($value) { return $value; }
function pht($value, ...$args) { return $value; }
class FixtureIssue {
  public function __call($name, $args) { return $this; }
}
abstract class PhabricatorSetupCheck {
  const GROUP_OTHER = 'other';
  public $issues = array();
  public function runSetupChecks() { $this->executeChecks(); }
  protected function newIssue($key) {
    $this->issues[] = $key;
    return new FixtureIssue();
  }
}
class PhabricatorEnv {
  public static function getEnvConfig($key) { return 'https://example.invalid/'; }
}
class PhabricatorUser {
  public static function getOmnipotentUser() { return new self(); }
}
class PhabricatorAuthProviderConfigQuery {
  public function setViewer($viewer) { return $this; }
  public function execute() { return $GLOBALS['configs']; }
}
class FixtureConfig {
  public function __construct(private $provider, private $enabled) {}
  public function getProviderClass() { return $this->provider; }
  public function getIsEnabled() { return $this->enabled; }
}
class PhutilURI {
  public function __construct($uri) {}
  public function setPath($path) { return $this; }
  public function replaceQueryParam($key, $value) { return $this; }
}
class HTTPSFuture {
  public function __construct($uri) {
    if ($uri instanceof PhutilURI) {
      $GLOBALS['anonymous_requests']++;
      throw new RuntimeException('native-check-reached');
    }
    $GLOBALS['metadata_requests']++;
  }
  public function setTimeout($timeout) { return $this; }
  public function resolvex() { return array(''); }
}
class FutureIterator extends ArrayIterator {}

require dirname(__DIR__, 2).
  '/src/applications/config/check/PhabricatorWebServerSetupCheck.php';
$passed = 0;
foreach (array('proxy', 'mixed', 'disabled', 'password', 'spoofed-header') as $case) {
  $_SERVER = array('HTTP_X_MOD_PAGESPEED' => 'fixture');
  if ($case === 'spoofed-header') {
    $_SERVER['HTTP_X_SETUP_SELFCHECK'] = '1';
    $_SERVER['HTTP_X_AUTH_USER'] = 'synthetic';
  }
  $configs = array();
  if (in_array($case, array('proxy', 'mixed', 'disabled'), true)) {
    $configs[] = new FixtureConfig('PhabricatorTraefikAuthProvider', $case !== 'disabled');
  }
  if (in_array($case, array('mixed', 'password'), true)) {
    $configs[] = new FixtureConfig('PhabricatorPasswordAuthProvider', true);
  }
  $anonymous_requests = $metadata_requests = 0;
  $check = new PhabricatorWebServerSetupCheck();
  $native = false;
  try {
    $check->runSetupChecks();
  } catch (RuntimeException $ex) {
    if ($ex->getMessage() !== 'native-check-reached') { throw $ex; }
    $native = true;
  }
  $skip = in_array($case, array('proxy', 'mixed'), true);
  if ($native === $skip || $anonymous_requests !== ($skip ? 0 : 1) ||
      ($skip && $metadata_requests !== 1) ||
      $check->issues !== array('webserver.pagespeed')) {
    throw new RuntimeException('fixture-control-flow-failed');
  }
  $passed++;
}
echo json_encode(array('passed_cases' => $passed, 'network_used' => false))."\n";
