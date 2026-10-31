<?php

/** Directory presentation is independent of all project permission checks. */
final class PhabricatorProjectNavigation extends Phobject {

  public static function isHierarchyDefault() {
    return PhabricatorEnv::getEnvConfig('projects.default-navigation') ===
      'hierarchy';
  }

  public static function getQueryKey(AphrontRequest $request) {
    $query_key = $request->getURIData('queryKey');
    if (!self::isHierarchyDefault() || $query_key || $request->isHTTPPost()) {
      return $query_key;
    }

    $query_data = array_diff_key(
      $request->getPassthroughRequestData(),
      array_fuse(array('before', 'after', 'nux', 'overheated')));
    return $query_data ? $query_key : 'hierarchy';
  }

}
