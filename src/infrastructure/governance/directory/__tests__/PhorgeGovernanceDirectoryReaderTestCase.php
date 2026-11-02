<?php

final class PhorgeGovernanceDirectoryReaderTestCase
  extends PhutilTestCase {

  public function testDirectoryQueryBounds() {
    PhorgeGovernanceDirectoryReader::validateOptions(
      'PHID-USER-aaaaaaaaaaaaaaaaaaaa', 0, 100, '团队');
    $this->assertTrue(true);
    foreach (array(-1, 0, 101, '50', PHP_INT_MAX) as $limit) {
      $caught = false;
      try {
        PhorgeGovernanceDirectoryReader::validateOptions(
          'PHID-USER-aaaaaaaaaaaaaaaaaaaa', 0, $limit, '');
      } catch (InvalidArgumentException $ex) {
        $caught = true;
      }
      $this->assertTrue($caught, 'Unbounded or coerced limits are rejected.');
    }
  }

  public function testDirectoryIdentityAndSearchValidation() {
    $inputs = array(
      array('PHID-USER-short', 0, ''),
      array('PHID-USER-aaaaaaaaaaaaaaaaaaaa', -1, ''),
      array('PHID-USER-aaaaaaaaaaaaaaaaaaaa', '1', ''),
      array('PHID-USER-aaaaaaaaaaaaaaaaaaaa', 0, str_repeat('x', 201)),
      array('PHID-USER-aaaaaaaaaaaaaaaaaaaa', 0, "\xFF"),
    );
    foreach ($inputs as $input) {
      $caught = false;
      try {
        PhorgeGovernanceDirectoryReader::validateOptions(
          $input[0], $input[1], 50, $input[2]);
      } catch (InvalidArgumentException $ex) {
        $caught = true;
      }
      $this->assertTrue($caught, 'Malformed cursor, identity or query rejected.');
    }
  }

  public function testExplicitFixtureConnectionBoundary() {
    $database = PhorgeGovernanceIsolatedDirectoryStore::validateFixtureDSN(
      'mysql:host=127.0.0.1;port=3306;dbname=governance_fixture_unit;'.
      'charset=utf8mb4');
    $this->assertEqual('governance_fixture_unit', $database);
    $rejected = array(
      'mysql:host=database;dbname=governance_fixture_unit',
      'mysql:host=127.0.0.1;dbname=application',
      'mysql:host=127.0.0.1;dbname=governance_fixture_unit;host=database',
      'mysql:host=127.0.0.1;dbname=governance_fixture_unit;port=0',
      'mysql:host=127.0.0.1;dbname=governance_fixture_unit;charset=latin1',
      'mysql:unix_socket=/tmp/mysql.sock;dbname=governance_fixture_unit',
      'sqlite::memory:',
    );
    foreach ($rejected as $dsn) {
      $caught = false;
      try {
        PhorgeGovernanceIsolatedDirectoryStore::validateFixtureDSN($dsn);
      } catch (InvalidArgumentException $ex) {
        $caught = true;
      }
      $this->assertTrue($caught, 'Deployment-like or ambiguous DSN rejected.');
    }
  }

}
