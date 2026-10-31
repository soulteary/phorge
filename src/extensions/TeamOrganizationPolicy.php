<?php

/** Team policies use live memberships; names always use viewer-aware queries. */
final class TeamOrganizationMembersCapability extends PhabricatorPolicyCapability {
  const CAPABILITY = 'team.organization.members';

  public function getCapabilityName() {
    return pht('查看成员名单');
  }

  public function describeCapabilityRejection() {
    return pht('您没有查看此团队成员名单的权限。');
  }
}

final class TeamOrganizationTeamPHIDType extends PhabricatorPHIDType {
  const TYPECONST = 'ORGT';

  public function getTypeName() { return pht('组织团队'); }
  public function getTypeIcon() { return 'fa-sitemap'; }
  public function newObject() { return new TeamOrganizationTeam(); }
  public function getPHIDTypeApplicationClass() {
    return TeamOrganizationApplication::class;
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query, array $phids) {
    return id(new TeamOrganizationTeamQuery())->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query, array $handles, array $objects) {
    foreach ($handles as $phid => $handle) {
      $team = $objects[$phid];
      // Ancestor names and member counts are deliberately not part of a handle.
      $handle->setName($team->getName())
        ->setURI('/organization/view/'.$team->getID().'/')
        ->setIcon('fa-sitemap');
      if ($team->getIsArchived()) {
        $handle->setStatus(PhabricatorObjectHandle::STATUS_CLOSED);
      }
    }
  }
}

final class TeamOrganizationDatasource extends PhabricatorTypeaheadDatasource {
  public function getBrowseTitle() { return pht('选择团队'); }
  public function getPlaceholderText() { return pht('输入团队名称…'); }
  public function getDatasourceApplicationClass() {
    return TeamOrganizationApplication::class;
  }

  public function loadResults() {
    $query = id(new TeamOrganizationTeamQuery())->withIsArchived(false);
    $text = trim((string)$this->getRawQuery());
    if (strlen($text)) {
      $query->withNameContains($text);
    }
    $teams = $this->executeQuery($query);
    $results = array();
    foreach ($teams as $team) {
      $results[] = id(new PhabricatorTypeaheadResult())
        ->setName($team->getName())
        ->setDisplayName($team->getName())
        ->setDisplayType(pht('组织团队'))
        ->setPHID($team->getPHID())
        ->setURI('/organization/view/'.$team->getID().'/')
        ->setIcon('fa-sitemap')
        ->setPriorityType('organization-team');
    }
    return $results;
  }
}

abstract class TeamOrganizationScopePolicyRule extends PhabricatorPolicyRule {
  private $scope = array();

  abstract protected function includeDescendants();

  public function willApplyRules(
    PhabricatorUser $viewer, array $values, array $objects) {
    // Replace the entire map on each evaluation, including an empty result.
    // Membership removal or user disable must not leave an old allow entry.
    $this->scope = TeamOrganizationService::getMembershipScope(
      $viewer, $this->includeDescendants());
  }

  public function applyRule(
    PhabricatorUser $viewer, $value, PhabricatorPolicyInterface $object) {
    if (!is_array($value)) {
      return false;
    }
    foreach ($value as $phid) {
      if (is_string($phid) && isset($this->scope[$phid])) {
        return true;
      }
    }
    return false;
  }

  public function getRuleOrder() { return 210; }
  public function getValueControlType() { return self::CONTROL_TYPE_TOKENIZER; }
  public function getValueControlTemplate() {
    return $this->getDatasourceTemplate(new TeamOrganizationDatasource());
  }

  public function getValueForStorage($value) {
    PhutilTypeSpec::newFromString('list<string>')->check($value);
    if (count($value) > 100) {
      throw new InvalidArgumentException('每条规则最多选择 100 个团队。');
    }
    foreach ($value as $phid) {
      if (phid_get_type($phid) !== TeamOrganizationTeamPHIDType::TYPECONST) {
        throw new InvalidArgumentException('团队规则只能选择组织团队。');
      }
    }
    return array_values(array_unique($value));
  }

  public function getValueForDisplay(PhabricatorUser $viewer, $value) {
    $handles = id(new PhabricatorHandleQuery())->setViewer($viewer)
      ->withPHIDs(is_array($value) ? $value : array())->execute();
    return mpull($handles, 'getFullName', 'getPHID');
  }

  public function ruleHasEffect($value) {
    return is_array($value) && (bool)$value;
  }
}

final class TeamOrganizationDirectPolicyRule extends TeamOrganizationScopePolicyRule {
  public function getRuleDescription() { return pht('所选团队本级成员'); }
  protected function includeDescendants() { return false; }
}

final class TeamOrganizationDescendantsPolicyRule extends TeamOrganizationScopePolicyRule {
  public function getRuleDescription() { return pht('所选团队及下级成员'); }
  protected function includeDescendants() { return true; }
  public function getRuleOrder() { return 211; }
}

