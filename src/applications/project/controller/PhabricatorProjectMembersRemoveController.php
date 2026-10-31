<?php

final class PhabricatorProjectMembersRemoveController
  extends PhabricatorProjectController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();
    $id = $request->getURIData('id');
    $type = $request->getURIData('type');

    $project = id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->withIDs(array($id))
      ->needMembers(true)
      ->needWatchers(true)
      ->requireCapabilities(
        array(
          PhabricatorPolicyCapability::CAN_VIEW,
          PhabricatorPolicyCapability::CAN_EDIT,
        ))
      ->executeOne();
    if (!$project) {
      return new Aphront404Response();
    }

    if ($type == 'watchers') {
      $is_watcher = true;
      $edge_type = PhabricatorObjectHasWatcherEdgeType::EDGECONST;
    } else {
      if (!$project->supportsEditMembers()) {
        return new Aphront404Response();
      }

      $is_watcher = false;
      $edge_type = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;
    }

    $members_uri = $this->getApplicationURI('members/'.$project->getID().'/');
    $remove_phid = $request->getStr('phid');

    if (!$is_watcher && !in_array(
      $remove_phid, $project->getDirectMemberPHIDs())) {
      return $this->newDialog()
        ->setTitle(pht('来自子项目的成员'))
        ->appendParagraph(pht('此用户不是本级直接成员，请在相应子项目中移除。'))
        ->addCancelButton($members_uri);
    }

    if ($request->isFormPost()) {
      $xactions = array();

      $xactions[] = id(new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
        ->setMetadataValue('edge:type', $edge_type)
        ->setNewValue(
          array(
            '-' => array($remove_phid => $remove_phid),
          ));

      $editor = id(new PhabricatorProjectTransactionEditor())
        ->setActor($viewer)
        ->setContentSourceFromRequest($request)
        ->setContinueOnNoEffect(true)
        ->setContinueOnMissingFields(true)
        ->applyTransactions($project, $xactions);

      return id(new AphrontRedirectResponse())
        ->setURI($members_uri);
    }

    $handle = id(new PhabricatorHandleQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($remove_phid))
      ->executeOne();

    $target_name = phutil_tag('strong', array(), $handle->getName());
    $project_name = phutil_tag('strong', array(), $project->getName());

    if ($is_watcher) {
      $title = pht('Remove Watcher');
      $body = pht(
        'Remove %s as a watcher of %s?',
        $target_name,
        $project_name);
      $button = pht('Remove Watcher');
    } else {
      $title = pht('Remove Member');
      $body = pht(
        'Remove %s as a project member of %s?',
        $target_name,
        $project_name);
      $button = pht('Remove Member');
      if ($project->getHasSubprojects()) {
        $body = array($body, ' ', pht(
          '仅移除本级直接成员身份；仍属于子项目的用户会继续作为汇总成员显示。'));
      }
    }

    return $this->newDialog()
      ->setTitle($title)
      ->addHiddenInput('phid', $remove_phid)
      ->appendParagraph($body)
      ->addCancelButton($members_uri)
      ->addSubmitButton($button);
  }

}
