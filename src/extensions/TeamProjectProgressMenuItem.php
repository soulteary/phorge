<?php

/** A discoverable project-local entry for progress, discussion and history. */
final class TeamProjectProgressProfileMenuItem
  extends PhabricatorProfileMenuItem {

  const MENUITEMKEY = 'team.project.progress';
  const BUILTINKEY = 'team.project.progress';

  public function getMenuItemTypeName() {
    return pht('开发历史');
  }

  public function getMenuItemTypeIcon() {
    return 'fa-history';
  }

  public function shouldEnableForObject($object) {
    // Existing menu configurations remain in storage. When tasks are enabled,
    // the native workboard is the single workspace entry; old record URLs and
    // this fallback remain usable when Maniphest is unavailable.
    return $object instanceof PhabricatorProject &&
      !PhabricatorApplication::isClassInstalledForViewer(
        PhabricatorManiphestApplication::class, $this->getViewer());
  }

  public function canHideMenuItem(
    PhabricatorProfileMenuItemConfiguration $config) {
    return false;
  }

  public function canMakeDefault(
    PhabricatorProfileMenuItemConfiguration $config) {
    return true;
  }

  public function getDisplayName(
    PhabricatorProfileMenuItemConfiguration $config) {
    return pht('开发历史');
  }

  public function buildEditEngineFields(
    PhabricatorProfileMenuItemConfiguration $config) {
    return array();
  }

  protected function newMenuItemViewList(
    PhabricatorProfileMenuItemConfiguration $config) {
    $id = $config->getProfileObject()->getID();
    return array(
      $this->newItemView()
        ->setURI('/project/progress/'.$id.'/')
        ->setName($this->getDisplayName($config))
        ->setIcon($this->getMenuItemTypeIcon()),
    );
  }
}

/** Keep action labels visible when the native mobile header hides its text. */
final class TeamProjectProgressActionsView extends AphrontView {

  private $actions = array();

  public function addAction(PHUIButtonView $action) {
    $this->actions[] = $action;
    return $this;
  }

  public function render() {
    return phutil_tag('div', array(
      'class' => 'team-project-progress-actions',
      'style' => 'display:flex;flex-wrap:wrap;gap:8px;padding:12px 16px;',
    ), $this->actions);
  }
}

/** Shared workspace shell; task operations keep all native permission gates. */
final class TeamProjectWorkspaceView extends AphrontView {

  private $project;
  private $section = 'board';
  private $boardControls = array();

  public function setProject(PhabricatorProject $project) {
    $this->project = $project;
    return $this;
  }

  public function setSection($section) {
    $this->section = $section;
    return $this;
  }

  public function addBoardControl(PHUIListItemView $control) {
    $this->boardControls[] = $control;
    return $this;
  }

