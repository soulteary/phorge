<?php

final class PhabricatorProjectMembersRemoveController
  extends PhabricatorProjectController {

  public function handleRequest(AphrontRequest $request) {
    if ($request->isHTTPPost()) {
      try { $request->validateCSRF(); }
      catch (AphrontMalformedRequestException $ex) { return new Aphront400Response(); }
    }
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

    if (!TeamProjectMembership::canManage($viewer, $project)) { return new Aphront403Response(); }

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

    $state = TeamProjectMembership::getStateToken($project);
    $errors = array();
    if ($request->isFormPost()) {
      $state = $request->getStr('expectedState');
      try {
        if (!$is_watcher) {
          TeamProjectMembership::changeMembers($viewer, $project, '-', array($remove_phid), $state,
            PhabricatorContentSource::newFromRequest($request));
        } else {
          id(new PhabricatorProjectTransactionEditor())->setActor($viewer)
            ->setContentSourceFromRequest($request)->setContinueOnNoEffect(true)->setContinueOnMissingFields(true)
            ->applyTransactions($project, array(id(new PhabricatorProjectTransaction())
              ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)->setMetadataValue('edge:type', $edge_type)
              ->setNewValue(array('-' => array($remove_phid => $remove_phid)))));
        }
        return id(new AphrontRedirectResponse())->setURI($members_uri.'?membersSaved=1');
      } catch (InvalidArgumentException $ex) { $errors[] = $ex->getMessage(); }
      catch (PhabricatorApplicationTransactionValidationException $ex) { $errors = $ex->getErrorMessages(); }
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
      $button = pht('移除成员');
      $body = array($body, ' ', '同时清除本级成员角色；其他团队或项目策略仍可能授予访问权限。');
      if ($project->getHasSubprojects()) {
        $body = array($body, ' ', pht(
          '仅移除本级直接成员身份；仍属于子项目的用户会继续作为汇总成员显示。'));
      }
    }

    $dialog = $this->newDialog()
      ->setTitle($title)
      ->addHiddenInput('phid', $remove_phid)
      ->addHiddenInput('expectedState', $state)
      ->appendParagraph($body)
      ->addCancelButton($members_uri)
      ->addSubmitButton($button);
    foreach ($errors as $error) { $dialog->appendParagraph($error); }
    return $dialog;
  }

}
