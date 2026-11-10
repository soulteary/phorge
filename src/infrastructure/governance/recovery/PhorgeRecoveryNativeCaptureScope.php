<?php

/** Isolated current account capture. No recovery permit and no source writes. */
final class PhorgeRecoveryNativeCaptureScope extends Phobject {
  private $connection;
  private $snapshot;
  private $context;
  private $target;
  private $facts;
  private $webFacts;
  private $apiFacts;
  private $route;
  private $runtime;
  private $ownsTransaction = false;
  private $closed = false;
  private $started;
  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('recovery_scope_serialization_refused'); }
  public function __unserialize(array $value) { throw new RuntimeException('recovery_scope_serialization_refused'); }
  public function __debugInfo() { return array('scope'=>'isolated-account-capture-no-permit'); }

  private static function routing() {
    if (PHP_SAPI !== 'cli' || getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
      throw new RuntimeException('recovery_isolated_scope_required');
    }
    foreach (array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE') as $key) {
      $value = getenv($key);
      if ($value !== false && $value !== '') { throw new RuntimeException('recovery_deployment_scope_refused'); }
    }
    $root = dirname(__DIR__, 4);
    foreach (array('/conf/local/local.json','/conf/local/deployment.json','/conf/local/ENVIRONMENT') as $path) {
      if (file_exists($root.$path)) { throw new RuntimeException('recovery_deployment_scope_refused'); }
    }
    if (LiskDAO::shouldIsolateAllLiskEffectsToCurrentProcess() ||
        LiskDAO::shouldIsolateAllLiskEffectsToTransactions() ||
        (new ReflectionProperty(PhabricatorLiskDAO::class, 'namespaceStack'))->getValue() !== array()) {
      throw new RuntimeException('recovery_native_effect_scope_unknown');
    }
    $keys = array('mysql.host','mysql.port','mysql.user','mysql.pass',
      'storage.default-namespace','cluster.databases','gorge.db.uri');
    $stack = (new ReflectionProperty(PhabricatorEnv::class, 'sourceStack'))->getValue();
    if (!$stack || get_class($stack) !== PhabricatorConfigStackSource::class) {
      throw new RuntimeException('recovery_config_scope_unknown');
    }
    $classes = array();
    foreach ($stack->getStack() as $source) { $classes[] = get_class($source); }
    if ($classes !== array(PhabricatorConfigDictionarySource::class, PhabricatorConfigDefaultSource::class)) {
      throw new RuntimeException('recovery_config_scope_unknown');
    }
    $configured = $stack->getKeys($keys); $values = array();
    foreach ($keys as $key) {
      $values[$key] = PhabricatorEnv::getEnvConfig($key);
      if (!array_key_exists($key, $configured) || $values[$key] !== $configured[$key]) {
        throw new RuntimeException('recovery_config_scope_unknown');
      }
    }
    if (!in_array($values['mysql.host'], array('127.0.0.1','::1','localhost'), true) ||
        !is_string($values['mysql.user']) || !preg_match('/\Agov_c_writer_[a-f0-9]{12}\z/D', $values['mysql.user']) ||
        !is_string($values['storage.default-namespace']) ||
        !preg_match('/\Agovernance_native_[a-f0-9]{16}\z/D', $values['storage.default-namespace']) ||
        $values['cluster.databases'] !== array() || !in_array($values['gorge.db.uri'], array('',null), true) ||
        PhabricatorEnv::isReadOnly() !== false) {
      throw new RuntimeException('recovery_fixture_routing_required');
    }
    return $values;
  }

  public static function open(PhorgeGovernanceNativeActorContext $context, $target) {
    if (get_class($context) !== PhorgeGovernanceNativeActorContext::class ||
        !is_string($target) || !preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D', $target)) {
      throw new RuntimeException('recovery_actor_or_target_required');
    }
    $scope = new self(); $scope->route = self::routing();
    $scope->runtime = PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
    $scope->context = $context; $scope->target = $target;
    $user = new PhabricatorUser();
    if ((new ReflectionProperty(LiskDAO::class, 'forcedConnection'))->getValue($user) !== null) {
      throw new RuntimeException('recovery_native_writer_unknown');
    }
    $conn = $user->establishConnection('w');
    if(class_exists(PhorgeRecoveryConduitProducerFence::class,false)){PhorgeRecoveryConduitProducerFence::assertWriterAvailable($conn);}
    if (get_class($conn) !== AphrontMySQLiDatabaseConnection::class || $conn->isInsideTransaction() ||
        $conn->isReadLocking() || $conn->isWriteLocking()) {
      throw new RuntimeException('recovery_outer_transaction_refused');
    }
    // SET TRANSACTION (not SET SESSION) is rejected by MySQL when a
    // physical transaction is already active, including an untracked raw START.
    $default = queryfx_one($conn, 'SELECT @@SESSION.transaction_isolation level');
    $restore = array('READ-UNCOMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
      'READ-COMMITTED'=>'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
      'REPEATABLE-READ'=>'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
      'SERIALIZABLE'=>'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    if (!$default || !isset($restore[$default['level']])) {
      throw new RuntimeException('recovery_isolation_unknown');
    }
    $queued = false;
    $scope->connection = $conn;
    try {
      queryfx($conn, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
      $queued = true;
      $conn->openTransaction(); $scope->ownsTransaction = true; $queued = false;
      $database = queryfx_one($conn, 'SELECT DATABASE() db,@@server_uuid uuid,UNIX_TIMESTAMP() n');
      if (!$database || $database['db'] !== $scope->route['storage.default-namespace'].'_user' ||
          !is_string($database['uuid']) || $database['uuid'] === '') {
        throw new RuntimeException('recovery_native_writer_unknown');
      }
      $scope->started = (int)$database['n'];
      $scope->assertMarker();
      $ids = array_unique(array($context->getUserPHID(), $target)); sort($ids, SORT_STRING);
      foreach ($ids as $id) {
        $scope->preflight($id);
        $identity = PhorgeGovernanceNativeIdentitySource::lockIdentityPHID($id, $conn);
        if ($id === $context->getUserPHID() && $identity['accountEpoch'] === null) {
          // lockCurrent initializes an absent issuer epoch. Never invoke that
          // write-capable branch from this strictly non-writing capture.
          throw new RuntimeException('recovery_actor_epoch_uninitialized');
        }
      }
      $scope->snapshot = PhorgeGovernanceNativeIdentitySource::lockCurrent($context, $conn);
      if (!$scope->snapshot->isSystemAdmin()) { throw new RuntimeException('recovery_current_admin_required'); }
      $scope->facts = $scope->readAccount();
      $scope->assertCurrent();
      return $scope;
    } catch (Throwable $error) {
      try { $scope->close(); } catch (Throwable $cleanup) {
        throw new RuntimeException('recovery_capture_cleanup_failed', 0, $cleanup);
      } finally {
        if ($queued) { queryfx($conn, $restore[$default['level']]); }
      }
      throw $error;
    }
  }

  private function assertMarker() {
    $namespace = $this->route['storage.default-namespace'];
    $rows = queryfx_all($this->connection,
      'SELECT marker,quickstart_sha256 FROM %T.fixture_guard WHERE id=1 LOCK IN SHARE MODE', $namespace.'_governance');
    if (count($rows) !== 1 || $rows[0]['marker'] !== 'N07.NATIVE-SOURCE.v1:synthetic-only' ||
        $rows[0]['quickstart_sha256'] !== hash_file('sha256', dirname(__DIR__,4).'/resources/sql/quickstart.sql')) {
      throw new RuntimeException('recovery_fixture_lineage_unknown');
    }
  }

  private function preflight($phid) {
    // Bound selected variable-width columns on the server before transferring
    // security fields. Real native identity reads then acquire current locks.
    $rows = queryfx_all($this->connection,
      'SELECT phid,OCTET_LENGTH(accountSecret) a,OCTET_LENGTH(conduitCertificate) c FROM %R WHERE BINARY phid=BINARY %s LIMIT 2 LOCK IN SHARE MODE',
      new PhabricatorUser(), $phid);
    $row = count($rows) === 1 ? head($rows) : null;
    if (!$row || $row['phid'] !== $phid || (int)$row['a'] > 4096 || (int)$row['c'] > 4096) {
      throw new RuntimeException('recovery_account_budget_unknown');
    }
  }

  private function readAccount() {
    $this->preflight($this->target);
    $row = queryfx_one($this->connection,
      'SELECT phid,isAdmin,isApproved,isDisabled,isSystemAgent,isMailingList,accountSecret,conduitCertificate FROM %R WHERE BINARY phid=BINARY %s LOCK IN SHARE MODE',
      new PhabricatorUser(), $this->target);
    if (!$row || $row['phid'] !== $this->target) { throw new RuntimeException('recovery_account_missing'); }
    $generation = queryfx_one($this->connection,
      'SELECT id FROM %R WHERE BINARY objectPHID=BINARY %s ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE',
      new PhabricatorUserTransaction(), $this->target);
    $epoch = queryfx_one($this->connection,
      'SELECT userPHID,epoch FROM %R WHERE BINARY userPHID=BINARY %s LOCK IN SHARE MODE',
      new PhorgeGovernanceIdentityEpoch(), $this->target);
    if ($epoch && $epoch['userPHID'] !== $this->target) { throw new RuntimeException('recovery_generation_unknown'); }
    return PhorgeRecoveryNativeAccountFacts::decode($row, $generation ? $generation['id'] : '0',
      $epoch ? $epoch['epoch'] : null);
  }

  /** Reuse fixed readers inside an already owned restoration transaction.
   * This method never opens, commits or rolls back its caller's transaction.
   * Returned storage facts do not certify producer absence or restoration.
   */
  public static function captureForRestoration(PhorgeGovernanceNativeActorContext $context,
    AphrontDatabaseConnection $conn,$target) {
    if(class_exists(PhorgeRecoveryConduitProducerFence::class,false)){PhorgeRecoveryConduitProducerFence::assertWriterAvailable($conn);}
    PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);
    PhorgeRecoveryStopRequest::user($target);
    $scope=new self();
    $scope->connection=$conn;$scope->target=$target;
    $scope->route=self::routing();
    // Deliberately keep ownsTransaction=false. Destructor/error paths cannot
    // end the service's transaction and no issuer epoch is initialized here.
    $scope->preflight($context->getUserPHID());$scope->preflight($target);
    $epoch=queryfx_one($conn,'SELECT userPHID,epoch FROM %R '.
      'WHERE BINARY userPHID=BINARY %s LOCK IN SHARE MODE',
      new PhorgeGovernanceIdentityEpoch(),$context->getUserPHID());
    if (!$epoch || $epoch['userPHID']!==$context->getUserPHID()) {
      throw new RuntimeException('recovery_actor_epoch_uninitialized');
    }
    $snapshot=PhorgeGovernanceNativeIdentitySource::lockCurrent($context,$conn);
    if (!$snapshot->isSystemAdmin()) {throw new RuntimeException('recovery_current_admin_required');}
    $account=$scope->readAccount();$web=$scope->readWebCredentials();$api=$scope->readAPICredentials();
    $current=PhorgeGovernanceNativeIdentitySource::revalidate($snapshot,$conn);
    if (!$current->isSystemAdmin()) {throw new RuntimeException('recovery_current_admin_required');}
    PhorgeRecoveryRestoreProfile::assertOwnedWriter($conn);
    if($scope->route!==self::routing()){throw new RuntimeException('recovery_capture_context_changed');}
    return array('provider_schema'=>1,'account_facts'=>$account,
      'web_storage_digest'=>$web['storage_set_digest'],'web_unknown_reasons'=>$web['unknown_reasons'],
      'api_storage_digest'=>$api['storage_set_digest'],'api_unknown_reasons'=>$api['unknown_reasons'],
      'all_producers_and_absence_fences_proved'=>false,'restore_lineage_complete'=>false,
      'complete'=>false,'restoration_authorized'=>false);
  }

  /** Capture selected direct credential resources, never an exhaustive family. */
  public function captureWebCredentials() {
    try {
      $this->assertCurrent();
      if ($this->webFacts !== null) { throw new RuntimeException('recovery_web_already_captured'); }
      $this->webFacts = $this->readWebCredentials();
      $this->assertCurrent();
      return $this->webFacts;
    } catch (Throwable $error) {
      // An unknown/budget/source failure cannot be caught and reused as a
      // seemingly successful account-only capture on this same scope.
      $this->close();
      throw $error;
    }
  }

  private function credentialTable($temporary) {
    $table = $temporary ? new PhabricatorAuthTemporaryToken() : new PhabricatorAuthSession();
    $route = $table->establishConnection('w');
    if (get_class($route) !== AphrontMySQLiDatabaseConnection::class ||
        ($route !== $this->connection && ($route->isInsideTransaction() ||
          $route->isReadLocking() || $route->isWriteLocking()))) {
      throw new RuntimeException('recovery_credential_route_unknown');
    }
    $local = queryfx_one($this->connection, 'SELECT @@server_uuid uuid');
    $remote = queryfx_one($route, 'SELECT @@server_uuid uuid,DATABASE() db');
    $database = $this->route['storage.default-namespace'].($temporary ? '_auth' : '_user');
    if (!$local || !$remote || $remote['uuid'] !== $local['uuid'] || $remote['db'] !== $database) {
      throw new RuntimeException('recovery_credential_route_unknown');
    }
    queryfx_one($this->connection,'SELECT id FROM %R WHERE 1=0 LOCK IN SHARE MODE',$table);
    $engine=queryfx_one($this->connection,
      'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
      $database,$table->getTableName());
    if(!$engine||$engine['ENGINE']!=='InnoDB'){throw new RuntimeException('recovery_credential_schema_unknown');}
    $columns=queryfx_all($this->connection,
      'SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION LIMIT 17',
      $database,$table->getTableName());
    $expected=$temporary ? array('id'=>'int','tokenResource'=>'varbinary','tokenType'=>'varchar',
      'tokenExpires'=>'int','tokenCode'=>'varchar','userPHID'=>'varbinary','properties'=>'longtext') :
      array('id'=>'int','userPHID'=>'varbinary','type'=>'varchar','sessionKey'=>'varchar',
        'sessionStart'=>'int','sessionExpires'=>'int','highSecurityUntil'=>'int',
        'isPartial'=>'tinyint','signedLegalpadDocuments'=>'tinyint','phid'=>'varbinary');
    $widths=$temporary ? array('tokenResource'=>64,'tokenType'=>64,'tokenCode'=>64,'userPHID'=>64) :
      array('userPHID'=>64,'type'=>32,'sessionKey'=>64,'phid'=>64);
    $nullable=$temporary ? array('userPHID') : array('highSecurityUntil');
    if(count($columns)!==count($expected)){throw new RuntimeException('recovery_credential_schema_unknown');}
    foreach($columns as$column){
      $name=$column['COLUMN_NAME'];
      if(!isset($expected[$name])||$column['DATA_TYPE']!==$expected[$name]||
          ($expected[$name]==='int'&&strpos($column['COLUMN_TYPE'],'unsigned')===false)||
          $column['IS_NULLABLE']!==(in_array($name,$nullable,true)?'YES':'NO')||
          (isset($widths[$name])&&(string)$column['CHARACTER_MAXIMUM_LENGTH']!==(string)$widths[$name])){
        throw new RuntimeException('recovery_credential_schema_unknown');}
      unset($expected[$name]);
    }
    if($expected){throw new RuntimeException('recovery_credential_schema_unknown');}
    return $table;
  }

  private function credentialRows($temporary, &$bytes, $api = false) {
    $maximum_bytes = $api ? PhorgeRecoveryNativeAPIFacts::MAX_BYTES : PhorgeRecoveryNativeWebFacts::MAX_BYTES;
    $budget_error = $api ? 'recovery_api_capture_budget_unknown' : 'recovery_web_capture_budget_unknown';
    $table=$this->credentialTable($temporary);
    $where=$temporary ? qsprintf($this->connection,
      '(BINARY tokenResource=BINARY %s OR BINARY userPHID=BINARY %s)',$this->target,$this->target) :
      qsprintf($this->connection,'BINARY userPHID=BINARY %s',$this->target);
    if ($api) {
      $where = qsprintf($this->connection, '%Q AND BINARY type=BINARY %s',
        $where, PhabricatorAuthSession::TYPE_CONDUIT);
    }
    $lengths=$temporary ? qsprintf($this->connection,
      'OCTET_LENGTH(tokenResource) a,OCTET_LENGTH(tokenType) b,OCTET_LENGTH(tokenCode) c,OCTET_LENGTH(userPHID) d,OCTET_LENGTH(properties) e') :
      qsprintf($this->connection,'OCTET_LENGTH(phid) a,OCTET_LENGTH(userPHID) b,OCTET_LENGTH(type) c,OCTET_LENGTH(sessionKey) d');
    $preflight=queryfx_all($this->connection,
      'SELECT id,%Q FROM %R WHERE %Q ORDER BY id LIMIT 65 LOCK IN SHARE MODE',
      $lengths,$table,$where);
    if(count($preflight)>PhorgeRecoveryNativeWebFacts::MAX_ROWS){throw new RuntimeException($budget_error);}
    $ids=array();
    foreach($preflight as$row){
      if((!is_string($row['id'])&&!is_int($row['id']))||
          !preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$row['id'])||
          (float)$row['id']>4294967295||isset($ids[(string)$row['id']])){
        throw new RuntimeException('recovery_web_storage_shape_unknown');}
      $ids[(string)$row['id']]=(int)$row['id'];$bytes+=128;
      foreach($row as$key=>$value){
        if($key==='id'){continue;}
        if($value!==null&&(!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$value)||
            (float)$value>($key==='e'?PhorgeRecoveryNativeWebFacts::MAX_PROPERTIES:64))){
          throw new RuntimeException('recovery_web_storage_budget_unknown');}
        $bytes+=(int)$value;
      }
    }
    if($bytes>$maximum_bytes){throw new RuntimeException($budget_error);}
    if(!$ids){return array();}
    $projection=$temporary ? qsprintf($this->connection,'id,tokenResource,tokenType,tokenExpires,tokenCode,userPHID,properties') :
      qsprintf($this->connection,'id,phid,userPHID,type,sessionKey,sessionStart,sessionExpires,highSecurityUntil,isPartial,signedLegalpadDocuments');
    // Only the already size-checked, current-lock-held IDs are fetched. New RC
    // phantom rows cannot introduce an unbounded payload between these reads.
    $rows=queryfx_all($this->connection,'SELECT %Q FROM %R WHERE id IN (%Ld) AND %Q ORDER BY id LOCK IN SHARE MODE',
      $projection,$table,array_values($ids),$where);
    if(count($rows)!==count($ids)){throw new RuntimeException('recovery_web_source_changed');}
    return $rows;
  }

  private function readWebCredentials() {
    $bytes=0;$sessions=$this->credentialRows(false,$bytes);$tokens=$this->credentialRows(true,$bytes);
    $now=(int)queryfx_one($this->connection,'SELECT UNIX_TIMESTAMP() n')['n'];
    return PhorgeRecoveryNativeWebFacts::describe($sessions,$tokens,$this->target,$now);
  }

  /** Selected API resources only; no credential or recovery grant. */
  public function captureAPICredentials() {
    try {
      $this->assertCurrent();
      if ($this->apiFacts !== null) { throw new RuntimeException('recovery_api_already_captured'); }
      $this->apiFacts = $this->readAPICredentials();
      $this->assertCurrent();
      return $this->apiFacts;
    } catch (Throwable $error) { $this->close(); throw $error; }
  }

  /** Only the actual borrowed owner may prelock referenced clients. No permit. */
  public static function prepareBorrowedOAuthClients($token,AphrontDatabaseConnection $writer,
    AphrontDatabaseConnection $observer,$target) {
    PhorgeRecoveryRestoreService::assertBorrowedOwner($token,$writer,$observer);
    PhorgeRecoveryRestoreProfile::assertOwnedWriter($writer);PhorgeRecoveryStopRequest::user($target);
    $profile=PhorgeRecoveryRestoreProfile::assertCurrent();$scope=new self();
    $scope->connection=$writer;$scope->target=$target;
    $scope->route=array('storage.default-namespace'=>$profile['namespace']);
    $references=$scope->apiClientReferences();$profiles=$scope->apiProfiles();$bytes=0;
    $rows=$scope->apiRows($profiles['oauth_clients'],$references,$bytes);
    $found=array();foreach($rows as$row){
      if(!is_string($row['phid']??null)||!isset($references[$row['phid']])||isset($found[$row['phid']])){throw new RuntimeException('recovery_api_client_source_changed');}
      $found[$row['phid']]=true;
    }
    ksort($found,SORT_STRING);if(array_keys($found)!==array_keys($references)){throw new RuntimeException('recovery_api_client_source_changed');}
    PhorgeRecoveryRestoreService::assertBorrowedOwner($token,$writer,$observer);
    return hash('sha256',serialize(array($profile,$target,$references,$rows)));
  }

  private function apiProfiles() {
    return array(
      'conduit'=>array(new PhabricatorConduitToken(),'conduit','objectPHID',
        array('id'=>'int','objectPHID'=>'varbinary','tokenType'=>'varchar','token'=>'varchar','expires'=>'int',
          'dateCreated'=>'int','dateModified'=>'int','tokenName'=>'varchar')),
      'oauth_tokens'=>array(new PhabricatorOAuthServerAccessToken(),'oauth_server','userPHID',
        array('id'=>'int','token'=>'varchar','userPHID'=>'varbinary','clientPHID'=>'varbinary','dateCreated'=>'int','dateModified'=>'int')),
      'oauth_authorizations'=>array(new PhabricatorOAuthClientAuthorization(),'oauth_server','userPHID',
        array('id'=>'int','phid'=>'varbinary','userPHID'=>'varbinary','clientPHID'=>'varbinary','dateCreated'=>'int','dateModified'=>'int','scope'=>'longtext')),
      'oauth_codes'=>array(new PhabricatorOAuthServerAuthorizationCode(),'oauth_server','userPHID',
        array('id'=>'int','code'=>'varchar','clientPHID'=>'varbinary','clientSecret'=>'varchar','userPHID'=>'varbinary','dateCreated'=>'int','dateModified'=>'int','redirectURI'=>'varchar')),
      'oauth_clients'=>array(new PhabricatorOAuthServerClient(),'oauth_server','phid',
        array('id'=>'int','phid'=>'varbinary','name'=>'varchar','secret'=>'varchar','redirectURI'=>'varchar','creatorPHID'=>'varbinary',
          'isTrusted'=>'tinyint','viewPolicy'=>'varbinary','editPolicy'=>'varbinary','dateCreated'=>'int','dateModified'=>'int','isDisabled'=>'tinyint')));
  }

  private function apiRows(array $profile, array $clients, &$bytes, $plan_clients=false) {
    list($table,$application,$owner,$columns)=$profile;
    if($plan_clients&&($owner!=='userPHID'||!isset($columns['clientPHID']))){throw new RuntimeException('recovery_api_client_plan_unknown');}
    $locking=$plan_clients?'':' LOCK IN SHARE MODE';
    $primary=$owner==='phid'?' FORCE INDEX (PRIMARY)':'';
    $route=$table->establishConnection('w');
    if(get_class($route)!==AphrontMySQLiDatabaseConnection::class ||
        ($route!==$this->connection&&($route->isInsideTransaction()||$route->isReadLocking()||$route->isWriteLocking()))){
      throw new RuntimeException('recovery_api_route_unknown');}
    $local=queryfx_one($this->connection,'SELECT @@server_uuid uuid');
    $remote=queryfx_one($route,'SELECT @@server_uuid uuid,DATABASE() db');
    $database=$this->route['storage.default-namespace'].'_'.$application;
    if(!$local||!$remote||$remote['uuid']!==$local['uuid']||$remote['db']!==$database){
      throw new RuntimeException('recovery_api_route_unknown');}
    queryfx_one($this->connection,'SELECT id FROM %R WHERE 1=0 LOCK IN SHARE MODE',$table);
    $engine=queryfx_one($this->connection,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
      $database,$table->getTableName());
    $actual=queryfx_all($this->connection,
      'SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION LIMIT 17',
      $database,$table->getTableName());
    if(!$engine||$engine['ENGINE']!=='InnoDB'||count($actual)!==count($columns)){
      throw new RuntimeException('recovery_api_schema_unknown');}
    foreach($actual as$row){$name=$row['COLUMN_NAME'];
      $width=$row['DATA_TYPE']==='varbinary'?64:($row['DATA_TYPE']==='varchar'?(in_array($name,array('name','redirectURI'),true)?255:($name==='tokenName'?64:32)):null);
      if(!isset($columns[$name])||$columns[$name]!==$row['DATA_TYPE']||
          ($row['DATA_TYPE']==='int'&&strpos($row['COLUMN_TYPE'],'unsigned')===false)||
          ($width!==null&&(string)$row['CHARACTER_MAXIMUM_LENGTH']!==(string)$width)||
          $row['IS_NULLABLE']!==($application==='conduit'&&$name==='expires'?'YES':'NO')){
        throw new RuntimeException('recovery_api_schema_unknown');}}
    $where=$owner==='phid' ? ($clients ? qsprintf($this->connection,'BINARY phid IN (%Ls)',array_keys($clients)) : qsprintf($this->connection,'1=0')) :
      qsprintf($this->connection,'BINARY %C=BINARY %s',$owner,$this->target);
    if($owner==='phid'&&$clients){
      // Resolve bounded exact IDs without record SHARE, then lock only those
      // clustered records. Never FORCE PRIMARY across the whole client table.
      $planned=queryfx_all($this->connection,'SELECT id,phid FROM %R WHERE BINARY phid IN (%Ls) ORDER BY id LIMIT 65',$table,array_keys($clients));
      if(count($planned)!==count($clients)||count($planned)>PhorgeRecoveryNativeAPIFacts::MAX_ROWS){throw new RuntimeException('recovery_api_client_source_changed');}
      $client_ids=array();$client_phids=array();foreach($planned as$row){
        if((!is_int($row['id']??null)&&!is_string($row['id']??null))||preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$row['id'])!==1||
          (float)$row['id']>4294967295||isset($client_ids[(string)$row['id']])||!is_string($row['phid']??null)||!isset($clients[$row['phid']])||isset($client_phids[$row['phid']])){throw new RuntimeException('recovery_api_client_source_changed');}
        $client_ids[(string)$row['id']]=(int)$row['id'];$client_phids[$row['phid']]=true;
      }
      $where=qsprintf($this->connection,'id IN (%Ld) AND BINARY phid IN (%Ls)',array_values($client_ids),array_keys($clients));
    }
    $names=array_keys($columns);$lengths=array();
    foreach($names as$name){$lengths[]=qsprintf($this->connection,'OCTET_LENGTH(%C)',$name);}
    // Names and formats originate exclusively in the exact fixed registry above.
    $sum=qsprintf($this->connection,implode('+',array_fill(0,count($lengths),'COALESCE(%Q,0)')),...$lengths);
    $maximum=qsprintf($this->connection,'GREATEST('.implode(',',array_fill(0,count($lengths),'COALESCE(%Q,0)')).')',...$lengths);
    $preflight=queryfx_all($this->connection,
      'SELECT id,%Q bytes,%Q largest FROM %R'.$primary.' WHERE %Q ORDER BY id LIMIT 65'.$locking,$sum,$maximum,$table,$where);
    if(count($preflight)>PhorgeRecoveryNativeAPIFacts::MAX_ROWS){throw new RuntimeException('recovery_api_capture_budget_unknown');}
    $ids=array();foreach($preflight as$row){
      if((!is_int($row['id'])&&!is_string($row['id']))||!preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$row['id'])||
          (float)$row['id']>4294967295||isset($ids[(string)$row['id']])){throw new RuntimeException('recovery_api_shape_unknown');}
      if((int)$row['largest']>4096){throw new RuntimeException('recovery_api_storage_budget_unknown');}
      $bytes+=(int)$row['bytes']+count($names)*32;
      if($bytes>PhorgeRecoveryNativeAPIFacts::MAX_BYTES){throw new RuntimeException('recovery_api_capture_budget_unknown');}
      $ids[(string)$row['id']]=(int)$row['id'];}
    if(!$ids){return array();}
    if($plan_clients){$names=array('id','clientPHID');}
    $projection=qsprintf($this->connection,implode(',',array_fill(0,count($names),'%C')),...$names);
    $rows=queryfx_all($this->connection,'SELECT %Q FROM %R'.$primary.' WHERE id IN (%Ld) AND %Q ORDER BY id'.$locking,
      $projection,$table,array_values($ids),$where);
    if(count($rows)!==count($ids)){throw new RuntimeException('recovery_api_source_changed');}
    return $rows;
  }

  private function apiClientReferences() {
    $profiles=$this->apiProfiles();$clients=array();$bytes=0;
    foreach(array('oauth_tokens','oauth_authorizations','oauth_codes')as$kind){
      $ids=array();foreach($this->apiRows($profiles[$kind],array(),$bytes,true)as$row){
        $keys=array_keys($row);sort($keys,SORT_STRING);
        if($keys!==array('clientPHID','id')||(!is_int($row['id'])&&!is_string($row['id']))||
          preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$row['id'])!==1||(float)$row['id']>4294967295||isset($ids[(string)$row['id']])||
          !is_string($row['clientPHID'])||preg_match('/\APHID-OASC-[a-zA-Z0-9]{20}\z/D',$row['clientPHID'])!==1){throw new RuntimeException('recovery_api_client_plan_unknown');}
        $ids[(string)$row['id']]=true;$clients[$row['clientPHID']]=true;
        if(count($clients)>PhorgeRecoveryNativeAPIFacts::MAX_ROWS){throw new RuntimeException('recovery_api_client_plan_budget');}
      }
    }
    ksort($clients,SORT_STRING);return$clients;
  }

  private function readAPICredentials() {
    $bytes=0;$groups=array();$profiles=$this->apiProfiles();$clients=$this->apiClientReferences();
    // Client record SHARE precedes every OAuth credential record SHARE.
    $groups['oauth_clients']=$this->apiRows($profiles['oauth_clients'],$clients,$bytes);
    $found=array();foreach($groups['oauth_clients']as$row){
      if(!is_string($row['phid']??null)||!isset($clients[$row['phid']])||isset($found[$row['phid']])){throw new RuntimeException('recovery_api_client_source_changed');}
      $found[$row['phid']]=true;
    }
    ksort($found,SORT_STRING);if(array_keys($found)!==array_keys($clients)){throw new RuntimeException('recovery_api_client_source_changed');}
    $observed=array();foreach($profiles as$kind=>$profile){
      if($kind==='oauth_clients'){continue;}
      $groups[$kind]=$this->apiRows($profile,array(),$bytes);
      foreach($groups[$kind]as$row){if(isset($row['clientPHID'])){
        if(!is_string($row['clientPHID'])||!preg_match('/\APHID-OASC-[a-zA-Z0-9]{20}\z/D',$row['clientPHID'])){
          throw new RuntimeException('recovery_api_client_binding_unknown');}
        $observed[$row['clientPHID']]=true;}}
    }
    ksort($observed,SORT_STRING);if(array_keys($observed)!==array_keys($clients)||$this->apiClientReferences()!==$clients){throw new RuntimeException('recovery_api_client_source_changed');}
    $groups['legacy_sessions']=array();
    foreach($this->credentialRows(false,$bytes,true)as$row){if($row['type']===PhabricatorAuthSession::TYPE_CONDUIT){$groups['legacy_sessions'][]=$row;}}
    $now=(int)queryfx_one($this->connection,'SELECT UNIX_TIMESTAMP() n')['n'];
    return PhorgeRecoveryNativeAPIFacts::describe($groups,$this->target,$now);
  }

  private function assertCurrent() {
    if ($this->closed || !$this->ownsTransaction || !$this->connection->isInsideTransaction() ||
        self::routing() !== $this->route ||
        PhorgeGovernanceNativeRuntimeProfile::assertCurrent() !== $this->runtime ||
        $this->connection !== (new PhabricatorUser())->establishConnection('w')) {
      throw new RuntimeException('recovery_capture_context_changed');
    }
    $this->assertMarker();
    PhorgeGovernanceNativeIdentitySource::revalidate($this->snapshot, $this->connection);
    $now = (int)queryfx_one($this->connection,'SELECT UNIX_TIMESTAMP() n')['n'];
    if ($now < $this->started || $now - $this->started > 10) {
      throw new RuntimeException('recovery_capture_deadline');
    }
  }

  public function finish() {
    try {
      $this->assertCurrent();
      if ($this->readAccount() !== $this->facts) { throw new RuntimeException('recovery_account_source_changed'); }
      // Locks prevent source writes, not credential expiry while reads wait.
      $this->assertCurrent();
      $web=null;
      if($this->webFacts!==null){
        $web=$this->readWebCredentials();
        if($web['storage_set_digest']!==$this->webFacts['storage_set_digest']){
          throw new RuntimeException('recovery_web_source_changed');}
        $this->assertCurrent();
      }
      $api=null;
      if($this->apiFacts!==null){
        $api=$this->readAPICredentials();
        if($api['storage_set_digest']!==$this->apiFacts['storage_set_digest']){throw new RuntimeException('recovery_api_source_changed');}
        $this->assertCurrent();$api['consistency']='two-matching-current-samples-not-atomic';
      }
      if($web!==null){$web['consistency']='two-matching-current-samples-not-atomic';}
      $families = array_fill_keys(PhorgeGovernanceRecoverySourceInventory::requiredFamilies(), 'uncovered');
      $result=array('schema_version'=>1, 'capture'=>'actual-locked-native-account',
        'account_facts'=>$this->facts, 'account_adapter'=>'captured-partial',
        'families'=>$families, 'all_sources_complete'=>false, 'can_execute'=>false,
        'future_execution_atomic'=>false, 'source_writes'=>false);
      if($web!==null){$result['web_credentials']=$web;}
      if($api!==null){$result['api_credentials']=$api;}
      return $result;
    } finally { $this->close(); }
  }

  public function close() {
    if ($this->closed) { return; }
    $this->closed = true;
    if ($this->ownsTransaction) {
      $this->ownsTransaction = false;
      // Always end our observation by rollback. No effects were executed and
      // no rollback claim is made about hypothetical native external effects.
      $this->connection->killTransaction();
    }
  }
  public function __destruct() {
    try { $this->close(); } catch (Throwable $ignored) {}
  }
}
