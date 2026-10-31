<?php

/** One-time member import; never copies policies or project roles. */
final class TeamProjectMemberImport extends Phobject {
  const MAX_MEMBERS = 500;

  public static function currentViewer(PhabricatorUser $viewer) {
    $user = $viewer->getPHID()
      ? id(new PhabricatorUser())->loadOneWhere('phid = %s', $viewer->getPHID())
      : null;
    if (!$user || !$user->isUserActivated() || $user->getIsSystemAgent() || $user->getIsMailingList()) {
      throw new InvalidArgumentException(pht('只有有效的登录用户可以导入成员。'));
    }
    return $user;
  }

  public static function resolve(PhabricatorUser $viewer, array $tokens) {
    $viewer = self::currentViewer($viewer);
    if (count($tokens) > self::MAX_MEMBERS) {
      throw new InvalidArgumentException(pht('一次最多选择或导入 %s 名成员。', self::MAX_MEMBERS));
    }
    $names = array();
    $sources = array();
    foreach ($tokens as $token) {
      if (!is_string($token) || strlen($token) > 256) {
        throw new InvalidArgumentException(pht('成员选择中包含无效的对象。'));
      }
      $token = trim($token);
      if ($token === '') { continue; }
      if (preg_match('/\A(teamMembers|members)\((PHID-(?:ORGT|PROJ)-[a-z0-9]{20})\)\z/', $token, $matches)) {
        $expected = $matches[1] === 'teamMembers' ? 'ORGT' : 'PROJ';
        if (phid_get_type($matches[2]) !== $expected) {
          throw new InvalidArgumentException(pht('成员来源类型无效，请重新选择。'));
        }
        $sources[$token] = array($matches[1], $matches[2]);
      } else if (strpos($token, '(') !== false || strpos($token, ')') !== false ||
          (strpos($token, 'PHID-') === 0 && phid_get_type($token) !== 'USER')) {
        throw new InvalidArgumentException(pht('请选择人员、团队本级成员或项目本级成员。'));
      } else {
        $names[] = ltrim($token, '@');
      }
    }

    // Resolve every source before reading any roster. A source which became
    // hidden or archived rejects the whole operation instead of partial import.
    $objects = array();
    foreach ($sources as $key => $source) {
      $objects[$key] = $source[0] === 'teamMembers'
        ? self::loadTeam($viewer, $source[1])
        : self::loadProject($viewer, $source[1]);
    }
    $result = array();
    $user_phids = id(new PhabricatorUserPHIDResolver())->setViewer($viewer)->resolvePHIDs($names);
    self::appendActiveUsers($viewer, $user_phids, $result);
    foreach ($sources as $key => $source) {
      $object = $objects[$key];
      $phids = $source[0] === 'teamMembers'
        ? TeamOrganizationService::getMemberPHIDs($object)
        : $object->getDirectMemberPHIDs();
      self::appendActiveUsers($viewer, $phids, $result);
    }
    $result = array_keys($result);
    sort($result, SORT_STRING);
    return $result;
  }

  public static function loadTeam(PhabricatorUser $viewer, $phid) {
    try {
      $team = id(new TeamOrganizationTeamQuery())->setViewer($viewer)
        ->withPHIDs(array($phid))->withIsArchived(false)
        ->requireCapabilities(array(PhabricatorPolicyCapability::CAN_VIEW,
          TeamOrganizationTeam::CAP_MEMBERS))->executeOne();
    } catch (PhabricatorPolicyException $ex) { $team = null; }
    if (!$team || !TeamOrganizationService::canViewMembers($viewer, $team)) {
      throw new InvalidArgumentException(pht('团队来源不可用、已归档或其成员名单不可读，请重新选择。'));
    }
    return $team;
  }