  public function render() {
    $viewer = $this->getViewer();
    $project = $this->project;
    $history_uri = '/project/progress/'.$project->getID().'/';
    $has_tasks = PhabricatorApplication::isClassInstalledForViewer(
      PhabricatorManiphestApplication::class, $viewer);
    $tabs = new TeamProjectProgressActionsView();
    if ($has_tasks) {
      $tabs->addAction(id(new PHUIButtonView())
        ->setTag('a')->setIcon('fa-columns')->setText('任务看板')
        ->setColor($this->section === 'board' ? PHUIButtonView::GREEN : null)
        ->setHref($project->getWorkboardURI()));
    }
    $tabs->addAction(id(new PHUIButtonView())
      ->setTag('a')->setIcon('fa-history')->setText('开发历史')
      ->setColor($this->section === 'history' ? PHUIButtonView::GREEN : null)
      ->setHref($history_uri));
    $actions = new TeamProjectProgressActionsView();
    if ($has_tasks && $viewer->isLoggedIn() &&
        $viewer->getIsApproved() && !$viewer->getIsDisabled()) {
      // The native create form enforces its own application capability and ACL.
      $create_uri = id(new PhutilURI('/maniphest/task/edit/'))
        ->replaceQueryParam('projects', $project->getPHID());
      $actions->addAction(id(new PHUIButtonView())
        ->setTag('a')->setIcon('fa-plus')->setText('新建卡片')
        ->setHref($create_uri));
    }
    if (TeamProjectProgress::canPublish($viewer, $project)) {
      $actions->addAction(id(new PHUIButtonView())
        ->setTag('a')->setIcon('fa-pencil')->setText('发布项目记录')
        ->setHref($history_uri.'#publish'));
    }
    if ($this->section === 'board') {
      foreach ($this->boardControls as $control) {
        // Render the native menus once, with text also visible on phones.
        // Preserve their original metadata, workflow and event handlers.
        $button = id(new PHUIButtonView())
          ->setTag('a')->setText($control->getName())
          ->setIcon($control->getIcon())->setHref($control->getHref())
          ->setHasCaret(true)->setDisabled($control->getDisabled())
          ->setMetadata($control->getMetadata());
        foreach ($control->getSigils() as $sigil) {
          $button->addSigil($sigil);
        }
        foreach ($control->getClasses() as $class) {
          $button->addClass($class);
        }
        if ($control->getWorkflow()) {
          $button->addSigil('workflow');
        }
        $actions->addAction($button);
      }
    }
    $guide = phutil_tag('p', array(
      'class' => 'phui-box-padding',
      'style' => 'padding-top:0;',
    ), '卡片用于推进工作，状态、负责人和讨论在卡片内维护。'.
      '开发历史保存项目级成果与讨论。项目标签不会自动共享卡片；'.
      '任务内评论按任务自身权限控制；'.
      '时间和发布成员指卡片的实际创建时间与创建者。');
    $current = null;
    if ($this->section === 'board') {
      $summary = TeamProjectProgress::getSummary($project, $viewer);
      $current = id(new PHUIPropertyListView())->setViewer($viewer)
        ->addProperty('项目整体评估（人工）', $summary['percent'] === null
          ? '尚未填写' : $summary['percent'].'%');
      $latest = $summary['latestNode'];
      if ($latest) {
        $current->addProperty('最新项目记录', array(
          phutil_tag('a', array('href' => $history_uri.'?entry='.
            $latest->getID().'#entry-'.$latest->getID()),
            idx($latest->getNewValue(), 'title')),
          ' · ', $viewer->renderHandle($latest->getAuthorPHID()),
          ' · ', phabricator_datetime($latest->getDateCreated(), $viewer),
        ));
      }
      $comment = $summary['latestComment'];
      if ($comment) {
        $current->addProperty('最新工作评论', phutil_tag('a',
          array('href' => $history_uri.'?entry='.$comment->getID().
            '#entry-'.$comment->getID()), idx($comment->getNewValue(), 'title')));
      }
    }
    return id(new PHUIObjectBoxView())
      ->setHeader(id(new PHUIHeaderView())->setHeader('工作看板'))
      ->addClass('team-project-workspace')
      ->appendChild($tabs)->appendChild($actions)->appendChild($guide)
      ->appendChild($current);
  }

}

/**
 * The native workboard markup in document flow, below the shared workspace.
 * PHUIWorkboardView is final and its shadow is fixed at a viewport offset;
 * retain the same panels, resources, sigils and multi-column renderer here.
 */
final class TeamProjectWorkspaceBoardView extends AphrontTagView {

  private $panels = array();

  public function addPanel(PHUIWorkpanelView $panel) {
    $this->panels[] = $panel;
    return $this;
  }

  protected function getTagAttributes() {
    return array('class' => 'phui-workboard-view');
  }

  protected function getTagContent() {
    require_celerity_resource('phui-workboard-view-css');
    $columns = id(new AphrontMultiColumnView())
      ->setGutter(AphrontMultiColumnView::GUTTER_MEDIUM);
    foreach ($this->panels as $panel) {
      $columns->addColumn($panel);
    }
    return javelin_tag('div', array(
      'class' => 'phui-workboard-view-shadow',
      'sigil' => 'workboard-shadow lock-scroll-y-while-dragging',
      'style' => 'position:relative;top:auto;bottom:auto;left:auto;right:auto;'.
        'overflow-x:auto;padding:16px;',
    ), $columns);
  }
}

