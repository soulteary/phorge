<?php

/**
 * Conservative defaults for newly created projects and tasks.
 *
 * These are ordinary, selectable object policies. They do not rewrite existing
 * objects or prevent an authorized editor from explicitly sharing an object.
 * Project membership and task assignment retain their native automatic rights.
 */
abstract class TeamPrivateAuthorPolicyRule extends PhabricatorPolicyRule {

  public function getValueControlType() {
    return self::CONTROL_TYPE_NONE;
  }

  protected function isEligiblePerson(PhabricatorUser $viewer) {
    return $viewer->getPHID() && $viewer->getIsApproved() &&
      !$viewer->getIsDisabled() && !$viewer->getIsSystemAgent() &&
      !$viewer->getIsMailingList();
  }

  protected function isAuthorOrAdministrator(
    PhabricatorUser $viewer,
    $author_phid) {
    if (!$this->isEligiblePerson($viewer)) {
      return false;
    }
    return $viewer->getIsAdmin() ||
      ($author_phid && $viewer->getPHID() === $author_phid);
  }
}

final class TeamPrivateProjectPolicyRule extends TeamPrivateAuthorPolicyRule {
  const POLICY = 'obj.team.project-author-admin';

  public function getObjectPolicyKey() { return 'team.project-author-admin'; }
  public function getObjectPolicyName() { return pht('项目创建者与站点管理员'); }
  public function getRuleDescription() { return pht('项目创建者或站点管理员'); }
  public function getPolicyExplanation() {
    return pht('创建者和站点管理员可操作；项目成员按原生规则自动可见。');
  }

  public function canApplyToObject(PhabricatorPolicyInterface $object) {
    return $object instanceof PhabricatorProject;
  }

  public function applyRule(
    PhabricatorUser $viewer,
    $value,
    PhabricatorPolicyInterface $object) {
    return $this->canApplyToObject($object) &&
      $this->isAuthorOrAdministrator($viewer, $object->getAuthorPHID());
  }
}

final class TeamPrivateTaskPolicyRule extends TeamPrivateAuthorPolicyRule {
  const POLICY = 'obj.team.task-author-admin';

  public function getObjectPolicyKey() { return 'team.task-author-admin'; }
  public function getObjectPolicyName() { return pht('任务创建者与站点管理员'); }
  public function getRuleDescription() { return pht('任务创建者或站点管理员'); }
  public function getPolicyExplanation() {
    return pht('创建者和站点管理员可操作；任务负责人保留原生查看和编辑权限。');
  }

  public function canApplyToObject(PhabricatorPolicyInterface $object) {
    return $object instanceof ManiphestTask;
  }

  public function applyRule(
    PhabricatorUser $viewer,
    $value,
    PhabricatorPolicyInterface $object) {
    return $this->canApplyToObject($object) &&
      $this->isAuthorOrAdministrator($viewer, $object->getAuthorPHID());
  }
}
