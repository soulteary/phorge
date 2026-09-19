<?php

/**
 * Lists the paths a commit touched, as absolute paths with a trailing slash
 * on directories.
 *
 * This was filed under the Owners application, which used it to match commits
 * against package paths, but it is a plain Diffusion path query and has no
 * connection to packages.
 */
final class DiffusionCommitAffectedPathQuery extends Phobject {

  public static function loadAffectedPaths(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit,
    PhabricatorUser $user) {

    $drequest = DiffusionRequest::newFromDictionary(
      array(
        'user'        => $user,
        'repository'  => $repository,
        'commit'      => $commit->getCommitIdentifier(),
      ));

    $path_query = DiffusionPathChangeQuery::newFromDiffusionRequest(
      $drequest);
    $paths = $path_query->loadChanges();

    $result = array();
    foreach ($paths as $path) {
      $basic_path = '/'.$path->getPath();
      if ($path->getFileType() == DifferentialChangeType::FILE_DIRECTORY) {
        $basic_path = rtrim($basic_path, '/').'/';
      }
      $result[] = $basic_path;
    }
    return $result;
  }

}
