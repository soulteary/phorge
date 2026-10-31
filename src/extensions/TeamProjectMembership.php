<?php

/** Project membership roles supplement native ACLs; no global account roles. */
final class TeamProjectMembership extends Phobject {
  const ROLES_PROPERTY = 'team.project.member-roles';
  const EXPECTED_STATE = 'team.project.membership.expected-state';
  const LEGACY_ADMIN = 'legacy-admin';

  public static function getRoleOptions() {
    return array('policy' => '按项目策略', 'viewer' => '只读成员',
      'editor' => '可编辑成员', 'admin' => '项目管理员');
  }

  public static function governingProject($project) {
    return $project->isMilestone() ? $project->getParentProject() : $project;
  }

  public static function getRole($project, $phid) {
    $project = self::governingProject($project);
    $roles = $project->getProperty(self::ROLES_PROPERTY);
    if ($roles === null) {
      return $phid === $project->getAuthorPHID() ? 'admin' : 'policy';
    }
    $role = is_array($roles) ? idx($roles, $phid, 'policy') : 'policy';
    return $role === self::LEGACY_ADMIN ? 'admin' : $role;
  }

  public static function roleMap($project) {
    $project = self::governingProject($project);
    $roles = $project->getProperty(self::ROLES_PROPERTY);
    if ($roles === null) {
      return $project->getAuthorPHID()
        ? array($project->getAuthorPHID() => self::LEGACY_ADMIN) : array();
    }
    return is_array($roles) ? $roles : array();
  }

  public static function isAutomaticRole($project, $viewer) {
    if (!self::isActive($viewer)) { return false; }
    $project = self::governingProject($project);
    $roles = $project->getProperty(self::ROLES_PROPERTY, array());
    return is_array($roles) &&
      in_array(idx($roles, $viewer->getPHID()), array('editor', 'admin'), true);
  }

  public static function hasAutomaticViewRole($project, $viewer) {
    if (!self::isActive($viewer)) { return false; }
    $project = self::governingProject($project);
    $roles = $project->getProperty(self::ROLES_PROPERTY, array());
    return is_array($roles) &&
      in_array(idx($roles, $viewer->getPHID()), array('viewer', 'editor', 'admin'), true);
  }

  public static function isActive($viewer) {
    if ($viewer && $viewer->isOmnipotent()) { return true; }
    return $viewer && $viewer->isUserActivated() &&
      !$viewer->getIsSystemAgent() && !$viewer->getIsMailingList();
  }

  public static function currentUser($viewer) {
    if ($viewer->isOmnipotent()) { return $viewer; }
    if (!$viewer->getPHID()) { return null; }
    $dao = new PhabricatorUser();
    $row = queryfx_one($dao->establishConnection('w'),
      'SELECT * FROM %T WHERE phid = %s', $dao->getTableName(), $viewer->getPHID());
    return $row ? $dao->loadFromArray($row) : null;
  }

  public static function hasManagerRole($viewer, $project) {
    return self::isActive($viewer) && ($viewer->isOmnipotent() || $viewer->getIsAdmin() ||
      self::getRole($project, $viewer->getPHID()) === 'admin');
  }

  public static function canManage($viewer, $project) {
    $viewer = self::currentUser($viewer);
    if (!self::isActive($viewer) || !$project->getID()) { return false; }
    id(new PhabricatorProject())->establishConnection('w');
    try {
      $current = id(new PhabricatorProjectQuery())->setViewer($viewer)
        ->withIDs(array($project->getID()))->needMembers(true)
        ->needAncestorMembers(true)->executeOne();
    } catch (PhabricatorPolicyException $ex) { return false; }
    return $current && self::hasManagerRole($viewer, $current) &&
      PhabricatorPolicyFilter::hasCapability($viewer, $current,
        PhabricatorPolicyCapability::CAN_EDIT);
  }

