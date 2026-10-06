<?php

final class PhabricatorGorgeCleanupSchemaSpec
  extends PhabricatorConfigSchemaSpec {

  public function buildSchemata() {
    foreach (array('cache', 'conduit', 'daemon', 'differential', 'multimeter') as $application) {
      $this->buildRawSchema(
        $application,
        'gorge_gc_control',
        array(
          'collectorID' => 'bytes64',
          'owner' => 'bytes8',
          'ownerEpoch' => 'uint64',
          'fence' => 'uint64',
          'policyJSON' => 'text',
          'policyHash' => 'bytes64',
          'leaseOwner' => 'bytes64',
          'leaseExpires' => 'uint64',
          'nextRun' => 'uint64',
          'cycleCutoff' => 'uint64',
          'deletedRows' => 'uint64',
          'lastSuccess' => 'uint64',
          'lastState' => 'bytes32',
          'failures' => 'uint32',
          'lastError' => 'text',
        ),
        array('PRIMARY' => array(
          'columns' => array('collectorID'),
          'unique' => true,
        )));
    }
  }

}
