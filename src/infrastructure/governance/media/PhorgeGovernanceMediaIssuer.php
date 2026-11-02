<?php

/** External media-only incarnation. Never rotates native application credentials. */
final class PhorgeGovernanceMediaIssuer extends Phobject {
  private $key;
  private $incarnation;

  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('media_issuer_serialization_refused'); }
  public function __unserialize(array $data) { throw new RuntimeException('media_issuer_serialization_refused'); }
  public function __debugInfo() { return array('scope'=>'external-media-only-issuer'); }

  public static function newFromConfiguration() {
    $names=array('governance.media-exchange.issuer-key','governance.media-exchange.incarnation');
    $stack=PhabricatorEnv::getConfigSourceStack();
    if (!$stack) { throw new RuntimeException('media_issuer_configuration_required'); }
    $values=array();
    foreach ($stack->getStack() as $source) {
      $provided=$source->getKeys($names);
      if ($source instanceof PhabricatorConfigDatabaseSource && $provided) {
        throw new RuntimeException('media_issuer_database_configuration_refused');
      }
      foreach ($provided as $name=>$value) {
        if (array_key_exists($name,$values)) { continue; }
        if ($value===null) {
          if (!($source instanceof PhabricatorConfigDefaultSource)) { $values[$name]=null; }
          continue;
        }
        $external=in_array(get_class($source),array(PhabricatorConfigLocalSource::class,
          PhabricatorConfigFileSource::class,PhabricatorDeploymentConfigSource::class),true);
        $isolated=PHP_SAPI==='cli' && getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')==='1' &&
          get_class($source)===PhabricatorConfigDictionarySource::class;
        if (!$external && !$isolated) { throw new RuntimeException('media_issuer_external_configuration_required'); }
        $values[$name]=$value;
      }
    }
    $key=$values[$names[0]]??null;
    $incarnation=$values[$names[1]]??null;
    if (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/D',$key) ||
        !is_string($incarnation) || !preg_match('/\A[a-f0-9]{32}\z/D',$incarnation)) {
      throw new RuntimeException('media_issuer_configuration_required');
    }
    $issuer=new self();
    $issuer->key=new PhutilOpaqueEnvelope(hex2bin($key));
    $issuer->incarnation=$incarnation;
    return $issuer;
  }

  public static function digestValue(PhutilOpaqueEnvelope $key, $incarnation, $purpose, $value) {
    $raw_key=$key->openEnvelope();
    if (!is_string($raw_key) || strlen($raw_key)!==32 ||
        !is_string($incarnation) || !preg_match('/\A[a-f0-9]{32}\z/D',$incarnation) ||
        !in_array($purpose,array('browser','transaction','code','credential','configuration','rate-client'),true) ||
        !is_string($value) || strlen($value)>8192) {
      throw new InvalidArgumentException('media_issuer_digest_input_refused');
    }
    return hash_hmac('sha256',"phorge-media:v2\0".$incarnation."\0".$purpose."\0".$value,$raw_key);
  }

  public function digest($purpose, $value) {
    return self::digestValue($this->key,$this->incarnation,$purpose,$value);
  }

  public function getIncarnation() { return $this->incarnation; }

  public function matchesRow(array $row) {
    return isset($row['issuerIncarnation']) && is_string($row['issuerIncarnation']) &&
      hash_equals($this->incarnation,$row['issuerIncarnation']);
  }

  public function assertCurrent() {
    $current=self::newFromConfiguration();
    if (!hash_equals($this->digest('configuration','current'),$current->digest('configuration','current'))) {
      throw new RuntimeException('media_issuer_changed');
    }
  }
}
