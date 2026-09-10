<?php

final class PhabricatorConfigSchemaQuery extends Phobject {

  private $refs;
  private $apis;

  public function setRefs(array $refs) {
    $this->refs = $refs;
    return $this;
  }

  public function getRefs() {
    if (!$this->refs) {
      return PhabricatorDatabaseRef::getMasterDatabaseRefs();
    }
    return $this->refs;
  }

  public function setAPIs(array $apis) {
    $map = array();
    foreach ($apis as $api) {
      $map[$api->getRef()->getRefKey()] = $api;
    }
    $this->apis = $map;
    return $this;
  }

  private function getDatabaseNames(PhabricatorDatabaseRef $ref) {
    $api = $this->getAPI($ref);
    $patches = PhabricatorSQLPatchList::buildAllPatches();
    return $api->getDatabaseList(
      $patches,
      $only_living = true);
  }

  private function getAPI(PhabricatorDatabaseRef $ref) {
    $key = $ref->getRefKey();

    if (isset($this->apis[$key])) {
      return $this->apis[$key];
    }

    return id(new PhabricatorStorageManagementAPI())
      ->setUser($ref->getUser())
      ->setHost($ref->getHost())
      ->setPort($ref->getPort())
      ->setNamespace(PhabricatorLiskDAO::getDefaultStorageNamespace())
      ->setPassword($ref->getPass());
  }

  public function loadActualSchemata() {
    return PhabricatorGorgeDBClient::executeWithFallback(
      'schema.actual',
      function() {
        return $this->loadActualSchemataViaGorge();
      },
      function() {
        return $this->loadActualSchemataNatively();
      });
  }

  private function loadActualSchemataNatively() {
    $refs = $this->getRefs();

    $schemata = array();
    foreach ($refs as $ref) {
      $schema = $this->loadActualSchemaForServer($ref);
      $schemata[$schema->getRef()->getRefKey()] = $schema;
    }

    return $schemata;
  }

  private function loadActualSchemataViaGorge() {
    $refs = $this->getRefs();
    $refs_by_key = mpull($refs, null, 'getRefKey');

    // The service cannot tell an expected-but-restricted database apart from
    // an absent one on its own, so pass the databases Phorge expects as an
    // explicit hint. A restricted one comes back as an accessDenied node; a
    // genuinely missing one is left to the expected-vs-actual comparison, the
    // same division the native path makes with its own SHOW TABLES probe.
    $expected_databases = array();
    foreach ($refs as $ref) {
      foreach ($this->getDatabaseNames($ref) as $database_name) {
        $expected_databases[$database_name] = $database_name;
      }
    }

    $params = array();
    if ($expected_databases) {
      $params['databases'] = implode(',', array_values($expected_databases));
    }

    $client = new PhabricatorGorgeDBClient();
    $nodes = $client->getSchemaDiff($params);

    if (!is_array($nodes)) {
      throw new Exception(
        pht(
          'The Gorge database service returned a malformed "%s" response: '.
          'expected a list of schema trees.',
          '/api/db/schema-diff'));
    }

    $schemata = array();

    // The service returns a forest of SchemaNode trees, one per server, each
    // nested server -> database -> table -> column, with the table's indexes
    // hanging off the table node. Match the top-level node against the refs we
    // already built from configuration and skip any node whose ref we do not
    // recognize.
    foreach ($nodes as $node) {
      if (!is_array($node)) {
        throw new Exception(
          pht(
            'The Gorge database service returned a malformed server node in '.
            'its "%s" response.',
            '/api/db/schema-diff'));
      }

      $ref_key = idx($node, 'refKey', '');
      $ref = idx($refs_by_key, $ref_key);
      if (!$ref) {
        continue;
      }

      $schemata[$ref_key] = self::newServerSchemaFromGorgeNode($ref, $node);
    }

    return $schemata;
  }