/** Convenient team-local policy; unrelated to project membership. */
final class TeamOrganizationMembersPolicyRule extends PhabricatorPolicyRule {
  private $scope = array();

  public function getRuleDescription() { return pht('本团队成员'); }
  public function getValueControlType() { return self::CONTROL_TYPE_NONE; }
  public function canApplyToObject(PhabricatorPolicyInterface $object) {
    return $object instanceof TeamOrganizationTeam;
  }
  public function getObjectPolicyKey() { return 'team.organization.members'; }
  public function getObjectPolicyName() { return pht('本团队成员'); }
  public function getObjectPolicyIcon() { return 'fa-users'; }
  public function getPolicyExplanation() {
    return pht('仅本团队直接成员；下级团队成员不会自动获得此权限。');
  }

  public function willApplyRules(
    PhabricatorUser $viewer, array $values, array $objects) {
    $this->scope = TeamOrganizationService::getMembershipScope($viewer, false);
  }

  public function applyRule(
    PhabricatorUser $viewer, $value, PhabricatorPolicyInterface $object) {
    return $object instanceof TeamOrganizationTeam &&
      isset($this->scope[$object->getPHID()]);
  }
}

/** Optional project-wide restriction, evaluated after native automatic grants. */
final class TeamOrganizationProjectBoundary extends Phobject {
  const TEAMS_PROPERTY = 'team.organization.boundary.teams';
  const SCOPE_PROPERTY = 'team.organization.boundary.scope';

  public static function getProject($project) {
    if ($project->isMilestone()) {
      return $project->getParentProject();
    }
    return $project;
  }

  public static function newGuard($project) {
    $project = self::getProject($project);
    $teams = $project->getProperty(self::TEAMS_PROPERTY, array());
    if ($teams === array()) {
      return null;
    }
    return new TeamOrganizationProjectBoundaryGuard($project);
  }

  public static function canPass($viewer, $project) {
    if (TeamOrganizationService::canManage($viewer)) {
      return true;
    }
    $project = self::getProject($project);
    $teams = $project->getProperty(self::TEAMS_PROPERTY, array());
    if ($teams === array()) {
      return true;
    }
    if (!is_array($teams)) {
      return false;
    }
    $scope = TeamOrganizationService::getMembershipScope(
      $viewer, $project->getProperty(self::SCOPE_PROPERTY, 'direct') === 'descendants');
    foreach ($teams as $phid) {
      if (is_string($phid) && isset($scope[$phid])) {
        return true;
      }
    }
    return false;
  }
}

final class TeamOrganizationProjectBoundaryGuard extends Phobject
  implements PhabricatorPolicyInterface {

  private $project;

  public function __construct($project) { $this->project = $project; }
  public function getPHID() {
    // Extended checks key objects by PHID. Never share a synthetic key across
    // different projects or scopes in a batched query.
    return 'PHID-ORGB-'.substr(hash('sha256', serialize(array(
      $this->project->getPHID(),
      $this->project->getProperty(TeamOrganizationProjectBoundary::TEAMS_PROPERTY),
      $this->project->getProperty(TeamOrganizationProjectBoundary::SCOPE_PROPERTY),
    ))), 0, 20);
  }
  public function getCapabilities() {
    return array(PhabricatorPolicyCapability::CAN_VIEW);
  }
  public function getPolicy($capability) {
    return PhabricatorPolicies::POLICY_NOONE;
  }
  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return TeamOrganizationProjectBoundary::canPass($viewer, $this->project);
  }
  public function describeAutomaticCapability($capability) {
    return pht('需要符合项目的团队访问边界；管理员可管理。');
  }
}

/** Display-only handles for synthetic checks in the native policy explanation. */
final class TeamOrganizationBoundaryPHIDType extends PhabricatorPHIDType {
  const TYPECONST = 'ORGB';
  public function getTypeName() { return pht('团队访问边界'); }
  public function getTypeIcon() { return 'fa-sitemap'; }
  public function getPHIDTypeApplicationClass() { return TeamOrganizationApplication::class; }
  protected function buildQueryForObjects(PhabricatorObjectQuery $query, array $phids) {
    return id(new TeamOrganizationTeamQuery())->withPHIDs(array());
  }
  public function loadObjects(PhabricatorObjectQuery $query, array $phids) {
    if (!($query->getParentQuery() instanceof PhabricatorHandleQuery)) {
      return array();
    }
    $objects = array();
    foreach ($phids as $phid) {
      if (preg_match('/\APHID-ORGB-[a-f0-9]{20}\z/', $phid)) {
        $objects[$phid] = new TeamOrganizationBoundaryLabel($phid);
      }
    }
    return $objects;
  }
  public function loadHandles(PhabricatorHandleQuery $query, array $handles, array $objects) {
    foreach ($handles as $handle) {
      $handle->setName(pht('团队访问边界'))->setIcon('fa-sitemap')->setURI(null);
    }
  }
}