  public static function loadProject(PhabricatorUser $viewer, $phid) {
    try {
      $project = id(new PhabricatorProjectQuery())->setViewer($viewer)
        ->withPHIDs(array($phid))->withIsMilestone(false)
        ->withStatuses(array(PhabricatorProjectStatus::STATUS_ACTIVE))->executeOne();
    } catch (PhabricatorPolicyException $ex) { $project = null; }
    if (!$project) {
      throw new InvalidArgumentException(pht('项目来源不可用、已归档或您无权查看；里程碑不能作为导入来源。'));
    }
    return $project;
  }

  private static function appendActiveUsers($viewer, array $phids, array &$result) {
    $phids = array_values(array_unique(array_filter($phids, function ($phid) {
      return is_string($phid) && preg_match('/\APHID-USER-[a-z0-9]{20}\z/', $phid);
    })));
    foreach (array_chunk($phids, 200) as $chunk) {
      $users = id(new PhabricatorPeopleQuery())->setViewer($viewer)->withPHIDs($chunk)
        ->withIsDisabled(false)->withIsApproved(true)->withIsSystemAgent(false)->setLimit(null)->execute();
      foreach ($users as $user) {
        if (!$user->isUserActivated() || $user->getIsMailingList()) { continue; }
        $result[$user->getPHID()] = true;
        if (count($result) > self::MAX_MEMBERS) {
          throw new InvalidArgumentException(pht('一次最多导入 %s 名有效成员，请缩小选择范围。', self::MAX_MEMBERS));
        }
      }
    }
  }
}

final class TeamProjectMemberImportDatasource extends PhabricatorTypeaheadCompositeDatasource {
  public function getBrowseTitle() { return pht('选择人员、团队或项目成员'); }
  public function getPlaceholderText() { return pht('搜索人员、团队或项目名称…'); }
  public function getComponentDatasources() {
    return array(new PhabricatorPeopleDatasource(),
      new TeamProjectMemberTeamDatasource(), new TeamProjectMemberProjectDatasource());
  }
  public function evaluateTokens(array $tokens) {
    return TeamProjectMemberImport::resolve($this->getViewer(), $tokens);
  }
}

abstract class TeamProjectMemberSourceDatasource extends PhabricatorTypeaheadDatasource {
  abstract protected function getFunctionName();
  abstract protected function getKindName();
  abstract protected function newSourceQuery();
  abstract protected function loadSource($viewer, $phid);

  public function getBrowseTitle() { return pht('选择%s本级成员', $this->getKindName()); }
  public function getPlaceholderText() { return pht('搜索%s名称…', $this->getKindName()); }
  public function getDatasourceFunctions() {
    return array($this->getFunctionName() => array('name' => pht('%s本级成员', $this->getKindName()),
      'arguments' => pht('来源'), 'summary' => pht('一次性导入来源本级的当前有效成员。'),
      'description' => pht('保存时导入本级成员，不包含下级，不同步后续变化，不复制编辑或管理权限。')));
  }
  protected function isFunctionWithLoginRequired($function) { return true; }
  public function loadResults() {
    $viewer = TeamProjectMemberImport::currentViewer($this->getViewer());
    $query = $this->newSourceQuery();
    $text = trim((string)$this->getRawQuery());
    if ($query instanceof TeamOrganizationTeamQuery) {
      if (strlen($text)) { $query->withNameContains($text); }
    } else if ($this->getPhase() === self::PHASE_PREFIX) {
      if (strlen($text)) { $query->withNamePrefixes(array(phutil_utf8_strtolower($text))); }
    } else if (strlen($text)) {
      $query->withNameTokens(self::tokenizeString($text));
    }
    $objects = $query->setViewer($viewer)->setOffset($this->getOffset())
      ->setLimit($this->getLimit())->execute();
    $results = array();
    foreach ($objects as $object) {
      $results[] = id(new PhabricatorTypeaheadResult())->setName($object->getName())
        ->setDisplayName(pht('%s本级成员：%s', $this->getKindName(), $object->getName()))
        ->setDisplayType(pht('一次性成员导入'))->setIcon('fa-users')
        ->setPHID($this->getFunctionName().'('.$object->getPHID().')')
        ->setURI($object->getURI())->setTokenType(PhabricatorTypeaheadTokenView::TYPE_FUNCTION)
        ->setPriorityType('member-import');
    }
    return $results;
  }
  protected function evaluateFunction($function, array $argv_list) {
    $tokens = array();
    foreach ($argv_list as $argv) {
      if (count($argv) !== 1) { throw new InvalidArgumentException(pht('成员来源无效。')); }
      $tokens[] = $function.'('.head($argv).')';
    }
    return TeamProjectMemberImport::resolve($this->getViewer(), $tokens);
  }
  public function renderFunctionTokens($function, array $argv_list) {
    $viewer = TeamProjectMemberImport::currentViewer($this->getViewer());
    $tokens = array();
    foreach ($argv_list as $key => $argv) {
      $phid = head($argv);
      $token = id(new PhabricatorTypeaheadTokenView())
        ->setKey($function.'('.$phid.')')->setIcon('fa-users');
      try {
        if (count($argv) !== 1) { throw new InvalidArgumentException(pht('成员来源无效。')); }
        $object = $this->loadSource($viewer, $phid);
        $token->setTokenType(PhabricatorTypeaheadTokenView::TYPE_FUNCTION)
          ->setValue(pht('%s本级成员：%s', $this->getKindName(), $object->getName()));
      } catch (InvalidArgumentException $ex) {
        $token->setTokenType(PhabricatorTypeaheadTokenView::TYPE_INVALID)
          ->setValue(pht('不可用的成员来源'));
      }
      $tokens[$key] = $token;
    }
    return $tokens;
  }
}