  public static function getStateToken($project) {
    $members = $project->getDirectMemberPHIDs(); sort($members, SORT_STRING);
    return hash('sha256', serialize(array($project->getPHID(),
      $project->getPolicy('view'), $project->getPolicy('edit'), $project->getPolicy('join'),
      $project->getProperty(self::ROLES_PROPERTY), $members,
      $project->getProperty(TeamOrganizationProjectBoundary::TEAMS_PROPERTY),
      $project->getProperty(TeamOrganizationProjectBoundary::SCOPE_PROPERTY))));
  }

  public static function roleURI($project, $phid) {
    return '/organization/project/'.$project->getID().'/member-role/?phid='.rawurlencode($phid);
  }

  public static function changeRole($actor, $project, $target, $role, $expected, $source) {
    if (!isset(self::getRoleOptions()[$role])) {
      throw new InvalidArgumentException('请选择有效的项目成员角色。');
    }
    return self::applyLocked($actor, $project, $expected, $source,
      function($current, $current_actor) use ($target, $role) {
        $roles = self::roleMap($current);
        if (!in_array($target, $current->getDirectMemberPHIDs(), true) && !isset($roles[$target])) {
          throw new InvalidArgumentException('仅可管理本级直接成员或本级已有角色；汇总成员请到相应子项目管理。');
        }
        $user = id(new PhabricatorPeopleQuery())->setViewer($current_actor)
          ->withPHIDs(array($target))->executeOne();
        if (!$user || !self::isActive(self::currentUser($user))) {
          throw new InvalidArgumentException('请选择可见且有效的项目成员。');
        }
        if ($role === 'policy') { unset($roles[$target]); }
        else { $roles[$target] = $role; }
        ksort($roles, SORT_STRING);
        return array(id(new PhabricatorProjectTransaction())
          ->setTransactionType(TeamProjectMemberRoleTransaction::TRANSACTIONTYPE)
          ->setNewValue($roles));
      });
  }

  public static function changeMembers($actor, $project, $action, array $members, $expected, $source) {
    if (!in_array($action, array('+', '-'), true)) {
      throw new InvalidArgumentException('成员操作无效。');
    }
    return self::applyLocked($actor, $project, $expected, $source,
      function($current, $current_actor) use ($action, $members) {
        if ($action === '-') {
          if (array_diff($members, $current->getDirectMemberPHIDs())) {
            throw new InvalidArgumentException('此用户不是本级直接成员，请到相应子项目管理。');
          }
        }
        return array(id(new PhabricatorProjectTransaction())
          ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
          ->setMetadataValue('edge:type', PhabricatorProjectProjectHasMemberEdgeType::EDGECONST)
          ->setNewValue(array($action => array_fuse($members))));
      });
  }

  private static function applyLocked($actor, $project, $expected, $source, $build) {
    if (!is_string($expected) || !preg_match('/\A[a-f0-9]{64}\z/', $expected)) {
      throw new InvalidArgumentException('缺少成员权限版本，请刷新后重试。');
    }
    $dao = new PhabricatorProject(); $conn = $dao->establishConnection('w');
    $dao->openTransaction();
    try {
      $row = queryfx_one($conn, 'SELECT phid FROM %T WHERE id = %d FOR UPDATE',
        $dao->getTableName(), $project->getID());
      $actor = self::currentUser($actor);
      if (!$row || $row['phid'] !== $project->getPHID() || !self::isActive($actor)) {
        throw new PhabricatorPolicyException('仅当前项目管理员可以管理成员和角色。');
      }
      $current = id(new PhabricatorProjectQuery())->setViewer($actor)
        ->withIDs(array($project->getID()))->needMembers(true)->needAncestorMembers(true)->executeOne();
      if (!$current || !self::canManage($actor, $current)) {
        throw new PhabricatorPolicyException('仅当前项目管理员可以管理成员和角色。');
      }
      if ($current->isMilestone()) {
        throw new InvalidArgumentException('里程碑沿用所属项目的成员和角色，请在所属项目管理。');
      }
      if (!hash_equals(self::getStateToken($current), $expected)) {
        throw new InvalidArgumentException('成员或权限已被其他操作修改，请刷新后重试。');
      }
      $xactions = $build($current, $actor);
      foreach ($xactions as $xaction) { $xaction->setMetadataValue(self::EXPECTED_STATE, $expected); }
      id(new PhabricatorProjectTransactionEditor())->setActor($actor)->setContentSource($source)
        ->setContinueOnNoEffect(true)->setContinueOnMissingFields(true)->applyTransactions($current, $xactions);
      $dao->saveTransaction();
    } catch (Throwable $ex) { $dao->killTransaction(); throw $ex; }
    return $current;
  }

