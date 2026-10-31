<?php

/** Independent organization data; hierarchy never implies object visibility. */
final class TeamOrganizationTeam extends PhabricatorUserDAO
  implements PhabricatorPolicyInterface {

  const PHID_TYPE = 'ORGT';
  const CAP_MEMBERS = 'team.organization.members';
  const DEFAULT_MEMBERS_POLICY = 'obj.team.organization.members';

  protected $name;
  protected $description = '';
  protected $parentPHID;
  protected $viewPolicy = self::DEFAULT_MEMBERS_POLICY;
  protected $membersPolicy = self::DEFAULT_MEMBERS_POLICY;
  protected $isArchived = 0;
  protected $version = 1;
  protected $creatorPHID;

  public function getTableName() {
    return 'user_organizationteam';
  }

  protected function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
      self::CONFIG_COLUMN_SCHEMA => array(
        'name' => 'sort255',
        'description' => 'text',
        'parentPHID' => 'phid?',
        'membersPolicy' => 'policy',
        'isArchived' => 'bool',
        'version' => 'uint32',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'key_parent' => array('columns' => array('parentPHID', 'id')),
        'key_name' => array('columns' => array('name')),
      ),
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(self::PHID_TYPE);
  }

  public function getURI() {
    return '/organization/view/'.$this->getID().'/';
  }

  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
      self::CAP_MEMBERS,
    );
  }

  public function getPolicy($capability) {
    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        return $this->getViewPolicy();
      case PhabricatorPolicyCapability::CAN_EDIT:
        return PhabricatorPolicies::POLICY_ADMIN;
      case self::CAP_MEMBERS:
        return $this->getMembersPolicy();
    }
    return PhabricatorPolicies::getFallbackPolicy($capability);
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return in_array($capability, $this->getCapabilities(), true) &&
      TeamOrganizationService::canManage($viewer);
  }

  public function describeAutomaticCapability($capability) {
    return pht('有效管理员始终可以查看和管理团队及其成员名单。');
  }
}

final class TeamOrganizationMember extends PhabricatorUserDAO {
  protected $teamPHID;
  protected $userPHID;

  public function getTableName() {
    return 'user_organizationmember';
  }

  protected function getConfiguration() {
    return array(
      self::CONFIG_KEY_SCHEMA => array(
        'key_team_user' => array(
          'columns' => array('teamPHID', 'userPHID'),
          'unique' => true,
        ),
        'key_user' => array('columns' => array('userPHID', 'teamPHID')),
      ),
    ) + parent::getConfiguration();
  }
}

/** Audit payloads are deliberately not exposed through public object handles. */
final class TeamOrganizationAudit extends PhabricatorUserDAO
  implements PhabricatorPolicyInterface {
  protected $teamPHID;
  protected $actorPHID;
  protected $action;
  protected $oldValue;
  protected $newValue;

  public function getTableName() {
    return 'user_organizationaudit';
  }

  protected function getConfiguration() {
    return array(
      self::CONFIG_SERIALIZATION => array(
        'oldValue' => self::SERIALIZATION_JSON,
        'newValue' => self::SERIALIZATION_JSON,
      ),
      self::CONFIG_COLUMN_SCHEMA => array('action' => 'text32'),
      self::CONFIG_KEY_SCHEMA => array(
        'key_team' => array('columns' => array('teamPHID', 'id')),
        'key_actor' => array('columns' => array('actorPHID', 'id')),
      ),
    ) + parent::getConfiguration();
  }

  public function getCapabilities() {
    return array(PhabricatorPolicyCapability::CAN_VIEW);
  }

  public function getPolicy($capability) {
    return PhabricatorPolicies::POLICY_NOONE;
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return $capability === PhabricatorPolicyCapability::CAN_VIEW &&
      TeamOrganizationService::canManage($viewer);
  }
}

/** A persistent singleton row serializes hierarchy and membership mutations. */
final class TeamOrganizationLock extends PhabricatorUserDAO {
  protected $lockName;

  public function getTableName() {
    return 'user_organizationlock';
  }

