<?php

// Disable Herald rules which test a condition backed by Differential revisions.
//
// Same stored-state problem the Owners removal left one branch earlier, and the
// same failure mode: "herald_condition.fieldName" is a string, so a rule keeps
// naming a field whose implementation is gone. HeraldAdapter::
// requireFieldImplementation() throws, HeraldEngine catches it into the
// transcript, and the rule contributes no actions -- so a pre-commit rule that
// blocked a push silently stops blocking it.
//
// The fields below fall into two groups, and both end up here:
//
//   - fields on adapters which are themselves gone ("differential.*"), where
//     the rule could not run anyway;
//   - fields on adapters which are RETAINED -- the commit and pre-commit
//     content adapters -- where the rule still runs, still evaluates its other
//     conditions, and throws only on this one. Those are the dangerous ones.
//
// As with the Owners migration, the condition being tested cannot be preserved:
// there are no revisions. So the point is to make the loss visible. Rules are
// disabled and named in the upgrade log; their conditions are left intact so an
// administrator can see what the rule used to test.

$rule_table = new HeraldRule();
$conn = $rule_table->establishConnection('w');

$condition_table = new HeraldCondition();

// FIELDCONST values of the removed revision-backed fields. These are stored
// strings, so the literals are what existing rows carry.
$removed_fields = array(
  'differential.diff.affected',
  'differential.diff.author',
  'differential.diff.author.projects',
  'differential.diff.content',
  'differential.diff.new',
  'differential.diff.old',
  'differential.diff.repository',
  'differential.diff.repository.projects',
  'differential.revision.author',
  'differential.revision.author.packages',
  'differential.revision.author.projects',
  'differential.revision.diff.affected',
  'differential.revision.diff.content',
  'differential.revision.diff.new',
  'differential.revision.diff.old',
  'differential.revision.jira.uris',
  'differential.revision.package',
  'differential.revision.package.owners',
  'differential.revision.repository',
  'differential.revision.repository.projects',
  'differential.revision.reviewers',
  'differential.revision.summary',
  'differential.revision.test-plan',
  'differential.revision.title',
  'diffusion.commit.builds.wrong',
  'diffusion.commit.revision',
  'diffusion.commit.revision.accepted',
  'diffusion.commit.revision.accepting',
  'diffusion.commit.revision.reviewers',
  'diffusion.commit.revision.subscribers',
  'diffusion.pre.content.builds.wrong',
  'diffusion.pre.content.revision',
  'diffusion.pre.content.revision.accepted',
  'diffusion.pre.content.revision.accepting',
  'diffusion.pre.content.revision.reviewers',
  'diffusion.pre.content.revision.subscribers',
  'revision.status',
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
  echo pht('No Herald rules test removed Differential revision fields.')."\n";
} else {
  foreach ($rows as $row) {
    echo pht(
      'Disabling Herald rule %d ("%s"): it tests a removed Differential '.
      'revision field and can no longer evaluate.',
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
    'based on revision state no longer blocks anything, whether it is '.
    'enabled or not.',
    count($rows))."\n";
}