  /**
   * Build one server's schema from a decoded `/api/db/schema-diff` node.
   *
   * This is the pure translation from the service's `SchemaNode` tree to
   * Phorge's own `PhabricatorConfig*Schema` objects, split out from the network
   * fetch in @{method:loadActualSchemataViaGorge} so it can be exercised
   * directly against the canonical contract fixtures. It reads exactly the
   * camelCase keys the Go `contracts.SchemaNode`/`contracts.SchemaKey` emit —
   * `databaseName`, `tableName`, `columnName`, `characterSet`, `collation`,
   * `engine`, `columnType`, `nullable`, `autoIncrement`, `accessDenied`, and
   * the `keys` list's `name`/`columnNames`/`unique`/`indexType` — and dropping
   * any one of them here is the regression the fixture test catches.
   *
   * @param PhabricatorDatabaseRef $ref Ref this node describes.
   * @param map<string, wild> $node Decoded top-level server node.
   * @return PhabricatorConfigServerSchema Fully populated server schema.
   */
  public static function newServerSchemaFromGorgeNode(
    PhabricatorDatabaseRef $ref,
    array $node) {

    $server_schema = id(new PhabricatorConfigServerSchema())
      ->setRef($ref);

    $children = idx($node, 'children', array());
    foreach ($children as $db_node) {
      $database_schema = id(new PhabricatorConfigDatabaseSchema())
        ->setName(idx($db_node, 'databaseName', ''));

      $db_charset = idx($db_node, 'characterSet');
      if (phutil_nonempty_string($db_charset)) {
        $database_schema->setCharacterSet($db_charset);
      }
      $db_collation = idx($db_node, 'collation');
      if (phutil_nonempty_string($db_collation)) {
        $database_schema->setCollation($db_collation);
      }

      // A database node the service flagged as existing-but-restricted maps
      // onto the same accessDenied state the native path sets from its own
      // SHOW TABLES probe, so the comparison treats it identically.
      if (idx($db_node, 'accessDenied')) {
        $database_schema->setAccessDenied(true);
      }

      $table_nodes = idx($db_node, 'children', array());
      foreach ($table_nodes as $table_node) {
        $table_schema = id(new PhabricatorConfigTableSchema())
          ->setName(idx($table_node, 'tableName', ''));

        // Tables carry collation and engine only; the schema table class has
        // no character-set setter, matching MySQL's own reflection.
        $table_collation = idx($table_node, 'collation');
        if (phutil_nonempty_string($table_collation)) {
          $table_schema->setCollation($table_collation);
        }
        $table_engine = idx($table_node, 'engine');
        if (phutil_nonempty_string($table_engine)) {
          $table_schema->setEngine($table_engine);
        }

        $column_nodes = idx($table_node, 'children', array());
        foreach ($column_nodes as $col_node) {
          $col_name = idx($col_node, 'columnName', '');
          if (!phutil_nonempty_string($col_name)) {
            continue;
          }

          $column_schema = id(new PhabricatorConfigColumnSchema())
            ->setName($col_name);

          $col_charset = idx($col_node, 'characterSet');
          if (phutil_nonempty_string($col_charset)) {
            $column_schema->setCharacterSet($col_charset);
          }
          $col_collation = idx($col_node, 'collation');
          if (phutil_nonempty_string($col_collation)) {
            $column_schema->setCollation($col_collation);
          }
          $col_type = idx($col_node, 'columnType');
          if (phutil_nonempty_string($col_type)) {
            $column_schema->setColumnType($col_type);
          }
          // Nullability and auto_increment are booleans, so read them only
          // when present rather than defaulting a missing value to false.
          $nullable = idx($col_node, 'nullable');
          if ($nullable !== null) {
            $column_schema->setNullable((bool)$nullable);
          }
          $auto_increment = idx($col_node, 'autoIncrement');
          if ($auto_increment !== null) {
            $column_schema->setAutoIncrement((bool)$auto_increment);
          }

          $table_schema->addColumn($column_schema);
        }

        // The service reflects indexes into the table node's "keys", already
        // ordered and prefixed the way SHOW INDEXES produces, so each maps
        // straight onto a KeySchema.
        $key_nodes = idx($table_node, 'keys', array());
        foreach ($key_nodes as $key_node) {
          if (!is_array($key_node)) {
            continue;
          }

          $column_names = idx($key_node, 'columnNames', array());
          if (!is_array($column_names)) {
            $column_names = array();
          }

          $key_schema = id(new PhabricatorConfigKeySchema())
            ->setName(idx($key_node, 'name', ''))
            ->setColumnNames($column_names)
            ->setUnique((bool)idx($key_node, 'unique', false));

          $index_type = idx($key_node, 'indexType');
          if (phutil_nonempty_string($index_type)) {
            $key_schema->setIndexType($index_type);
          }

          $table_schema->addKey($key_schema);
        }

        $database_schema->addTable($table_schema);
      }

      $server_schema->addDatabase($database_schema);
    }

    return $server_schema;
  }

