<?php

require_once dirname(__DIR__).'/fixtures/PhorgeGovernanceNativeFixture.php';

/** Disposable full-native source fixture. Control credentials never escape. */
final class PhorgeGovernanceCompletionFixture {
  private $control;
  private $base;
  private $root;
  private $host;
  private $port;
  private $namespace;
  private $createdDatabases = array();
  private $createdUsers = array();
  private $tables = array();
  private $connections = array();
  private $configs = array();
  private $baseline;
  private $sourceHash;
  private $patchAudit;
  private $ownerNonce;
  private $auxiliarySetupThreads = array();

  public function __construct(mysqli $control, $root, $host, $port) {
    if (PHP_SAPI !== 'cli' || getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1' ||
        $host !== '127.0.0.1' || !is_int($port) || $port < 1 || $port > 65535) {
      throw new LogicException('Owned disposable completion context required.');
    }
    $this->control = $control;
    $this->root = $root;
    $this->host = $host;
    $this->port = $port;
    $this->base = new PhorgeGovernanceNativeFixture($control, $host, $port);
  }

  public function create() {
    $path = $this->root.'/resources/sql/quickstart.sql';
    $source = file_get_contents($path);
    $this->sourceHash = hash('sha256', $source);
    $this->base->create($path);
    $this->base->createReader();
    $reader = $this->base->getReaderConfig();
    $this->namespace = $reader['namespace'];
    $this->configs['reader'] = $reader;
    $existing_apps = array('user','project','maniphest','phriction','policy','spaces','config');
    $existing_tables = array('user.user','user.phabricator_session','user.edge',
      'project.project','project.edge','maniphest.maniphest_task',
      'phriction.phriction_document','phriction.phriction_content',
      'policy.policy','policy.policy_namedpolicy','spaces.spaces_namespace','config.config_entry');
    $current = null;
    $databases = array();
    $charset = array('{$NAMESPACE}' => $this->namespace,
      '{$CHARSET}' => 'utf8mb4','{$CHARSET_SORT}' => 'utf8mb4',
      '{$CHARSET_FULLTEXT}' => 'utf8mb4','{$COLLATE_TEXT}' => 'utf8mb4_bin',
      '{$COLLATE_SORT}' => 'utf8mb4_unicode_ci','{$COLLATE_FULLTEXT}' => 'utf8mb4_unicode_ci');
    foreach (array_filter(preg_split('/;\s+/', $source)) as $statement) {
      $statement = trim($statement);
      if (preg_match('/\ACREATE DATABASE .*`\{\$NAMESPACE\}_([a-z_]+)`/s', $statement, $match)) {
        $app = $match[1];
        if (isset($databases[$app])) {
          throw new RuntimeException('Duplicate source schema refused.');
        }
        $databases[$app] = true;
        if (!in_array($app, $existing_apps, true)) {
          $database = $this->namespace.'_'.$app;
          $this->control->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
          $this->createdDatabases[] = $database;
        }
        continue;
      }
      if (preg_match('/\AUSE `\{\$NAMESPACE\}_([a-z_]+)`\z/', $statement, $match)) {
        $current = $match[1];
        if (!isset($databases[$current])) {
          throw new RuntimeException('Source schema order refused.');
        }
        $this->control->select_db($this->namespace.'_'.$current);
        continue;
      }
      if ($current === null) {
        throw new RuntimeException('Unscoped source SQL refused.');
      }
      if (preg_match('/\ACREATE TABLE `([a-zA-Z0-9_]+)` \(/', $statement, $match)) {
        $table = $match[1];
        $this->tables[$current][$table] = true;
        if (in_array($current.'.'.$table, $existing_tables, true)) {
          continue;
        }
      } else if (preg_match('/\AINSERT INTO `([a-zA-Z0-9_]+)` VALUES\s/', $statement, $match)) {
        if (!isset($this->tables[$current][$match[1]])) {
          throw new RuntimeException('Unowned source seed refused.');
        }
      } else {
        throw new RuntimeException('Unexpected full quickstart statement refused.');
      }
      $this->control->query(strtr($statement, $charset));
    }
    $this->tables['governance']['fixture_guard'] = true;
    $owner_nonce = bin2hex(random_bytes(32));
    $guard = '`'.$this->namespace.'_governance`.`fixture_guard`';
    $this->control->query('ALTER TABLE '.$guard.' ADD COLUMN owner_nonce BINARY(64) NOT NULL');
    $nonce_statement = $this->control->prepare('UPDATE '.$guard.' SET owner_nonce=? WHERE id=1');
    $nonce_statement->execute(array($owner_nonce));
    $nonce_statement->close();
    $this->ownerNonce = $owner_nonce;

    $this->createPrincipal('writer', false);
    $this->createPrincipal('setup', true);
    $this->baseline = $this->base->seedBaseline();
    $this->auditPatches();
    return $this;
  }