  /** Runs in the native editor's row lock before regular edge normalization. */
  public static function validateTransactions(
    $actor, $project, array $xactions, $is_new, $is_inverse = false) {
    $actor = self::currentUser($actor);
    if (!self::isActive($actor)) {
      throw new PhabricatorPolicyException('只有有效用户可以修改项目。');
    }
    $roles = $project->getProperty(self::ROLES_PROPERTY);
    $new_roles = $roles; $role_xaction = null; $removed = array(); $self_leave = false;
    foreach ($xactions as $xaction) {
      $type = $xaction->getTransactionType();
      $protected = in_array($type, array(PhabricatorTransactions::TYPE_VIEW_POLICY,
        PhabricatorTransactions::TYPE_EDIT_POLICY, PhabricatorTransactions::TYPE_JOIN_POLICY,
        PhabricatorTransactions::TYPE_SPACE, PhabricatorProjectLockTransaction::TRANSACTIONTYPE,
        PhabricatorProjectParentTransaction::TRANSACTIONTYPE, PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE,
        TeamOrganizationBoundaryTeamsTransaction::TRANSACTIONTYPE,
        TeamOrganizationBoundaryScopeTransaction::TRANSACTIONTYPE), true);
      if ($type === PhabricatorTransactions::TYPE_EDGE &&
          $xaction->getMetadataValue('edge:type') === PhabricatorProjectProjectHasMemberEdgeType::EDGECONST) {
        // Native validation precedes adjustTransactionValues(): edge values
        // are still {+, -, =} operations here, and oldValue is not populated.
        $old = array_fuse($project->getDirectMemberPHIDs());
        $new = $old; $spec = (array)$xaction->getNewValue();
        if (array_key_exists('=', $spec)) { $new = array_fuse(array_keys((array)$spec['='])); }
        foreach ((array)idx($spec, '+', array()) as $phid => $value) { $new[$phid] = $phid; }
        foreach ((array)idx($spec, '-', array()) as $phid => $value) { unset($new[$phid]); }
        if ($is_inverse) {
          // Native inverse edits supply complete maps, including an empty
          // new map when the final member leaves. Unchanged other members
          // in these maps are not additional management requests.
          $old = (array)$xaction->getOldValue(); $new = $spec;
        }
        $added = array_keys(array_diff_key($new, $old));
        $deleted = array_keys(array_diff_key($old, $new)); $removed = array_merge($removed, $deleted);
        $join = $added === array($actor->getPHID()) && !$deleted;
        $leave = $deleted === array($actor->getPHID()) && !$added;
        if ($is_inverse) {
          $self_request = $join || $leave;
        } else {
          $requested = array_merge(array_keys((array)idx($spec, '+', array())),
            array_keys((array)idx($spec, '-', array())));
          // Inspect intent as well as delta: setting the whole member list
          // or naming any other user remains administrative even if those
          // parts of a mixed request would be no-ops.
          $self_request = $requested &&
            !array_diff(array_keys($spec), array('+', '-')) &&
            !array_diff($requested, array($actor->getPHID()));
        }
        $join = $self_request && $join;
        $leave = $self_request && $leave;
        $self_leave = $self_leave || $leave;
        $self_no_effect = $self_request && !$added && !$deleted;
        $protected = !$join && !$leave && !$self_no_effect;
        if ($join && !$is_new && !PhabricatorPolicyFilter::hasCapability($actor, $project, 'join')) {
          throw new PhabricatorPolicyException('您没有加入此项目的权限。');
        }
      }
      if (!$is_new && $protected && !self::canManage($actor, $project)) {
        throw new PhabricatorPolicyException('仅项目管理员可以管理成员和项目权限。');
      }
      $expected = $xaction->getMetadataValue(self::EXPECTED_STATE);
      if (!$is_new && $expected !== null &&
          (!is_string($expected) || !hash_equals(self::getStateToken($project), $expected))) {
        throw new InvalidArgumentException('成员或权限已被其他操作修改，请刷新后重试。');
      }
      if ($type === TeamProjectMemberRoleTransaction::TRANSACTIONTYPE) {
        $role_xaction = $xaction; $new_roles = $xaction->getNewValue();
      }
    }
    if ($role_xaction) {
      if ($project->isMilestone()) { throw new InvalidArgumentException('里程碑成员角色沿用所属项目。'); }
      self::validateRoleMap($project, $new_roles, $is_new);
      foreach ($removed as $phid) {
        if (isset($new_roles[$phid])) { throw new InvalidArgumentException('移除成员时必须同时清除其本级角色。'); }
      }
      if (!$is_new && !self::canManage($actor, $project)) {
        $pruned = self::roleMap($project);
        foreach ($removed as $phid) {
          if ($phid === $actor->getPHID() && idx($pruned, $phid) === 'viewer') { continue; }
          unset($pruned[$phid]);
        }
        ksort($pruned, SORT_STRING);
        if (!$self_leave || array_diff($removed, array($actor->getPHID())) || $pruned !== $new_roles) {
          throw new PhabricatorPolicyException('仅项目管理员可以更改成员角色。');
        }
      }
      if (!$is_new) {
        $copy = clone $project; $copy->setProperty(self::ROLES_PROPERTY, $new_roles);
        foreach ($xactions as $other) {
          switch ($other->getTransactionType()) {
            case PhabricatorTransactions::TYPE_VIEW_POLICY: $copy->setViewPolicy($other->getNewValue()); break;
            case PhabricatorTransactions::TYPE_EDIT_POLICY: $copy->setEditPolicy($other->getNewValue()); break;
            case TeamOrganizationBoundaryTeamsTransaction::TRANSACTIONTYPE:
              $copy->setProperty(TeamOrganizationProjectBoundary::TEAMS_PROPERTY, $other->getNewValue()); break;
            case TeamOrganizationBoundaryScopeTransaction::TRANSACTIONTYPE:
              $copy->setProperty(TeamOrganizationProjectBoundary::SCOPE_PROPERTY, $other->getNewValue()); break;
          }
        }
        if (self::effectiveAdmins($project) && !self::effectiveAdmins($copy)) {
          throw new InvalidArgumentException('不能移除最后一名有效项目管理员，请先将管理权限交给其他成员。');
        }
      }
    }
  }

