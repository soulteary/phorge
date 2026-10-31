<?php

final class PhabricatorProjectListView extends AphrontView {

  private $projects;
  private $showMember;
  private $showWatching;
  private $noDataString;
  private $browseHierarchy;
  private $showHierarchyPath;

  public function setBrowseHierarchy($browse) {
    $this->browseHierarchy = $browse;
    return $this;
  }

  public function setShowHierarchyPath($show) {
    $this->showHierarchyPath = $show;
    return $this;
  }

  public function setProjects(array $projects) {
    $this->projects = $projects;
    return $this;
  }

  public function getProjects() {
    return $this->projects;
  }

  public function setShowWatching($watching) {
    $this->showWatching = $watching;
    return $this;
  }

  public function setShowMember($member) {
    $this->showMember = $member;
    return $this;
  }

  public function setNoDataString($text) {
    $this->noDataString = $text;
    return $this;
  }

  public function renderList() {
    $viewer = $this->getUser();
    $viewer_phid = $viewer->getPHID();
    $projects = $this->getProjects();

    $no_data = pht('No projects found.');
    if ($this->noDataString) {
      $no_data = $this->noDataString;
    }

    $list = id(new PHUIObjectItemListView())
      ->setViewer($viewer)
      ->setNoDataString($no_data);

    foreach ($projects as $key => $project) {
      $id = $project->getID();

      $icon = $project->getDisplayIconIcon();
      $icon_icon = id(new PHUIIconView())
        ->setIcon($icon);

      $icon_name = $project->getDisplayIconName();

      $item = id(new PHUIObjectItemView())
        ->setObject($project)
        ->setHeader($project->getName())
        ->setHref("/project/view/{$id}/")
        ->setImageURI($project->getProfileImageURI())
        ->addAttribute(
          array(
            $icon_icon,
            ' ',
            $icon_name,
          ));

      if ($this->showHierarchyPath) {
        $path = array();
        foreach (array_reverse($project->getAncestorProjects()) as $ancestor) {
          // Project queries attach ancestors for policy evaluation. Check
          // visibility before rendering their names into search results.
          if (!PhabricatorPolicyFilter::hasCapability(
            $viewer, $ancestor, PhabricatorPolicyCapability::CAN_VIEW)) {
            $path = array(pht('Restricted Parent Project'));
            continue;
          }
          $path[] = phutil_tag(
            'a',
            array('href' => '/project/subprojects/'.$ancestor->getID().'/'),
            $ancestor->getName());
        }
        $item->addAttribute($path
          ? phutil_implode_html(' / ', $path)
          : pht('Root Project'));
      }

      if ($this->browseHierarchy && $project->supportsSubprojects()) {
        $item->addAttribute(phutil_tag(
          'a',
          array('href' => "/project/subprojects/{$id}/"),
          pht('Browse Subprojects')));
      }

      if ($project->getStatus() == PhabricatorProjectStatus::STATUS_ARCHIVED) {
        $item->addIcon('fa-ban', pht('Archived'));
        $item->setDisabled(true);
      }

      if ($this->showMember) {
        $is_member = $project->isUserMember($viewer_phid);
        if ($is_member) {
          $item->addIcon('fa-user', pht('Member'));
        }
      }

      if ($this->showWatching) {
        $is_watcher = $project->isUserWatcher($viewer_phid);
        if ($is_watcher) {
          $item->addIcon('fa-eye', pht('Watching'));
        }
      }

      $subtype = $project->newSubtypeObject();
      if ($subtype && $subtype->hasTagView()) {
        $subtype_tag = $subtype->newTagView()
          ->setSlimShady(true);
        $item->addAttribute($subtype_tag);
      }

      $list->addItem($item);
    }

    return $list;
  }

  public function render() {
    return $this->renderList();
  }

}
