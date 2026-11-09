<?php

/** Fenced native database configuration, not a replacement permission engine. */
final class PhorgeProjectExecutionConfigSource extends Phobject {
  private $connection;
  private $digest;
  private $applicationPolicy;
  private $epochs;
  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('project_execution_configuration_serialization_refused'); }
  public function __unserialize(array $value) { throw new RuntimeException('project_execution_configuration_serialization_refused'); }
  public function __debugInfo() { return array('scope'=>'fenced-native-configuration'); }
  private static function keys() { return PhorgeProjectExecutionCatalog::configurationKeys(); }
  /** Native nullable-string default, bounded before opening a new connection. */
  public static function normalizePort($value) {
    if ($value === null || $value === '') { return 3306; }
    $text=is_int($value)?(string)$value:$value;
    if (!is_string($text) || !preg_match('/\A[1-9][0-9]{0,4}\z/',$text) ||
        (int)$text>65535) {
      throw new RuntimeException('project_execution_configuration_control_type_refused');
    }
    return (int)$text;
  }
  /** Pure shape validation; production values always come from the native stack. */
  public static function validateControlValues(array $values) {
    $booleans=array('governance.project-approval.enabled','governance.project-content-grants.enabled','governance.organization.enabled','policy.allow-public',
      'phabricator.show-prototypes','security.require-multi-factor-auth');
    $arrays=array('policy.locked','phabricator.application-settings',
      'phabricator.uninstalled-applications','load-libraries','cluster.databases');
    $strings=array('mysql.host','mysql.user','mysql.pass','storage.default-namespace');
    foreach ($booleans as $key) {
      if (!array_key_exists($key,$values) || !is_bool($values[$key])) { throw new RuntimeException('project_execution_configuration_control_type_refused'); }
    }
    foreach ($arrays as $key) {
      if (!array_key_exists($key,$values) || !is_array($values[$key])) { throw new RuntimeException('project_execution_configuration_control_type_refused'); }
    }
    foreach ($strings as $key) {
      if (!array_key_exists($key,$values) || !is_string($values[$key])) { throw new RuntimeException('project_execution_configuration_control_type_refused'); }
    }
    if (!array_key_exists('mysql.port',$values)) { throw new RuntimeException('project_execution_configuration_control_type_refused'); }
    self::normalizePort($values['mysql.port']);
    if (
        !in_array($values['phorge.product-profile']??null,array(PhorgeProductProfile::PROFILE_FULL,PhorgeProductProfile::PROFILE_COLLABORATION),true) ||
        !array_key_exists('gorge.db.uri',$values) || ($values['gorge.db.uri']!==null && !is_string($values['gorge.db.uri']))) {
      throw new RuntimeException('project_execution_configuration_control_type_refused');
    }
  }
  private static function effective() {
    $stack=(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->getValue();
    if (!$stack || get_class($stack)!==PhabricatorConfigStackSource::class) {
      throw new RuntimeException('project_execution_configuration_stack_unsupported');
    }
    $sources=$stack->getStack();
    if (!$sources || count($sources)>16) { throw new RuntimeException('project_execution_configuration_stack_unsupported'); }
    $database=null;
    foreach ($sources as $source) {
      if (!in_array(get_class($source),array(PhabricatorConfigDefaultSource::class,
          PhabricatorConfigDictionarySource::class,PhabricatorConfigDatabaseSource::class,
          PhabricatorConfigLocalSource::class,PhabricatorConfigFileSource::class,
          PhabricatorDeploymentConfigSource::class),true)) {
        throw new RuntimeException('project_execution_configuration_stack_unsupported');
      }
      if (get_class($source)===PhabricatorConfigDatabaseSource::class) {
        if ($database!==null) { throw new RuntimeException('project_execution_configuration_stack_unsupported'); }
        $database=$source->getKeys(self::keys());
      }
    }
    $actual=$stack->getKeys(self::keys()); $effective=array();
    foreach (self::keys() as $key) {
      if (!array_key_exists($key,$actual)) { throw new RuntimeException('project_execution_configuration_unknown'); }
      $value=PhabricatorEnv::getEnvConfig($key);
      if (PhorgeProjectExecutionValue::canonical($value)!==PhorgeProjectExecutionValue::canonical($actual[$key])) {
        throw new RuntimeException('project_execution_configuration_cache_stale');
      }
      $effective[$key]=$value;
    }
    self::validateControlValues($effective);
    if ($effective['cluster.databases']!==array() || $effective['load-libraries']!==array() ||
        !in_array($effective['gorge.db.uri'],array(null,''),true)) {
      throw new RuntimeException('project_execution_configuration_profile_unsupported');
    }
    return array($effective,$database);
  }
  public static function capture(AphrontDatabaseConnection $conn) {
    if (!PhorgeProjectNativeExecutionScope::isSourceConnection($conn)) {
      throw new RuntimeException('project_execution_configuration_scope_required');
    }
    list($effective,$cached_database)=self::effective();
    $table=new PhabricatorConfigEntry();
    $schema=PhorgeProjectNativeExecutionScope::sourceDatabase($conn,$table);
    // Equality/IN range locks also fence missing keys. Use the native
    // collation for lookup, then reject non-byte-exact aliases explicitly.
    $rows=queryfx_all($conn,'SELECT namespace,configKey,isDeleted,CASE WHEN OCTET_LENGTH(value)<=32768 THEN value ELSE NULL END value FROM %R WHERE namespace=%s AND configKey IN (%Ls) ORDER BY configKey LIMIT %d LOCK IN SHARE MODE',
      $table,'default',self::keys(),count(self::keys())+1);
    $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$schema,$table->getTableName());
    if (!$engine || $engine['ENGINE']!=='InnoDB' || count($rows)>count(self::keys())) {
      throw new RuntimeException('project_execution_configuration_source_unsupported');
    }
    $database=array(); $bytes=0;
    foreach ($rows as $row) {
      if ($row['namespace']!=='default' || !in_array($row['configKey'],self::keys(),true) ||
          !in_array($row['isDeleted'],array('0','1',0,1),true) || !is_string($row['value']) ||
          ($bytes+=strlen($row['value']))>131072) {
        throw new RuntimeException('project_execution_configuration_source_unsupported');
      }
      $stored=(new PhabricatorConfigEntry())->loadFromArray($row);
      if (!$stored->getIsDeleted()) { $database[$row['configKey']]=$stored->getValue(); }
    }
    if ($cached_database===null) {
      if ($database!==array()) { throw new RuntimeException('project_execution_configuration_database_not_loaded'); }
    } else if (PhorgeProjectExecutionValue::canonical($cached_database)!==PhorgeProjectExecutionValue::canonical($database)) {
      throw new RuntimeException('project_execution_configuration_database_stale');
    }
    // Database values must actually be effective, not hidden behind a new
    // higher-priority test/dynamic source which silently drops a denial.
    foreach ($database as $key=>$value) {
      if (PhorgeProjectExecutionValue::canonical($value)!==PhorgeProjectExecutionValue::canonical($effective[$key])) {
        throw new RuntimeException('project_execution_configuration_precedence_unsupported');
      }
    }
    $source=new self(); $source->connection=$conn;
    $app=new PhabricatorProjectApplication();
    $source->applicationPolicy=PhorgeProjectExecutionCatalog::policy($app->getPolicy(PhabricatorPolicyCapability::CAN_VIEW));
    if (!$app->isInstalled()) { throw new RuntimeException('project_execution_application_unavailable'); }
    $source->epochs=PhorgeProjectConfigEpochScope::capture($conn);
    $source->digest=PhorgeProjectExecutionValue::digest(array('effective'=>$effective,'epochs'=>$source->epochs));
    return $source;
  }
  public function getDigest() { return $this->digest; }
  public function assertCurrent() {
    if (!PhorgeProjectNativeExecutionScope::isSourceConnection($this->connection)) {
      throw new RuntimeException('project_execution_configuration_scope_required');
    }
    list($effective,$database)=self::effective();
    if (!hash_equals($this->digest,PhorgeProjectExecutionValue::digest(array('effective'=>$effective,'epochs'=>$this->epochs)))) {
      throw new RuntimeException('project_execution_configuration_changed');
    }
    if (PhorgeProjectConfigEpochScope::capture($this->connection)!==$this->epochs) {
      throw new RuntimeException('project_execution_configuration_generation_changed');
    }
    $app=new PhabricatorProjectApplication();
    if (!$app->isInstalled() || $app->getPolicy(PhabricatorPolicyCapability::CAN_VIEW)!==$this->applicationPolicy) {
      throw new RuntimeException('project_execution_application_changed');
    }
  }
  public function requireApplicationView(PhabricatorUser $user) {
    $this->assertCurrent();
    if (!PhabricatorPolicyFilter::hasCapability($user,new PhabricatorProjectApplication(),PhabricatorPolicyCapability::CAN_VIEW)) {
      throw new RuntimeException('project_execution_native_application_view_required');
    }
  }
}
