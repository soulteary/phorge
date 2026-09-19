<?php

// The Harbormaster application has been removed, so its models no longer
// exist. This patch still has to run against installations whose schema
// predates it, and it wanted those models only for a connection and two table
// names, so it declares the minimum here. HarbormasterDAO is retained for the
// legacy "harbormaster" database.
final class HarbormasterRenameMigrationStepDAO extends HarbormasterDAO {

  public function getTableName() {
    return 'harbormaster_buildstep';
  }

}

final class HarbormasterRenameMigrationTargetDAO extends HarbormasterDAO {

  public function getTableName() {
    return 'harbormaster_buildtarget';
  }

}

$names = array(
  'CommandBuildStepImplementation',
  'LeaseHostBuildStepImplementation',
  'PublishFragmentBuildStepImplementation',
  'SleepBuildStepImplementation',
  'UploadArtifactBuildStepImplementation',
  'WaitForPreviousBuildStepImplementation',
);

$step_table = new HarbormasterRenameMigrationStepDAO();

$tables = array(
  $step_table->getTableName(),
  id(new HarbormasterRenameMigrationTargetDAO())->getTableName(),
);

echo pht('Renaming Harbormaster classes...')."\n";

$conn_w = $step_table->establishConnection('w');
foreach ($names as $name) {
  $old = $name;
  $new = 'Harbormaster'.$name;

  echo pht('Renaming %s -> %s...', $old, $new)."\n";
  foreach ($tables as $table) {
    queryfx(
      $conn_w,
      'UPDATE %T SET className = %s WHERE className = %s',
      $table,
      $new,
      $old);
  }
}

echo pht('Done.')."\n";