  private function loadActualSchemaForServer(PhabricatorDatabaseRef $ref) {
    $databases = $this->getDatabaseNames($ref);

    $conn = $ref->newManagementConnection();

    $tables = queryfx_all(
      $conn,
      'SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_COLLATION, ENGINE
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA IN (%Ls)',
      $databases);

    $database_info = queryfx_all(
      $conn,
      'SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
        FROM INFORMATION_SCHEMA.SCHEMATA
        WHERE SCHEMA_NAME IN (%Ls)',
      $databases);
    $database_info = ipull($database_info, null, 'SCHEMA_NAME');

    // Find databases which exist, but which the user does not have permission
    // to see.
    $invisible_databases = array();
    foreach ($databases as $database_name) {
      if (isset($database_info[$database_name])) {
        continue;
      }

      try {
        queryfx($conn, 'SHOW TABLES IN %T', $database_name);
      } catch (AphrontAccessDeniedQueryException $ex) {
        // This database exists, the user just doesn't have permission to
        // see it.
        $invisible_databases[] = $database_name;
      } catch (AphrontSchemaQueryException $ex) {
        // This database is legitimately missing.
      }
    }

    $sql = array();
    foreach ($tables as $table) {
      $sql[] = qsprintf(
        $conn,
        '(TABLE_SCHEMA = %s AND TABLE_NAME = %s)',
        $table['TABLE_SCHEMA'],
        $table['TABLE_NAME']);
    }

    if ($sql) {
      $column_info = queryfx_all(
        $conn,
        'SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME,
            COLLATION_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE %LO',
        $sql);
      $column_info = igroup($column_info, 'TABLE_SCHEMA');
    } else {
      $column_info = array();
    }

    // NOTE: Tables like KEY_COLUMN_USAGE and TABLE_CONSTRAINTS only contain
    // primary, unique, and foreign keys, so we can't use them here. We pull
    // indexes later on using SHOW INDEXES.

    $server_schema = id(new PhabricatorConfigServerSchema())
      ->setRef($ref);

    $tables = igroup($tables, 'TABLE_SCHEMA');
    foreach ($tables as $database_name => $database_tables) {
      $info = $database_info[$database_name];

      $database_schema = id(new PhabricatorConfigDatabaseSchema())
        ->setName($database_name)
        ->setCharacterSet($info['DEFAULT_CHARACTER_SET_NAME'])
        ->setCollation($info['DEFAULT_COLLATION_NAME']);

      $database_column_info = idx($column_info, $database_name, array());
      $database_column_info = igroup($database_column_info, 'TABLE_NAME');

      foreach ($database_tables as $table) {
        $table_name = $table['TABLE_NAME'];

        $table_schema = id(new PhabricatorConfigTableSchema())
          ->setName($table_name)
          ->setCollation($table['TABLE_COLLATION'])
          ->setEngine($table['ENGINE']);

        $columns = idx($database_column_info, $table_name, array());
        foreach ($columns as $column) {
          if (strpos($column['EXTRA'], 'auto_increment') === false) {
            $auto_increment = false;
          } else {
            $auto_increment = true;
          }

          $column_schema = id(new PhabricatorConfigColumnSchema())
            ->setName($column['COLUMN_NAME'])
            ->setCharacterSet($column['CHARACTER_SET_NAME'])
            ->setCollation($column['COLLATION_NAME'])
            ->setColumnType($column['COLUMN_TYPE'])
            ->setNullable($column['IS_NULLABLE'] == 'YES')
            ->setAutoIncrement($auto_increment);

          $table_schema->addColumn($column_schema);
        }

        $key_parts = queryfx_all(
          $conn,
          'SHOW INDEXES FROM %T.%T',
          $database_name,
          $table_name);
        $keys = igroup($key_parts, 'Key_name');
        foreach ($keys as $key_name => $key_pieces) {
          $key_pieces = isort($key_pieces, 'Seq_in_index');
          $head = head($key_pieces);

          // This handles string indexes which index only a prefix of a field.
          $column_names = array();
          foreach ($key_pieces as $piece) {
            $name = $piece['Column_name'];
            if ($piece['Sub_part']) {
              $name = $name.'('.$piece['Sub_part'].')';
            }
            $column_names[] = $name;
          }

          $key_schema = id(new PhabricatorConfigKeySchema())
            ->setName($key_name)
            ->setColumnNames($column_names)
            ->setUnique(!$head['Non_unique'])
            ->setIndexType($head['Index_type']);

          $table_schema->addKey($key_schema);
        }

        $database_schema->addTable($table_schema);
      }

      $server_schema->addDatabase($database_schema);
    }

    foreach ($invisible_databases as $database_name) {
      $server_schema->addDatabase(
        id(new PhabricatorConfigDatabaseSchema())
          ->setName($database_name)
          ->setAccessDenied(true));
    }

    return $server_schema;
  }

