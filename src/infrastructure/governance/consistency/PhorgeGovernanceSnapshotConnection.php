<?php

/** Only owned read-view scopes may construct this test-only SQL bridge. */
final class PhorgeGovernanceSnapshotConnection extends AphrontDatabaseConnection {

  private $connection;
  private $database;
  private $rows = array();
  private $closed = false;
  private $queries = 0;
  private $nativeFetchAttempts = 0;
  private $attempts = 0;
  private $preFetchRejections = 0;
  private $thread;
  private $sqlMode;
  private $deadline;
  private $checkedTables = array();

  private function __construct(mysqli $connection, $database, $deadline) {
    if (PHP_SAPI !== 'cli' || getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1' ||
        !preg_match('/\Agovernance_native_[a-f0-9]{16}_'.
          '(user|project|maniphest|phriction|policy|spaces|config|governance)\z/',
          $database)) {
      throw new RuntimeException('snapshot_bridge_scope_refused');
    }
    $this->connection = $connection;
    $this->deadline = $deadline;
    $this->database = $database;
    $this->thread = $connection->thread_id;
    $this->sqlMode = $connection->query('SELECT @@SESSION.sql_mode AS mode')
      ->fetch_assoc()['mode'];
    if (!self::supportsSqlMode($this->sqlMode)) {
      throw new RuntimeException('snapshot_bridge_sql_mode_refused');
    }
    $this->setReadOnly(true);
  }

  private function __clone() {}

  public function __serialize() {
    throw new RuntimeException('native_owned_scope_serialization_refused');
  }

  public function __unserialize(array $data) {
    throw new RuntimeException('native_owned_scope_serialization_refused');
  }

  public function __debugInfo() {
    return array('scope' => 'isolated-owned-read-only', 'closed' => $this->closed);
  }

  public static function supportsSqlMode($mode) {
    if (!is_string($mode)) {
      return false;
    }
    $allowed = array('ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES',
      'NO_ZERO_IN_DATE', 'NO_ZERO_DATE', 'ERROR_FOR_DIVISION_BY_ZERO',
      'NO_ENGINE_SUBSTITUTION');
    foreach ($mode === '' ? array() : explode(',', $mode) as $flag) {
      if (!in_array($flag, $allowed, true)) {
        return false;
      }
    }
    return true;
  }

