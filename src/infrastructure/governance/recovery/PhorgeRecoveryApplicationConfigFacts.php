<?php

/** Selected process-local configuration facts. Not an immutable deployment lease. */
final class PhorgeRecoveryApplicationConfigFacts extends Phobject {
  private $key;
  private $binding;
  private $summary;
  private $reviewDigest;
  private $owners;
  private $finished = false;

  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('recovery_config_facts_serialization_refused'); }
  public function __unserialize(array $data) { throw new RuntimeException('recovery_config_facts_serialization_refused'); }
  public function __debugInfo() { return array('scope'=>'partial-source-dictionary-facts'); }

  /** Fixed selected dependencies, not a claim that these exhaust application configuration. */
  public static function selectedOptions() {
    return array('auth.require-email-verification'=>'bool','auth.require-approval'=>'bool',
      'auth.email-domains'=>'string-list','auth.lock-config'=>'bool',
      'account.editable'=>'bool','account.minimum-password-length'=>'int',
      'security.require-https'=>'bool','security.require-multi-factor-auth'=>'bool',
      'governance.account-recovery.enabled'=>'bool',
      'governance.account-recovery.incarnation'=>'nullable-string');
  }

  private static function readProperty($class,$name,$object=null) {
    return (new ReflectionProperty($class,$name))->getValue($object);
  }

  /** Inspect only already-created, exact native classes; call no source getters. */
  private static function snapshot() {
    $stack=self::readProperty(PhabricatorEnv::class,'sourceStack');
    if (!is_object($stack)||get_class($stack)!==PhabricatorConfigStackSource::class) {
      throw new RuntimeException('recovery_config_facts_stack_unknown');
    }
    $sources=self::readProperty(PhabricatorConfigStackSource::class,'stack',$stack);
    if (!is_array($sources)||!array_is_list($sources)||!count($sources)||count($sources)>8) {
      throw new RuntimeException('recovery_config_facts_stack_unknown');
    }
    $rows=array();$effective=array();$bytes=0;$selected=self::selectedOptions();$default=0;
    foreach ($sources as $source) {
      if (!is_object($source)) { throw new RuntimeException('recovery_config_facts_source_unknown'); }
      $class=get_class($source);
      if ($class===PhabricatorConfigDictionarySource::class) { $dictionary=$source; }
      else if (in_array($class,array(PhabricatorConfigDefaultSource::class,
          PhabricatorConfigLocalSource::class,PhabricatorConfigDatabaseSource::class),true)) {
        $dictionary=self::readProperty(PhabricatorConfigProxySource::class,'source',$source);
        if (!is_object($dictionary)||get_class($dictionary)!==PhabricatorConfigDictionarySource::class) {
          throw new RuntimeException('recovery_config_facts_proxy_unknown');
        }
      } else {
        // FileSource executes PHP configuration. Unknown extensions are not invoked.
        throw new RuntimeException('recovery_config_facts_source_unknown');
      }
      if ($class===PhabricatorConfigDefaultSource::class) { $default++; }
      $values=self::readProperty(PhabricatorConfigDictionarySource::class,'dictionary',$dictionary);
      if (!is_array($values)||count($values)>2048) { throw new RuntimeException('recovery_config_facts_budget'); }
      foreach ($values as $name=>$value) {
        if (!is_string($name)||!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,191}\z/D',$name)) {
          throw new RuntimeException('recovery_config_facts_key_unknown');
        }
        self::boundedValue($value,0,$bytes);
        if (array_key_exists($name,$selected)) { self::optionType($selected[$name],$value); }
      }
      // Native stack uses array union: first source wins, even for NULL.
      $effective+=$values;
      $rows[]=array('class'=>$class,'object'=>$source,'dictionary'=>$dictionary,'values'=>$values);
    }
    if ($default!==1||get_class(end($sources))!==PhabricatorConfigDefaultSource::class) {
      throw new RuntimeException('recovery_config_facts_default_unknown');
    }
    $resolved=array();$missing=0;
    foreach ($selected as $name=>$type) {
      $present=array_key_exists($name,$effective);
      if (!$present) { $missing++; }
      $resolved[$name]=array('present'=>$present,'value'=>$present?$effective[$name]:null);
    }
    // Missing dependencies are refused, never filled with guessed defaults.
    if ($missing) { throw new RuntimeException('recovery_config_facts_dependency_missing'); }
    return array($stack,$rows,$resolved,$bytes);
  }

  private static function boundedValue($value,$depth,&$bytes) {
    if ($depth>8) { throw new RuntimeException('recovery_config_facts_budget'); }
    if (is_array($value)) {
      if (count($value)>2048) { throw new RuntimeException('recovery_config_facts_budget'); }
      foreach ($value as $key=>$item) {
        if (!is_int($key)&&!is_string($key)) { throw new RuntimeException('recovery_config_facts_value_unknown'); }
        $bytes+=strlen((string)$key);self::boundedValue($item,$depth+1,$bytes);
      }
    } else if (is_string($value)) { $bytes+=strlen($value); }
    else if (is_int($value)||is_bool($value)||$value===null) { $bytes+=16; }
    else { throw new RuntimeException('recovery_config_facts_value_unknown'); }
    if ($bytes>1048576) { throw new RuntimeException('recovery_config_facts_budget'); }
  }

  private static function optionType($type,$value) {
    $valid=($type==='bool'&&is_bool($value))||($type==='int'&&is_int($value)&&$value>=0)||
      ($type==='nullable-string'&&($value===null||is_string($value)));
    if ($type==='string-list'&&is_array($value)&&array_is_list($value)) {
      $valid=true;foreach ($value as $item) { if (!is_string($item)) { $valid=false; } }
    }
    if (!$valid) { throw new RuntimeException('recovery_config_facts_type_unknown'); }
  }

  private function fingerprint(array $snapshot) {
    list($stack,$rows,$resolved)=$snapshot;$bound=array();
    foreach ($rows as $row) {
      $bound[]=array($row['class'],spl_object_id($row['object']),spl_object_id($row['dictionary']),$row['values']);
    }
    // Key is random per capture and is never exposed, including through debug/serialization.
    return hash_hmac('sha256',serialize(array(spl_object_id($stack),$bound,$resolved)),$this->key);
  }

  /** Stable selected values and source order only; no process object IDs or raw output. */
  private static function reviewDigest(array $snapshot) {
    $sources=array();
    foreach ($snapshot[1] as $row) {
      $sources[]=array('class'=>$row['class'],
        'selected_values'=>array_intersect_key($row['values'],self::selectedOptions()));
    }
    return hash('sha256',serialize(array('profile'=>'selected-config-review-v1',
      'sources'=>$sources,'resolved'=>$snapshot[2])));
  }

  public static function capture() {
    $snapshot=self::snapshot();$facts=new self();$facts->key=random_bytes(32);
    $facts->binding=$facts->fingerprint($snapshot);
    $facts->reviewDigest=self::reviewDigest($snapshot);
    // Retain actual objects: object IDs must not be recycled into an ABA match.
    $facts->owners=array($snapshot[0]);
    foreach ($snapshot[1] as $row) { $facts->owners[]=$row['object'];$facts->owners[]=$row['dictionary']; }
    $facts->summary=array('schema_version'=>1,'scope'=>'selected-source-dictionary-facts',
      'source_count'=>count($snapshot[1]),'selected_option_count'=>count(self::selectedOptions()),
      'reason'=>'source-provenance-and-leases-incomplete','complete'=>false,
      'native_Env_cache_bound'=>false,'SQL_row_or_absence_lease'=>false,'deployment_immutability_proved'=>false,
      'all_sources_complete'=>false,'restoration_authorized'=>false);
    return $facts;
  }

  public function finish() {
    if ($this->finished) { throw new RuntimeException('recovery_config_facts_finished'); }
    $this->finished=true;
    try {
      if (!hash_equals($this->binding,$this->fingerprint(self::snapshot()))) {
        throw new RuntimeException('recovery_config_facts_changed');
      }
      return $this->summary+array('source_digest'=>$this->reviewDigest);
    } finally { $this->binding=null;$this->key=null;$this->owners=null;$this->reviewDigest=null; }
  }
}