/** A label never authorizes a user, even if reused by an extended policy. */
final class TeamOrganizationBoundaryLabel extends Phobject implements PhabricatorPolicyInterface {
  private $phid;
  public function __construct($phid) { $this->phid = $phid; }
  public function getPHID() { return $this->phid; }
  public function getCapabilities() { return array(PhabricatorPolicyCapability::CAN_VIEW); }
  public function getPolicy($capability) { return PhabricatorPolicies::POLICY_NOONE; }
  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) { return false; }
}

final class TeamOrganizationBoundaryTeamsEditField extends PhabricatorTokenizerEditField {
  protected function newDatasource() { return new TeamOrganizationDatasource(); }
  protected function newControl() {
    return parent::newControl()->setCaption(
      pht('高级额外限制，与普通授权取交集；留空不限制，已有项目成员也受限。'.
        '同时影响查看、编辑和加入，不改变团队目录或名单隐私。'.
        '这项设置只限制、不授予权限；已有项目请在“权限管理”中增添人员或团队，'.
        '新项目可在创建后配置这些授权。创建时仍由“谁能查看、编辑、加入”决定初始范围。'.
        '站点管理员仍须满足项目原有权限。'));
  }
}

final class TeamOrganizationProjectEditExtension extends PhabricatorEditEngineExtension {
  const EXTENSIONKEY = 'team.organization.project-boundary';
  public function isExtensionEnabled() { return true; }
  public function getExtensionName() { return pht('团队额外限制'); }
  public function supportsObject(
    PhabricatorEditEngine $engine,
    PhabricatorApplicationTransactionInterface $object) {
    return $object instanceof PhabricatorProject && !$object->isMilestone();
  }
  public function buildCustomEditFields(
    PhabricatorEditEngine $engine,
    PhabricatorApplicationTransactionInterface $object) {
    return array(
      id(new TeamOrganizationBoundaryTeamsEditField())
        ->setKey('organizationTeams')->setLabel(pht('团队额外限制（影响查看、编辑、加入）'))
        ->setEditTypeKey('organization.teams')
        ->setDescription(pht('在原项目权限之外要求符合所选团队；空列表关闭此额外限制。'))
        ->setTransactionType(TeamOrganizationBoundaryTeamsTransaction::TRANSACTIONTYPE)
        ->setValue($object->getProperty(TeamOrganizationProjectBoundary::TEAMS_PROPERTY, array())),
      id(new PhabricatorSelectEditField())
        ->setKey('organizationScope')->setLabel(pht('额外限制的团队范围'))
        ->setEditTypeKey('organization.scope')
        ->setDescription(pht('限制范围包含所选团队本级，或所选团队及全部下级。'))
        ->setTransactionType(TeamOrganizationBoundaryScopeTransaction::TRANSACTIONTYPE)
        ->setOptions(array('direct' => '仅所选团队本级', 'descendants' => '所选团队及全部下级'))
        ->setValue($object->getProperty(TeamOrganizationProjectBoundary::SCOPE_PROPERTY, 'direct')),
    );
  }
}

abstract class TeamOrganizationBoundaryTransaction extends PhabricatorProjectTransactionType {
  abstract protected function getPropertyKey();
  abstract protected function getDefaultValue();

  public function generateOldValue($object) {
    return $object->getProperty($this->getPropertyKey(), $this->getDefaultValue());
  }
  public function applyInternalEffects($object, $value) {
    $object->setProperty($this->getPropertyKey(), $value);
  }
  public function getRequiredCapabilities($object, PhabricatorApplicationTransaction $xaction) {
    if (!TeamOrganizationService::isActiveUser($this->getActor())) {
      throw new PhabricatorPolicyException('只有有效用户可以修改团队访问边界。');
    }
    return PhabricatorPolicyCapability::CAN_EDIT;
  }
  public function getIcon() { return 'fa-sitemap'; }
}

final class TeamOrganizationBoundaryTeamsTransaction extends TeamOrganizationBoundaryTransaction {
  const TRANSACTIONTYPE = 'team:organization-boundary-teams';
  protected function getPropertyKey() { return TeamOrganizationProjectBoundary::TEAMS_PROPERTY; }
  protected function getDefaultValue() { return array(); }