  public function loadExpectedSchemata() {
    return PhabricatorGorgeDBClient::executeWithFallback(
      'schema.expected',
      function() {
        return $this->loadExpectedSchemataViaGorge();
      },
      function() {
        return $this->loadExpectedSchemataNatively();
      });
  }

  private function loadExpectedSchemataNatively() {
    $refs = $this->getRefs();

    $schemata = array();
    foreach ($refs as $ref) {
      $schema = $this->loadExpectedSchemaForServer($ref);
      $schemata[$schema->getRef()->getRefKey()] = $schema;
    }

    return $schemata;
  }

  private function loadExpectedSchemataViaGorge() {
    $refs = $this->getRefs();

    $client = new PhabricatorGorgeDBClient();
    $all_charset = $client->getCharsetInfo();

    // Index the per-server charset rows by ref key so each ref can pick up its
    // own charset/collation, falling back to the utf8mb4 defaults when the
    // service does not report a row for it.
    $charset_map = array();
    foreach ($all_charset as $entry) {
      $key = idx($entry, 'refKey', '');
      if (phutil_nonempty_string($key)) {
        $charset_map[$key] = $entry;
      }
    }

    $schemata = array();
    foreach ($refs as $ref) {
      $ref_key = $ref->getRefKey();
      $info = idx($charset_map, $ref_key, null);
      $schema = $this->buildExpectedSchemaFromCharset($ref, $info);
      $schemata[$ref_key] = $schema;
    }

    return $schemata;
  }

  private function buildExpectedSchemaFromCharset(
    PhabricatorDatabaseRef $ref,
    $charset_info = null) {

    if ($charset_info !== null) {
      $charset_default = idx($charset_info, 'charsetDefault', 'utf8mb4');
      $collate_text = idx($charset_info, 'collateText', 'utf8mb4_bin');
      $collate_sort = idx($charset_info, 'collateSort', 'utf8mb4_unicode_ci');
    } else {
      $charset_default = 'utf8mb4';
      $collate_text = 'utf8mb4_bin';
      $collate_sort = 'utf8mb4_unicode_ci';
    }

    $specs = id(new PhutilClassMapQuery())
      ->setAncestorClass(PhabricatorConfigSchemaSpec::class)
      ->execute();

    $server_schema = id(new PhabricatorConfigServerSchema())
      ->setRef($ref);

    foreach ($specs as $spec) {
      $spec
        ->setUTF8Charset($charset_default)
        ->setUTF8BinaryCollation($collate_text)
        ->setUTF8SortingCollation($collate_sort)
        ->setServer($server_schema)
        ->buildSchemata($server_schema);
    }

    return $server_schema;
  }

  public function loadExpectedSchemaForServer(PhabricatorDatabaseRef $ref) {
    $databases = $this->getDatabaseNames($ref);
    $info = $this->getAPI($ref)->getCharsetInfo();

    $specs = id(new PhutilClassMapQuery())
      ->setAncestorClass(PhabricatorConfigSchemaSpec::class)
      ->execute();

    $server_schema = id(new PhabricatorConfigServerSchema())
      ->setRef($ref);

    foreach ($specs as $spec) {
      $spec
        ->setUTF8Charset(
          $info[PhabricatorStorageManagementAPI::CHARSET_DEFAULT])
        ->setUTF8BinaryCollation(
          $info[PhabricatorStorageManagementAPI::COLLATE_TEXT])
        ->setUTF8SortingCollation(
          $info[PhabricatorStorageManagementAPI::COLLATE_SORT])
        ->setServer($server_schema)
        ->buildSchemata($server_schema);
    }

    return $server_schema;
  }

