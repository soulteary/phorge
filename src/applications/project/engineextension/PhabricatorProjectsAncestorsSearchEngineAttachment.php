<?php

final class PhabricatorProjectsAncestorsSearchEngineAttachment
  extends PhabricatorSearchEngineAttachment {

  public function getAttachmentName() {
    return pht('Project Ancestors');
  }

  public function getAttachmentDescription() {
    return pht('Get the visible ancestors for each project.');
  }

  public function getAttachmentForObject($object, $data, $spec) {
    $ancestors = $object->getAncestorProjects();

    // Order ancestors by depth, ascending.
    $ancestors = array_reverse($ancestors);

    $results = array();
    foreach ($ancestors as $ancestor) {
      if (!PhabricatorPolicyFilter::hasCapability(
        $this->getViewer(),
        $ancestor,
        PhabricatorPolicyCapability::CAN_VIEW)) {
        continue;
      }

      $results[] = $ancestor->getRefForConduit();
    }

    return array(
      'ancestors' => $results,
    );
  }

}
