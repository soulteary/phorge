<?php

final class PhabricatorProjectUpdateController
  extends PhabricatorProjectController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();
    $id = $request->getURIData('id');
    $action = $request->getURIData('action');

    $capabilities = array(
      PhabricatorPolicyCapability::CAN_VIEW,
    );

    switch ($action) {
      case 'join':
        $capabilities[] = PhabricatorPolicyCapability::CAN_JOIN;
        break;
      case 'leave':
        break;
      default:
        return new Aphront404Response();
    }

    $project = id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->withIDs(array($id))
      ->needMembers(true)
      ->requireCapabilities($capabilities)
      ->executeOne();
    if (!$project) {
      return new Aphront404Response();
    }

    $done_uri = "/project/members/{$id}/";

    if (!$project->supportsEditMembers()) {
      $copy = pht('里程碑沿用父项目成员，请在父项目中管理成员。');

      $parent_id = $project->getParentProject()->getID();
      $subprojects_uri = "/project/members/{$parent_id}/";

      return $this->newDialog()
        ->setTitle(pht('Unsupported Project'))
        ->appendParagraph($copy)
        ->setSubmitURI($subprojects_uri)
        ->addSubmitButton(pht('查看父项目成员'))
        ->setDisableWorkflowOnSubmit(true)
        ->addCancelButton($done_uri);
    }

    if ($action === 'leave' && !in_array(
      $viewer->getPHID(), $project->getDirectMemberPHIDs())) {
      return $this->newDialog()
        ->setTitle(pht('来自子项目的成员'))
        ->appendParagraph(pht('你没有本级直接成员身份，请到相应子项目中退出。'))
        ->addCancelButton($done_uri);
    }

    if ($request->isFormPost()) {
      $edge_action = null;
      switch ($action) {
        case 'join':
          $edge_action = '+';
          break;
        case 'leave':
          $edge_action = '-';
          break;
      }

      $type_member = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;

      $member_spec = array(
        $edge_action => array($viewer->getPHID() => $viewer->getPHID()),
      );

      $xactions = array();
      $xactions[] = id(new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
        ->setMetadataValue('edge:type', $type_member)
        ->setNewValue($member_spec);

      $editor = id(new PhabricatorProjectTransactionEditor())
        ->setActor($viewer)
        ->setContentSourceFromRequest($request)
        ->setContinueOnNoEffect(true)
        ->setContinueOnMissingFields(true)
        ->applyTransactions($project, $xactions);

      return id(new AphrontRedirectResponse())->setURI($done_uri);
    }

    $is_locked = $project->getIsMembershipLocked();
    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $project,
      PhabricatorPolicyCapability::CAN_EDIT);
    $can_leave = ($can_edit || !$is_locked);

    $button = null;
    if ($action == 'leave') {
      if ($can_leave) {
        $title = pht('Leave Project');
        $body = pht(
          'Your tremendous contributions to this project will be sorely '.
          'missed. Are you sure you want to leave?');
        $button = pht('Leave Project');
        if ($project->getHasSubprojects()) {
          $body = array($body, ' ', pht(
            '退出本级后，若你仍属于子项目，仍会保留汇总成员身份。'));
        }
      } else {
        $title = pht('Membership Locked');
        $body = pht(
          'Membership for this project is locked. You can not leave.');
      }
    } else {
      $title = pht('Join Project');
      $body = pht(
        'Join this project? You will become a member and enjoy whatever '.
        'benefits membership may confer.');
      $button = pht('Join Project');
    }

    $dialog = $this->newDialog()
      ->setTitle($title)
      ->appendParagraph($body)
      ->addCancelButton($done_uri);

    if ($button) {
      $dialog->addSubmitButton($button);
    }

    return $dialog;
  }

}