  public function getTitle() {
    if (!$this->getNewValue()) {
      return pht('%s 关闭了团队访问边界。', $this->renderAuthor());
    }
    return pht('%s 将团队访问边界设为 %s。',
      $this->renderAuthor(), $this->renderHandleList($this->getNewValue()));
  }
  public function getTitleForFeed() {
    return pht('%s 更新了 %s 的团队访问边界。', $this->renderAuthor(), $this->renderObject());
  }
  public function validateTransactions($object, array $xactions) {
    $errors = array();
    foreach ($xactions as $xaction) {
      try {
        if ($object->isMilestone()) {
          throw new InvalidArgumentException('里程碑沿用所属项目的团队访问边界。');
        }
        $teams = id(new TeamOrganizationDirectPolicyRule())
          ->getValueForStorage($xaction->getNewValue());
        // Existing references remain valid when the team later becomes hidden
        // or archived. Re-saving an unrelated project field must not require
        // visibility of every previously selected team.
        $old = $object->getProperty(TeamOrganizationProjectBoundary::TEAMS_PROPERTY, array());
        $added = array_values(array_diff($teams, is_array($old) ? $old : array()));
        if ($added) {
          $visible = id(new TeamOrganizationTeamQuery())->setViewer($this->getActor())
            ->withPHIDs($added)->withIsArchived(false)->execute();
          if (count($visible) !== count($added)) {
            throw new InvalidArgumentException('请选择您可见的有效团队。');
          }
        }
        $xaction->setNewValue($teams);
      } catch (Exception $ex) {
        $errors[] = $this->newInvalidError($ex->getMessage(), $xaction);
      }
    }
    return $errors;
  }
}

final class TeamOrganizationBoundaryScopeTransaction extends TeamOrganizationBoundaryTransaction {
  const TRANSACTIONTYPE = 'team:organization-boundary-scope';
  protected function getPropertyKey() { return TeamOrganizationProjectBoundary::SCOPE_PROPERTY; }
  protected function getDefaultValue() { return 'direct'; }
  public function getTitle() {
    return pht('%s 将团队边界范围设为“%s”。', $this->renderAuthor(),
      $this->getNewValue() === 'descendants' ? '包含下级团队成员' : '仅本团队成员');
  }
  public function getTitleForFeed() {
    return pht('%s 更新了 %s 的团队边界范围。', $this->renderAuthor(), $this->renderObject());
  }
  public function validateTransactions($object, array $xactions) {
    $errors = array();
    foreach ($xactions as $xaction) {
      if ($object->isMilestone() ||
          !in_array($xaction->getNewValue(), array('direct', 'descendants'), true)) {
        $errors[] = $this->newInvalidError('请选择有效的团队边界范围；里程碑沿用所属项目设置。', $xaction);
      }
    }
    return $errors;
  }
}

/** Check both fields together so form field order cannot alter validation. */
final class TeamOrganizationBoundaryValidationExtension extends PhabricatorEditorExtension {
  const EXTENSIONKEY = 'team.organization.boundary-validation';
  public function getExtensionName() { return pht('团队边界校验'); }
  // This pinned editor invokes setObject(), although its extension base omits
  // that setter. Validation receives the same object explicitly below.
  public function setObject($object) { return $this; }
  public function supportsObject(
    PhabricatorApplicationTransactionEditor $editor,
    PhabricatorApplicationTransactionInterface $object) {
    return $object instanceof PhabricatorProject;
  }
  public function validateTransactions($object, array $xactions) {
    $copy = clone $object;
    $changed = null;
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case TeamOrganizationBoundaryTeamsTransaction::TRANSACTIONTYPE:
          $copy->setProperty(TeamOrganizationProjectBoundary::TEAMS_PROPERTY, $xaction->getNewValue());
          $changed = $xaction;
          break;
        case TeamOrganizationBoundaryScopeTransaction::TRANSACTIONTYPE:
          $copy->setProperty(TeamOrganizationProjectBoundary::SCOPE_PROPERTY, $xaction->getNewValue());
          $changed = $xaction;
          break;
      }
    }
    if ($changed && !TeamOrganizationProjectBoundary::canPass($this->getViewer(), $copy)) {
      return array($this->newTransactionError($changed, pht('团队边界'),
        pht('您不属于所选团队范围。请选择包含自己的范围，或由管理员设置。')));
    }
    return array();
  }
}

/** Read-only summary of the boundary; authorization is native transactions. */
final class TeamOrganizationProjectBoundaryField extends PhabricatorProjectCustomField {
  public function getFieldKey() { return 'team:organization-boundary'; }
  public function getFieldName() { return pht('团队访问边界'); }
  public function canDisableField() { return false; }
  public function shouldAppearInPropertyView() { return true; }
  public function renderPropertyViewValue(array $handles) {
    $project = TeamOrganizationProjectBoundary::getProject($this->getObject());
    $teams = $project->getProperty(TeamOrganizationProjectBoundary::TEAMS_PROPERTY, array());
    if (!is_array($teams) || !$teams) { return null; }
    return array(
      $this->getViewer()->renderHandleList($teams)->setAsInline(true),
      $project->getProperty(TeamOrganizationProjectBoundary::SCOPE_PROPERTY, 'direct') === 'descendants'
        ? '（包含下级团队成员）' : '（仅本团队成员）',
    );
  }
}

/** Manage unions of native policy grants without a parallel permissions store. */
final class TeamProjectAccessService extends Phobject {
  const MAX_RULES = 100;
  const MAX_SUBJECTS = 100;

