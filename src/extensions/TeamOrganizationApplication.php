<?php

/** Independent organization teams; no project hierarchy or membership reuse. */
final class TeamOrganizationApplication extends PhabricatorApplication {
  public function getName() { return '团队架构'; }
  public function getShortDescription() { return '组织团队与成员归属'; }
  public function getBaseURI() { return '/organization/'; }
  public function getIcon() { return 'fa-sitemap'; }
  public function getApplicationGroup() { return self::GROUP_UTILITIES; }
  public function canUninstall() { return false; }
  public function isPinnedByDefault(PhabricatorUser $viewer) { return true; }
  public function getQuicksandURIPatternBlacklist() {
    // This form has a small same-origin enhancement script. Load the full page
    // instead of inserting an inert script tag through Quicksand innerHTML.
    return array('/organization/project/[1-9]\\d*/permissions/');
  }
  public function getRoutes() {
    return array('/organization/' => array(
      '' => 'TeamOrganizationListController',
      'view/(?P<id>[1-9]\d*)/' => 'TeamOrganizationViewController',
      'edit/(?:(?P<id>[1-9]\d*)/)?' => 'TeamOrganizationEditController',
      'members/(?P<id>[1-9]\d*)/' => 'TeamOrganizationMembersController',
      'user/(?P<phid>PHID-USER-[a-z0-9]+)/' => 'TeamOrganizationUserController',
      'project/(?P<id>[1-9]\\d*)/permissions/' => 'TeamProjectAccessController',
      'resources/project-access\\.js' => 'TeamProjectAccessScriptController',
    ));
  }
}

abstract class TeamOrganizationController extends PhabricatorController {
  public function willSendResponse(AphrontResponse $response) {
    $response = parent::willSendResponse($response);
    $response->addHeader('Cache-Control', 'private, no-store, max-age=0');
    return $response;
  }

  public function willBeginExecution() {
    $response = parent::willBeginExecution();
    if ($response !== null) { return $response; }
    $request = $this->getRequest();
    if ($request->isHTTPPost()) {
      try { $request->validateCSRF(); }
      catch (AphrontMalformedRequestException $ex) { return new Aphront400Response(); }
    } else if (!$request->isHTTPGet()) {
      return id(new AphrontPlainTextResponse())->setHTTPResponseCode(405)
        ->setContent('Method not allowed.');
    }
  }

  protected function canManage() {
    return TeamOrganizationService::canManage($this->getViewer());
  }

  protected function loadTeam($id) {
    return head(id(new TeamOrganizationTeamQuery())->setViewer($this->getViewer())
      ->withIDs(array($id))->execute());
  }

  public static function teamURI($team) {
    return '/organization/view/'.$team->getID().'/';
  }

  protected function button($label, $uri, $icon = null) {
    return id(new PHUIButtonView())->setTag('a')->setText($label)
      ->setHref($uri)->setIcon($icon);
  }

  protected function actions(array $actions) {
    return phutil_tag('div', array('class' => 'team-organization-actions',
      'style' => 'display:flex;flex-wrap:wrap;gap:8px;padding:12px 0;'), $actions);
  }

  protected function notice($text) {
    return id(new PHUIInfoView())->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
      ->appendChild($text);
  }

  protected function box($title, $content, array $errors = array()) {
    return id(new PHUIObjectBoxView())->setHeaderText($title)
      ->setFormErrors($errors)->appendChild($content);
  }

  protected function visibleParent($team) {
    $parent = $team->getParentPHID();
    if (!$parent) { return null; }
    return head(id(new TeamOrganizationTeamQuery())->setViewer($this->getViewer())
      ->withPHIDs(array($parent))->execute());
  }

  protected function page($title, array $content, $team = null) {
    $crumbs = $this->buildApplicationCrumbs()->setBorder(true);
    $back = $this->button('返回团队列表', '/organization/', 'fa-arrow-left');
    if ($team) {
      $parent = $this->visibleParent($team);
      if ($parent) {
        $back = $this->button('返回上级团队', self::teamURI($parent), 'fa-arrow-left');
      }
      $ancestors = array();
      $seen = array($team->getPHID() => true);
      while ($parent && !isset($seen[$parent->getPHID()])) {
        $seen[$parent->getPHID()] = true;
        $ancestors[] = $parent;
        $parent = $this->visibleParent($parent);
      }
      foreach (array_reverse($ancestors) as $ancestor) {
        $crumbs->addTextCrumb($ancestor->getName(), self::teamURI($ancestor));
      }
      $crumbs->addTextCrumb($team->getName(), self::teamURI($team));
    } else if ($title !== '团队架构') {
      $crumbs->addTextCrumb($title);
    }
    return $this->newPage()->setTitle($title)->setCrumbs($crumbs)
      ->appendChild(id(new PHUIBoxView())->addPadding(PHUI::PADDING_LARGE)
        ->appendChild(array(
          id(new PHUIHeaderView())->setHeader($title)->setHeaderIcon('fa-sitemap'),
          $this->actions(array($back)),
          $content,
        )));
  }

  protected function requireAdmin() {
    return $this->canManage() ? null : new Aphront403Response();
  }

  protected function addPolicies(AphrontFormView $form, $team, array $values) {
    $policies = id(new PhabricatorPolicyQuery())->setViewer($this->getViewer())
      ->setObject($team)->execute();
    foreach (array('viewPolicy' => array(PhabricatorPolicyCapability::CAN_VIEW, '谁能查看团队'),
      'membersPolicy' => array(TeamOrganizationTeam::CAP_MEMBERS, '谁能查看成员名单')) as $key => $spec) {
      $form->appendControl(id(new AphrontFormPolicyControl())
        ->setName($key)->setPolicyObject($team)->setPolicies($policies)
        ->setCapability($spec[0])->setLabel($spec[1])->setValue($values[$key]));
    }
  }

