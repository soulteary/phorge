<?php

final class PhabricatorGorgeCleanupSchemaSpec
  extends PhabricatorConfigSchemaSpec {

  public function buildSchemata() {
    foreach (array('cache', 'conduit', 'daemon', 'differential', 'multimeter') as $application) {
      $this->buildRawSchema(
        $application,
        'gorge_gc_control',
        array(
          'collectorID' => 'varbytes64',
          'owner' => 'varbytes8',
          'ownerEpoch' => 'uint64',
          'fence' => 'uint64',
          'policyJSON' => 'text',
          'policyHash' => 'varbytes64',
          'leaseOwner' => 'varbytes64',
          'leaseExpires' => 'uint64',
          'nextRun' => 'uint64',
          'cycleCutoff' => 'uint64',
          'deletedRows' => 'uint64',
          'lastSuccess' => 'uint64',
          'lastState' => 'varbytes32',
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