  private static function validateRoleMap($project, $roles, $is_new) {
    if (!is_array($roles) || count($roles) > 5000) { throw new InvalidArgumentException('项目成员角色格式无效。'); }
    $old = self::roleMap($project);
    $members = $project->getDirectMemberPHIDs();
    foreach ($roles as $phid => $role) {
      if (!is_string($phid) || !preg_match('/\APHID-USER-[a-z0-9]{20}\z/', $phid) ||
          !in_array($role, array('viewer', 'editor', 'admin', self::LEGACY_ADMIN), true)) {
        throw new InvalidArgumentException('项目成员角色格式无效。');
      }
      if ($role === self::LEGACY_ADMIN && idx($old, $phid) !== self::LEGACY_ADMIN) {
        throw new InvalidArgumentException('不能新增旧版管理来源。');
      }
      if (!$is_new && !in_array($phid, $members, true) && !isset($old[$phid])) {
        throw new InvalidArgumentException('请先将用户加入本级项目，再设置成员角色。');
      }
    }
    if ($is_new && idx($roles, $project->getAuthorPHID()) !== 'admin') {
      throw new InvalidArgumentException('新项目必须由创建者担任初始管理员。');
    }
  }

  private static function effectiveAdmins($project) {
    if ($project->getID()) {
      $complete = id(new PhabricatorProjectQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withIDs(array($project->getID()))->needMembers(true)->needAncestorMembers(true)->executeOne();
      if ($complete) {
        $complete->setProperties($project->getProperties())->setViewPolicy($project->getViewPolicy())
          ->setEditPolicy($project->getEditPolicy())->setJoinPolicy($project->getJoinPolicy());
        $project = $complete;
      }
    }
    $admins = array();
    foreach (self::roleMap($project) as $phid => $role) {
      if (in_array($role, array('admin', self::LEGACY_ADMIN), true)) { $admins[] = $phid; }
    }
    if (!$admins) { return array(); }
    $users = id(new PhabricatorPeopleQuery())->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs($admins)->execute();
    return array_filter($users, function($user) use ($project) {
      return self::isActive($user) && PhabricatorPolicyFilter::hasCapability($user, $project, 'view') &&
        PhabricatorPolicyFilter::hasCapability($user, $project, 'edit');
    });
  }
}

/** A per-member read-only ceiling applies after all native union grants. */
final class TeamProjectMemberRoleGuard extends Phobject implements PhabricatorPolicyInterface {
  private $project;
  public function __construct($project) { $this->project = TeamProjectMembership::governingProject($project); }
  public function getPHID() {
    return 'PHID-MROL-'.substr(hash('sha256', serialize(array($this->project->getPHID(), 'edit',
      $this->project->getProperty(TeamProjectMembership::ROLES_PROPERTY)))), 0, 20);
  }
  public function getCapabilities() { return array(PhabricatorPolicyCapability::CAN_VIEW); }
  public function getPolicy($capability) { return PhabricatorPolicies::POLICY_NOONE; }
  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return TeamProjectMembership::getRole($this->project, $viewer->getPHID()) !== 'viewer';
  }
  public function describeAutomaticCapability($capability) { return '明确设为只读的项目成员不能通过其他授权来源获得编辑权限。'; }
}