  private function createPrincipal($role, $trigger) {
    $user = 'gov_c_'.$role.'_'.bin2hex(random_bytes(6));
    $password = bin2hex(random_bytes(24));
    $this->control->query("CREATE USER '".$user."'@'%' IDENTIFIED BY '".$password."'");
    $this->createdUsers[] = $user;
    foreach (array_keys($this->tables) as $app) {
      $database = str_replace('_', '\\_', $this->namespace.'_'.$app);
      $privileges = $app === 'governance' ? 'SELECT' :
        ($role === 'setup' ? 'ALL PRIVILEGES' : 'SELECT, INSERT, UPDATE, DELETE');
      $this->control->query('GRANT '.$privileges.' ON `'.$database.
        "`.* TO '".$user."'@'%'");
    }
    if ($trigger) {
      $database = str_replace('_', '\\_', $this->namespace.'_maniphest');
      $this->control->query('GRANT TRIGGER ON `'.$database."`.* TO '".$user."'@'%'");
    }
    $connection = new mysqli($this->host,$user,$password,'',$this->port);
    $connection->set_charset('utf8mb4');
    $this->connections[$role] = $connection;
    $this->configs[$role] = array('host'=>$this->host,'port'=>$this->port,
      'namespace'=>$this->namespace,'user'=>$user,'password'=>$password);
  }

  private function auditPatches() {
    $rows = $this->getAdmin()->query('SELECT patch FROM '.$this->table('meta_data','patch_status'))
      ->fetch_all(MYSQLI_ASSOC);
    $applied = array_fill_keys(array_column($rows,'patch'),true);
    $pending = array();
    $patches = PhabricatorSQLPatchList::buildAllPatches();
    foreach ($patches as $key => $patch) {
      if (!$patch->isDead() && !isset($applied[$key])) { $pending[] = $key; }
    }
    $this->patchAudit = array('source_patch_count'=>count($patches),
      'applied_patch_count'=>count($applied),'pending_patch_keys'=>$pending,
      'current_patch_ledger_complete'=>!$pending);
    if ($pending) {
      $this->applyPendingPatches($patches,$applied,$pending);
      $this->patchAudit['migrations_executed'] = count($pending);
      $this->patchAudit['pending_patch_keys'] = array();
      $this->patchAudit['applied_patch_count'] += count($pending);
      $this->patchAudit['current_patch_ledger_complete'] = true;
      foreach (array_keys($this->tables) as $app) {
        if ($app === 'governance') { continue; }
        $rows = $this->control->query('SHOW TABLES FROM `'.$this->namespace.'_'.$app.'`')->fetch_all(MYSQLI_NUM);
        $this->tables[$app] = array_fill_keys(array_column($rows,0),true);
      }
    }
  }