  protected function teamList(array $teams) {
    $list = id(new PHUIObjectItemListView())->setUser($this->getViewer())
      ->setNoDataString('暂无可显示团队');
    foreach ($this->sortTeams($teams) as $team) {
      $item = id(new PHUIObjectItemView())->setHeader($team->getName())
        ->setHref(self::teamURI($team));
      if ($team->getIsArchived()) { $item->addAttribute('已归档'); }
      $list->addItem($item);
    }
    return $list;
  }

  protected function sortTeams(array $teams) {
    usort($teams, function($a, $b) {
      $order = strcmp(phutil_utf8_strtolower($a->getName()), phutil_utf8_strtolower($b->getName()));
      return $order ? $order : ($a->getID() - $b->getID());
    });
    return $teams;
  }
}

final class TeamOrganizationListController extends TeamOrganizationController {
  public function handleRequest(AphrontRequest $request) {
    if ($request->isHTTPPost()) { return new Aphront403Response(); }
    $query = id(new TeamOrganizationTeamQuery())->setViewer($this->getViewer());
    $archived = $request->getBool('archived');
    if (!$archived) { $query->withIsArchived(false); }
    // Load the complete visible set before constructing the tree. A hidden or
    // omitted parent must not make an independently visible child disappear.
    $teams = $query->execute();
    $search = trim($request->getStr('q'));
    $form = id(new AphrontFormView())->setUser($this->getViewer())->setMethod('GET')
      ->setAction('/organization/')
      ->appendControl(id(new AphrontFormTextControl())->setName('q')
        ->setLabel('搜索团队')->setValue($search))
      ->appendControl(id(new AphrontFormCheckboxControl())->addCheckbox(
        'archived', '1', '包含已归档团队', $archived))
      ->appendControl(id(new AphrontFormSubmitControl())->setValue('搜索'));
    $actions = array();
    if ($this->canManage()) { $actions[] = $this->button('新建团队', '/organization/edit/', 'fa-plus'); }
    if ($search !== '') {
      $needle = phutil_utf8_strtolower($search);
      $teams = array_filter($teams, function($team) use ($needle) {
        return strpos(phutil_utf8_strtolower($team->getName()), $needle) !== false;
      });
      $list = $this->teamList($teams);
    } else {
      $by_phid = mpull($teams, null, 'getPHID');
      $children = array();
      foreach ($this->sortTeams($teams) as $team) {
        $parent = $team->getParentPHID();
        if (!isset($by_phid[$parent])) { $parent = ''; }
        $children[$parent][] = $team;
      }
      $seen = array();
      $list = $this->renderTree('', $children, $seen);
      if (!$teams) { $list = $this->notice('暂无可显示团队'); }
    }
    return $this->page('团队架构', array($this->actions($actions),
      $this->notice('团队归属与项目成员分别管理。项目中的“权限管理”可增添、替换或回收指定用户及团队授权，默认合并授权范围。团队可选择仅本级或包含下级；任务权限仍须在任务自身另行设置。'),
      $this->box('查找团队', $form),
      $this->box($search !== '' ? '匹配的团队' : '团队层级', $list)));
  }

  private function renderTree($parent, array $children, array &$seen) {
    $items = array();
    foreach (idx($children, $parent, array()) as $team) {
      $phid = $team->getPHID();
      if (isset($seen[$phid])) { continue; }
      $seen[$phid] = true;
      $items[] = phutil_tag('li', array('data-team-id' => $team->getID(),
        'style' => 'padding:8px 0;'), array(
        phutil_tag('a', array('href' => self::teamURI($team)), $team->getName()),
        $team->getIsArchived() ? ' · 已归档' : null,
        $this->renderTree($phid, $children, $seen),
      ));
    }
    if (!$items) { return null; }
    return phutil_tag('ul', array('class' => 'team-organization-tree',
      'style' => 'list-style:none;margin:0;padding-left:20px;overflow-wrap:anywhere;'), $items);
  }
}

final class TeamOrganizationViewController extends TeamOrganizationController {
  public function handleRequest(AphrontRequest $request) {
    if ($request->isHTTPPost()) { return new Aphront403Response(); }
    $team = $this->loadTeam($request->getURIData('id'));
    if (!$team) { return new Aphront404Response(); }
    $actions = array();
    $admin = $this->canManage();
    if ($admin) {
      $actions[] = $this->button('编辑团队', '/organization/edit/'.$team->getID().'/', 'fa-pencil');
      $actions[] = $this->button('管理成员', '/organization/members/'.$team->getID().'/', 'fa-users');
      $actions[] = $this->button('新建下级团队', '/organization/edit/?parent='.$team->getPHID(), 'fa-plus');
    }
    $properties = id(new PHUIPropertyListView())->setViewer($this->getViewer())
      ->addProperty('状态', $team->getIsArchived() ? '已归档' : '正常');
    if (strlen($team->getDescription())) {
      $properties->addTextContent(phutil_tag('p', array('style' => 'white-space:pre-wrap;overflow-wrap:anywhere;'), $team->getDescription()));
    }
    $children = id(new TeamOrganizationTeamQuery())->setViewer($this->getViewer())
      ->withParentPHIDs(array($team->getPHID()))->execute();
    $can_roster = PhabricatorPolicyFilter::hasCapability(
      $this->getViewer(), $team, TeamOrganizationTeam::CAP_MEMBERS);
    $roster = $this->notice('成员名单未对当前账户开放。');
    if ($can_roster) {
      $phids = TeamOrganizationService::getMemberPHIDs($team);
      $roster = $phids ? $this->getViewer()->renderHandleList($phids)->setAsInline(true)
        : phutil_tag('p', array(), '暂无成员');
    }
    $content = array($this->actions($actions), $this->box('团队资料', $properties),
      $this->box('下级团队', $this->teamList($children)),
      $this->box('本级成员', $roster));
    if ($admin) { $content[] = $this->newAuditView($team); }
    return $this->page($team->getName(), $content, $team);
  }