final class TeamProjectMemberRoleTransaction extends PhabricatorProjectTransactionType {
  const TRANSACTIONTYPE = 'team:project-member-roles';
  public function generateOldValue($object) {
    return $object->getID() === null ? null : $object->getProperty(TeamProjectMembership::ROLES_PROPERTY);
  }
  public function applyInternalEffects($object, $value) { $object->setProperty(TeamProjectMembership::ROLES_PROPERTY, $value); }
  public function getRequiredCapabilities($object, PhabricatorApplicationTransaction $xaction) {
    return PhabricatorPolicyCapability::CAN_EDIT;
  }
  public function getIcon() { return 'fa-user-shield'; }
  public function getTitle() { return pht('%s 更新了项目成员角色。', $this->renderAuthor()); }
  public function getTitleForFeed() { return pht('%s 更新了 %s 的项目成员角色。', $this->renderAuthor(), $this->renderObject()); }
  public function hasChangeDetailView() { return true; }
  public function newChangeDetailView() {
    $old = (array)$this->getOldValue(); $new = (array)$this->getNewValue(); $items = array();
    $options = TeamProjectMembership::getRoleOptions();
    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $phid) {
      $before = idx($old, $phid, 'policy'); $after = idx($new, $phid, 'policy');
      if ($before === $after) { continue; }
      $items[] = phutil_tag('li', array(), array($this->renderHandle($phid), '：',
        idx($options, $before, '项目管理员（旧版来源）'), ' → ',
        idx($options, $after, '项目管理员（旧版来源）')));
    }
    return phutil_tag('ul', array(), $items);
  }
}

