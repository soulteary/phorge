<?php

final class PhabricatorProjectListController
  extends PhabricatorProjectController {

  public function shouldAllowPublic() {
    return true;
  }

  public function handleRequest(AphrontRequest $request) {
    $query_key = $request->getURIData('queryKey');
    $query_data = array_diff_key(
      $request->getPassthroughRequestData(),
      array_fuse(array('before', 'after', 'nux', 'overheated')));

    // The application entry is the project directory, including for users
    // with an old pinned flat query. Explicit searches and form submissions
    // retain the normal search engine behavior.
    if (!$query_key && !$query_data && !$request->isFormPost()) {
      $query_key = 'hierarchy';
    }

    $engine = id(new PhabricatorProjectSearchEngine())
      ->setController($this);
    $search = id(new PhabricatorApplicationSearchController())
      ->setSearchEngine($engine)
      ->setQueryKey($query_key);

    return $this->delegateToController($search);
  }

  protected function buildApplicationCrumbs() {
    $crumbs = parent::buildApplicationCrumbs();

    $crumbs->addAction(
      id(new PHUIListItemView())
        ->setName(pht('项目层级'))
        ->setIcon('fa-sitemap')
        ->setHref('/project/query/hierarchy/'));

    id(new PhabricatorProjectEditEngine())
      ->setViewer($this->getViewer())
      ->addActionToCrumbs($crumbs);

    return $crumbs;
  }

}
