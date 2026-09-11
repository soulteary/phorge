<?php

// The Harbormaster application has been removed, so its model and application
// classes no longer exist. This patch still has to run against installations
// whose schema predates it. HarbormasterDAO is retained for the legacy
// "harbormaster" database.
final class HarbormasterPolicyMigrationPlanDAO extends HarbormasterDAO {

  public function getTableName() {
    return 'harbormaster_buildplan';
  }

}

$table = new HarbormasterPolicyMigrationPlanDAO();
$conn_w = $table->establishConnection('w');

$view_policy = PhabricatorPolicies::getMostOpenPolicy();
queryfx(
  $conn_w,
  'UPDATE %T SET viewPolicy = %s WHERE viewPolicy = %s',
  $table->getTableName(),
  $view_policy,
  '');

// This was the default for HarbormasterCreatePlansCapability, which is what
// PhabricatorHarbormasterApplication::getPolicy() returned for any install
// which had not overridden it. With the application gone there is no
// configured value left to read.
$edit_policy = PhabricatorPolicies::POLICY_ADMIN;
queryfx(
  $conn_w,
  'UPDATE %T SET editPolicy = %s WHERE editPolicy = %s',
  $table->getTableName(),
  $edit_policy,
  '');