  private function newAuditView($team) {
    $rows = array();
    foreach (TeamOrganizationService::loadAudit($this->getViewer(), $team, 30) as $entry) {
      $names = array('create' => '创建团队', 'update' => '更新团队资料',
        'members' => '调整团队成员', 'set_members' => '调整团队成员',
        'user_teams' => '调整用户归属', 'set_user_teams' => '调整用户归属',
        'archive' => '归档团队', 'restore' => '恢复团队');
      $rows[] = array(phabricator_datetime($entry->getDateCreated(), $this->getViewer()),
        $this->getViewer()->renderHandle($entry->getActorPHID()),
        idx($names, $entry->getAction(), '更新团队'));
    }
    $table = id(new AphrontTableView($rows))->setHeaders(array('时间', '操作人', '操作'));
    return $this->box('最近管理记录（仅管理员可见）', $table);
  }
}

final class TeamOrganizationEditController extends TeamOrganizationController {
  public function shouldRequireAdmin() { return true; }
  public function handleRequest(AphrontRequest $request) {
    if ($response = $this->requireAdmin()) { return $response; }
    $id = $request->getURIData('id');
    $team = $id ? $this->loadTeam($id) : null;
    if ($id && !$team) { return new Aphront404Response(); }
    $object = $team ?: new TeamOrganizationTeam();
    $input = array('name' => $team ? $team->getName() : '',
      'description' => $team ? $team->getDescription() : '',
      'parentPHID' => $team ? $team->getParentPHID() : $request->getStr('parent'),
      'viewPolicy' => $team ? $team->getViewPolicy() : TeamOrganizationTeam::DEFAULT_MEMBERS_POLICY,
      'membersPolicy' => $team ? $team->getMembersPolicy() : TeamOrganizationTeam::DEFAULT_MEMBERS_POLICY,
      'isArchived' => $team ? $team->getIsArchived() : false,
      'expectedVersion' => $team ? $team->getVersion() : 0);
    $errors = array();
    if ($request->isHTTPPost()) {
      $parent_phids = $request->getArr('parentPHIDs');
      $input = array('name' => $request->getStr('name'), 'description' => $request->getStr('description'),
        'parentPHID' => $parent_phids ? head($parent_phids) : null,
        'viewPolicy' => $request->getStr('viewPolicy'), 'membersPolicy' => $request->getStr('membersPolicy'),
        'isArchived' => $request->getBool('isArchived', false), 'expectedVersion' => $request->getInt('expectedVersion'));
      try {
        $saved = TeamOrganizationService::saveTeam($this->getViewer(), $team, $input);
        return id(new AphrontRedirectResponse())->setURI(self::teamURI($saved));
      } catch (InvalidArgumentException $ex) { $errors[] = $ex->getMessage(); }
    }
    $form = id(new AphrontFormView())->setUser($this->getViewer())
      ->addHiddenInput('expectedVersion', $input['expectedVersion'])
      ->appendControl(id(new AphrontFormTextControl())->setName('name')->setLabel('团队名称')
        ->setValue($input['name'])->setError(true))
      ->appendControl(id(new AphrontFormTextAreaControl())->setName('description')->setLabel('说明')
        ->setValue($input['description']))
      ->appendControl(id(new AphrontFormTokenizerControl())->setName('parentPHIDs')->setLabel('上级团队')
        ->setLimit(1)->setValue($input['parentPHID'] ? array($input['parentPHID']) : array())
        ->setDatasource(new TeamOrganizationDatasource())->setCaption('可留空；层级不会自动复制成员或隐私设置。'));
    $this->addPolicies($form, $object, $input);
    $form->appendControl(id(new AphrontFormCheckboxControl())->addCheckbox(
      'isArchived', '1', '归档此团队', $input['isArchived']))
      ->appendControl(id(new AphrontFormSubmitControl())->setValue($team ? '保存团队' : '创建团队')
        ->addCancelButton($team ? self::teamURI($team) : '/organization/', '取消'));
    $title = $team ? '编辑团队' : '新建团队';
    return $this->page($title, array(
      $this->notice('团队可见性与成员名单可见性分别设置，每一级团队独立生效。团队成员和用户归属由管理员维护。'),
      $this->box($title, $form, $errors)), $team);
  }
}

final class TeamOrganizationMembersController extends TeamOrganizationController {
  public function shouldRequireAdmin() { return true; }
  public function handleRequest(AphrontRequest $request) {
    if ($response = $this->requireAdmin()) { return $response; }
    $team = $this->loadTeam($request->getURIData('id'));
    if (!$team) { return new Aphront404Response(); }
    $members = TeamOrganizationService::getMemberPHIDs($team);
    $version = $team->getVersion();
    $errors = array();
    if ($request->isHTTPPost()) {
      $members = $request->getArr('members');
      $version = $request->getInt('expectedVersion');
      try {
        TeamOrganizationService::setMembers($this->getViewer(), $team, $members, $version);
        return id(new AphrontRedirectResponse())->setURI(self::teamURI($team));
      } catch (InvalidArgumentException $ex) { $errors[] = $ex->getMessage(); }
    }
    $form = id(new AphrontFormView())->setUser($this->getViewer())
      ->addHiddenInput('expectedVersion', $version)
      ->appendControl(id(new AphrontFormTokenizerControl())->setName('members')->setLabel('本级成员')
        ->setValue($members)->setDatasource(new PhabricatorPeopleDatasource())
        ->setCaption('仅调整本级团队归属，不会自动加入父团队、子团队或项目。'))
      ->appendControl(id(new AphrontFormSubmitControl())->setValue('保存成员')
        ->addCancelButton(self::teamURI($team), '返回团队'));
    return $this->page('管理成员', array(
      $team->getIsArchived() ? $this->notice('归档团队只能保留或移除已有成员；恢复团队后可添加成员。') : null,
      $this->box('本级团队成员', $form, $errors)), $team);
  }
}