  protected function getConfiguration() {
    return array(
      self::CONFIG_COLUMN_SCHEMA => array('lockName' => 'text32'),
      self::CONFIG_KEY_SCHEMA => array(
        'key_name' => array('columns' => array('lockName'), 'unique' => true),
      ),
    ) + parent::getConfiguration();
  }
}

/** @extends PhabricatorCursorPagedPolicyAwareQuery<TeamOrganizationTeam> */
final class TeamOrganizationTeamQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {
  private $ids;
  private $phids;
  private $parentPHIDs;
  private $memberPHIDs;
  private $isArchived;
  private $names;
  private $nameContains;
  private $namePrefix;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withParentPHIDs(array $phids) {
    $this->parentPHIDs = $phids;
    return $this;
  }

  public function withMemberPHIDs(array $phids) {
    $this->memberPHIDs = $phids;
    return $this;
  }

  public function withIsArchived($archived) {
    $this->isArchived = $archived;
    return $this;
  }

  public function withNames(array $names) {
    $this->names = $names;
    return $this;
  }

  public function withNameContains($name) {
    $this->nameContains = $name;
    return $this;
  }

  public function withNamePrefix($name) {
    $this->namePrefix = $name;
    return $this;
  }

  public function newResultObject() {
    return new TeamOrganizationTeam();
  }

  public function getQueryApplicationClass() {
    return TeamOrganizationApplication::class;
  }

  protected function getPrimaryTableAlias() {
    return 'team';
  }

  protected function buildWhereClauseParts(AphrontDatabaseConnection $conn) {
    $where = parent::buildWhereClauseParts($conn);
    if ($this->ids !== null) {
      $where[] = qsprintf($conn, 'team.id IN (%Ld)', $this->ids);
    }
    if ($this->phids !== null) {
      $where[] = qsprintf($conn, 'team.phid IN (%Ls)', $this->phids);
    }
    if ($this->parentPHIDs !== null) {
      $parents = $this->parentPHIDs;
      $null_parent = in_array(null, $parents, true);
      $parents = array_values(array_filter($parents, function($phid) {
        return $phid !== null && $phid !== '';
      }));
      $parts = array();
      if ($parents) {
        $parts[] = qsprintf($conn, 'team.parentPHID IN (%Ls)', $parents);
      }
      if ($null_parent) {
        $parts[] = qsprintf($conn, 'team.parentPHID IS NULL');
      }
      $where[] = $parts ? qsprintf($conn, '(%LO)', $parts) :
        qsprintf($conn, '1 = 0');
    }
    if ($this->memberPHIDs !== null) {
      $where[] = qsprintf(
        $conn,
        'EXISTS (SELECT 1 FROM %T m WHERE m.teamPHID = team.phid '.
        'AND m.userPHID IN (%Ls))',
        id(new TeamOrganizationMember())->getTableName(),
        $this->memberPHIDs);
    }
    if ($this->isArchived !== null) {
      $where[] = qsprintf($conn, 'team.isArchived = %d', (int)$this->isArchived);
    }
    if ($this->names !== null) {
      $where[] = qsprintf($conn, 'team.name IN (%Ls)', $this->names);
    }
    if ($this->nameContains !== null && strlen($this->nameContains)) {
      $where[] = qsprintf($conn, 'team.name LIKE %~', $this->nameContains);
    }
    if ($this->namePrefix !== null && strlen($this->namePrefix)) {
      $where[] = qsprintf($conn, 'team.name LIKE %>', $this->namePrefix);
    }
    return $where;
  }

  public function getOrderableColumns() {
    return array(
      'name' => array('table' => 'team', 'column' => 'name', 'type' => 'string'),
    ) + parent::getOrderableColumns();
  }

  protected function newPagingMapFromPartialObject($object) {
    return array('id' => (int)$object->getID(), 'name' => $object->getName());
  }
}

final class TeamOrganizationService extends Phobject {
  const MAX_DEPTH = 64;
  const MAX_MEMBERS = 10000;

  /** Never trust administrator flags on a caller's cached user object. */
  public static function canManage(PhabricatorUser $viewer) {
    $user = self::loadCurrentUser($viewer);
    return self::isUsablePerson($user) && $user->getIsAdmin();
  }

