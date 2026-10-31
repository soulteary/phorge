<?php

final class PhabricatorProjectMembersViewController
  extends PhabricatorProjectController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();
    $id = $request->getURIData('id');

    $project = id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->withIDs(array($id))
      ->needMembers(true)
      ->needWatchers(true)
      ->needImages(true)
      ->executeOne();
    if (!$project) {
      return new Aphront404Response();
    }

    $this->setProject($project);
    $title = pht('Members and Watchers');
    $curtain = $this->buildCurtainView($project);

    $direct_members = $project->isMilestone()
      ? $project->getMemberPHIDs()
      : $project->getDirectMemberPHIDs();
    $inherited_members = array_diff($project->getMemberPHIDs(), $direct_members);

    $member_list = id(new PhabricatorProjectMemberListView())
      ->setViewer($viewer)
      ->setProject($project)
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->setUserPHIDs($direct_members)
      ->setShowNote(true);

    $inherited_list = null;
    if ($inherited_members) {
      $inherited_list = id(new PhabricatorProjectMemberListView())
        ->setViewer($viewer)
        ->setProject($project)
        ->setIsInherited(true)
        ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
        ->setUserPHIDs($inherited_members)
        ->setShowNote(true);
    }

    $watcher_list = id(new PhabricatorProjectWatcherListView())
      ->setViewer($viewer)
      ->setProject($project)
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->setUserPHIDs($project->getWatcherPHIDs())
      ->setShowNote(true);

    $nav = $this->newNavigation(
      $project,
      PhabricatorProject::ITEM_MEMBERS);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(pht('Members'));
    $crumbs->setBorder(true);

    $header = id(new PHUIHeaderView())
      ->setHeader($title)
      ->setHeaderIcon('fa-group');

    require_celerity_resource('project-view-css');

    $view = id(new PHUITwoColumnView())
      ->setHeader($header)
      ->setCurtain($curtain)
      ->addClass('project-view-home')
      ->addClass('project-view-people-home')
      ->setMainColumn(array(
        $member_list,
        $inherited_list,
        $watcher_list,
      ));

    return $this->newPage()
      ->setNavigation($nav)
      ->setCrumbs($crumbs)
      ->setTitle(array($project->getName(), $title))
      ->appendChild($view);
  }

  private function buildCurtainView(PhabricatorProject $project) {
    $viewer = $this->getViewer();
    $id = $project->getID();

    $curtain = $this->newCurtainView();

    $is_locked = $project->getIsMembershipLocked();

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $project,
      PhabricatorPolicyCapability::CAN_EDIT);

    $supports_edit = $project->supportsEditMembers();

    $can_join = $supports_edit && PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $project,
      PhabricatorPolicyCapability::CAN_JOIN);

    $can_leave = $supports_edit && (!$is_locked || $can_edit);

    $viewer_phid = $viewer->getPHID();

    $is_direct_member = in_array(
      $viewer_phid, $project->getDirectMemberPHIDs());

    if (!$is_direct_member) {
      $curtain->addAction(
        id(new PhabricatorActionView())
          ->setHref('/project/update/'.$project->getID().'/join/')
          ->setIcon('fa-plus')
          ->setDisabled(!$can_join)
          ->setWorkflow(true)
          ->setName(pht('Join Project')));
    } else {
      $curtain->addAction(
        id(new PhabricatorActionView())
          ->setHref('/project/update/'.$project->getID().'/leave/')
          ->setIcon('fa-times')
          ->setDisabled(!$can_leave)
          ->setWorkflow(true)
          ->setName(pht('Leave Project')));
    }

    if (!$project->isUserWatcher($viewer->getPHID())) {
      $curtain->addAction(
        id(new PhabricatorActionView())
          ->setWorkflow(true)
          ->setHref('/project/watch/'.$project->getID().'/')
          ->setIcon('fa-eye')
          ->setName(pht('Watch Project')));
    } else {
      $curtain->addAction(
        id(new PhabricatorActionView())
          ->setWorkflow(true)
          ->setHref('/project/unwatch/'.$project->getID().'/')
          ->setIcon('fa-eye-slash')
          ->setName(pht('Unwatch Project')));
    }

    $can_silence = $project->isUserMember($viewer_phid);
    $is_silenced = $this->isProjectSilenced($project);

    if ($is_silenced) {
      $silence_text = pht('Enable Mail');
    } else {
      $silence_text = pht('Disable Mail');
    }

    $curtain->addAction(
      id(new PhabricatorActionView())
        ->setName($silence_text)
        ->setIcon('fa-envelope-o')
        ->setHref("/project/silence/{$id}/")
        ->setWorkflow(true)
        ->setDisabled(!$can_silence));

    $can_add = $can_edit && $supports_edit;

    $curtain->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Add Members'))
        ->setIcon('fa-user-plus')
        ->setHref("/project/members/{$id}/add/")
        ->setWorkflow(true)
        ->setDisabled(!$can_add));

    $can_lock = $can_edit && $supports_edit && $this->hasApplicationCapability(
      ProjectCanLockProjectsCapability::CAPABILITY);

    if ($is_locked) {
      $lock_name = pht('Unlock Project');
      $lock_icon = 'fa-unlock';
    } else {
      $lock_name = pht('Lock Project');
      $lock_icon = 'fa-lock';
    }

    $curtain->addAction(
      id(new PhabricatorActionView())
        ->setName($lock_name)
        ->setIcon($lock_icon)
        ->setHref($this->getApplicationURI("lock/{$id}/"))
        ->setDisabled(!$can_lock)
        ->setWorkflow(true));

    if ($project->isMilestone()) {
      $icon_key = PhabricatorProjectIconSet::getMilestoneIconKey();
      $header = PhabricatorProjectIconSet::getIconName($icon_key);
      $note = pht(
        'Members of the parent project are members of this project.');
      $show_join = false;
    } else if ($project->getHasSubprojects()) {
      $header = pht('Parent Project');
      $note = pht(
        '本级成员可直接管理。子项目成员会汇总到本级；移除其汇总身份需在'.
        '相应子项目中操作。本级成员不会自动加入子项目。');
      $show_join = true;
    } else if ($project->getIsMembershipLocked()) {
      $header = pht('Locked Project');
      $note = pht(
        'Users with access may join this project, but may not leave.');
      $show_join = true;
    } else {
      $header = pht('Normal Project');
      $note = pht('Users with access may join and leave this project.');
      $show_join = true;
    }

    $curtain->newPanel()
      ->setHeaderText($header)
      ->appendChild($note);

    if ($show_join) {
      $descriptions = PhabricatorPolicyQuery::renderPolicyDescriptions(
        $viewer,
        $project);

      $curtain->newPanel()
        ->setHeaderText(pht('Joinable By'))
        ->appendChild($descriptions[PhabricatorPolicyCapability::CAN_JOIN]);
    }

    return $curtain;
  }

  private function isProjectSilenced(PhabricatorProject $project) {
    $viewer = $this->getViewer();

    $viewer_phid = $viewer->getPHID();
    if (!$viewer_phid) {
      return false;
    }

    $edge_type = PhabricatorProjectSilencedEdgeType::EDGECONST;
    $silenced = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $project->getPHID(),
      $edge_type);
    $silenced = array_fuse($silenced);
    return isset($silenced[$viewer_phid]);
  }

}
