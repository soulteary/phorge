<?php

final class PhabricatorProjectMembersAddController extends PhabricatorProjectController {
  public function handleRequest(AphrontRequest $request) {
    if ($request->isHTTPPost()) {
      try { $request->validateCSRF(); }
      catch (AphrontMalformedRequestException $ex) { return new Aphront400Response(); }
    }
    $viewer = $request->getViewer(); $id = $request->getURIData('id');
    $project = TeamProjectAccessService::loadProject($viewer, $id);
    if (!$project) { return new Aphront404Response(); }
    if (!TeamProjectMembership::canManage($viewer, $project)) { return new Aphront403Response(); }
    $this->setProject($project); $done_uri = '/project/members/'.$id.'/';
    if (!$project->supportsEditMembers()) {
      return $this->newDialog()->setTitle('沿用所属项目成员')
        ->appendParagraph('里程碑沿用所属项目成员，请在所属项目管理。')
        ->addCancelButton('/project/members/'.$project->getParentProject()->getID().'/');
    }
    $tokens = array(); $errors = array(); $state = TeamProjectMembership::getStateToken($project);
    if ($request->isFormPost()) {
      $tokens = $request->getArr('memberPHIDs'); $state = $request->getStr('expectedState');
      try {
        $members = TeamProjectMemberImport::resolve($viewer, $tokens);
        TeamProjectMembership::changeMembers($viewer, $project, '+', $members, $state,
          PhabricatorContentSource::newFromRequest($request));
        return id(new AphrontRedirectResponse())->setURI($done_uri.'?membersSaved=1');
      } catch (InvalidArgumentException $ex) { $errors[] = $ex->getMessage(); }
      catch (PhabricatorApplicationTransactionValidationException $ex) { $errors = $ex->getErrorMessages(); }
    }
    $form = id(new AphrontFormView())->setUser($viewer)
      ->addHiddenInput('expectedState', $state)
      ->appendControl(id(new AphrontFormTokenizerControl())->setName('memberPHIDs')
        ->setLabel('添加成员')->setValue($tokens)->setDatasource(new TeamProjectMemberImportDatasource())
        ->setCaption('支持搜索人员、团队或项目，一次性导入当前有效成员；不会复制来源的权限或角色。'));
    $dialog = $this->newDialog()->setTitle('添加项目成员')->appendForm($form)
      ->addCancelButton($done_uri)->addSubmitButton('添加成员');
    foreach ($errors as $error) { $dialog->appendParagraph($error); }
    return $dialog;
  }
}