  private function applyPendingPatches(array $patches,array $applied,array $pending) {
    $reviewed = array(
      '20260907.herald.01.requeststatuskey.sql','20260911.differential.01.appemail.php',
      '20260911.paste.01.appemail.php','20260918.differential.01.heraldrules.php',
      '20260918.differential.02.diffpolicy.php','20260918.owners.01.heraldrules.php',
      '20260918.owners.02.auditrequests.php','20261006.cache.01.gorgecleanup.sql',
      '20261006.conduit.01.gorgecleanup.sql','20261006.daemon.01.gorgecleanup.sql',
      '20261006.feed.01.gorgeoutbox.sql','20261006.metamta.01.gorgeoutbox.sql',
      '20261006.metamta.02.gorgedelivery.sql','20261006.metamta.03.gorgerecovery.sql',
      '20261006.search.01.gorgeprojection.sql','20261006.search.02.gorgedeletion.sql',
      '20261006.worker.01.gorgeinbox.sql','20261007.differential.01.gorgecleanup.sql',
      '20261007.file.01.gorgedeletion.sql','20261007.metamta.01.gorgeinbound.sql',
      '20261007.multimeter.01.cleanupindex.sql','20261007.multimeter.01.gorgecleanup.sql',
      '20261007.worker.scheduler.sql','20261007.worker.scheduleridentity.sql',
      '20261102.people.organization.sql','20261102.people.mediaexchange.sql',
      '20261103.people.organizationmetadata.sql','20261103.people.mediaissuer.sql','20261103.people.mediatargets.sql',
      '20261103.people.organizationtransfer.sql','20261103.project.approvalexecution.sql','20261103.project.configepoch.sql','20261104.people.recoverystop.sql','20261104.metamta.governancenotice.sql','20261104.people.recoveryrestore.sql','20261104.people.organizationparticipants.sql','20261105.people.noticeidentityepoch.sql',
      '20261110.project.contentgrants.sql');
    foreach ($pending as $key) {
      if (!str_starts_with($key,'phabricator:') ||
          !in_array(substr($key,12),$reviewed,true)) {
        throw new RuntimeException('Unreviewed current-source migration refused.');
      }
      $path = $patches[$key]->getName();
      $real = realpath($path);
      if (!$real || is_link($path) ||
          !str_starts_with($real,realpath($this->root.'/resources/sql').'/') ||
          !in_array($patches[$key]->getType(),array('sql','php'),true)) {
        throw new RuntimeException('Migration source scope refused.');
      }
      $this->patchAudit['migration_source_hashes'][$key] = hash_file('sha256',$path);
    }
    if (PhabricatorEnv::getConfigSourceStack() !== null ||
        (new ReflectionProperty(LiskDAO::class,'connections'))->getValue() !== array() ||
        (new ReflectionProperty(PhabricatorLiskDAO::class,'namespaceStack'))->getValue() !== array()) {
      throw new RuntimeException('Inherited migration context refused.');
    }
    $environment = (new ReflectionClass(PhabricatorEnv::class))->getStaticProperties();
    $cache_property = new ReflectionProperty(PhabricatorCaches::class,'requestCache');
    $old_cache = $cache_property->getValue();
    $api = null;
    $write_guard = null;
    $namespace_owned = false;
    try {
      $config = $this->configs['setup'];
      $stack = new PhabricatorConfigStackSource();
      $stack->pushSource(new PhabricatorConfigDefaultSource());
      $stack->pushSource(new PhabricatorConfigDictionarySource(array(
        'mysql.host'=>$config['host'],'mysql.port'=>$config['port'],
        'mysql.user'=>$config['user'],'mysql.pass'=>$config['password'],
        'storage.default-namespace'=>$config['namespace'],
        'cluster.databases'=>array(),'cluster.search'=>array(),'gorge.db.uri'=>null,
        'gorge.file.uri'=>null,'gorge.image.uri'=>null,'gorge.render.uri'=>null,
        'gorge.taskqueue.owner'=>'phorge','gorge.taskqueue.uri'=>null,
        'phabricator.silent'=>false,'notification.servers'=>array())));
      (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
      (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
      $cache_property->setValue(null,null);
      if (AphrontWriteGuard::isGuardActive() ||
          (new ReflectionProperty(AphrontWriteGuard::class,'allowUnguardedWrites'))->getValue()) {
        throw new RuntimeException('Inherited migration write guard refused.');
      }
      $write_guard = new AphrontWriteGuard(function() use ($config) {
        $this->assertOwned();
        if (PhabricatorEnv::getEnvConfig('mysql.user') !== $config['user'] ||
            PhabricatorEnv::getEnvConfig('storage.default-namespace') !== $config['namespace']) {
          throw new RuntimeException('Migration writer routing refused.');
        }
      });
      $api = (new PhabricatorStorageManagementAPI())->setHost($config['host'])
        ->setPort($config['port'])->setUser($config['user'])->setPassword($config['password'])
        ->setNamespace($config['namespace']);
      $namespace_owned = true;
      $remaining = array_fill_keys($pending,true);
      while ($remaining) {
        $progress = false;
        foreach (array_keys($remaining) as $key) {
          if (array_diff($patches[$key]->getAfter(),array_keys($applied))) { continue; }
          $api->applyPatch($patches[$key]);
          // Match native storage workflow: only after actual successful execution.
          $api->markPatchApplied($key);
          $applied[$key] = true; unset($remaining[$key]); $progress = true;
        }
        if (!$progress) { throw new RuntimeException('Migration dependency closure refused.'); }
      }
      $actual = $api->getAppliedPatches();
      if (array_diff($pending,$actual)) {
        throw new RuntimeException('Executed migration ledger incomplete.');
      }
    } finally {
      $cleanup_failed = false;
      try { if ($write_guard !== null) { $write_guard->dispose(); } }
      catch (Throwable $error) { $cleanup_failed = true; }
      try { LiskDAO::closeAllConnections(); }
      catch (Throwable $error) { $cleanup_failed = true; }
      try {
        if ($api !== null) {
          $connections = (new ReflectionProperty(PhabricatorStorageManagementAPI::class,'conns'))->getValue($api);
          foreach ($connections as $users) { foreach ($users as $databases) {
            foreach ($databases as $connection) { $connection->close(); }
          }}
        }
      } catch (Throwable $error) { $cleanup_failed = true; }
      if ($namespace_owned) { PhabricatorLiskDAO::popStorageNamespace(); }
      foreach ($environment as $key=>$value) {
        (new ReflectionProperty(PhabricatorEnv::class,$key))->setValue(null,$value);
      }
      $cache_property->setValue(null,$old_cache);
      if ($cleanup_failed) { throw new RuntimeException('Owned migration context cleanup failed.'); }
    }
  }

  public function assertOwned() {
    if (!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/', $this->namespace ?: '') ||
        getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
      throw new RuntimeException('Completion fixture ownership refused.');
    }
    $row = $this->connections['setup']->query('SELECT marker,quickstart_sha256,owner_nonce FROM '.
      $this->table('governance','fixture_guard').' WHERE id=1')->fetch_assoc();
    if (!$row || $row['marker'] !== PhorgeGovernanceNativeFixture::MARKER ||
        !hash_equals($this->sourceHash,$row['quickstart_sha256']) ||
        !hash_equals($this->ownerNonce,$row['owner_nonce'])) {
      throw new RuntimeException('Completion source lineage refused.');
    }
  }

  public function getNamespace() { return $this->namespace; }
  public function getAdmin() { $this->assertOwned(); return $this->connections['setup']; }

  /** Dedicated async DDL socket; existing setup credentials never escape. */
  public function newOwnedSetupConnection() {
    $this->assertOwned();$config=$this->configs['setup'];
    if($config['host']!==$this->host||$this->host!=='127.0.0.1'||
      $config['port']!==$this->port||$config['namespace']!==$this->namespace||
      !preg_match('/\Agov_c_setup_[a-f0-9]{12}\z/D',$config['user'])||
      !preg_match('/\A[a-f0-9]{48}\z/D',$config['password'])){
      throw new RuntimeException('owned_auxiliary_setup_profile_refused');}
    $mode=(new mysqli_driver())->report_mode;$connection=null;$key=null;
    try{
      mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
      $connection=new mysqli($config['host'],$config['user'],$config['password'],'',$config['port']);
      // Register before charset/identity/marker queries: partial initialization
      // is still owned by outer fixture cleanup, never an untracked connection.
      $key='auxiliary-setup-'.count($this->auxiliarySetupThreads);
      $this->connections[$key]=$connection;
      $this->auxiliarySetupThreads[$key]=$connection->thread_id;
      $connection->set_charset('utf8mb4');
      $row=$connection->query('SELECT CURRENT_USER() actual_user,@@server_uuid server_uuid')->fetch_assoc();
      $original=$this->connections['setup']->query('SELECT @@server_uuid server_uuid')->fetch_assoc();
      $marker=$connection->query('SELECT marker,quickstart_sha256,owner_nonce FROM '.
        $this->table('governance','fixture_guard').' WHERE id=1')->fetch_assoc();
      if(!$row||!$original||$row['server_uuid']!==$original['server_uuid']||
        !str_starts_with($row['actual_user'],$config['user'].'@')||
        $connection->thread_id===$this->connections['setup']->thread_id||
        !$marker||$marker['marker']!==PhorgeGovernanceNativeFixture::MARKER||
        !hash_equals($this->sourceHash,$marker['quickstart_sha256'])||
        !hash_equals($this->ownerNonce,$marker['owner_nonce'])){
        throw new RuntimeException('owned_auxiliary_setup_identity_refused');}
      return $connection;
    }catch(Throwable$error){
      if($connection!==null){
        try{$connection->close();unset($this->connections[$key],$this->auxiliarySetupThreads[$key]);}
        catch(Throwable$cleanup){throw new PhutilAggregateException('owned_auxiliary_setup_cleanup_failed',
          array('original'=>$error,'cleanup'=>$cleanup));}
      }
      throw$error;
    }finally{mysqli_report($mode);}
  }

  /** Close this fixture's independent mysqli writer, not the native DAO pool.
   * The HTTP parent must still prove ALL writer sessions zero after HTTP stops.
   */
  public function closeOwnedWriterConnectionForHTTP() {
    $this->assertOwned();
    if (!isset($this->connections['writer'])) { throw new RuntimeException('owned_HTTP_writer_handle_unknown'); }
    $config=$this->configs['writer'];$writer=$this->connections['writer'];
    if($config['host']!=='127.0.0.1'||$config['host']!==$this->host||$config['port']!==$this->port||
      $config['namespace']!==$this->namespace||!preg_match('/\Agov_c_writer_[a-f0-9]{12}\z/D',$config['user'])){
      throw new RuntimeException('owned_HTTP_writer_config_refused');}
    $physical=$writer->query('SELECT CURRENT_USER() principal,@@server_uuid u,CONNECTION_ID() t,@@autocommit a')->fetch_assoc();
    $observer=$this->control->query('SELECT @@server_uuid u,CONNECTION_ID() t,@@autocommit a')->fetch_assoc();$thread=$writer->thread_id;
    if(!is_int($thread)||$thread<1||!$physical||!$observer||$physical['principal']!==$config['user'].'@%'||
      $physical['u']!==$observer['u']||(string)$physical['t']!==(string)$thread||
      (string)$observer['t']===(string)$thread||(string)$physical['a']!=='1'||(string)$observer['a']!=='1'){
      throw new RuntimeException('owned_HTTP_writer_physical_identity_refused');}
    $txn=$this->control->prepare("SELECT t.INSTRUMENTED instrumented,(SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME='global_instrumentation') global_consumer,(SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME='thread_instrumentation') thread_consumer,(SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME='events_transactions_current') consumer,(SELECT ENABLED FROM performance_schema.setup_instruments WHERE NAME='transaction') instrument,(SELECT COUNT(*) FROM performance_schema.events_transactions_current e WHERE e.THREAD_ID=t.THREAD_ID AND e.STATE='ACTIVE') active FROM performance_schema.threads t WHERE t.PROCESSLIST_ID=?");
    $txn->bind_param('i',$thread);$txn->execute();$txrows=$txn->get_result()->fetch_all(MYSQLI_ASSOC);$txn->close();
    if(count($txrows)!==1||($txrows[0]['global_consumer']??null)!=='YES'||($txrows[0]['thread_consumer']??null)!=='YES'||
      ($txrows[0]['instrumented']??null)!=='YES'||($txrows[0]['consumer']??null)!=='YES'||
      ($txrows[0]['instrument']??null)!=='YES'||(string)($txrows[0]['active']??null)!=='0'){
      throw new RuntimeException('owned_HTTP_writer_transaction_unknown_or_active');}
    $count=function()use($thread,$config){
      $stmt=$this->control->prepare('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE ID=? AND USER=?');
      $stmt->bind_param('is',$thread,$config['user']);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
      if(!$row||!in_array((string)$row['n'],array('0','1'),true)){throw new RuntimeException('owned_HTTP_writer_thread_unknown');}
      return (string)$row['n'];
    };
    if($count()!=='1'){throw new RuntimeException('owned_HTTP_writer_thread_not_observed');}
    if($writer->close()!==true){throw new RuntimeException('owned_HTTP_writer_close_failed');}
    unset($this->connections['writer']); // A successful close must not be closed twice by final cleanup.
    $deadline=microtime(true)+2;$absent=false;
    do{$absent=$count()==='0';if($absent){break;}usleep(20000);}while(microtime(true)<$deadline);
    if(!$absent){throw new RuntimeException('owned_HTTP_writer_thread_still_present');}
    return array('owned_fixture_writer_connection_closed'=>true,'same_primary_observer'=>true,
      'exact_original_thread_absent'=>true,'closed_thread_private'=>(string)$thread,
      'all_native_writer_sessions_zero_not_claimed'=>true);
  }

  public function getWriterConfig() { $this->assertOwned(); return $this->configs['writer']; }
  /** Optional physical-mail fixture account; never grants native authority reads. */
  public function createPrivateMailerPrincipal() {
    $this->assertOwned();
    $tables=array($this->table('metamta','gorge_mail_delivery'),
      $this->table('metamta','gorge_mail_attempt'));
    $user='gov_c_mailer_'.bin2hex(random_bytes(6));
    $password=bin2hex(random_bytes(24));
    $driver=new mysqli_driver();$old_report=$driver->report_mode;
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    try{
      if($this->control->query("CREATE USER '".$user."'@'%' IDENTIFIED BY '".$password."'")!==true){
        throw new RuntimeException('Private mail principal creation refused.');
      }
      // Register only after successful CREATE: failed name collisions are unowned.
      $this->createdUsers[]=$user;
      foreach($tables as$table){
        if($this->control->query('GRANT SELECT, INSERT, UPDATE, DELETE ON '.$table.
            " TO '".$user."'@'%'")!==true){
          throw new RuntimeException('Private mail principal grant refused.');
        }
      }
    }finally{mysqli_report($old_report);}
    return array('host'=>$this->host,'port'=>$this->port,
      'database'=>$this->namespace.'_metamta','user'=>$user,'password'=>$password);
  }
  /** Optional daemon relay: no native authority tables and no mail INSERT. */
  public function createPrivateNoticeRelayPrincipal() {
    $this->assertOwned();$tables=array($this->table('metamta','metamta_gorgeoutbox'),
      $this->table('metamta','gorge_mail_delivery'));
    $user='gov_c_relay_'.bin2hex(random_bytes(6));$password=bin2hex(random_bytes(24));
    $driver=new mysqli_driver();$old_report=$driver->report_mode;mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    try{
      if($this->control->query("CREATE USER '".$user."'@'%' IDENTIFIED BY '".$password."'")!==true){
        throw new RuntimeException('Private notice relay principal creation refused.');}
      $this->createdUsers[]=$user;
      foreach($tables as$table){
        if($this->control->query('GRANT SELECT, UPDATE ON '.$table." TO '".$user."'@'%'")!==true){
          throw new RuntimeException('Private notice relay grant refused.');}}
    }finally{mysqli_report($old_report);}
    return array('host'=>$this->host,'port'=>$this->port,'database'=>$this->namespace.'_metamta',
      'user'=>$user,'password'=>$password);
  }
  public function getReaderConfig() { $this->assertOwned(); return $this->configs['reader']; }
  public function getBaselineIDs() { $this->assertOwned(); return $this->baseline; }
  public function table($app,$table) {
    if (!isset($this->tables[$app][$table])) {
      throw new InvalidArgumentException('Unowned completion table refused.');
    }
    return '`'.$this->namespace.'_'.$app.'`.`'.$table.'`';
  }
  /** Fixed synthetic source rows for native Wiki consumer boundaries. */
  public function seedWikiBoundaryRow($table,array $values) {
    $this->assertOwned();
    if (!in_array($table,array('phriction_document','phriction_content'),true)) {
      throw new InvalidArgumentException('Fixed Wiki fixture table required.');
    }
    $values['id']=(int)$this->getAdmin()->query('SELECT COALESCE(MAX(id),0)+1 FROM '.
      $this->table('phriction',$table))->fetch_row()[0];
    $this->base->seed('phriction',$table,$values);
  }
  public function countRows($app,$table) {
    return (int)$this->getAdmin()->query('SELECT COUNT(*) FROM '.$this->table($app,$table))->fetch_row()[0];
  }
  public function getSummary() {
    return array('quickstart_sha256'=>$this->sourceHash,
      'app_schema_count'=>count($this->tables)-1,
      'owned_schema_count'=>count($this->tables),
      'quickstart_source_table_count'=>500,
      'current_table_count'=>array_sum(array_map('count',$this->tables))-1,
      'patch_audit'=>$this->patchAudit,
      'restore_phase_workers_started'=>0,'restore_phase_network_endpoints_configured'=>0);
  }
  public function cleanup() {
    if ($this->ownerNonce !== null) {
      $row = $this->control->query('SELECT owner_nonce FROM `'.
        $this->namespace.'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();
      if (!$row || !hash_equals($this->ownerNonce,$row['owner_nonce'])) {
        throw new RuntimeException('Completion cleanup ownership nonce refused.');
      }
    }
    $errors=array();$mode=(new mysqli_driver())->report_mode;$base=null;$removed=0;
    $auxiliary_count=count($this->auxiliarySetupThreads);
    try{
      mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
      foreach($this->connections as$key=>$connection){
        try{$connection->close();}catch(Throwable$error){$errors['connection-'.$key]=$error;}
      }
      $this->connections=array();
      if($auxiliary_count){
        try{
          $ids=array_values($this->auxiliarySetupThreads);
          foreach($ids as$id){if(!is_int($id)||$id<1){
            throw new RuntimeException('owned_auxiliary_setup_thread_refused');}}
          $deadline=microtime(true)+2;$absent=false;
          do{
            $result=$this->control->query('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE ID IN ('.implode(',',$ids).')');
            if(!$result){throw new RuntimeException('owned_auxiliary_setup_observation_failed');}
            $absent=(int)$result->fetch_assoc()['n']===0;$result->free();
            if($absent){break;}usleep(20000);
          }while(microtime(true)<$deadline);
          if(!$absent){throw new RuntimeException('owned_auxiliary_setup_remaining');}
        }catch(Throwable$error){$errors['auxiliary-connections']=$error;}
      }
      // Preserve cleanup errors but continue every owned principal/schema. An
      // auxiliary close/observation fault must not strand the rest of fixture.
      foreach($this->createdUsers as$user){
        try{if($this->control->query("DROP USER '".$user."'@'%'")!==true){throw new RuntimeException('owned_runtime_user_drop_failed');}}
        catch(Throwable$error){$errors['user-'.$user]=$error;}
      }
      foreach(array_reverse($this->createdDatabases)as$database){
        try{if($this->control->query('DROP DATABASE `'.$database.'`')!==true){throw new RuntimeException('owned_schema_drop_failed');}$removed++;}
        catch(Throwable$error){$errors['schema-'.$database]=$error;}
      }
      try{$base=$this->base->cleanup();}catch(Throwable$error){$errors['base']=$error;}
    }finally{mysqli_report($mode);}
    if($errors){throw new PhutilAggregateException('owned_completion_cleanup_failed',$errors);}
    $result=array('ownedSchemasRemoved'=>$removed+$base['ownedSchemasRemoved'],
      'ownedRuntimeUsersRemoved'=>count($this->createdUsers),
      'readerRemoved'=>$base['readerRemoved']);
    if($auxiliary_count){$result['ownedAuxiliarySetupConnectionsClosed']=$auxiliary_count;}
    return $result;
  }
}