/** The same summary can appear on the overview and the complete record page. */
final class TeamProjectProgressSummaryView extends AphrontView {

  private $project;
  private $showActions = true;

  public function setProject(PhabricatorProject $project) {
    $this->project = $project;
    return $this;
  }

  public function setShowActions($show_actions) {
    $this->showActions = $show_actions;
    return $this;
  }

  public function render() {
    $viewer = $this->getViewer();
    $project = $this->project;
    $uri = '/project/progress/'.$project->getID().'/';
    $summary = TeamProjectProgress::getSummary($project, $viewer);

    $header = id(new PHUIHeaderView())
      ->setHeader(pht('项目开发历史'));
    $actions = new TeamProjectProgressActionsView();

    if (TeamProjectProgress::canPublish($viewer, $project)) {
      $actions->addAction(
        id(new PHUIButtonView())
          ->setTag('a')
          ->setIcon('fa-pencil')
          ->setColor(PHUIButtonView::GREEN)
          ->setText(pht('发布项目记录'))
          ->setHref($uri.'#publish')
          ->addClass('team-project-publish'));
    }
    $actions->addAction(
      id(new PHUIButtonView())
        ->setTag('a')
        ->setIcon('fa-history')
        ->setText(pht('开发历史'))
        ->setHref($uri));

    $properties = id(new PHUIPropertyListView())
      ->setViewer($viewer);

    $percent = $summary['percent'];
    if ($percent === null) {
      $percent_view = pht('尚未填写');
    } else {
      $percent_view = array(
        phutil_tag(
          'progress',
          array(
            'value' => $percent,
            'max' => 100,
            'aria-label' => pht('项目完成度'),
            'style' => 'width: 120px; vertical-align: middle;',
          ),
          pht('%s%%', $percent)),
        ' ',
        pht('%s%%', $percent),
      );
    }
    $properties->addProperty(pht('项目完成度（人工评估）'), $percent_view);

    $latest = $summary['latest'];
    $node = $summary['latestNode'];
    if ($node) {
      $value = $node->getNewValue();
      $properties->addProperty(
        pht('最新项目记录'),
        phutil_tag('span', array('class' => 'team-project-latest-node'),
          phutil_tag(
            'a',
            array('href' => $uri.'?entry='.$node->getID().
              '#entry-'.$node->getID()),
            $value['title'])));
      $properties->addProperty(
        pht('记录发布者'),
        array(
          $viewer->renderHandle($node->getAuthorPHID()),
          ' · ',
          phabricator_datetime($node->getDateCreated(), $viewer),
        ));
      if ($value['participants']) {
        $properties->addProperty(
          pht('记录参与者'),
          $this->newParticipantList($value['participants']));
      }
    } else {
      $properties->addProperty(pht('最新项目记录'), pht('暂无项目记录'));
    }

    $comment = $summary['latestComment'];
    if ($comment) {
      $value = $comment->getNewValue();
      $properties->addProperty(
        pht('最新工作评论'),
        phutil_tag('span', array('class' => 'team-project-latest-discussion'),
          array(
            phutil_tag('a',
              array('href' => $uri.'?entry='.$comment->getID().
                '#entry-'.$comment->getID()),
              $value['title']),
            ' · ',
            $viewer->renderHandle($comment->getAuthorPHID()),
            ' · ',
            phabricator_datetime($comment->getDateCreated(), $viewer),
          )));
    }

    if (!$latest) {
      $properties->addTextContent(phutil_tag(
        'p',
        array(),
        pht('还没有项目记录或工作评论。待推进的事项请创建工作卡片；'.
          '项目记录保存阶段成果、决策与参与者，工作评论用于讨论与补充。')));
    }

    return id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->addClass('team-project-progress-summary')
      ->appendChild($this->showActions ? $actions : null)
      ->appendChild($properties);
  }

  private function newParticipantList(array $phids) {
    $limit = 5;
    $names = $this->getViewer()
      ->renderHandleList(array_slice($phids, 0, $limit))
      ->setAsInline(true);
    if (count($phids) <= $limit) {
      return $names;
    }
    return array($names, pht(' 等 %s 人', count($phids)));
  }
}