/** Synthetic handles describe a failed role guard without exposing a roster. */
final class TeamProjectMemberRolePHIDType extends PhabricatorPHIDType {
  const TYPECONST = 'MROL';
  public function getTypeName() { return '项目成员角色限制'; }
  public function getTypeIcon() { return 'fa-user'; }
  public function getPHIDTypeApplicationClass() { return TeamOrganizationApplication::class; }
  protected function buildQueryForObjects(PhabricatorObjectQuery $query, array $phids) {
    return id(new TeamOrganizationTeamQuery())->withPHIDs(array());
  }
  public function loadObjects(PhabricatorObjectQuery $query, array $phids) {
    if (!($query->getParentQuery() instanceof PhabricatorHandleQuery)) { return array(); }
    $objects = array();
    foreach ($phids as $phid) {
      if (preg_match('/\APHID-MROL-[a-f0-9]{20}\z/', $phid)) {
        $objects[$phid] = new TeamOrganizationBoundaryLabel($phid);
      }
    }
    return $objects;
  }
  public function loadHandles(PhabricatorHandleQuery $query, array $handles, array $objects) {
    foreach ($handles as $handle) { $handle->setName('项目成员角色限制')->setIcon('fa-user')->setURI(null); }
  }
}

final class TeamProjectMemberRoleController extends TeamOrganizationController {
  public function handleRequest(AphrontRequest $request) {
    $viewer = $this->getViewer();
    $project = TeamProjectAccessService::loadProject($viewer, $request->getURIData('id'));
    if (!$project) { return new Aphront404Response(); }
    if (!TeamProjectMembership::canManage($viewer, $project)) { return new Aphront403Response(); }
    $done = '/project/members/'.$project->getID().'/';
    if ($project->isMilestone()) {
      return id(new AphrontRedirectResponse())->setURI('/project/members/'.$project->getParentProject()->getID().'/');
    }
    $target = $request->getStr('phid');
    $roles = TeamProjectMembership::roleMap($project);
    if (!in_array($target, $project->getDirectMemberPHIDs(), true) && !isset($roles[$target])) {
      return new Aphront404Response();
    }
    $role = TeamProjectMembership::getRole($project, $target);
    $legacy_caption = idx($roles, $target) === TeamProjectMembership::LEGACY_ADMIN
      ? '当前为旧版管理来源，仍须满足原有项目权限；保存为“项目管理员”将明确授予项目查看和编辑权限。 ' : '';
    $state = TeamProjectMembership::getStateToken($project); $errors = array();
    if ($request->isHTTPPost()) {
      $role = $request->getStr('role'); $state = $request->getStr('expectedState');
      try {
        TeamProjectMembership::changeRole($viewer, $project, $target, $role, $state,
          PhabricatorContentSource::newFromRequest($request));
        return id(new AphrontRedirectResponse())->setURI($done.'?roleSaved=1');
      } catch (InvalidArgumentException $ex) { $errors[] = $ex->getMessage(); }
      catch (PhabricatorApplicationTransactionValidationException $ex) { $errors = $ex->getErrorMessages(); }
    }
    $form = id(new AphrontFormView())->setUser($viewer)->setAction(TeamProjectMembership::roleURI($project, $target))
      ->addHiddenInput('phid', $target)->addHiddenInput('expectedState', $state)
      ->appendControl(id(new AphrontFormStaticControl())->setLabel('成员')->setValue($viewer->renderHandle($target)))
      ->appendControl(id(new AphrontFormSelectControl())->setName('role')->setLabel('项目内角色')
        ->setValue($role)->setOptions(TeamProjectMembership::getRoleOptions())
        ->setCaption($legacy_caption.'按项目策略保留所有授权来源；只读授予查看并限制编辑；可编辑授予本项目的编辑权限；项目管理员可增删成员、分配角色和管理权限。角色只影响本项目。'))
      ->appendControl(id(new AphrontFormSubmitControl())->setValue('保存成员角色')->addCancelButton($done));
    return $this->page('项目成员角色', array($this->actions(array($this->button('返回项目成员', $done, 'fa-arrow-left'))),
      $this->box('管理成员角色', $form, $errors)));
  }
}