  public static function isActiveUser(PhabricatorUser $viewer) {
    return (bool)self::isUsablePerson(self::loadCurrentUser($viewer));
  }

  public static function canViewMembers(
    PhabricatorUser $viewer,
    TeamOrganizationTeam $team) {
    $user = self::loadCurrentUser($viewer);
    $team = self::loadCurrentTeam($team);
    if (!$team || !self::isUsablePerson($user)) {
      return false;
    }
    return PhabricatorPolicyFilter::hasCapability(
      $user, $team, PhabricatorPolicyCapability::CAN_VIEW) &&
      PhabricatorPolicyFilter::hasCapability(
        $user, $team, TeamOrganizationTeam::CAP_MEMBERS);
  }

  public static function saveTeam(
    PhabricatorUser $actor,
    ?TeamOrganizationTeam $team,
    array $input) {
    return self::withWriteLock(function() use ($actor, $team, $input) {
      $actor = self::requireManager($actor);
      if ($team !== null) {
        $team = self::requireCurrentTeam($team);
        self::requireVersion($team, idx($input, 'expectedVersion'));
        $old = self::getTeamSnapshot($team);
      } else {
        $team = id(new TeamOrganizationTeam())
          ->setCreatorPHID($actor->getPHID());
        $old = null;
      }

      $name = idx($input, 'name', $team->getName());
      if (!is_string($name) || !phutil_is_utf8($name)) {
        throw new InvalidArgumentException(pht('请输入有效的团队名称。'));
      }
      $name = trim($name);
      if (!strlen($name) || phutil_utf8_strlen($name) > 200) {
        throw new InvalidArgumentException(pht('团队名称须为 1 至 200 个字符。'));
      }
      $description = idx($input, 'description', $team->getDescription());
      if (!is_string($description) || !phutil_is_utf8($description) ||
          phutil_utf8_strlen($description) > 20000) {
        throw new InvalidArgumentException(pht('团队说明最多为 20000 个字符。'));
      }
      $parent = idx($input, 'parentPHID', $team->getParentPHID());
      if ($parent === '') {
        $parent = null;
      }
      self::validateParent($team, $parent);
      $archived = idx($input, 'isArchived', $team->getIsArchived());
      if (!in_array($archived, array(0, 1, false, true, '0', '1'), true)) {
        throw new InvalidArgumentException(pht('无效的团队归档状态。'));
      }
      $view = idx($input, 'viewPolicy', $team->getViewPolicy());
      $members = idx($input, 'membersPolicy', $team->getMembersPolicy());
      self::validatePolicy($actor, $team, $view);
      self::validatePolicy($actor, $team, $members);

      $team->setName($name)
        ->setDescription($description)
        ->setParentPHID($parent)
        ->setViewPolicy($view)
        ->setMembersPolicy($members)
        ->setIsArchived((int)(bool)$archived);
      $new = self::getTeamSnapshot($team);
      if ($new === $old) {
        return $team;
      }
      if ($old !== null) {
        $team->setVersion($team->getVersion() + 1);
      }
      $team->save();
      $action = 'create';
      if ($old !== null) {
        $action = $old['isArchived'] !== $new['isArchived'] ?
          ($new['isArchived'] ? 'archive' : 'restore') : 'update';
      }
      self::writeAudit($actor, $team, $action, $old, $new);
      return $team;
    });
  }

  public static function setMembers(
    PhabricatorUser $actor,
    TeamOrganizationTeam $team,
    array $user_phids,
    $expected_version) {
    return self::withWriteLock(function() use (
      $actor, $team, $user_phids, $expected_version) {
      $actor = self::requireManager($actor);
      $team = self::requireCurrentTeam($team);
      self::requireVersion($team, $expected_version);
      $user_phids = self::normalizePHIDs($user_phids, 'USER');
      self::validateUsers($user_phids);
      self::replaceMembers($actor, $team, $user_phids);
      return $team;
    });
  }

