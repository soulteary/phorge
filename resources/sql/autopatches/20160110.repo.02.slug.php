<?php

// PhabricatorRepository is gone, including its isValidRepositorySlug()
// validator. The rules it enforced are reproduced inline below so this patch
// still refuses the same names it always refused; they are a fixed historical
// contract, not live configuration.
final class PhabricatorRepositorySlugMigrationDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository';
  }

  public static function isValidSlug($slug) {
    if (!phutil_nonempty_string($slug)) {
      return false;
    }

    if (strlen($slug) > 64) {
      return false;
    }

    if (preg_match('/[^a-zA-Z0-9._-]/', $slug)) {
      return false;
    }

    if (!preg_match('/^[a-zA-Z0-9]/', $slug)) {
      return false;
    }

    if (!preg_match('/[a-zA-Z0-9]\z/', $slug)) {
      return false;
    }

    if (preg_match('/__|--|\.\./', $slug)) {
      return false;
    }

    if (preg_match('/^[A-Z]+\z/', $slug)) {
      return false;
    }

    if (preg_match('/^\d+\z/', $slug)) {
      return false;
    }

    return true;
  }

}

$table = new PhabricatorRepositorySlugMigrationDAO();
$conn_w = $table->establishConnection('w');

foreach (new LiskRawMigrationIterator($conn_w, $table->getTableName())
  as $repository) {

  if ($repository['repositorySlug'] !== null) {
    continue;
  }

  $details = phutil_json_decode($repository['details']);
  $clone_name = idx($details, 'clone-name');

  if (!phutil_nonempty_string($clone_name)) {
    continue;
  }

  $display_name = $repository['name'];

  if (!PhabricatorRepositorySlugMigrationDAO::isValidSlug($clone_name)) {
    echo tsprintf(
      "%s\n",
      pht(
        'Repository "%s" has a "Clone/Checkout As" name which is no longer '.
        'valid ("%s"). You can edit the repository to give it a new, valid '.
        'short name.',
        $display_name,
        $clone_name));
    continue;
  }

  try {
    queryfx(
      $conn_w,
      'UPDATE %T SET repositorySlug = %s WHERE id = %d',
      $table->getTableName(),
      $clone_name,
      $repository['id']);
  } catch (AphrontDuplicateKeyQueryException $ex) {
    echo tsprintf(
      "%s\n",
      pht(
        'Repository "%s" has a duplicate "Clone/Checkout As" name ("%s"). '.
        'Each name must now be unique. You can edit the repository to give '.
        'it a new, unique short name.',
        $display_name,
        $clone_name));
  }

}