final class TeamOrganizationUserController extends TeamOrganizationController {
  public function shouldRequireAdmin() { return true; }
  public function handleRequest(AphrontRequest $request) {
    if ($response = $this->requireAdmin()) { return $response; }
    $target = head(id(new PhabricatorPeopleQuery())->setViewer($this->getViewer())
      ->withPHIDs(array($request->getURIData('phid')))->execute());
    if (!$target) { return new Aphront404Response(); }
    $profile_uri = urisprintf('/p/%s/', $target->getUsername());
    $teams = TeamOrganizationService::getUserTeams($this->getViewer(), $target);
    $selected = array_values(mpull($teams, 'getPHID'));
    $expected = $selected;
    $errors = array();
    if ($request->isHTTPPost()) {
      $selected = $request->getArr('teams');
      $expected = $request->getArr('expectedTeams');
      try {
        TeamOrganizationService::setUserTeams($this->getViewer(), $target, $selected, $expected);
        return id(new AphrontRedirectResponse())->setURI($profile_uri);
      } catch (InvalidArgumentException $ex) { $errors[] = $ex->getMessage(); }
    }
    $form = id(new AphrontFormView())->setUser($this->getViewer());
    foreach ($expected as $index => $phid) { $form->addHiddenInput('expectedTeams['.$index.']', $phid); }
    $form->appendControl(id(new AphrontFormStaticControl())->setLabel('用户')
        ->setValue($this->getViewer()->renderHandle($target->getPHID())))
      ->appendControl(id(new AphrontFormTokenizerControl())->setName('teams')->setLabel('所属团队')
        ->setValue($selected)->setDatasource(new TeamOrganizationDatasource())
        ->setCaption('选择用户直接所属的团队；不自动增加父级、下级或项目成员身份。'))
      ->appendControl(id(new AphrontFormSubmitControl())->setValue('保存团队归属')
        ->addCancelButton($profile_uri, '返回用户详情'));
    return $this->page('分配团队', array($this->box('用户团队归属', $form, $errors)));
  }
}

/** Display-only: ordinary self-profile edits must never change authorization. */
final class TeamOrganizationUserField extends PhabricatorUserCustomField {
  public function getFieldKey() { return 'team:organization'; }
  public function getFieldName() { return '所属团队'; }
  public function canDisableField() { return false; }
  public function shouldAppearInPropertyView() { return true; }
  public function shouldAppearInEditView() { return false; }
  public function shouldAppearInApplicationTransactions() { return false; }
  public function shouldAppearInConduitTransactions() { return false; }
  public function renderPropertyViewValue(array $handles) {
    $viewer = $this->getViewer();
    $target = $this->getObject();
    $teams = TeamOrganizationService::getUserTeams($viewer, $target);
    $links = array();
    foreach ($teams as $team) {
      $links[] = phutil_tag('a', array('href' => TeamOrganizationController::teamURI($team)), $team->getName());
    }
    $content = $links ? phutil_implode_html(' · ', $links) : '暂无可显示团队';
    if (TeamOrganizationService::canManage($viewer)) {
      $content = array($content, ' ', phutil_tag('a', array(
        'href' => '/organization/user/'.$target->getPHID().'/'), '分配团队'));
    }
    return phutil_tag('span', array('class' => 'team-organization-user-teams'), $content);
  }
}

/** Project policy mutations are authorized by the project, not team administration. */
final class TeamProjectAccessController extends TeamOrganizationController {
  public static function projectURI($project, $capability = 'view') {
    return '/organization/project/'.$project->getID().'/permissions/?capability='.$capability;
  }