  /** UI callers pass the full original team set to prevent stale form writes. */
  public static function setUserTeams(
    PhabricatorUser $actor,
    PhabricatorUser $target,
    array $team_phids,
    ?array $expected_team_phids = null) {
    return self::withWriteLock(function() use (
      $actor, $target, $team_phids, $expected_team_phids) {
      $actor = self::requireManager($actor);
      $target = self::loadCurrentUser($target);
      if (!$target || $target->getIsSystemAgent() || $target->getIsMailingList()) {
        throw new InvalidArgumentException(pht('请选择有效的用户账户。'));
      }
      $team_phids = self::normalizePHIDs($team_phids, TeamOrganizationTeam::PHID_TYPE);
      $old_phids = self::loadDirectTeamPHIDs($target->getPHID());
      if ($expected_team_phids !== null) {
        $expected = self::normalizePHIDs(
          $expected_team_phids, TeamOrganizationTeam::PHID_TYPE);
        if ($expected !== $old_phids) {
          throw new InvalidArgumentException(pht('用户的团队归属已变更，请刷新后重试。'));
        }
      }
      $all_phids = array_values(array_unique(array_merge($old_phids, $team_phids)));
      $teams = self::loadTeamsByPHIDs($all_phids);
      if (count($teams) !== count($all_phids)) {
        throw new InvalidArgumentException(pht('一个或多个团队已不存在，请刷新后重试。'));
      }
      $added = array_diff($team_phids, $old_phids);
      foreach ($added as $phid) {
        if ($teams[$phid]->getIsArchived()) {
          throw new InvalidArgumentException(pht('不能将用户加入已归档的团队。'));
        }
      }
      foreach ($teams as $phid => $team) {
        $was_member = in_array($phid, $old_phids, true);
        $is_member = in_array($phid, $team_phids, true);
        if ($was_member === $is_member) {
          continue;
        }
        $members = self::getMemberPHIDs($team);
        if ($is_member) {
          $members[] = $target->getPHID();
        } else {
          $members = array_values(array_diff($members, array($target->getPHID())));
        }
        self::replaceMembers($actor, $team, $members);
      }
      return array_select_keys($teams, $team_phids);
    });
  }

  /** Internal raw relation access. Callers must separately authorize display. */
  public static function getMemberPHIDs(TeamOrganizationTeam $team) {
    if (!$team->getPHID()) {
      return array();
    }
    $dao = new TeamOrganizationMember();
    $rows = queryfx_all(
      $dao->establishConnection('w'),
      'SELECT userPHID FROM %T WHERE teamPHID = %s ORDER BY userPHID',
      $dao->getTableName(),
      $team->getPHID());
    return ipull($rows, 'userPHID');
  }

  public static function getUserTeams(
    PhabricatorUser $viewer,
    PhabricatorUser $target) {
    $viewer = self::loadCurrentUser($viewer);
    $target = self::loadCurrentUser($target);
    if (!self::isUsablePerson($viewer) || !$target) {
      return array();
    }
    $capabilities = array(PhabricatorPolicyCapability::CAN_VIEW);
    if ($viewer->getPHID() !== $target->getPHID() && !$viewer->getIsAdmin()) {
      $capabilities[] = TeamOrganizationTeam::CAP_MEMBERS;
    }
    $teams = id(new TeamOrganizationTeamQuery())
      ->setViewer($viewer)
      ->requireCapabilities($capabilities)
      ->withMemberPHIDs(array($target->getPHID()))
      ->execute();
    return mpull($teams, null, 'getPHID');
  }

  public static function loadAudit(
    PhabricatorUser $viewer,
    TeamOrganizationTeam $team,
    $limit = 100,
    $before_id = null) {
    self::requireManager($viewer);
    $team = self::requireCurrentTeam($team);
    $limit = max(1, min(500, (int)$limit));
    $dao = new TeamOrganizationAudit();
    $conn = $dao->establishConnection('w');
    $before = $before_id === null ? qsprintf($conn, '') :
      qsprintf($conn, 'AND id < %d', $before_id);
    $rows = queryfx_all(
      $conn,
      'SELECT * FROM %T WHERE teamPHID = %s %Q ORDER BY id DESC LIMIT %d',
      $dao->getTableName(), $team->getPHID(), $before, $limit);
    return $dao->loadAllFromArray($rows);
  }

