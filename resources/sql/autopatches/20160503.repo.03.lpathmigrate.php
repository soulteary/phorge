<?php

// PhabricatorRepository is gone. The only model behaviour this patch relied
// on was setLocalPath()'s normalization, which collapsed runs of slashes; it
// is reproduced inline.
final class PhabricatorLocalPathMigrationDAO
  extends PhabricatorRepositoryDAO {

  public function getTableName() {
    return 'repository';
  }

  public static function normalizeLocalPath($path) {
    return preg_replace('(//+)', '/', $path);
  }

}

$table = new PhabricatorLocalPathMigrationDAO();
$conn_w = $table->establishConnection('w');

$default_path = PhabricatorEnv::getEnvConfig('repository.default-local-path');
$default_path = rtrim($default_path, '/');

foreach (new LiskRawMigrationIterator($conn_w, $table->getTableName())
  as $repository) {

  if (phutil_nonempty_string($repository['localPath'])) {
    // Repository already has a modern, unique local path.
    continue;
  }

  $details = phutil_json_decode($repository['details']);
  $local_path = idx($details, 'local-path');
  if (!phutil_nonempty_string($local_path)) {
    // Repository does not have a local path using the older format.
    continue;
  }

  $id = $repository['id'];
  $random = Filesystem::readRandomCharacters(8);

  // Try the configured path first, then a default path, then a path with some
  // random noise.
  $paths = array(
    $local_path,
    $default_path.'/'.$id.'/',
    $default_path.'/'.$id.'-'.$random.'/',
  );

  foreach ($paths as $path) {
    $path = PhabricatorLocalPathMigrationDAO::normalizeLocalPath($path);

    try {
      queryfx(
        $conn_w,
        'UPDATE %T SET localPath = %s WHERE id = %d',
        $table->getTableName(),
        $path,
        $id);

      echo tsprintf(
        "%s\n",
        pht(
          'Assigned repository "%s" to local path "%s".',
          $repository['name'],
          $path));

      break;
    } catch (AphrontDuplicateKeyQueryException $ex) {
      // Ignore, try the next one.
    }
  }
}
