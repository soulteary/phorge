<?php

/**
 * Fixed SELECT-only producer fence, held across real native postcommit work.
 * The MySQL transaction is READ WRITE because READ ONLY forbids locking reads.
 * No raw connection escapes, and this connection never writes business rows.
 */
final class PhorgeProjectExecutionProducerFence extends Phobject {
  private $connection;
  private $closed=false;
  private $cleanupFailed=false;
  private $receipt;
  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('project_execution_fence_serialization_refused'); }
  public function __unserialize(array $value) { throw new RuntimeException('project_execution_fence_serialization_refused'); }
  public function __debugInfo() { return array('scope'=>'fixed-native-producer-fence'); }

  public static function acquire(AphrontDatabaseConnection $project_writer) {
    if (get_class($project_writer)!==AphrontMySQLiDatabaseConnection::class ||
        !$project_writer->isInsideTransaction() ||
        $project_writer!==(new PhabricatorProject())->establishConnection('w') ||
        PhabricatorEnv::getEnvConfig('cluster.databases')) {
      throw new RuntimeException('project_execution_fence_profile_unsupported');
    }
    $route=array();
    foreach (array('mysql.host','mysql.port','mysql.user','mysql.pass') as $key) {
      $route[$key]=PhabricatorEnv::getEnvConfig($key);
    }
    if (!is_string($route['mysql.host']) || $route['mysql.host']==='' ||
        !is_string($route['mysql.user']) || !is_string($route['mysql.pass'])) {
      throw new RuntimeException('project_execution_fence_profile_unsupported');
    }
    $route['mysql.port']=PhorgeProjectExecutionConfigSource::normalizePort($route['mysql.port']);
    $project_identity=queryfx_one($project_writer,'SELECT @@server_uuid uuid,VERSION() version');
    $herald_writer=(new HeraldRule())->establishConnection('w');
    $herald_identity=queryfx_one($herald_writer,'SELECT @@server_uuid uuid,DATABASE() db');
    if (!$project_identity || !$herald_identity ||
        $project_identity['uuid']!==$herald_identity['uuid'] ||
        stripos($project_identity['version'],'MariaDB')!==false ||
        !preg_match('/\A8\./',$project_identity['version']) ||
        !is_string($herald_identity['db']) || !preg_match('/\A[a-zA-Z0-9_]+\z/',$herald_identity['db'])) {
      throw new RuntimeException('project_execution_fence_profile_unsupported');
    }
    $fence=new self(); $mode=(new mysqli_driver())->report_mode;
    try {
      mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
      $connection=mysqli_init(); $fence->connection=$connection;
      $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT,5);
      $connection->options(MYSQLI_OPT_READ_TIMEOUT,5);
      $connection->real_connect($route['mysql.host'],$route['mysql.user'],$route['mysql.pass'],
        $herald_identity['db'],$route['mysql.port']);
      $connection->set_charset('utf8mb4');
      $actual=$connection->query('SELECT @@server_uuid uuid,DATABASE() db')->fetch_assoc();
      if ($actual!==$herald_identity) { throw new RuntimeException('project_execution_fence_primary_changed'); }
      $connection->query('SET SESSION innodb_lock_wait_timeout=5');
      $connection->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
      $connection->begin_transaction(MYSQLI_TRANS_START_READ_WRITE);
      $rules=$connection->query('SELECT id FROM herald_rule FORCE INDEX (PRIMARY) WHERE id>=0 LIMIT 1 LOCK IN SHARE MODE')->fetch_all(MYSQLI_ASSOC);
      $hooks=$connection->query('SELECT id,status FROM herald_webhook FORCE INDEX (PRIMARY) WHERE id>=0 LIMIT 65 LOCK IN SHARE MODE')->fetch_all(MYSQLI_ASSOC);
      // Each current locking read retains MDL; engine changes cannot follow it.
      $statement=$connection->prepare('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN (\'herald_rule\',\'herald_webhook\')');
      $statement->execute(array($herald_identity['db']));
      $engines=$statement->get_result()->fetch_all(MYSQLI_ASSOC); $statement->close();
      if (count($engines)!==2) { throw new RuntimeException('project_execution_fence_schema_unsupported'); }
      foreach ($engines as $engine) {
        if ($engine['ENGINE']!=='InnoDB') { throw new RuntimeException('project_execution_fence_schema_unsupported'); }
      }
      if ($rules || count($hooks)>64) { throw new RuntimeException('project_execution_fence_producers_unsupported'); }
      foreach ($hooks as $hook) {
        if (!in_array($hook['status'],array(HeraldWebhook::HOOKSTATUS_ENABLED,
          HeraldWebhook::HOOKSTATUS_DISABLED),true)) {
          throw new RuntimeException('project_execution_fence_producers_unsupported');
        }
      }
      $fence->receipt=array('actual_primary_verified'=>true,'native_rule_count'=>0,
        'nonfirehose_webhook_count'=>count($hooks),'transaction_access_mode'=>'READ WRITE',
        'sql_operations'=>'fixed locking SELECT only','primary_range_fences'=>array('herald_rule','herald_webhook'),
        'whole_native_pipeline_atomic'=>false);
      return $fence;
    } catch (Throwable $error) {
      try { $fence->release(); } catch (Throwable $cleanup) {}
      if (get_class($error)===RuntimeException::class &&
          preg_match('/\Aproject_execution_fence_[a-z_]+\z/',$error->getMessage())) { throw $error; }
      throw new RuntimeException('project_execution_fence_unavailable');
    } finally { mysqli_report($mode); }
  }

  public function getReceipt() {
    if ($this->closed) { throw new RuntimeException('project_execution_fence_ended'); }
    return $this->receipt;
  }

  public function release() {
    if ($this->closed) {
      if ($this->cleanupFailed) { throw new RuntimeException('project_execution_fence_cleanup_failed'); }
      return;
    }
    $this->closed=true; $failed=false;
    try { if ($this->connection!==null) { $this->connection->rollback(); } }
    catch (Throwable $error) { $failed=true; }
    try { if ($this->connection!==null) { $this->connection->close(); } }
    catch (Throwable $error) { $failed=true; }
    $this->cleanupFailed=$failed;
    if ($failed) { throw new RuntimeException('project_execution_fence_cleanup_failed'); }
  }
  public function __destruct() { try { $this->release(); } catch (Throwable $ignored) {} }
}