  /**
   * Return a PHID => true set, read freshly from the primary database.
   * Descendant rules match ancestors of the viewer's active direct teams.
   * An archived ancestor grants nothing itself but does not archive children.
   */
  public static function getMembershipScope(
    PhabricatorUser $viewer,
    $include_descendants = false) {
    $viewer = self::loadCurrentUser($viewer);
    if (!self::isUsablePerson($viewer)) {
      return array();
    }
    $teams = self::loadTeamsByPHIDs(self::loadDirectTeamPHIDs($viewer->getPHID()));
    $scope = array();
    $parents = array();
    foreach ($teams as $phid => $team) {
      if ($team->getIsArchived()) {
        continue;
      }
      $scope[$phid] = true;
      if ($include_descendants && $team->getParentPHID()) {
        $parents[] = $team->getParentPHID();
      }
    }
    $visited = array();
    for ($depth = 0; $parents; $depth++) {
      if ($depth >= self::MAX_DEPTH) {
        throw new Exception(pht('团队层级超出安全上限。'));
      }
      $parents = array_values(array_diff(array_unique($parents), array_keys($visited)));
      if (!$parents) {
        break;
      }
      $ancestors = self::loadTeamsByPHIDs($parents);
      $parents = array();
      foreach ($ancestors as $phid => $team) {
        $visited[$phid] = true;
        if (!$team->getIsArchived()) {
          $scope[$phid] = true;
        }
        if ($team->getParentPHID()) {
          $parents[] = $team->getParentPHID();
        }
      }
    }
    return $scope;
  }

  private static function withWriteLock($callback) {
    $dao = new TeamOrganizationLock();
    $conn = $dao->establishConnection('w');
    $dao->openTransaction();
    try {
      $row = queryfx_one(
        $conn, 'SELECT id FROM %T WHERE id = 1 FOR UPDATE', $dao->getTableName());
      if (!$row) {
        throw new Exception(pht('团队数据库尚未初始化。'));
      }
      $result = $callback();
      $dao->saveTransaction();
      return $result;
    } catch (Throwable $ex) {
      $dao->killTransaction();
      throw $ex;
    }
  }

  private static function loadCurrentUser(PhabricatorUser $user) {
    if (!$user->getPHID()) {
      return null;
    }
    $dao = new PhabricatorUser();
    $row = queryfx_one(
      $dao->establishConnection('w'),
      'SELECT * FROM %T WHERE phid = %s',
      $dao->getTableName(), $user->getPHID());
    return $row ? $dao->loadFromArray($row) : null;
  }

  private static function isUsablePerson($user) {
    return $user && $user->isUserActivated() &&
      !$user->getIsSystemAgent() && !$user->getIsMailingList();
  }

  private static function requireManager(PhabricatorUser $actor) {
    $actor = self::loadCurrentUser($actor);
    if (!self::isUsablePerson($actor) || !$actor->getIsAdmin()) {
      throw id(new PhabricatorPolicyException(pht('仅有效管理员可以管理团队。')))
        ->setTitle(pht('无管理权限'))
        ->setCapability(PhabricatorPolicyCapability::CAN_EDIT)
        ->setRejection(pht('仅有效管理员可以管理团队。'));
    }
    return $actor;
  }

  private static function loadTeamsByPHIDs(array $phids) {
    if (!$phids) {
      return array();
    }
    $dao = new TeamOrganizationTeam();
    $rows = queryfx_all(
      $dao->establishConnection('w'),
      'SELECT * FROM %T WHERE phid IN (%Ls)', $dao->getTableName(), $phids);
    return mpull($dao->loadAllFromArray($rows), null, 'getPHID');
  }

  private static function loadCurrentTeam(TeamOrganizationTeam $team) {
    $teams = self::loadTeamsByPHIDs(array($team->getPHID()));
    $fresh = idx($teams, $team->getPHID());
    if (!$fresh || (int)$fresh->getID() !== (int)$team->getID()) {
      return null;
    }
    return $fresh;
  }

