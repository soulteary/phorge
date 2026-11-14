<?php

/** Same native Config transaction/savepoint for all actual DAO producers. */
final class PhorgeProjectConfigEpochScope extends Phobject {
  private $connection;
  private $keys=array();
  private $state='new';
  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('configuration_generation_serialization_refused'); }
  public function __unserialize(array $value) { throw new RuntimeException('configuration_generation_serialization_refused'); }
  public function __debugInfo() { return array('scope'=>'native-config-generation'); }
  public static function canonicalEpoch($value) {
    $text=is_int($value)?(string)$value:$value;
    if (!is_string($text) || !preg_match('/\A[1-9][0-9]{0,19}\z/',$text) ||
        (strlen($text)===20 && strcmp($text,'18446744073709551615')>0)) {
      throw new RuntimeException('configuration_generation_unknown');
    }
    return $text;
  }
  private static function assertEngine(AphrontDatabaseConnection $conn,$table) {
    queryfx_one($conn,'SELECT %C FROM %R WHERE 1=0 LOCK IN SHARE MODE',
      $table instanceof PhorgeProjectConfigEpoch?'namespace':'id',$table);
    $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table->getTableName());
    if (!$engine || $engine['ENGINE']!=='InnoDB') { throw new RuntimeException('configuration_generation_transactional_schema_required'); }
  }
  /** Native unique constraints are the complete set of possible REPLACE victims. */
  public static function validateUniqueIndexProfile(array $rows) {
    if (!$rows || count($rows)>64) { throw new RuntimeException('configuration_generation_unique_profile_unsupported'); }
    $indexes=array();
    foreach ($rows as $row) {
      if (!isset($row['INDEX_NAME'],$row['NON_UNIQUE'],$row['SEQ_IN_INDEX']) ||
          !in_array($row['NON_UNIQUE'],array(0,1,'0','1'),true)) {
        throw new RuntimeException('configuration_generation_unique_profile_unsupported');
      }
      if ((string)$row['NON_UNIQUE']==='1') { continue; }
      if (!is_string($row['INDEX_NAME']) || !is_string($row['COLUMN_NAME']??null) ||
          !array_key_exists('SUB_PART',$row) || $row['SUB_PART']!==null ||
          (!is_int($row['SEQ_IN_INDEX']) && !is_string($row['SEQ_IN_INDEX'])) || !preg_match('/\A[1-9][0-9]?\z/',(string)$row['SEQ_IN_INDEX'])) {
        throw new RuntimeException('configuration_generation_unique_profile_unsupported');
      }
      $seq=(int)$row['SEQ_IN_INDEX'];
      if (isset($indexes[$row['INDEX_NAME']][$seq])) { throw new RuntimeException('configuration_generation_unique_profile_unsupported'); }
      $indexes[$row['INDEX_NAME']][$seq]=$row['COLUMN_NAME'];
    }
    $actual=array();
    foreach ($indexes as $columns) {
      ksort($columns,SORT_NUMERIC);
      if (array_keys($columns)!==range(1,count($columns))) { throw new RuntimeException('configuration_generation_unique_profile_unsupported'); }
      $actual[]=json_encode(array_values($columns),JSON_THROW_ON_ERROR);
    }
    sort($actual,SORT_STRING);
    $expected=array('["id"]','["phid"]','["namespace","configKey"]'); sort($expected,SORT_STRING);
    if ($actual!==$expected) { throw new RuntimeException('configuration_generation_unique_profile_unsupported'); }
  }
  /** Closed union for native config producers; Project capture remains unchanged. */
  public static function producerConfigurationKeys() { return PhorgeTaskContentGrantConfiguration::keys(); }
  /** Use actual native column collations, never ad-hoc lowercase/space trimming. */
  private static function trackedKeys(AphrontDatabaseConnection $conn,array $identity) {
    if (!is_string($identity['namespace']??null) || !is_string($identity['configKey']??null)) { return array(); }
    $columns=queryfx_all($conn,'SELECT COLUMN_NAME,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME IN (%Ls)',
      (new PhabricatorConfigEntry())->getTableName(),array('namespace','configKey'));
    $columns=ipull($columns,null,'COLUMN_NAME');
    if (count($columns)!==2) { throw new RuntimeException('configuration_generation_native_column_profile_required'); }
    $compare=function($column,$value,$expected) use ($conn,$columns) {
      $meta=$columns[$column];
      if ($meta['CHARACTER_SET_NAME']===null && $meta['COLLATION_NAME']===null) { return $value===$expected; }
      foreach (array($meta['CHARACTER_SET_NAME'],$meta['COLLATION_NAME']) as $name) {
        if (!is_string($name) || !preg_match('/\A[a-zA-Z0-9_]{1,64}\z/',$name)) {
          throw new RuntimeException('configuration_generation_native_column_profile_required');
        }
      }
      // These two raw identifiers come only from validated native metadata;
      // both user values remain ordinary quoted parameters.
      $row=queryfx_one($conn,'SELECT CONVERT(%s USING %Z) COLLATE %Z = CONVERT(%s USING %Z) COLLATE %Z AS matched',
        $value,$meta['CHARACTER_SET_NAME'],$meta['COLLATION_NAME'],
        $expected,$meta['CHARACTER_SET_NAME'],$meta['COLLATION_NAME']);
      return $row && (string)$row['matched']==='1';
    };
    if (!$compare('namespace',$identity['namespace'],'default')) { return array(); }
    $keys=array();
    foreach (self::producerConfigurationKeys() as $key) {
      if ($compare('configKey',$identity['configKey'],$key)) { $keys[]=$key; }
    }
    return $keys;
  }
  public static function begin(PhabricatorConfigEntry $entry,$operation) {
    if (get_class($entry)!==PhabricatorConfigEntry::class || !in_array($operation,array('insert','update','replace','delete'),true)) {
      throw new RuntimeException('configuration_generation_native_entry_required');
    }
    $deleting=$operation==='delete';
    $conn=$entry->establishConnection('w');
    if (get_class($conn)!==AphrontMySQLiDatabaseConnection::class ||
        $conn!==(new PhabricatorConfigEntry())->establishConnection('w') ||
        $conn!==(new PhorgeProjectConfigEpoch())->establishConnection('w') ||
        $entry->getAphrontRefDatabaseName()!==(new PhabricatorConfigEntry())->getAphrontRefDatabaseName()) {
      throw new RuntimeException('configuration_generation_native_writer_required');
    }
    $scope=new self(); $scope->connection=$conn;
    $conn->openTransaction(); $scope->state='open';
    try {
      $old=$entry->getID()?queryfx_one($conn,'SELECT id,namespace,configKey FROM %R WHERE id=%d FOR UPDATE',
        new PhabricatorConfigEntry(),$entry->getID()):null;
      $by_phid=$deleting || !$entry->getPHID()?null:queryfx_one($conn,
        'SELECT id,namespace,configKey,phid FROM %R WHERE phid=%s FOR UPDATE',new PhabricatorConfigEntry(),$entry->getPHID());
      if ($by_phid && $by_phid['phid']!==$entry->getPHID()) { throw new RuntimeException('configuration_generation_identity_alias'); }
      if ($operation==='replace') { self::validateUniqueIndexProfile(queryfx_all($conn,
        'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY INDEX_NAME,SEQ_IN_INDEX LIMIT 65',
        $entry->getTableName())); }
      $tuple=$deleting?null:queryfx_one($conn,'SELECT id,namespace,configKey FROM %R WHERE namespace=%s AND configKey=%s FOR UPDATE',
        new PhabricatorConfigEntry(),$entry->getNamespace(),$entry->getConfigKey());
      $keys=array();
      foreach (array($old,$by_phid,$tuple,$deleting?null:array('namespace'=>$entry->getNamespace(),'configKey'=>$entry->getConfigKey())) as $identity) {
        if ($identity!==null) { $keys=array_merge($keys,self::trackedKeys($conn,$identity)); }
      }
      $keys=array_values(array_unique($keys)); sort($keys,SORT_STRING); $scope->keys=$keys;
      if ($keys) {
        self::assertEngine($conn,new PhabricatorConfigEntry());
        self::assertEngine($conn,new PhorgeProjectConfigEpoch());
        // Acquire native entry/gap locks before generation locks, just as the
        // Project capture does. No authorization, policy or Feed is changed.
        $rows=queryfx_all($conn,'SELECT namespace,configKey FROM %R WHERE namespace=%s AND configKey IN (%Ls) ORDER BY configKey FOR UPDATE',
          new PhabricatorConfigEntry(),'default',$keys);
        foreach ($rows as $row) {
          if (!array_intersect(self::trackedKeys($conn,$row),$keys)) {
            throw new RuntimeException('configuration_generation_identity_alias');
          }
        }
        $generations=queryfx_all($conn,'SELECT namespace,configKey,epoch FROM %R WHERE namespace=%s AND configKey IN (%Ls) ORDER BY configKey LIMIT %d FOR UPDATE',
          new PhorgeProjectConfigEpoch(),'default',$keys,count($keys)+1);
        if (count($generations)!==count($keys)) { throw new RuntimeException('configuration_generation_schema_required'); }
        foreach ($generations as $row) {
          if ($row['namespace']!=='default' || !in_array($row['configKey'],$keys,true)) { throw new RuntimeException('configuration_generation_identity_alias'); }
          self::canonicalEpoch($row['epoch']);
        }
      }
      return $scope;
    } catch (Throwable $error) { $scope->rollback(); throw $error; }
  }
  public function finish() {
    if ($this->state!=='open' || !$this->connection->isInsideTransaction()) { throw new RuntimeException('configuration_generation_scope_required'); }
    if ($this->keys) {
      queryfx($this->connection,'UPDATE %R SET epoch=epoch+1 WHERE namespace=%s AND configKey IN (%Ls) AND epoch<18446744073709551615',
        new PhorgeProjectConfigEpoch(),'default',$this->keys);
      if ($this->connection->getAffectedRows()!==count($this->keys)) { throw new RuntimeException('configuration_generation_overflow_or_missing'); }
    }
    $this->connection->saveTransaction(); $this->state='committed';
  }
  public function rollback() {
    if ($this->state!=='open') { return; }
    try { if ($this->connection->isInsideTransaction()) { $this->connection->killTransaction(); } } finally { $this->state='closed'; }
  }
  /** Capture existing mandatory generations; absence is never version zero. */
  public static function capture(AphrontDatabaseConnection $conn) {
    if (!PhorgeProjectNativeExecutionScope::isSourceConnection($conn)) { throw new RuntimeException('project_execution_configuration_scope_required'); }
    $table=new PhorgeProjectConfigEpoch();
    $schema=PhorgeProjectNativeExecutionScope::sourceDatabase($conn,$table);
    $rows=queryfx_all($conn,'SELECT namespace,configKey,epoch FROM %R WHERE namespace=%s AND configKey IN (%Ls) ORDER BY configKey LIMIT %d LOCK IN SHARE MODE',
      $table,'default',PhorgeProjectExecutionCatalog::configurationKeys(),count(PhorgeProjectExecutionCatalog::configurationKeys())+1);
    $engine=queryfx_one($conn,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$schema,$table->getTableName());
    if (!$engine || $engine['ENGINE']!=='InnoDB' || count($rows)!==count(PhorgeProjectExecutionCatalog::configurationKeys())) {
      throw new RuntimeException('configuration_generation_schema_required');
    }
    $epochs=array();
    foreach ($rows as $row) {
      if ($row['namespace']!=='default' || !in_array($row['configKey'],PhorgeProjectExecutionCatalog::configurationKeys(),true)) {
        throw new RuntimeException('configuration_generation_identity_alias');
      }
      $epochs[$row['configKey']]=self::canonicalEpoch($row['epoch']);
    }
    ksort($epochs,SORT_STRING); return $epochs;
  }
  public function __destruct() { try { $this->rollback(); } catch (Throwable $ignored) {} }
}