  public static function getFormModes() {
    return array(
      'add' => '增添指定人员或团队的授权',
      'replace' => '替换为指定人员或团队的授权',
      'revoke' => '回收指定人员或团队的授权',
      'keep' => '保持当前授权',
      'all-users' => '替换为所有已登录用户',
      'administrators' => '替换为站点管理员',
      'automatic' => '替换为仅系统自动权限',
    );
  }

  public static function getTeamScopes() {
    return array(
      'none' => '不选择团队',
      'direct' => '仅所选团队本级',
      'descendants' => '所选团队及全部下级',
      'mixed' => '不同团队分别设置',
    );
  }

  public static function getBoundaryModes() {
    return array(
      'keep' => '保持现有额外限制',
      'none' => '关闭团队额外限制',
      'direct' => '额外要求属于所选团队本级',
      'descendants' => '额外要求属于所选团队及下级',
    );
  }

  public static function loadProject(PhabricatorUser $viewer, $id) {
    if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
      return null;
    }
    $viewer = self::currentUser($viewer);
    if (!$viewer) { return null; }
    // Establish the primary connection before the policy-aware query. Lisk
    // reuses an established write connection for subsequent read queries.
    id(new PhabricatorProject())->establishConnection('w');
    return id(new PhabricatorProjectQuery())->setViewer($viewer)
      ->withIDs(array((int)$id))->needMembers(true)->needAncestorMembers(true)
      ->executeOne();
  }

  public static function canManage(
    PhabricatorUser $viewer, PhabricatorProject $project) {
    $viewer = self::currentUser($viewer);
    if (!self::usableUser($viewer)) { return false; }
    $current = self::loadProject($viewer, $project->getID());
    return $current && $current->getPHID() === $project->getPHID() &&
      PhabricatorPolicyFilter::hasCapability(
        $viewer, $current, PhabricatorPolicyCapability::CAN_EDIT);
  }

  public static function getStateToken(PhabricatorProject $project) {
    $boundary = TeamOrganizationProjectBoundary::getProject($project);
    return hash('sha256', serialize(array(
      $project->getPHID(), $project->getPolicy('view'),
      $project->getPolicy('edit'), $project->getPolicy('join'),
      $boundary->getProperty(TeamOrganizationProjectBoundary::TEAMS_PROPERTY, array()),
      $boundary->getProperty(TeamOrganizationProjectBoundary::SCOPE_PROPERTY, 'direct'),
    )));
  }

  public static function getPolicy(
    PhabricatorUser $viewer, PhabricatorProject $project, $capability) {
    self::requireCapability($capability);
    $policy = id(new PhabricatorPolicyQuery())->setViewer($viewer)
      ->withPHIDs(array($project->getPolicy($capability)))
      ->needPolicyDetails(true)->executeOne();
    if (!$policy) {
      throw new InvalidArgumentException(pht('当前权限规则无法加载，请通过原生高级权限设置检查。'));
    }
    return $policy;
  }

  public static function apply(
    PhabricatorUser $actor, PhabricatorProject $project,
    array $input, PhabricatorContentSource $source) {
    return self::applyInput($actor, $project, $input, $source, null);
  }

  /** Validate dropdown semantics before sharing the native transaction path. */
  public static function applyForm(
    PhabricatorUser $actor, PhabricatorProject $project,
    array $input, PhabricatorContentSource $source) {
    $mode = idx($input, 'mode', 'add');
    $scope = idx($input, 'teamScope', 'none');
    $boundary_mode = idx($input, 'boundaryMode', 'keep');
    if (!is_string($mode) || !isset(self::getFormModes()[$mode]) ||
        !is_string($scope) || !isset(self::getTeamScopes()[$scope]) ||
        !is_string($boundary_mode) || !isset(self::getBoundaryModes()[$boundary_mode])) {
      throw new InvalidArgumentException(pht('请选择有效的授权方式、团队范围和团队额外限制。'));
    }
    // Old clients retain clearBoundary through apply(). The unified form must
    // use only boundaryMode, so two controls can never silently disagree.
    if (array_key_exists('clearBoundary', $input)) {
      throw new InvalidArgumentException(pht('请通过“团队额外限制”选项调整限制，不要同时提交旧版关闭选项。'));
    }
    $users = self::normalizeSubjects(idx($input, 'users', array()), 'USER');
    $teams = self::normalizeSubjects(idx($input, 'teams', array()), 'ORGT');
    $descendants = self::normalizeSubjects(idx($input, 'descendantTeams', array()), 'ORGT');
    if (($scope === 'none' && ($teams || $descendants)) ||
        ($scope === 'direct' && $descendants) ||
        ($scope === 'descendants' && $teams)) {
      throw new InvalidArgumentException(pht('团队选择与当前包含范围不一致，请重新选择。'));
    }
    if (!in_array($mode, array('add', 'replace', 'revoke'), true) &&
        ($users || $teams || $descendants)) {
      throw new InvalidArgumentException(pht('当前授权方式不接受指定人员或团队，请清除这些选择后重试。'));
    }
    $boundary_teams = self::normalizeSubjects(idx($input, 'boundaryTeams', array()), 'ORGT');
    if (in_array($boundary_mode, array('direct', 'descendants'), true)) {
      if (!$boundary_teams) {
        throw new InvalidArgumentException(pht('启用团队额外限制时，至少选择一个团队。'));
      }
    } else if ($boundary_teams) {
      throw new InvalidArgumentException(pht('保持或关闭额外限制时，不应提交新的限制团队。'));
    }
    $input['mode'] = $mode;
    $input['users'] = $users;
    $input['teams'] = $teams;
    $input['descendantTeams'] = $descendants;
    $input['clearBoundary'] = false;
    return self::applyInput($actor, $project, $input, $source, array(
      'mode' => $boundary_mode,
      'teams' => $boundary_teams,
    ));
  }

  private static function applyInput(
    PhabricatorUser $actor, PhabricatorProject $project,
    array $input, PhabricatorContentSource $source, $boundary_form) {
    $capability = idx($input, 'capability', 'view');
    self::requireCapability($capability);
    $mode = idx($input, 'mode', 'add');
    $modes = $boundary_form === null
      ? array('add', 'replace', 'revoke', 'remove-rule')
      : array_keys(self::getFormModes());
    if (!in_array($mode, $modes, true)) {
      throw new InvalidArgumentException(pht('请选择有效的授权操作。'));
    }
    $clear = idx($input, 'clearBoundary', false);
    if (!is_bool($clear)) {
      throw new InvalidArgumentException(pht('团队额外限制选项无效。'));
    }
    $expected = idx($input, 'expectedState');
    if (!is_string($expected) || !preg_match('/\A[a-f0-9]{64}\z/', $expected)) {
      throw new InvalidArgumentException(pht('缺少权限版本，请刷新后重试。'));
    }
    $selected = array(
      PhabricatorUsersPolicyRule::class => self::normalizeSubjects(idx($input, 'users', array()), 'USER'),
      TeamOrganizationDirectPolicyRule::class => self::normalizeSubjects(idx($input, 'teams', array()), 'ORGT'),
      TeamOrganizationDescendantsPolicyRule::class => self::normalizeSubjects(idx($input, 'descendantTeams', array()), 'ORGT'),
    );
    $has_subjects = (bool)array_filter($selected);
    $boundary_requested = $boundary_form !== null && $boundary_form['mode'] !== 'keep';
    if (($mode === 'add' || $mode === 'revoke') && !$has_subjects && !$clear && !$boundary_requested) {
      throw new InvalidArgumentException(pht('请选择要增添或回收授权的人员、团队。'));
    }

    $dao = new PhabricatorProject();
    $conn = $dao->establishConnection('w');
    $dao->openTransaction();
    try {
      $row = queryfx_one($conn,
        'SELECT id, phid FROM %T WHERE id = %d FOR UPDATE',
        $dao->getTableName(), (int)$project->getID());
      $actor = self::currentUser($actor);
      if (!$row || $row['phid'] !== $project->getPHID() || !self::usableUser($actor)) {
        throw self::managementException();
      }
      $project = self::loadProject($actor, (int)$row['id']);
      if (!$project || !PhabricatorPolicyFilter::hasCapability(
          $actor, $project, PhabricatorPolicyCapability::CAN_EDIT)) {
        throw self::managementException();
      }
      if ($project->isMilestone()) {
        throw new InvalidArgumentException(pht('里程碑沿用所属项目权限，请在所属项目中管理授权。'));
      }
      if (!hash_equals(self::getStateToken($project), $expected)) {
        throw new InvalidArgumentException(pht('权限已被其他操作修改，请刷新后重试。'));
      }

      $xactions = array();
      $global = array(
        'all-users' => PhabricatorPolicies::POLICY_USER,
        'administrators' => PhabricatorPolicies::POLICY_ADMIN,
        'automatic' => PhabricatorPolicies::POLICY_NOONE,
      );
      $policy_change = $mode !== 'keep' &&
        (!in_array($mode, array('add', 'revoke'), true) || $has_subjects);
      if ($boundary_form !== null && in_array($mode, array('add', 'revoke'), true) &&
          phid_get_type($project->getPolicy($capability)) === PhorgePolicyPHIDTypeNamedPolicy::TYPECONST) {
        throw new InvalidArgumentException(pht('当前使用命名策略。调整团队额外限制请选择“保持当前授权”；改变授权请使用原生高级权限管理或明确选择“替换”。'));
      }
      if (isset($global[$mode])) {
        // A preset references the native policy directly; shared and named
        // policy objects remain untouched. Automatic grants still apply.
        if ($project->getPolicy($capability) !== $global[$mode]) {
          $xactions[] = self::newPolicyTransaction($capability, $global[$mode], $mode);
        }
      } else if ($policy_change) {
        $identifier = $project->getPolicy($capability);
        if (phid_get_type($identifier) === PhorgePolicyPHIDTypeNamedPolicy::TYPECONST && $mode !== 'replace') {
          throw new InvalidArgumentException(pht('当前使用命名策略。请使用原生高级权限管理，或明确选择“替换”；增添或回收不会自动复制并断开命名策略。'));
        }
        $old = self::getPolicy($actor, $project, $capability);
        $old_rules = $old->getRules();
        $default = $old->getDefaultAction();
        if (!is_array($old_rules) || !in_array($default, array('allow', 'deny'), true)) {
          throw new InvalidArgumentException(pht('当前规则格式异常，请使用原生高级权限管理。'));
        }
        $rules = $old_rules;
        // Native global "public" is restricted to logged-in users for
        // capabilities which do not support anonymous authorization.
        $cap = PhabricatorPolicyCapability::getCapabilityByKey($capability);
        if ($identifier === PhabricatorPolicies::POLICY_PUBLIC &&
            (!$cap || !$cap->shouldAllowPublicPolicySetting())) {
          $rules = PhabricatorPolicyQuery::getGlobalPolicy(
            PhabricatorPolicies::POLICY_USER)->getRules();
        }

        if ($mode === 'add' || $mode === 'replace') {
          self::validateNewSubjects($actor, $selected);
        }
        switch ($mode) {
          case 'add':
            // Only deduplicate against unconditional leading allow rules.
            // Crossing a deny rule would change ordered legacy semantics.
            $new = $selected;
            foreach ($rules as $rule) {
              if (!is_array($rule) || idx($rule, 'action') !== 'allow') { break; }
              $class = idx($rule, 'rule');
              if (isset($new[$class]) && is_array(idx($rule, 'value'))) {
                $new[$class] = array_values(array_diff($new[$class], $rule['value']));
              }
            }
            $rules = array_merge(self::grantRules($new), $rules);
            break;
          case 'replace':
            $rules = self::grantRules($selected);
            $default = PhabricatorPolicy::ACTION_DENY;
            break;
          case 'revoke':
            foreach ($rules as $index => $rule) {
              if (!is_array($rule) || idx($rule, 'action') !== 'allow') { continue; }
              $class = idx($rule, 'rule');
              if (!isset($selected[$class]) || !is_array(idx($rule, 'value'))) { continue; }
              $value = array_values(array_diff($rule['value'], $selected[$class]));
              if (!$value) { unset($rules[$index]); }
              else { $rules[$index]['value'] = $value; }
            }
            $rules = array_values($rules);
            break;
          case 'remove-rule':
            $index = idx($input, 'ruleIndex');
            if (!is_int($index) && !(is_string($index) && ctype_digit($index))) {
              throw new InvalidArgumentException(pht('请选择要回收的授权规则。'));
            }
            $index = (int)$index;
            if (!isset($rules[$index]) || idx($rules[$index], 'action') !== 'allow') {
              throw new InvalidArgumentException(pht('只能回收允许规则；拒绝规则请通过原生高级权限管理。'));
            }
            unset($rules[$index]);
            $rules = array_values($rules);
            break;
        }
        // Existing oversized policies must remain reducible. The limit guards
        // growth, not a manager's ability to remove old authorization.
        if (count($rules) > self::MAX_RULES && count($rules) > count($old_rules)) {
          throw new InvalidArgumentException(pht('权限规则超过 %s 条，请先整理现有规则。', self::MAX_RULES));
        }
        if ($rules !== $old_rules || $default !== $old->getDefaultAction() ||
            ($mode === 'replace' && phid_get_type($identifier) === PhorgePolicyPHIDTypeNamedPolicy::TYPECONST)) {
          // Policies may be shared by unrelated objects and history. Never
          // mutate the old policy or its rules in place.
          $policy = id(new PhabricatorPolicy())->setRules($rules)
            ->setDefaultAction($default)->save();
          $xactions[] = self::newPolicyTransaction($capability, $policy->getPHID(), $mode);
        }
      }
      if ($boundary_form !== null && $boundary_form['mode'] !== 'keep') {
        $boundary_teams = $boundary_form['teams'];
        $old_teams = $project->getProperty(TeamOrganizationProjectBoundary::TEAMS_PROPERTY, array());
        if ($boundary_teams !== $old_teams) {
          $xactions[] = id(new PhabricatorProjectTransaction())
            ->setTransactionType(TeamOrganizationBoundaryTeamsTransaction::TRANSACTIONTYPE)
            ->setNewValue($boundary_teams)
            ->setMetadataValue('team.project.access.operation', $mode)
            ->setMetadataValue('team.project.access.boundary-mode', $boundary_form['mode']);
        }
        if (in_array($boundary_form['mode'], array('direct', 'descendants'), true) &&
            $project->getProperty(TeamOrganizationProjectBoundary::SCOPE_PROPERTY, 'direct') !== $boundary_form['mode']) {
          $xactions[] = id(new PhabricatorProjectTransaction())
            ->setTransactionType(TeamOrganizationBoundaryScopeTransaction::TRANSACTIONTYPE)
            ->setNewValue($boundary_form['mode'])
            ->setMetadataValue('team.project.access.operation', $mode)
            ->setMetadataValue('team.project.access.boundary-mode', $boundary_form['mode']);
        }
        if (($mode === 'add' || $mode === 'revoke') && !$has_subjects && !$xactions) {
          throw new InvalidArgumentException(pht('未选择授权对象，也没有需要变更的团队额外限制。'));
        }
      } else if ($clear && TeamOrganizationProjectBoundary::newGuard($project)) {
        $xactions[] = id(new PhabricatorProjectTransaction())
          ->setTransactionType(TeamOrganizationBoundaryTeamsTransaction::TRANSACTIONTYPE)
          ->setNewValue(array())
          ->setMetadataValue('team.project.access.operation', $mode)
          ->setMetadataValue('team.project.access.clear-boundary', true);
      }
      if ($xactions) {
        id(new PhabricatorProjectTransactionEditor())->setActor($actor)
          ->setContentSource($source)->setContinueOnNoEffect(true)
          ->setContinueOnMissingFields(true)->applyTransactions($project, $xactions);
      }
      $dao->saveTransaction();
    } catch (Throwable $ex) {
      $dao->killTransaction();
      throw $ex;
    }
    return self::loadProject($actor, $project->getID());
  }

  private static function newPolicyTransaction($capability, $policy, $mode) {
    $types = array('view' => PhabricatorTransactions::TYPE_VIEW_POLICY,
      'edit' => PhabricatorTransactions::TYPE_EDIT_POLICY,
      'join' => PhabricatorTransactions::TYPE_JOIN_POLICY);
    return id(new PhabricatorProjectTransaction())
      ->setTransactionType($types[$capability])->setNewValue($policy)
      ->setMetadataValue('team.project.access.operation', $mode)
      ->setMetadataValue('team.project.access.capability', $capability);
  }

  private static function requireCapability($capability) {
    if (!in_array($capability, array('view', 'edit', 'join'), true)) {
      throw new InvalidArgumentException(pht('请选择查看、编辑或加入权限。'));
    }
  }

  private static function normalizeSubjects($values, $type) {
    if (!is_array($values) || count($values) > self::MAX_SUBJECTS) {
      throw new InvalidArgumentException(pht('每类授权对象最多选择 %s 项。', self::MAX_SUBJECTS));
    }
    foreach ($values as $value) {
      if (!is_string($value) || !preg_match('/\APHID-'.$type.'-[a-z0-9]{20}\z/', $value)) {
        throw new InvalidArgumentException(pht('包含无效的授权对象。'));
      }
    }
    $values = array_values(array_unique($values));
    sort($values, SORT_STRING);
    return $values;
  }

  private static function grantRules(array $selected) {
    $rules = array();
    foreach ($selected as $class => $values) {
      if ($values) { $rules[] = array('action' => 'allow', 'rule' => $class, 'value' => $values); }
    }
    return $rules;
  }

  private static function validateNewSubjects($actor, array $selected) {
    $users = $selected[PhabricatorUsersPolicyRule::class];
    if ($users) {
      $visible = id(new PhabricatorPeopleQuery())->setViewer($actor)
        ->withPHIDs($users)->execute();
      if (count($visible) !== count($users)) {
        throw new InvalidArgumentException(pht('请选择您可见的有效用户。'));
      }
      foreach ($visible as $user) {
        if (!self::usableUser(self::currentUser($user))) {
          throw new InvalidArgumentException(pht('请选择您可见的有效用户。'));
        }
      }
    }
    $teams = array_values(array_unique(array_merge(
      $selected[TeamOrganizationDirectPolicyRule::class],
      $selected[TeamOrganizationDescendantsPolicyRule::class])));
    if ($teams) {
      id(new TeamOrganizationTeam())->establishConnection('w');
      $visible = id(new TeamOrganizationTeamQuery())->setViewer($actor)
        ->withPHIDs($teams)->withIsArchived(false)->execute();
      if (count($visible) !== count($teams)) {
        throw new InvalidArgumentException(pht('请选择您可见且未归档的团队。'));
      }
    }
  }

  private static function currentUser(PhabricatorUser $user) {
    if (!$user->getPHID()) { return null; }
    $dao = new PhabricatorUser();
    $row = queryfx_one($dao->establishConnection('w'),
      'SELECT * FROM %T WHERE phid = %s', $dao->getTableName(), $user->getPHID());
    return $row ? $dao->loadFromArray($row) : null;
  }

  private static function usableUser($user) {
    return $user && $user->isUserActivated() &&
      !$user->getIsSystemAgent() && !$user->getIsMailingList();
  }

  private static function managementException() {
    return new PhabricatorPolicyException(pht('仅当前具有项目查看和编辑权限的有效用户可以管理授权。'));
  }
}
