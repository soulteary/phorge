<?php

/** Project-only shortcuts which still store ordinary immutable policy rules. */
final class TeamProjectTeamPolicy extends Phobject {
  const SELECT_KEY = 'select_team';
  const MAX_TEAMS = 100;

  public static function supportsObject($object, $capability) {
    return $object instanceof PhabricatorProject && !$object->isMilestone() &&
      in_array($capability, array('view', 'edit', 'join'), true);
  }

  public static function requireContext(
    PhabricatorUser $viewer, $project = null) {
    $actor = self::currentActor($viewer);
    if (!$actor || !TeamOrganizationService::isActiveUser($actor)) {
      throw self::permissionException();
    }
    if ($project !== null) {
      if (!($project instanceof PhabricatorProject) || !$project->getPHID()) {
        throw self::permissionException();
      }
      $project = id(new PhabricatorProjectQuery())->setViewer($actor)
        ->withPHIDs(array($project->getPHID()))->needMembers(true)
        ->needAncestorMembers(true)->executeOne();
      if (!$project || $project->isMilestone() ||
          !TeamProjectMembership::canManage($actor, $project)) {
        throw self::permissionException();
      }
    } else {
      $engine = id(new PhabricatorProjectEditEngine())->setViewer($actor);
      if (!PhabricatorPolicyFilter::hasCapability($actor, $engine,
          PhabricatorPolicyCapability::CAN_EDIT) ||
          !PhabricatorPolicyFilter::hasCapability($actor, $engine->getApplication(),
          PhabricatorPolicyCapability::CAN_VIEW)) {
        throw self::permissionException();
      }
    }
    return $actor;
  }

  public static function newPolicy(
    PhabricatorUser $viewer, array $team_phids, $scope, $project = null) {
    $actor = self::requireContext($viewer, $project);
    if (!in_array($scope, array('direct', 'descendants'), true)) {
      throw new InvalidArgumentException(pht('请选择仅本级或包含下级的团队范围。'));
    }
    if (!$team_phids || count($team_phids) > self::MAX_TEAMS) {
      throw new InvalidArgumentException(pht('请选择 1 至 %s 个团队。', self::MAX_TEAMS));
    }
    foreach ($team_phids as $phid) {
      if (!is_string($phid) || phid_get_type($phid) !== TeamOrganizationTeamPHIDType::TYPECONST) {
        throw new InvalidArgumentException(pht('团队策略只能选择有效的组织团队。'));
      }
    }
    $team_phids = array_values(array_unique($team_phids));
    $teams = id(new TeamOrganizationTeamQuery())->setViewer($actor)
      ->withPHIDs($team_phids)->withIsArchived(false)->execute();
    if (count($teams) !== count($team_phids)) {
      throw new InvalidArgumentException(pht('请选择您可见且未归档的团队。'));
    }
    // Saving the picker creates a policy, not a grant or project membership.
    // Applying it to a project still uses native project validation/self-locking.
    return id(new PhabricatorPolicy())->setDefaultAction(PhabricatorPolicy::ACTION_DENY)
      ->setRules(array(array('action' => PhabricatorPolicy::ACTION_ALLOW,
        'rule' => $scope === 'descendants'
          ? TeamOrganizationDescendantsPolicyRule::class : TeamOrganizationDirectPolicyRule::class,
        'value' => $team_phids)))->save();
  }

  /** Give simple team policies stable readable names after a page reload. */
  public static function getPolicyInfo(PhabricatorUser $viewer, $policy) {
    if (phid_get_type($policy->getPHID()) !== PhabricatorPolicyPHIDTypePolicy::TYPECONST ||
        $policy->getDefaultAction() !== PhabricatorPolicy::ACTION_DENY) {
      return null;
    }
    $rules = $policy->getRules();
    if (!is_array($rules) || count($rules) !== 1) { return null; }
    $rule = head($rules);
    if (!is_array($rule)) { return null; }
    $classes = array(TeamOrganizationDirectPolicyRule::class => '仅本级',
      TeamOrganizationDescendantsPolicyRule::class => '包含下级');
    $class = idx($rule, 'rule');
    if (idx($rule, 'action') !== PhabricatorPolicy::ACTION_ALLOW ||
        !isset($classes[$class]) || !is_array(idx($rule, 'value')) || !$rule['value']) {
      return null;
    }
    foreach ($rule['value'] as $phid) {
      if (!is_string($phid) || phid_get_type($phid) !== TeamOrganizationTeamPHIDType::TYPECONST) {
        return null;
      }
    }
    $teams = id(new TeamOrganizationTeamQuery())->setViewer($viewer)
      ->withPHIDs($rule['value'])->execute();
    $visible = mpull($teams, null, 'getPHID');
    $names = array();
    foreach ($rule['value'] as $phid) {
      if (isset($visible[$phid])) {
        $team = $visible[$phid];
        $names[] = $team->getName().($team->getIsArchived() ? '（已归档）' : '');
      } else {
        $names[] = pht('不可见团队');
      }
    }
    $full = pht('团队成员（%s）：%s', $classes[$class], implode('、', $names));
    $short = id(new PhutilUTF8StringTruncator())->setMaximumGlyphs(24)
      ->truncateString($full);
    return array('name' => $short, 'full' => $full, 'icon' => 'fa-sitemap');
  }

