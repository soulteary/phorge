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

    $can_create = $this->hasApplicationCapability(
      ProjectCreateProjectsCapability::CAPABILITY);
    if (!$can_edit || !$can_create || !$project->supportsSubprojects()) {
      return new Aphront404Response();
    }

    $id = $project->getID();
    $done_uri = "/project/edit/?parent={$id}";
    return id(new AphrontRedirectResponse())->setURI($done_uri);
  }

}