  public static function isSupportedSelect($sql) {
    if (!is_string($sql) || strlen($sql) > 65536 || str_contains($sql, '"')) {
      return false;
    }
    // Mask SQL literals/identifiers before rejecting operators or functions.
    // Comments, variables, multi-statements and unknown functions fail closed.
    $plain = '';
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
      $char = $sql[$i];
      if ($char === "'" || $char === '`') {
        $quote = $char;
        $ended = false;
        for ($i++; $i < $length; $i++) {
          if ($sql[$i] === '\\' && $quote === "'") {
            $i++;
            continue;
          }
          if ($sql[$i] === $quote) {
            if ($i + 1 < $length && $sql[$i + 1] === $quote) {
              $i++;
              continue;
            }
            $ended = true;
            break;
          }
        }
        if (!$ended) {
          return false;
        }
        $plain .= ' literal ';
      } else {
        $plain .= $char;
      }
    }
    if (!preg_match('/\A\s*SELECT\s/i', $plain) ||
        preg_match('/[;@#]|--|\/\*|\*\//', $plain) ||
        preg_match('/\b(INTO|OUTFILE|DUMPFILE|FOR|LOCK|PROCEDURE|CALL|SET|'.
          'INSERT|UPDATE|DELETE|REPLACE|UNION|WITH|TABLE|VALUES|EXCEPT|INTERSECT)\b/i', $plain)) {
      return false;
    }
    preg_match_all('/\b([a-z_][a-z0-9_]*)\s*\(/i', $plain, $functions);
    $allowed = array('COUNT', 'MAX', 'MIN', 'SUM', 'AVG', 'CONCAT',
      'COALESCE', 'IFNULL', 'IF', 'LENGTH', 'OCTET_LENGTH', 'LOWER', 'UPPER',
      'SUBSTRING', 'SUBSTR', 'CAST', 'IN', 'NOT', 'AND', 'OR', 'EXISTS',
      'WHERE', 'ON', 'SELECT');
    foreach ($functions[1] as $function) {
      if (!in_array(strtoupper($function), $allowed, true)) {
        return false;
      }
    }
    return true;
  }

  public static function profileTables($sql, $database = null) {
    if (!self::isSupportedSelect($sql)) {
      return null;
    }
    // A fixed SELECT profile, not a general SQL grammar. String values cannot
    // become table names or projection syntax in these native queries.
    $shape = preg_replace("/'(?:\\\\.|''|[^'\\\\])*'/s", "'value'", $sql);
    if ($shape === null) {
      return null;
    }
    $identifiers_valid = true;
    $shape = preg_replace_callback('/`((?:``|[^`])*)`/',
      static function($match) use (&$identifiers_valid) {
        if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $match[1]) ||
            in_array(strtoupper($match[1]), array('SELECT', 'FROM', 'JOIN',
              'STRAIGHT_JOIN', 'WHERE', 'GROUP', 'ORDER', 'HAVING', 'LIMIT',
              'NATURAL', 'CROSS', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'FULL',
              'ON', 'USING', 'PARTITION', 'USE', 'FORCE', 'IGNORE', 'INDEX',
              'TABLE', 'VALUES', 'EXCEPT', 'INTERSECT'), true)) {
          // These quoted identifiers would lose their token identity when
          // normalized and could masquerade as an early clause delimiter.
          // None belongs to this fixed native table/column/alias profile.
          $identifiers_valid = false;
        }
        return $match[1];
      }, $shape);
    if (!$identifiers_valid) {
      return null;
    }
    $shape = ltrim($shape);
    if (preg_match('/[{}]|\b(?:STRAIGHT_JOIN|NATURAL|CROSS|LEFT|RIGHT|'.
        'INNER|OUTER|FULL)\b/i', $shape)) {
      return null;
    }
    if ($database !== null && (!is_string($database) ||
        !preg_match('/\Agovernance_native_[a-f0-9]{16}_'.
          '(user|project|maniphest|phriction|policy|spaces|config|governance)\z/',
          $database))) {
      return null;
    }
    $qualification_valid = true;
    $shape = preg_replace_callback('/\b(FROM|JOIN)\s+([a-z_][a-z0-9_]*)\s*\.\s*'.
      '([a-z_][a-z0-9_]*)\b/i',
      static function($match) use ($database, &$qualification_valid) {
        if ($database === null || $match[2] !== $database) {
          $qualification_valid = false;
        }
        return $match[1].' '.$match[3];
      }, $shape);
    if (!$qualification_valid) {
      return null;
    }
    if (preg_match_all('/\bSELECT\b/i', $shape) !== 1 ||
        !preg_match('/\ASELECT\s+(.*?)\s+FROM\s+([a-z_][a-z0-9_]*)\b/is',
          $shape, $select)) {
      return null;
    }
    $projection = trim($select[1]);
    $projection = preg_replace('/\ADISTINCT\s+/i', '', $projection);
    $column = '(?:[a-z_][a-z0-9_]*\.)?[a-z_][a-z0-9_]*';
    $item = '(?:'.$column.'|(?:[a-z_][a-z0-9_]*\.)?\*|COUNT\((?:\*|'.$column.')\))'.
      '(?:\s+AS\s+[a-z_][a-z0-9_]*)?';
    if (!preg_match('/\A'.$item.'(?:\s*,\s*'.$item.')*\z/i', $projection)) {
      return null;
    }
    $items = preg_split('/\s*,\s*/', $projection);
    if (count($items) > 16 || (count($items) > 1 && str_contains($projection, '*'))) {
      return null;
    }
    $names = array();
    foreach ($items as $projection_item) {
      $name = preg_replace('/\s+AS\s+.*\z/i', '', $projection_item);
      $name = strtolower(preg_replace('/\A[a-z_][a-z0-9_]*\./i', '', $name));
      if (isset($names[$name])) {
        return null;
      }
      $names[$name] = true;
    }
    preg_match('/\bFROM\s+/i', $shape, $from, PREG_OFFSET_CAPTURE);
    $from_tail = substr($shape, $from[0][1] + strlen($from[0][0]));
    $from_clause = preg_split('/\b(?:WHERE|GROUP|ORDER|HAVING|LIMIT)\b/i', $from_tail)[0];
    if (str_contains($from_clause, ',') ||
        preg_match('/\b(?:FROM|JOIN)\s+[a-z_][a-z0-9_]*\s*\./i', $shape)) {
      return null;
    }
    // Enumerating FROM/JOIN tokens alone misses MySQL's STRAIGHT_JOIN and
    // other table-reference grammars. Accept the complete fixed FROM clause,
    // not a keyword count or a denylist of join spellings.
    $from_profile = null;
    if (preg_match('/\A\s*([a-z_][a-z0-9_]*)(?:\s+(?:AS\s+)?'.
        '[a-z_][a-z0-9_]*)?\s*\z/i', $from_clause, $single)) {
      $from_profile = array($single[1]);
    } else if (preg_match('/\A\s*project\s+project\s+JOIN\s+edge\s+e\s+ON\s+'.
        'e\.src\s*=\s*project\.phid\s+AND\s+e\.type\s*=\s*60\s*\z/i',
        $from_clause)) {
      $from_profile = array('project', 'edge');
    } else {
      return null;
    }
    preg_match_all('/\b([a-z_][a-z0-9_]*)\s*\(/i', $shape, $calls);
    foreach ($calls[1] as $call) {
      if (!in_array(strtoupper($call), array('COUNT', 'IN', 'NOT', 'AND', 'OR',
          'WHERE', 'ON', 'SELECT'), true)) {
        return null;
      }
    }
    preg_match_all('/\b(?:FROM|JOIN)\s+([a-z_][a-z0-9_]*)([.])?/i', $shape, $tables);
    if ($tables[1] !== $from_profile || in_array('.', $tables[2], true)) {
      return null;
    }
    if ((count($tables[1]) > 1 && $projection === '*') ||
        count($tables[1]) > 2 || (count($tables[1]) > 1 &&
        !preg_match('/\b(?:DISTINCT|GROUP\s+BY)\b/i', $shape))) {
      return null;
    }
    if (count($tables[1]) === 2 &&
        ($tables[1] !== array('project', 'edge') ||
          preg_match('/\b(CROSS|NATURAL|LEFT|RIGHT|OUTER|FULL|STRAIGHT_JOIN)\b/i', $shape) ||
          !preg_match('/\bJOIN\s+edge\s+e\s+ON\s+e\.src\s*=\s*'.
            'project\.phid\s+AND\s+e\.type\s*=\s*60\b/i', $shape))) {
      return null;
    }
    return array_values(array_unique($tables[1]));
  }

  private function remainingMilliseconds() {
    $remaining = (int)(($this->deadline - hrtime(true)) / 1000000);
    if ($remaining < 1) {
      throw new RuntimeException('snapshot_bridge_time_budget');
    }
    return min(5000, $remaining);
  }

  private function preflightTable($table) {
    if (isset($this->checkedTables[$table])) {
      return;
    }
    $profiles = array('user' => array('user', 'phabricator_session', 'edge'),
      'project' => array('project', 'edge'),
      'maniphest' => array('maniphest_task'),
      'phriction' => array('phriction_document', 'phriction_content'),
      'policy' => array('policy', 'policy_namedpolicy'),
      'spaces' => array('spaces_namespace'), 'config' => array('config_entry'),
      'governance' => array('fixture_guard'));
    preg_match('/_([a-z]+)$/', $this->database, $match);
    if (!isset($profiles[$match[1]]) ||
        !in_array($table, $profiles[$match[1]], true)) {
      throw new RuntimeException('snapshot_bridge_table_profile_refused');
    }
    $qualified = '`'.$this->database.'`.`'.$table.'`';
    $this->connection->query('SET SESSION max_execution_time = '.
      $this->remainingMilliseconds());
    // COUNT acquires the table MDL without materializing a native long value.
    $this->connection->query('SELECT COUNT(*) AS n FROM '.$qualified)->fetch_assoc();
    // Avoid SHOW's full ENUM/type text: these metadata values are server-bounded.
    $columns = $this->connection->query("SELECT COLUMN_NAME AS Field, DATA_TYPE AS Type ".
      "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='".$this->database.
      "' AND TABLE_NAME='".$table."' ORDER BY ORDINAL_POSITION LIMIT 129")
      ->fetch_all(MYSQLI_ASSOC);
    if (!$columns || count($columns) > 128) {
      throw new RuntimeException('snapshot_bridge_column_budget');
    }
    $lengths = array();
    foreach ($columns as $column) {
      if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $column['Field'])) {
        throw new RuntimeException('snapshot_bridge_column_profile_refused');
      }
      $lengths[] = 'COALESCE(OCTET_LENGTH(`'.$column['Field'].'`),0)';
    }
    $length = implode('+', $lengths);
    // SQL-side aggregate returns only three small numbers, never long values.
    // Reading the table acquires MDL for the rest of this owned transaction.
    $budget = $this->connection->query('SELECT COUNT(*) AS n, '.
      'COALESCE(MAX('.$length.'),0) AS m, COALESCE(SUM('.$length.'),0) AS b '.
      'FROM '.$qualified)->fetch_assoc();
    $engine = $this->connection->query("SELECT ENGINE FROM information_schema.TABLES ".
      "WHERE TABLE_SCHEMA='".$this->database."' AND TABLE_NAME='".$table."'")
      ->fetch_assoc();
    if (!$engine || $engine['ENGINE'] !== 'InnoDB' ||
        (int)$budget['n'] > 512 || (int)$budget['m'] > 65536 ||
        (int)$budget['b'] > 524288) {
      $this->preFetchRejections++;
      throw new RuntimeException('snapshot_bridge_pre_fetch_budget_refused');
    }
    $this->remainingMilliseconds();
    $this->checkedTables[$table] = true;
  }

  public function executeQuery(PhutilQueryString $query) {
    if ($this->closed || $this->connection->thread_id !== $this->thread ||
        getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1' ||
        ++$this->attempts > 256 ||
        !self::isSupportedSelect($query->getUnmaskedString())) {
      throw new RuntimeException('snapshot_bridge_query_refused');
    }
    $this->rows = array();
    try {
      $mode = $this->connection->query('SELECT @@SESSION.sql_mode AS mode')
        ->fetch_assoc()['mode'];
      if ($mode !== $this->sqlMode || !self::supportsSqlMode($mode)) {
        throw new RuntimeException('snapshot_bridge_sql_mode_changed');
      }
      $this->connection->select_db($this->database);
      $tables = self::profileTables($query->getUnmaskedString(), $this->database);
      if ($tables === null) {
        throw new RuntimeException('snapshot_bridge_query_profile_refused');
      }
      foreach ($tables as $table) {
        $this->preflightTable($table);
      }
      $this->connection->query('SET SESSION max_execution_time = '.
        $this->remainingMilliseconds());
      $this->nativeFetchAttempts++;
      $result = $this->connection->query($query->getUnmaskedString(), MYSQLI_USE_RESULT);
      if (!$result instanceof mysqli_result) {
        throw new RuntimeException('snapshot_bridge_result_refused');
      }
      $bytes = 0;
      try {
        while ($row = $result->fetch_assoc()) {
          $this->remainingMilliseconds();
          foreach ($row as $value) {
            $bytes += strlen((string)$value);
          }
          if (count($this->rows) >= 512 || $bytes > 524288) {
            throw new RuntimeException('snapshot_bridge_result_budget');
          }
          $this->rows[] = $row;
        }
      } finally {
        $result->free();
      }
      $this->queries++;
    } catch (Throwable $error) {
      throw new RuntimeException('snapshot_bridge_query_incomplete');
    }
  }

  public function selectAllResults() { return $this->rows; }
  public function getInsertID() { throw new RuntimeException('snapshot_bridge_no_write'); }
  public function getAffectedRows() { return count($this->rows); }
  public function executeRawQueries(array $queries) {
    throw new RuntimeException('snapshot_bridge_raw_queries_refused');
  }
  public function openConnection() {
    if ($this->closed) { throw new RuntimeException('snapshot_bridge_closed'); }
  }
  public function simulateErrorOnNextQuery($error) {
    throw new RuntimeException('snapshot_bridge_fault_injection_refused');
  }
  public function close() { $this->closed = true; $this->rows = array(); }
  public function getObservation() {
    return array('query_count' => $this->queries,
      'native_fetch_attempts' => $this->nativeFetchAttempts,
      'pre_fetch_budget_rejections' => $this->preFetchRejections,
      'same_physical_connection' => !$this->closed &&
        $this->connection->thread_id === $this->thread);
  }
  public function escapeBinaryString($value) {
    if ($this->closed) { throw new RuntimeException('snapshot_bridge_closed'); }
    return $this->connection->real_escape_string($value);
  }
  public function escapeUTF8String($value) {
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
      throw new RuntimeException('snapshot_bridge_utf8_refused');
    }
    return $this->escapeBinaryString($value);
  }
  public function escapeColumnName($value) { return '`'.str_replace('`', '``', $value).'`'; }
  public function escapeMultilineComment($value) {
    throw new RuntimeException('snapshot_bridge_comments_refused');
  }
  public function escapeStringForLikeClause($value) {
    return $this->escapeUTF8String(addcslashes($value, '\\%_'));
  }
}
