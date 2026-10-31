<?php

final class PhabricatorProjectMemberListView
  extends PhabricatorProjectUserListView {

  private $isInherited = false;

  public function setIsInherited($is_inherited) {
    $this->isInherited = $is_inherited;
    return $this;
  }

  protected function canEditList() {
    if ($this->isInherited) {
      return false;
    }
    $viewer = $this->getViewer();
    $project = $this->getProject();

    if (!$project->supportsEditMembers()) {
      return false;
    }

    return PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $project,
      PhabricatorPolicyCapability::CAN_EDIT);
  }

  protected function getNoDataString() {
    return pht('This project does not have any members.');
  }

  protected function getRemoveURI($phid) {
    if ($this->isInherited) {
      return null;
    }
    $project = $this->getProject();
    $id = $project->getID();
    return "/project/members/{$id}/remove/?phid={$phid}";
  }

  protected function getHeaderText() {
    if ($this->isInherited) {
      return pht('来自子项目的成员');
    }
    if ($this->getProject()->getHasSubprojects() && !$this->getLimit()) {
      return pht('本级直接成员');
    }
    return pht('Members');
  }

  protected function getMembershipNote() {
    if ($this->isInherited) {
      return pht('这些成员通过子项目加入本级，请在相应子项目中管理其成员身份。');
    }
    $viewer = $this->getViewer();
    $viewer_phid = $viewer->getPHID();
    $project = $this->getProject();

    if (!$viewer_phid) {
      return null;
    }

    $note = null;
    if ($project->isUserMember($viewer_phid)) {
      $edge_type = PhabricatorProjectSilencedEdgeType::EDGECONST;
      $silenced = PhabricatorEdgeQuery::loadDestinationPHIDs(
        $project->getPHID(),
        $edge_type);
      $silenced = array_fuse($silenced);
      $is_silenced = isset($silenced[$viewer_phid]);
      if ($is_silenced) {
        $note = pht(
          'You have disabled mail. When mail is sent to project members, '.
          'you will not receive a copy.');
      } else {
        $note = pht(
          'You are a member and you will receive mail that is sent to all '.
          'project members.');
      }
    }

    return $note;
  }

}