  public function handleRequest(AphrontRequest $request) {
    $viewer = $this->getViewer();
    $project = TeamProjectAccessService::loadProject($viewer, $request->getURIData('id'));
    if (!$project) { return new Aphront404Response(); }
    if (!TeamProjectAccessService::canManage($viewer, $project)) {
      return new Aphront403Response();
    }
    $capabilities = array('view' => '谁能查看', 'edit' => '谁能编辑', 'join' => '谁能加入');
    $capability = $request->getStr('capability', 'view');
    if (!isset($capabilities[$capability])) { return new Aphront400Response(); }
    if ($project->isMilestone()) {
      if ($request->isHTTPPost()) { return new Aphront403Response(); }
      $parent = $project->getParentProject();
      return $this->projectPage($project, array(
        $this->notice('里程碑沿用所属项目的权限，请到所属项目进行授权管理。'),
        $this->actions(array($this->button('管理所属项目权限', self::projectURI($parent), 'fa-lock'))),
      ));
    }
    $input = array('capability' => $capability, 'mode' => 'add',
      'users' => array(), 'teamScope' => 'none', 'teams' => array(),
      'descendantTeams' => array(), 'boundaryMode' => 'keep', 'boundaryTeams' => array(),
      'expectedState' => TeamProjectAccessService::getStateToken($project));
    $errors = array();
    if ($request->isHTTPPost()) {
      $input = array('capability' => $capability, 'mode' => $request->getStr('mode', 'add'),
        'users' => $request->getArr('users'), 'teamScope' => $request->getStr('teamScope', 'none'),
        'teams' => $request->getArr('teams'), 'descendantTeams' => $request->getArr('descendantTeams'),
        'boundaryMode' => $request->getStr('boundaryMode', 'keep'),
        'boundaryTeams' => $request->getArr('boundaryTeams'),
        'expectedState' => $request->getStr('expectedState'),
        'ruleIndex' => $request->getStr('ruleIndex'));
      if ($request->getExists('clearBoundary')) {
        $input['clearBoundary'] = $request->getBool('clearBoundary');
      }
      try {
        $source = PhabricatorContentSource::newFromRequest($request);
        if ($input['mode'] === 'remove-rule') {
          // Rule-row removal remains independent of the new dropdown form.
          if ($request->getExists('clearBoundary') || $input['boundaryMode'] !== 'keep' ||
              $input['boundaryTeams'] || $input['users'] || $input['teams'] || $input['descendantTeams']) {
            throw new InvalidArgumentException('回收整条规则不能同时提交其他权限调整，请刷新后重试。');
          }
          TeamProjectAccessService::apply($viewer, $project, $input, $source);
        } else {
          TeamProjectAccessService::applyForm($viewer, $project, $input, $source);
        }
        return id(new AphrontRedirectResponse())->setURI(self::projectURI($project, $capability).'&saved=1');
      } catch (InvalidArgumentException $ex) {
        $errors[] = $ex->getMessage();
      } catch (PhabricatorApplicationTransactionValidationException $ex) {
        $errors = $ex->getErrorMessages();
      }
    }
    $policy = TeamProjectAccessService::getPolicy($viewer, $project, $capability);
    $boundary = TeamOrganizationProjectBoundary::newGuard($project);
    $named = phid_get_type($project->getPolicy($capability)) === 'NPLC';
    if ($named && !$request->isHTTPPost()) { $input['mode'] = 'keep'; }
    $disabled_modes = $named ? array('add', 'revoke') : array();
    if ($capability === 'edit') {
      // A project has no automatic edit grant. Empty explicit edit permission
      // would always lock out its manager and is rejected by the native editor.
      $disabled_modes[] = 'automatic';
      if (!$viewer->getIsAdmin()) { $disabled_modes[] = 'administrators'; }
    }
    $is_custom = in_array($input['mode'], array('add', 'replace', 'revoke'), true);
    $boundary_modes = TeamProjectAccessService::getBoundaryModes();
    $boundary_modes['keep'] = $boundary ? '保持现有团队额外限制' : '保持现状（没有团队额外限制）';
    $selector = id(new AphrontFormView())->setUser($viewer)->setMethod('GET')
      ->setID('team-project-access-selector')
      ->setAction('/organization/project/'.$project->getID().'/permissions/')
      ->appendControl(id(new AphrontFormSelectControl())->setName('capability')->setLabel('权限项')
        ->setValue($capability)->setOptions(array('view' => '谁能查看',
          'edit' => '谁能编辑与发布', 'join' => '谁能加入'))
        ->setCaption('切换后查看对应规则；每次保存只修改所选权限，项目整体额外限制除外。'))
      ->appendControl(id(new AphrontFormSubmitControl())->setValue('打开权限项'));
    $form = id(new AphrontFormView())->setUser($viewer)
      ->setID('team-project-access-form')->setAction(self::projectURI($project, $capability))
      ->addHiddenInput('capability', $capability)
      ->addHiddenInput('expectedState', $input['expectedState'])
      ->appendControl(id(new AphrontFormStaticControl())->setLabel('当前显式授权')
        ->setValue($this->describePolicy($project, $policy, $capability)))
      ->appendControl(id(new AphrontFormSelectControl())->setName('mode')->setLabel('调整方式')
        ->setValue($input['mode'] === 'remove-rule' ? 'revoke' : $input['mode'])
        ->setOptions(TeamProjectAccessService::getFormModes())
        ->setDisabledOptions($disabled_modes)
        ->setCaption('增添与已有授权取并集；替换重设本项显式授权；回收只移除所选来源。成员等自动权限仍生效，系统会阻止使您失去管理权限的设置。'))
      ->appendControl(id(new AphrontFormStaticControl())->setLabel('本次操作')
        ->setValue(phutil_tag('span', array('id' => 'team-project-access-effect', 'aria-live' => 'polite'),
          '选择调整方式后，在保存前核对所选人员、团队及额外限制。')));
    $form->appendChild($this->controlGroup('team-project-access-custom-subjects', $is_custom, array(
      id(new AphrontFormTokenizerControl())->setName('users')->setLabel('指定人员')
        ->setControlID('team-project-access-users')->setValue($input['users'])
        ->setDatasource(new PhabricatorPeopleDatasource())
        ->setCaption('可选跨团队参与者，与本次选择的团队授权取并集。'),
      id(new AphrontFormSelectControl())->setName('teamScope')->setLabel('团队授权范围')
        ->setControlID('team-project-access-team-scope')->setValue($input['teamScope'])
        ->setOptions(TeamProjectAccessService::getTeamScopes())
        ->setCaption('只决定这些团队成员对项目的授权，不修改团队目录或成员名单的隐私。'),
      $this->controlGroup('team-project-access-direct-group',
        $is_custom && in_array($input['teamScope'], array('direct', 'mixed'), true), array(
          id(new AphrontFormTokenizerControl())->setName('teams')->setLabel('团队（仅本级）')
            ->setControlID('team-project-access-direct-teams')->setValue($input['teams'])
            ->setDatasource(new TeamOrganizationDatasource()),
        )),
      $this->controlGroup('team-project-access-descendant-group',
        $is_custom && in_array($input['teamScope'], array('descendants', 'mixed'), true), array(
          id(new AphrontFormTokenizerControl())->setName('descendantTeams')->setLabel('团队（包含下级）')
            ->setControlID('team-project-access-descendant-teams')->setValue($input['descendantTeams'])
            ->setDatasource(new TeamOrganizationDatasource()),
        )),
    )));
    $form->appendControl(id(new AphrontFormSelectControl())->setName('boundaryMode')
        ->setLabel('项目整体额外限制')->setValue($input['boundaryMode'])->setOptions($boundary_modes)
        ->setCaption('限制同时作用于查看、编辑和加入，也限制项目成员的自动权限；它只缩小范围，不会授予权限。'))
      ->appendChild($this->controlGroup('team-project-access-boundary-group',
        in_array($input['boundaryMode'], array('direct', 'descendants'), true), array(
          id(new AphrontFormTokenizerControl())->setName('boundaryTeams')->setLabel('限制到这些团队')
            ->setControlID('team-project-access-boundary-teams')->setValue($input['boundaryTeams'])
            ->setDatasource(new TeamOrganizationDatasource())
            ->setCaption('必须选择团队。保存后替换项目整体限制的团队范围；站点管理员仅豁免此额外限制，仍须满足项目原有权限。'),
        )))
      ->appendControl(id(new AphrontFormSubmitControl())->setValue('保存授权调整')
        ->addCancelButton(self::projectURI($project, $capability), '重新加载'));
    $script_uri = '/organization/resources/project-access.js?h='.substr(
      hash('sha256', TeamProjectAccessScriptController::JAVASCRIPT), 0, 16);
    CelerityAPI::getStaticResourceResponse()->addContentSecurityPolicyURI(
      'script-src', PhabricatorEnv::getURI($script_uri));
    $content = array(
      $request->getBool('saved', false) ? $this->notice('授权调整已保存。回收后是否仍能访问，取决于剩余授权来源。') : null,
      $selector,
      $project->getParentProject() ? $this->notice('普通子项目保留上级项目可见性要求；授权不会绕过上级项目权限。') : null,
      $boundary ? $this->notice(array('高级限制正在生效：团队额外限制会与全部项目权限取交集，跨团队个人授权也受限制。',
        ' 默认保持现有范围；只有明确选择关闭或替换，才会改变它。当前范围：', $this->describeBoundary($project))) : null,
      $named ? $this->notice('当前使用命名策略，默认保持其独立管理关系。增添和回收请使用高级策略设置；选择任一“替换为”选项会解除关联。') : null,
      $this->box($capabilities[$capability].' · 隐私与授权', $form, $errors),
      $this->box($capabilities[$capability].' · 当前显式规则',
        $this->renderRules($project, $policy, $capability, $input['expectedState'])),
      $this->box('自动权限与其他规则', array(
        phutil_tag('p', array(), '项目成员仍自动可见，包含通过子项目聚合的成员；编辑者仍可加入。编辑和发布也需要先能查看项目。'),
        phutil_tag('p', array(), '回收一个来源后，其他来源可能继续授权。替换或回收显式规则不修改项目成员或用户的团队归属。'),
        phutil_tag('a', array('href' => '/project/members/'.$project->getID().'/'), '管理项目成员'),
        ' · ', phutil_tag('a', array('href' => '/project/edit/'.$project->getID().'/'), '高级策略设置'),
      )),
      phutil_tag('script', array('src' => $script_uri, 'type' => 'text/javascript', 'defer' => 'defer')),
    );
    return $this->projectPage($project, $content);
  }

