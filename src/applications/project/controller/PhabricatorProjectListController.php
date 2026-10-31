<?php

final class PhabricatorProjectListController
  extends PhabricatorProjectController {

  public function shouldAllowPublic() {
    return true;
  }

  public function handleRequest(AphrontRequest $request) {
    $query_key = PhabricatorProjectNavigation::getQueryKey($request);

    $engine = id(new PhabricatorProjectSearchEngine())
      ->setController($this);
    $search = id(new PhabricatorApplicationSearchController())
      ->setSearchEngine($engine)
      ->setQueryKey($query_key);

    return $this->delegateToController($search);
  }

  protected function buildApplicationCrumbs() {
    $crumbs = parent::buildApplicationCrumbs();

    if (PhabricatorProjectNavigation::isHierarchyDefault()) {
      $crumbs->addAction(
        id(new PHUIListItemView())
          ->setName(pht('Project Hierarchy'))
          ->setIcon('fa-sitemap')
          ->setHref('/project/query/hierarchy/'));
    }

    id(new PhabricatorProjectEditEngine())
      ->setViewer($this->getViewer())
      ->addActionToCrumbs($crumbs);

    return $crumbs;
  }

}
