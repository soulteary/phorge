<?php

final class PhabricatorProjectMembersPolicyRule extends PhabricatorPolicyRule {

  private $memberships = array();

  public function getRuleDescription() {
    return pht('members of project');
  }

  public function willApplyRules(
    PhabricatorUser $viewer,
    array $values,
    array $objects) {

    $viewer_phid = $viewer->getPHID();
    if (!$viewer_phid) {
      return;
    }

    if (empty($this->memberships[$viewer_phid])) {
      $this->memberships[$viewer_phid] = array();
    }

    $sources = array();
    foreach ($objects as $object) {
      $object_phid = $object->getPHID();
      unset($this->memberships[$viewer_phid][$object_phid]);

      $cache = $this->getTransactionHint($object);
      if ($cache !== null) {
        if (isset($cache[$viewer_phid])) {
          $this->memberships[$viewer_phid][$object_phid] = true;
        }
        continue;
      }

      if ($object->isMilestone()) {
        $source_phid = $object->getParentProjectPHID();
      } else {
        $source_phid = $object_phid;
      }
      $sources[$source_phid][] = $object_phid;
    }

    if (!$sources) {
      return;
    }

    $edge_query = id(new PhabricatorEdgeQuery())
      ->withSourcePHIDs(array($viewer_phid))
      ->withDestinationPHIDs(array_keys($sources))
      ->withEdgeTypes(
        array(
          PhabricatorProjectMemberOfProjectEdgeType::EDGECONST,
        ));
    $edge_query->execute();

    $memberships = $edge_query->getDestinationPHIDs();
    foreach ($memberships as $source_phid) {
      foreach ($sources[$source_phid] as $object_phid) {
        $this->memberships[$viewer_phid][$object_phid] = true;
      }
    }
  }

  public function applyRule(
    PhabricatorUser $viewer,
    $value,
    PhabricatorPolicyInterface $object) {
    $viewer_phid = $viewer->getPHID();
    if (!$viewer_phid) {
      return false;
    }

    $memberships = idx($this->memberships, $viewer_phid);
    return isset($memberships[$object->getPHID()]);
  }

  public function getValueControlType() {
    return self::CONTROL_TYPE_NONE;
  }

  public function canApplyToObject(PhabricatorPolicyInterface $object) {
    return ($object instanceof PhabricatorProject);
  }

  public function getObjectPolicyKey() {
    return 'project.members';
  }

  public function getObjectPolicyName() {
    return pht('Project Members');
  }

  public function getObjectPolicyIcon() {
    return 'fa-users';
  }

  public function getPolicyExplanation() {
    return pht('Project members can take this action.');
  }

}
