<?php

final class PhabricatorProjectSubprojectWarningController
  extends PhabricatorProjectController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();

    $response = $this->loadProject();
    if ($response) {
      return $response;
    }

    $project = $this->getProject();

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $project,
      PhabricatorPolicyCapability::CAN_EDIT);

    if (!$can_edit) {
      return new Aphront404Response();
    }

    $id = $project->getID();
    return id(new AphrontRedirectResponse())
      ->setURI("/project/edit/?parent={$id}");
  }

}