final class TeamProjectMemberTeamDatasource extends TeamProjectMemberSourceDatasource {
  protected function getFunctionName() { return 'teamMembers'; }
  protected function getKindName() { return pht('团队'); }
  public function getDatasourceApplicationClass() { return TeamOrganizationApplication::class; }
  protected function newSourceQuery() {
    return id(new TeamOrganizationTeamQuery())->withIsArchived(false)
      ->requireCapabilities(array(PhabricatorPolicyCapability::CAN_VIEW, TeamOrganizationTeam::CAP_MEMBERS));
  }
  protected function loadSource($viewer, $phid) { return TeamProjectMemberImport::loadTeam($viewer, $phid); }
}

final class TeamProjectMemberProjectDatasource extends TeamProjectMemberSourceDatasource {
  protected function getFunctionName() { return 'members'; }
  protected function getKindName() { return pht('项目'); }
  public function getDatasourceApplicationClass() { return PhabricatorProjectApplication::class; }
  protected function newSourceQuery() {
    return id(new PhabricatorProjectQuery())->withIsMilestone(false)
      ->withStatuses(array(PhabricatorProjectStatus::STATUS_ACTIVE));
  }
  protected function loadSource($viewer, $phid) { return TeamProjectMemberImport::loadProject($viewer, $phid); }
}

final class TeamProjectMembersHTTPParameterType extends AphrontListHTTPParameterType {
  protected function getParameterValue(AphrontRequest $request, $key) {
    $values = $this->getValueWithType(new AphrontStringListHTTPParameterType(), $request, $key);
    try { return TeamProjectMemberImport::resolve($this->getViewer(), $values); }
    catch (InvalidArgumentException $ex) {
      throw new AphrontMalformedRequestException(pht('无法导入初始成员'), $ex->getMessage(), true);
    }
  }
  protected function getParameterTypeName() { return 'list<project-member-source>'; }
  protected function getParameterFormatDescriptions() { return array(pht('人员、团队本级成员或项目本级成员。')); }
  protected function getParameterExamples() { return array('members[]=PHID-USER-1111', 'members[]=members(PHID-PROJ-1111)'); }
}

final class TeamProjectMembersEditField extends PhabricatorTokenizerEditField {
  protected function newDatasource() { return new TeamProjectMemberImportDatasource(); }
  protected function newHTTPParameterType() { return new TeamProjectMembersHTTPParameterType(); }
  protected function newConduitParameterType() { return new ConduitUserListParameterType(); }
}
