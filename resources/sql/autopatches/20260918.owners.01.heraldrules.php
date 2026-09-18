<?php

// Disable Herald rules which test a condition backed by Owners packages.
//
// Removing the application removed the nine field implementations below, but
// "herald_condition.fieldName" is a stored string: an existing rule keeps
// naming a field which no longer resolves. HeraldAdapter::
// requireFieldImplementation() then throws, HeraldEngine catches it into the
// transcript as an evaluation exception, and the rule's actions do not run.
//
// For a reporting rule that is merely useless. For a *pre-commit* rule whose
// action blocks a push, it fails open: the push is accepted, silently, with
// the only trace in a Herald transcript nobody reads.
//
// The protection itself cannot be preserved -- it tested package membership,
// and there are no packages any more -- so the goal here is to make its loss
// loud instead of silent. Disabling the rule stops the same pushes as the
// broken rule did, but leaves an administrator a disabled rule to look at and
// this message in the upgrade log.
//
// Conditions are left in place rather than deleted: dropping one silently
// changes what the remaining conditions mean, and an administrator re-enabling
// the rule should see what it used to test.

$rule_table = new HeraldRule();
$conn = $rule_table->establishConnection('w');

$condition_table = new HeraldCondition();

// FIELDCONST values of the removed Owners-backed fields. These are stored
// strings, so the literals are what existing rows carry.
$removed_fields = array(
  'diffusion.commit.author.packages',
  'diffusion.commit.committer.packages',
  'diffusion.commit.package',
  'diffusion.commit.package.audit',
  'diffusion.commit.package.owners',
  'diffusion.pre.commit.author.packages',
  'diffusion.pre.commit.committer.packages',
  'diffusion.pre.content.package',
  'diffusion.pre.content.package.owners',
);

$rows = queryfx_all(
  $conn,
  'SELECT DISTINCT r.id, r.name FROM %T r
     JOIN %T c ON c.ruleID = r.id
     WHERE c.fieldName IN (%Ls) AND r.isDisabled = 0
     ORDER BY r.id',
  $rule_table->getTableName(),
  $condition_table->getTableName(),
  $removed_fields);

if (!$rows) {
  echo pht('No Herald rules test removed Owners package fields.')."\n";
} else {
  foreach ($rows as $row) {
    echo pht(
      'Disabling Herald rule %d ("%s"): it tests a removed Owners package '.
      'field and can no longer evaluate.',
      $row['id'],
      $row['name'])."\n";
  }

  queryfx(
    $conn,
    'UPDATE %T SET isDisabled = 1 WHERE id IN (%Ld)',
    $rule_table->getTableName(),
    ipull($rows, 'id'));

  echo pht(
    'Disabled %d Herald rule(s). Review them: a rule which blocked pushes '.
    'based on package membership no longer blocks anything, whether it is '.
    'enabled or not.',
    count($rows))."\n";
}