  private static function currentActor(PhabricatorUser $viewer) {
    if (!$viewer->getPHID()) { return null; }
    $dao = new PhabricatorUser();
    $row = queryfx_one($dao->establishConnection('w'),
      'SELECT * FROM %T WHERE phid = %s', $dao->getTableName(), $viewer->getPHID());
    return $row ? $dao->loadFromArray($row) : null;
  }

  private static function permissionException() {
    return new PhabricatorPolicyException(pht('仅有权创建项目或管理当前项目的用户可以设置此策略。'));
  }
}

final class TeamProjectTeamPolicyController extends TeamOrganizationController {
  public function handleRequest(AphrontRequest $request) {
    $viewer = $this->getViewer();
    $capability = $request->getStr('capability');
    if (!in_array($capability, array('view', 'edit', 'join'), true)) {
      return new Aphront400Response();
    }
    $project = null;
    $object_phid = $request->getURIData('objectPHID');
    if ($object_phid) {
      $project = id(new PhabricatorProjectQuery())->setViewer($viewer)
        ->withPHIDs(array($object_phid))->needMembers(true)
        ->needAncestorMembers(true)->executeOne();
      if (!$project) { return new Aphront404Response(); }
    } else if ($request->getURIData('objectType') !== PhabricatorProjectProjectPHIDType::TYPECONST) {
      return new Aphront400Response();
    }
    try { TeamProjectTeamPolicy::requireContext($viewer, $project); }
    catch (PhabricatorPolicyException $ex) { return new Aphront403Response(); }
    if ($request->getURIData('phid') !== TeamProjectTeamPolicy::SELECT_KEY) {
      // Keep the complete native custom/project/named editor available.
      $response = $this->delegateToController(new PhabricatorPolicyEditController());
      if ($response instanceof AphrontDialogResponse) {
        $response->getDialog()->addHiddenInput('capability', $capability);
      } else if ($response instanceof AphrontDialogView) {
        $response->addHiddenInput('capability', $capability);
      }
      return $response;
    }

    $scope = $request->getStr('scope', 'direct');
    $teams = $request->getArr('teams');
    $errors = array();
    if ($request->isHTTPPost()) {
      try {
        $policy = TeamProjectTeamPolicy::newPolicy($viewer, $teams, $scope, $project);
        return id(new AphrontAjaxResponse())->setContent(array('phid' => $policy->getPHID(),
          'info' => TeamProjectTeamPolicy::getPolicyInfo($viewer, $policy)));
      } catch (PhabricatorPolicyException $ex) { return new Aphront403Response(); }
      catch (InvalidArgumentException $ex) { $errors[] = $ex->getMessage(); }
    }
    $form = id(new AphrontFormView())->setViewer($viewer)
      ->addHiddenInput('capability', $capability)
      ->appendControl(id(new AphrontFormTokenizerControl())->setName('teams')
        ->setLabel(pht('团队'))->setValue($teams)->setDatasource(new TeamOrganizationDatasource())
        ->setCaption(pht('可选择多个团队；这里不会把成员加入项目，团队归属变更后权限随之更新。')))
      ->appendControl(id(new AphrontFormSelectControl())->setName('scope')->setLabel(pht('团队范围'))
        ->setValue($scope)->setOptions(array('direct' => '仅所选团队本级成员',
          'descendants' => '所选团队及全部下级成员'))
        ->setCaption(pht('只设置当前这一项的授权范围；项目成员等自动权限仍生效。')));
    return $this->newDialog()->setWidth(AphrontDialogView::WIDTH_FORM)
      ->setTitle(pht('团队成员策略'))->setErrors($errors)->appendForm($form)
      ->addSubmitButton(pht('使用此团队策略'))->addCancelButton('#');
  }
}