  private function controlGroup($id, $enabled, array $controls) {
    foreach ($controls as $control) {
      if ($control instanceof AphrontFormControl) {
        $control->setViewer($this->getViewer());
        $control->willRender();
      }
    }
    // A disabled fieldset also excludes hidden inputs created asynchronously by
    // Javelin tokenizers after the page enhancement has run.
    return phutil_tag('fieldset', array('id' => $id, 'disabled' => $enabled ? null : 'disabled',
      'style' => 'border:0;margin:0;padding:0;min-width:0;'.($enabled ? '' : 'display:none;'),
      'aria-hidden' => $enabled ? 'false' : 'true'), $controls);
  }

  private function describePolicy($project, $policy, $capability) {
    $identifier = $project->getPolicy($capability);
    $labels = array(PhabricatorPolicies::POLICY_USER => '所有已登录用户',
      PhabricatorPolicies::POLICY_ADMIN => '站点管理员',
      PhabricatorPolicies::POLICY_NOONE => '没有显式授权；成员等自动权限仍生效',
      PhabricatorPolicies::POLICY_PUBLIC => '公开策略；站点入口仍要求登录');
    if (isset($labels[$identifier])) { return $labels[$identifier]; }
    if (phid_get_type($identifier) === 'NPLC') {
      return array('命名策略：', $policy->getName(), '（独立管理）');
    }
    $supported = array('PhabricatorUsersPolicyRule', 'TeamOrganizationDirectPolicyRule',
      'TeamOrganizationDescendantsPolicyRule');
    $simple = $policy->getDefaultAction() === PhabricatorPolicy::ACTION_DENY;
    foreach ($policy->getRules() as $rule) {
      if (idx($rule, 'action') !== 'allow' || !in_array(idx($rule, 'rule'), $supported, true)) {
        $simple = false;
      }
    }
    if ($simple && !$policy->getRules()) { return '没有显式授权；成员等自动权限仍生效'; }
    return $simple ? '指定人员与团队的组合授权（详见下方规则）' : '高级自定义策略（按下方原有顺序判断）';
  }