  private static function requireCurrentTeam(TeamOrganizationTeam $team) {
    $team = self::loadCurrentTeam($team);
    if (!$team) {
      throw new InvalidArgumentException(pht('团队不存在，请刷新后重试。'));
    }
    return $team;
  }

  private static function loadDirectTeamPHIDs($user_phid) {
    $dao = new TeamOrganizationMember();
    $rows = queryfx_all(
      $dao->establishConnection('w'),
      'SELECT teamPHID FROM %T WHERE userPHID = %s ORDER BY teamPHID',
      $dao->getTableName(), $user_phid);
    return ipull($rows, 'teamPHID');
  }

  private static function requireVersion(TeamOrganizationTeam $team, $version) {
    if (!is_int($version) && !(is_string($version) && ctype_digit($version))) {
      throw new InvalidArgumentException(pht('缺少团队版本，请刷新后重试。'));
    }
    if ((string)$version !== (string)$team->getVersion()) {
      throw new InvalidArgumentException(pht('团队已被其他操作更新，请刷新后重试。'));
    }
  }

  private static function normalizePHIDs(array $phids, $type) {
    if (count($phids) > self::MAX_MEMBERS) {
      throw new InvalidArgumentException(pht('一次最多可以选择 %s 项。', self::MAX_MEMBERS));
    }
    foreach ($phids as $phid) {
      if (!is_string($phid) || !preg_match('/^PHID-'.$type.'-[a-z0-9]{20}$/', $phid)) {
        throw new InvalidArgumentException(pht('包含无效的对象标识。'));
      }
    }
    $phids = array_values(array_unique($phids));
    sort($phids, SORT_STRING);
    return $phids;
  }

  private static function validateUsers(array $phids) {
    if (!$phids) {
      return;
    }
    $dao = new PhabricatorUser();
    $rows = queryfx_all(
      $dao->establishConnection('w'),
      'SELECT phid FROM %T WHERE phid IN (%Ls) AND isSystemAgent = 0 AND isMailingList = 0',
      $dao->getTableName(), $phids);
    if (count($rows) !== count($phids)) {
      throw new InvalidArgumentException(pht('成员中包含不存在或不适用的用户账户。'));
    }
  }

  private static function validateParent(TeamOrganizationTeam $team, $parent) {
    if ($parent !== null) {
      self::normalizePHIDs(array($parent), TeamOrganizationTeam::PHID_TYPE);
    }
    $visited = array();
    for ($parent_depth = 0; $parent; $parent_depth++) {
      if ($parent === $team->getPHID() || isset($visited[$parent])) {
        throw new InvalidArgumentException(pht('团队不能成为自身或其下级团队的子团队。'));
      }
      if ($parent_depth >= self::MAX_DEPTH - 1) {
        throw new InvalidArgumentException(pht('团队层级最多为 %s 级。', self::MAX_DEPTH));
      }
      $visited[$parent] = true;
      $parents = self::loadTeamsByPHIDs(array($parent));
      $object = idx($parents, $parent);
      if (!$object) {
        throw new InvalidArgumentException(pht('上级团队不存在。'));
      }
      $parent = $object->getParentPHID();
    }

    // Moving a whole subtree must not make its deepest member exceed the
    // same limit enforced by authorization traversal.
    $descendants = $team->getPHID() ? array($team->getPHID()) : array();
    $seen = array();
    $height = 1;
    $dao = new TeamOrganizationTeam();
    $conn = $dao->establishConnection('w');
    while ($descendants) {
      foreach ($descendants as $phid) {
        if (isset($seen[$phid])) {
          throw new InvalidArgumentException(pht('团队层级包含循环。'));
        }
        $seen[$phid] = true;
      }
      $rows = queryfx_all(
        $conn, 'SELECT phid FROM %T WHERE parentPHID IN (%Ls)',
        $dao->getTableName(), $descendants);
      $descendants = ipull($rows, 'phid');
      if ($descendants) {
        $height++;
      }
      if ($height + $parent_depth > self::MAX_DEPTH) {
        throw new InvalidArgumentException(pht('移动后团队层级将超过 %s 级。', self::MAX_DEPTH));
      }
    }
  }