  public function buildComparisonSchemata(
    array $expect_servers,
    array $actual_servers) {

    $schemata = array();
    foreach ($actual_servers as $key => $actual_server) {
      $schemata[$key] = $this->buildComparisonSchemaForServer(
        $expect_servers[$key],
        $actual_server);
    }

    return $schemata;
  }

  private function buildComparisonSchemaForServer(
    PhabricatorConfigServerSchema $expect,
    PhabricatorConfigServerSchema $actual) {

    $comp_server = $actual->newEmptyClone();

    $all_databases = $actual->getDatabases() + $expect->getDatabases();
    foreach ($all_databases as $database_name => $database_template) {
      $actual_database = $actual->getDatabase($database_name);
      $expect_database = $expect->getDatabase($database_name);

      $issues = $this->compareSchemata($expect_database, $actual_database);

      $comp_database = $database_template->newEmptyClone()
        ->setIssues($issues);

      if (!$actual_database) {
        $actual_database = $expect_database->newEmptyClone();
      }

      if (!$expect_database) {
        $expect_database = $actual_database->newEmptyClone();
      }

      $all_tables =
        $actual_database->getTables() +
        $expect_database->getTables();
      foreach ($all_tables as $table_name => $table_template) {
        $actual_table = $actual_database->getTable($table_name);
        $expect_table = $expect_database->getTable($table_name);

        $issues = $this->compareSchemata($expect_table, $actual_table);

        $comp_table = $table_template->newEmptyClone()
          ->setIssues($issues);

        if (!$actual_table) {
          $actual_table = $expect_table->newEmptyClone();
        }
        if (!$expect_table) {
          $expect_table = $actual_table->newEmptyClone();
        }

        $all_columns =
          $actual_table->getColumns() +
          $expect_table->getColumns();
        foreach ($all_columns as $column_name => $column_template) {
          $actual_column = $actual_table->getColumn($column_name);
          $expect_column = $expect_table->getColumn($column_name);

          $issues = $this->compareSchemata($expect_column, $actual_column);

          $comp_column = $column_template->newEmptyClone()
            ->setIssues($issues);

          $comp_table->addColumn($comp_column);
        }

        $all_keys =
          $actual_table->getKeys() +
          $expect_table->getKeys();
        foreach ($all_keys as $key_name => $key_template) {
          $actual_key = $actual_table->getKey($key_name);
          $expect_key = $expect_table->getKey($key_name);

          $issues = $this->compareSchemata($expect_key, $actual_key);

          $comp_key = $key_template->newEmptyClone()
            ->setIssues($issues);

          $comp_table->addKey($comp_key);
        }

        $comp_table->setPersistenceType($expect_table->getPersistenceType());

        $comp_database->addTable($comp_table);
      }
      $comp_server->addDatabase($comp_database);
    }

    return $comp_server;
  }

  private function compareSchemata(
    ?PhabricatorConfigStorageSchema $expect = null,
    ?PhabricatorConfigStorageSchema $actual = null) {

    $expect_is_key = ($expect instanceof PhabricatorConfigKeySchema);
    $actual_is_key = ($actual instanceof PhabricatorConfigKeySchema);

    if ($expect_is_key || $actual_is_key) {
      $missing_issue = PhabricatorConfigStorageSchema::ISSUE_MISSINGKEY;
      $surplus_issue = PhabricatorConfigStorageSchema::ISSUE_SURPLUSKEY;
    } else {
      $missing_issue = PhabricatorConfigStorageSchema::ISSUE_MISSING;
      $surplus_issue = PhabricatorConfigStorageSchema::ISSUE_SURPLUS;
    }

    if (!$expect && !$actual) {
      throw new Exception(pht('Can not compare two missing schemata!'));
    } else if ($expect && !$actual) {
      $issues = array($missing_issue);
    } else if ($actual && !$expect) {
      $issues = array($surplus_issue);
    } else {
      $issues = $actual->compareTo($expect);
    }

    return $issues;
  }


}