  private function describeBoundary($project) {
    $ids = $project->getProperty(TeamOrganizationProjectBoundary::TEAMS_PROPERTY, array());
    if (!is_array($ids)) { return '现有高级限制，请到高级策略设置检查。'; }
    $teams = id(new TeamOrganizationTeamQuery())->setViewer($this->getViewer())->withPHIDs($ids)->execute();
    $visible = mpull($teams, null, 'getPHID');
    $labels = array();
    foreach ($ids as $id) {
      if (isset($visible[$id])) {
        $team = $visible[$id];
        $labels[] = $team->getName().($team->getIsArchived() ? '（已归档，不产生团队匹配）' : '');
      } else {
        $labels[] = '不可见团队';
      }
    }
    return array(phutil_implode_html('、', $labels),
      $project->getProperty(TeamOrganizationProjectBoundary::SCOPE_PROPERTY, 'direct') === 'descendants'
        ? '；包括所选团队及全部下级成员。' : '；仅所选团队本级成员。');
  }

  private function renderRules($project, $policy, $capability, $state) {
    $viewer = $this->getViewer();
    if ($project->getPolicy($capability) === PhabricatorPolicies::POLICY_NOONE) {
      return phutil_tag('p', array('style' => 'padding:12px;'),
        '当前没有显式授权；项目成员自动可见、编辑者自动加入等规则仍按下方说明生效。');
    }
    if (phid_get_type($project->getPolicy($capability)) === 'NPLC') {
      return array(phutil_tag('p', array('style' => 'padding:12px;'), $policy->getName()),
        phutil_tag('p', array('style' => 'padding:12px;'),
          '命名策略由独立策略对象管理，本页不展开其内部规则。选择任一“替换为”选项会解除关联，改用本项目选择的授权范围。'));
    }
    $rules = id(new PhutilClassMapQuery())->setAncestorClass(PhabricatorPolicyRule::class)->execute();
    $items = array();
    foreach ($policy->getRules() as $index => $spec) {
      $rule = idx($rules, idx($spec, 'rule'));
      $label = $rule ? $rule->getRuleDescription() : '高级策略规则';
      if (idx($spec, 'rule') === PhabricatorUsersPolicyRule::class) {
        $label = '指定人员';
      }
      $values = array();
      if ($rule) {
        $display = $rule->getValueForDisplay($viewer, idx($spec, 'value'));
        $values = is_array($display) ? array_values($display) : array($display);
      }
      if (idx($spec, 'rule') === 'PhorgeGlobalPolicyRule') {
        $label = idx(array(PhabricatorPolicies::POLICY_USER => '所有登录用户',
          PhabricatorPolicies::POLICY_PUBLIC => '公开（是否允许匿名访问取决于站点设置）',
          PhabricatorPolicies::POLICY_ADMIN => '站点管理员',
          PhabricatorPolicies::POLICY_NOONE => '不授予任何人'), idx($spec, 'value'), '全局策略');
        $values = array();
      }
      $allow = idx($spec, 'action') === PhabricatorPolicy::ACTION_ALLOW;
      $action = $allow ? '允许' : '拒绝';
      $remove = null;
      if ($allow) {
        $remove = id(new AphrontFormView())->setUser($viewer)
          ->setAction(self::projectURI($project, $capability))
          ->addHiddenInput('capability', $capability)->addHiddenInput('mode', 'remove-rule')
          ->addHiddenInput('ruleIndex', $index)->addHiddenInput('expectedState', $state)
          ->appendControl(id(new AphrontFormSubmitControl())->setValue('回收此授权'));
      }
      $items[] = phutil_tag('div', array('class' => 'team-project-access-rule',
        'data-access-rule-index' => $index, 'data-access-rule-action' => $allow ? 'allow' : 'deny',
        'style' => 'padding:12px;overflow-wrap:anywhere;border-bottom:1px solid #d5dce5;'), array(
          phutil_tag('strong', array(), ($index + 1).'. '.$action.' · '.$label),
          $values ? phutil_tag('p', array(), phutil_implode_html('、', $values)) : null,
          $remove,
          !$allow ? phutil_tag('p', array(), '这是拒绝规则；如需修改，请使用高级权限设置。') : null,
      ));
    }
    $items[] = phutil_tag('p', array('style' => 'padding:12px;'),
      $policy->getDefaultAction() === PhabricatorPolicy::ACTION_ALLOW
        ? '没有规则匹配时：允许。若要收紧此范围，请使用“替换”或高级权限设置。'
        : '没有规则匹配时：拒绝（项目成员等自动授权仍单独生效）。');
    $items[] = phutil_tag('p', array('style' => 'padding:12px;'),
      '原生规则按顺序判断。增添会把新的允许规则放在原规则之前，确保新增用户或团队可与原有授权合并。');
    return $items;
  }

  private function projectPage($project, array $content) {
    $crumbs = $this->buildApplicationCrumbs()->setBorder(true)
      ->addTextCrumb($project->getDisplayName(), $project->getProfileURI())
      ->addTextCrumb('权限管理');
    return $this->newPage()->setTitle(array('权限管理', $project->getDisplayName()))
      ->setCrumbs($crumbs)->setPageObjectPHIDs(array($project->getPHID()))
      ->appendChild(id(new PHUIBoxView())->addPadding(PHUI::PADDING_LARGE)
        ->appendChild(array(id(new PHUIHeaderView())->setHeader('项目隐私与权限')->setHeaderIcon('fa-lock'),
          $this->actions(array($this->button('返回项目', $project->getProfileURI(), 'fa-arrow-left'),
            $this->button('高级策略设置', '/project/edit/'.$project->getID().'/', 'fa-cog'))), $content)));
  }
}

