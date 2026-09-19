<?php

final class DifferentialSchemaSpec extends PhabricatorConfigSchemaSpec {

  public function buildSchemata() {
    // The edge table is declared through a diff rather than a revision now:
    // both are in the "differential" database, and the revision models have
    // been removed. Existing edge rows are left in place.
    $this->buildEdgeSchemata(new DifferentialDiff());

    $this->buildRawSchema(
      id(new DifferentialDiff())->getApplicationName(),
      DifferentialChangeset::TABLE_CACHE,
      array(
        'id' => 'auto',
        'cacheIndex' => 'bytes12',
        'cache' => 'bytes',
        'dateCreated' => 'epoch',
      ),
      array(
        'PRIMARY' => array(
          'columns' => array('id'),
          'unique' => true,
        ),
        'key_cacheIndex' => array(
          'columns' => array('cacheIndex'),
          'unique' => true,
        ),
        'key_created' => array(
          'columns' => array('dateCreated'),
        ),
      ),
      array(
        'persistence' => PhabricatorConfigTableSchema::PERSISTENCE_CACHE,
      ));

    $this->buildRawSchema(
      id(new DifferentialDiff())->getApplicationName(),
      ArcanistDifferentialRevisionHash::TABLE_NAME,
      array(
        'revisionID' => 'id',
        'type' => 'bytes4',
        'hash' => 'bytes40',
      ),
      array(
        'type' => array(
          'columns' => array('type', 'hash'),
        ),
        'revisionID' => array(
          'columns' => array('revisionID'),
        ),
      ));


  }

}
