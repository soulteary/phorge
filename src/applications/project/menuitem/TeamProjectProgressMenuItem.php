<?php

/** A discoverable project-local entry for progress, discussion and history. */
final class TeamProjectProgressProfileMenuItem
  extends PhabricatorProfileMenuItem {

  const MENUITEMKEY = 'team.project.progress';
  const BUILTINKEY = 'team.project.progress';

  public function getMenuItemTypeName() {
    return pht('Development History');
  }

  public function getMenuItemTypeIcon() {
    return 'fa-history';
  }

  public function shouldEnableForObject($object) {
    return $object instanceof PhabricatorProject &&
      TeamProjectProgress::isHistoryVisible() &&
      (!TeamProjectProgress::isWorkspaceEnabled() ||
        !PhabricatorApplication::isClassInstalledForViewer(
          PhabricatorManiphestApplication::class, $this->getViewer()));
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
    return pht('Development History');
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
      ->setHeader(pht('Project Development History'));
    $actions = new TeamProjectProgressActionsView();

    if (TeamProjectProgress::canPublish($viewer, $project)) {
      $actions->addAction(
        id(new PHUIButtonView())
          ->setTag('a')
          ->setIcon('fa-pencil')
          ->setColor(PHUIButtonView::GREEN)
          ->setText(pht('Publish Project Record'))
          ->setHref($uri.'#publish')
          ->addClass('team-project-publish'));
    }
    $actions->addAction(
      id(new PHUIButtonView())
        ->setTag('a')
        ->setIcon('fa-history')
        ->setText(pht('Development History'))
        ->setHref($uri));

    $properties = id(new PHUIPropertyListView())
      ->setViewer($viewer);

    $percent = $summary['percent'];
    if ($percent === null) {
      $percent_view = pht('Not Reported');
    } else {
      $percent_view = array(
        phutil_tag(
          'progress',
          array(
            'value' => $percent,
            'max' => 100,
            'aria-label' => pht('Reported Project Completion'),
            'style' => 'width: 120px; vertical-align: middle;',
          ),
          pht('%s%%', $percent)),
        ' ',
        pht('%s%%', $percent),
      );
    }
    $properties->addProperty(pht('Project Completion (Manual Assessment)'), $percent_view);

    $latest = $summary['latest'];
    $node = $summary['latestNode'];
    if ($node) {
      $value = $node->getNewValue();
      $properties->addProperty(
        pht('Latest Project Record'),
        phutil_tag('span', array('class' => 'team-project-latest-node'),
          phutil_tag(
            'a',
            array('href' => $uri.'?entry='.$node->getID().
              '#entry-'.$node->getID()),
            $value['title'])));
      $properties->addProperty(
        pht('Record Publisher'),
        array(
          $viewer->renderHandle($node->getAuthorPHID()),
          ' · ',
          phabricator_datetime($node->getDateCreated(), $viewer),
        ));
      if ($value['participants']) {
        $properties->addProperty(
          pht('Record Participants'),
          $this->newParticipantList($value['participants']));
      }
    } else {
      $properties->addProperty(pht('Latest Project Record'), pht('No Project Records'));
    }

    $comment = $summary['latestComment'];
    if ($comment) {
      $value = $comment->getNewValue();
      $properties->addProperty(
        pht('Latest Work Comment'),
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
        pht('No project records or work comments have been published. Create native tasks for actionable work. Project records preserve results, decisions, and participants; work comments add discussion.')));
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
    $remaining = count($phids) - $limit;
    $suffix = ($remaining === 1)
      ? pht(' and one other participant')
      : pht(' and %s other participants', new PhutilNumber($remaining));
    return array($names, $suffix);
  }
}