/** Same-origin progressive enhancement; authorization is always server-side. */
final class TeamProjectAccessScriptController extends TeamOrganizationController {
  const JAVASCRIPT = <<<'JS'
(function () {
  'use strict';
  function initialize() {
    var form = document.getElementById('team-project-access-form');
    if (!form || form.getAttribute('data-access-ready') === '1') { return; }
    form.setAttribute('data-access-ready', '1');
    var mode = form.querySelector('select[name="mode"]');
    var scope = form.querySelector('select[name="teamScope"]');
    var boundary = form.querySelector('select[name="boundaryMode"]');
    var effect = document.getElementById('team-project-access-effect');
    var descriptions = {
      add: '将所选人员、团队增加到当前权限的已有授权中，保留原有规则。',
      replace: '用所选人员和团队替换当前这一项的全部显式授权，另外两项不变。',
      revoke: '只撤销所选来源的显式授权；其他来源可能继续授予同一人权限。',
      keep: '保持当前这一项权限的全部原有规则。',
      'all-users': '将当前这一项的显式授权替换为所有已登录用户。',
      administrators: '将当前这一项的显式授权替换为站点管理员。',
      automatic: '清空当前这一项的显式授权；成员自动可见、编辑者自动加入仍生效。'
    };
    var restrictions = {
      keep: '项目整体额外限制保持原状。',
      none: '同时关闭整个项目的团队额外限制。',
      direct: '同时将整个项目限制为所选团队本级成员；这一限制影响查看、编辑和加入。',
      descendants: '同时将整个项目限制为所选团队及下级成员；这一限制影响查看、编辑和加入。'
    };
    function show(id, visible) {
      var fieldset = document.getElementById(id);
      fieldset.disabled = !visible;
      fieldset.style.display = visible ? '' : 'none';
      fieldset.setAttribute('aria-hidden', visible ? 'false' : 'true');
    }
    function update() {
      var custom = ['add', 'replace', 'revoke'].indexOf(mode.value) !== -1;
      show('team-project-access-custom-subjects', custom);
      show('team-project-access-direct-group', custom && ['direct', 'mixed'].indexOf(scope.value) !== -1);
      show('team-project-access-descendant-group', custom && ['descendants', 'mixed'].indexOf(scope.value) !== -1);
      show('team-project-access-boundary-group', ['direct', 'descendants'].indexOf(boundary.value) !== -1);
      effect.textContent = (descriptions[mode.value] || '请选择有效的调整方式。') + ' ' +
        (restrictions[boundary.value] || '请选择有效的项目整体限制。');
    }
    var dirty = false;
    form.addEventListener('input', function () { dirty = true; });
    form.addEventListener('change', function () { dirty = true; update(); });
    form.addEventListener('submit', update, true);
    update();
    function selectionSnapshot() {
      // Tokenizer browsing and removing a token do not emit DOM change events.
      // Compare submitted values as well as observing ordinary input events.
      return JSON.stringify(Array.from(new FormData(form).entries()).filter(function (entry) {
        return /^(mode|teamScope|boundaryMode|users|teams|descendantTeams|boundaryTeams)(\[|$)/.test(entry[0]);
      }).sort(function (a, b) { return JSON.stringify(a).localeCompare(JSON.stringify(b)); }));
    }
    var initialSelection = selectionSnapshot();
    var selector = document.getElementById('team-project-access-selector');
    if (selector) {
      var capability = selector.querySelector('select[name="capability"]');
      function canSwitch() {
        if ((!dirty && selectionSnapshot() === initialSelection) ||
            window.confirm('当前权限调整尚未保存。放弃这些调整并切换权限项？')) { return true; }
        capability.value = form.querySelector('input[name="capability"]').value;
        return false;
      }
      capability.addEventListener('change', function () {
        if (!canSwitch()) { return; }
        selector.submit();
      });
      selector.addEventListener('submit', function (event) {
        if (!canSwitch()) { event.preventDefault(); }
      });
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
}());
JS;

  public function handleRequest(AphrontRequest $request) {
    if (!$request->isHTTPGet()) {
      return id(new AphrontPlainTextResponse())->setHTTPResponseCode(405)->setContent('Method not allowed.');
    }
    return id(new AphrontFileResponse())->setMimeType('text/javascript; charset=utf-8')
      ->setContent(self::JAVASCRIPT);
  }
}

/** Discoverable on project overview and history; never confers a capability. */
final class TeamProjectAccessLinkField extends PhabricatorProjectCustomField {
  public function getFieldKey() { return 'team:project-access-link'; }
  public function getFieldName() { return '权限管理'; }
  public function canDisableField() { return false; }
  public function shouldAppearInPropertyView() { return true; }
  public function renderPropertyViewValue(array $handles) {
    $project = $this->getObject();
    if (!TeamProjectAccessService::canManage($this->getViewer(), $project)) { return null; }
    return phutil_tag('a', array('href' => TeamProjectAccessController::projectURI($project)),
      '增添、替换或回收授权');
  }
}

final class TeamProjectAccessEditLinkExtension extends PhabricatorEditEngineExtension {
  const EXTENSIONKEY = 'team.project.access-link';
  public function isExtensionEnabled() { return true; }
  public function getExtensionName() { return '项目权限管理入口'; }
  public function supportsObject(PhabricatorEditEngine $engine,
    PhabricatorApplicationTransactionInterface $object) {
    return $object instanceof PhabricatorProject && (bool)$object->getID();
  }
  public function buildCustomEditFields(PhabricatorEditEngine $engine,
    PhabricatorApplicationTransactionInterface $object) {
    if (!TeamProjectAccessService::canManage($engine->getViewer(), $object)) { return array(); }
    return array(id(new PhabricatorStaticEditField())->setKey('teamProjectAccessLink')
      ->setLabel('权限管理')->setDescription('增添、替换或回收指定用户与团队的项目授权。')
      ->setValue(array(phutil_tag('a', array('href' => TeamProjectAccessController::projectURI($object)),
        '打开项目权限管理'), '。统一选择查看、编辑、加入的授权方式；人员与团队默认合并。下方为项目全部权限的额外限制，单独设置不会授权。')));
  }
}
