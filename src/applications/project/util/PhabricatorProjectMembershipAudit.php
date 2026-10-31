<?php

/** Inspect dormant direct edges before upgrading parent membership semantics. */
final class PhabricatorProjectMembershipAudit extends Phobject {

  public static function inspect() {
    $viewer = PhabricatorUser::getOmnipotentUser();
    $parents = id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->withHasSubprojects(true)
      ->withIsMilestone(false)
      ->execute();
    $direct_type = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;
    $effective_type = PhabricatorProjectMaterializedMemberEdgeType::EDGECONST;
    $findings = array();
    if ($parents) {
      $edges = id(new PhabricatorEdgeQuery())
        ->withSourcePHIDs(mpull($parents, 'getPHID'))
        ->withEdgeTypes(array($direct_type, $effective_type));
      $edges->execute();
      foreach ($parents as $parent) {
        $source = array($parent->getPHID());
        $direct = $edges->getDestinationPHIDs($source, array($direct_type));
        $effective = $edges->getDestinationPHIDs($source, array($effective_type));
        $dormant = array_values(array_diff($direct, $effective));
        if ($dormant) {
          sort($dormant);
          $findings[] = array(
            'projectID' => (int)$parent->getID(),
            'projectPHID' => $parent->getPHID(),
            'dormantDirectMemberPHIDs' => $dormant,
          );
        }
      }
    }
    return array(
      'checkedAt' => gmdate('c'),
      'readOnly' => true,
      'passed' => !$findings,
      'parentProjectsChecked' => count($parents),
      'findings' => $findings,
      'existingChildMembers' => 'Historical automatic copies can not be reliably '.
        'distinguished from deliberate invitations and are not changed.',
    );
  }
}
