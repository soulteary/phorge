<?php

// Preserve the view restriction that retained diffs used to inherit from their
// revision.
//
// Before the revision layer was removed, DifferentialDiff::getExtendedPolicy()
// deferred CAN_VIEW to the attached revision when there was one, and only fell
// back to the repository otherwise. A diff belonging to a private revision was
// therefore restricted by the revision, and its own "viewPolicy" column was
// left at the broad default nobody ever had to tighten.
//
// With the revision gone the extended policy now falls through to the
// repository, so that same diff becomes visible to anyone who can see the
// repository -- and Harbormaster build and log records, which inherit their
// policy from the buildable diff, follow it.
//
// The revision rows still exist even though the class does not, so the
// restriction can be copied onto the diffs that relied on it. Only restrictive
// policies are copied: a revision left at "public" or "users" was not
// restricting anything, and rewriting those would churn every historical row
// for no gain -- and could loosen a diff an administrator had tightened by
// hand.

$diff_table = new DifferentialDiff();
$conn = $diff_table->establishConnection('w');

// Table name DifferentialRevision produced before it was removed. The class is
// gone; the rows and their viewPolicy are not.
$revision_table_name = 'differential_revision';

// PhabricatorPolicies::POLICY_PUBLIC and ::POLICY_USER. A revision at either
// was not restricting its diffs beyond what the repository already does.
$open_policies = array(
  PhabricatorPolicies::POLICY_PUBLIC,
  PhabricatorPolicies::POLICY_USER,
);

$rows = queryfx_all(
  $conn,
  'SELECT d.id, r.viewPolicy FROM %T d
     JOIN %T r ON d.revisionID = r.id
     WHERE d.revisionID IS NOT NULL
       AND r.viewPolicy NOT IN (%Ls)
       AND d.viewPolicy != r.viewPolicy',
  $diff_table->getTableName(),
  $revision_table_name,
  $open_policies);

if (!$rows) {
  echo pht('No retained diffs inherited a restrictive revision policy.')."\n";
} else {
  echo pht(
    'Copying the revision view policy onto %d retained diff(s)...',
    count($rows))."\n";

  foreach (array_chunk($rows, 500) as $chunk) {
    foreach ($chunk as $row) {
      queryfx(
        $conn,
        'UPDATE %T SET viewPolicy = %s WHERE id = %d',
        $diff_table->getTableName(),
        $row['viewPolicy'],
        $row['id']);
    }
  }

  echo pht('Done.')."\n";
}