  private static function validatePolicy(
    PhabricatorUser $actor,
    TeamOrganizationTeam $team,
    $policy) {
    if (!is_string($policy) || !strlen($policy) || strlen($policy) > 64) {
      throw new InvalidArgumentException(pht('请选择有效的团队权限。'));
    }
    if (PhabricatorPolicyQuery::isGlobalPolicy($policy)) {
      return;
    }
    $objects = PhabricatorPolicyQuery::getObjectPolicies($team);
    if (isset($objects[$policy])) {
      return;
    }
    $types = array('USER', 'PROJ', 'PLCY', 'NPLC');
    if (!in_array(phid_get_type($policy), $types, true)) {
      throw new InvalidArgumentException(pht('无效的团队权限类型。'));
    }
    if (in_array(phid_get_type($policy), array('USER', 'PROJ'), true)) {
      $object = id(new PhabricatorObjectQuery())
        ->setViewer($actor)->withPHIDs(array($policy))->executeOne();
      if (!$object) {
        throw new InvalidArgumentException(pht('权限引用的对象不存在或不可见。'));
      }
    }
    $policies = id(new PhabricatorPolicyQuery())
      ->setViewer($actor)->withPHIDs(array($policy))->execute();
    if (!isset($policies[$policy])) {
      throw new InvalidArgumentException(pht('权限策略不存在。'));
    }
    foreach ($policies[$policy]->getCustomRuleClasses() as $class) {
      $rule = newv($class, array());
      if (!$rule->canApplyToObject($team)) {
        throw new InvalidArgumentException(pht('权限策略包含不适用于团队的规则。'));
      }
    }
  }

  private static function replaceMembers(
    PhabricatorUser $actor,
    TeamOrganizationTeam $team,
    array $members) {
    $members = self::normalizePHIDs($members, 'USER');
    $old = self::getMemberPHIDs($team);
    if ($old === $members) {
      return;
    }
    $add = array_values(array_diff($members, $old));
    $remove = array_values(array_diff($old, $members));
    if ($team->getIsArchived() && $add) {
      throw new InvalidArgumentException(pht('已归档团队只能移除成员，恢复后才可新增。'));
    }
    $dao = new TeamOrganizationMember();
    $conn = $dao->establishConnection('w');
    if ($remove) {
      queryfx(
        $conn, 'DELETE FROM %T WHERE teamPHID = %s AND userPHID IN (%Ls)',
        $dao->getTableName(), $team->getPHID(), $remove);
    }
    foreach ($add as $phid) {
      id(new TeamOrganizationMember())
        ->setTeamPHID($team->getPHID())->setUserPHID($phid)->save();
    }
    $team->setVersion($team->getVersion() + 1)->save();
    self::writeAudit($actor, $team, 'members', $old, $members);
  }

  private static function getTeamSnapshot(TeamOrganizationTeam $team) {
    return array(
      'name' => $team->getName(),
      'description' => $team->getDescription(),
      'parentPHID' => $team->getParentPHID(),
      'viewPolicy' => $team->getViewPolicy(),
      'membersPolicy' => $team->getMembersPolicy(),
      'isArchived' => (bool)$team->getIsArchived(),
    );
  }

  private static function writeAudit(
    PhabricatorUser $actor,
    TeamOrganizationTeam $team,
    $action,
    $old,
    $new) {
    id(new TeamOrganizationAudit())
      ->setTeamPHID($team->getPHID())
      ->setActorPHID($actor->getPHID())
      ->setAction($action)
      ->setOldValue($old)
      ->setNewValue($new)
      ->save();
  }
}

final class TeamOrganizationSQLPatchList extends PhabricatorSQLPatchList {
  public function getNamespace() {
    return 'teamorganization';
  }

  public function getPatches() {
    return array(
      '20261027.organization.sql' => array(
        'type' => 'sql',
        'name' => dirname(phutil_get_library_root('phorge')).
          '/resources/sql/team-organization/20261027.organization.sql',
        'after' => array('phabricator:db.user'),
      ),
    );
  }
}
