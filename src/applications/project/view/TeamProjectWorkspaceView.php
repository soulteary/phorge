<?php

/** Shared workspace shell; task operations keep all native permission gates. */
final class TeamProjectWorkspaceView extends AphrontView {

  private $project;
  private $section = 'board';
  private $boardControls = array();

  public static function newProjectActions($viewer, $project) {
    $actions = id(new TeamProjectProgressActionsView())->setViewer($viewer);
    $actions->addAction(id(new PHUIButtonView())
      ->setTag('a')->setIcon('fa-rss')->setText(pht('All Project Activity'))
      ->setHref('/feed/?projectPHIDs='.$project->getPHID()));
    if (PhabricatorApplication::isClassInstalledForViewer(
        PhabricatorManiphestApplication::class, $viewer)) {
      $actions->addAction(id(new PHUIButtonView())
        ->setTag('a')->setIcon('fa-tasks')->setText(pht('Advanced Task Search'))
        ->setHref('/maniphest/?projectPHIDs='.$project->getPHID()));
    }
    return $actions->addAction(id(new PHUIButtonView())
      ->setTag('a')->setIcon('fa-list-alt')->setText(pht('Project Change History'))
      ->setHref('/project/manage/'.$project->getID().'/'));
  }

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
        ->setTag('a')->setIcon('fa-columns')->setText(pht('Task Workboard'))
        ->setColor($this->section === 'board' ? PHUIButtonView::GREEN : null)
        ->setHref($project->getWorkboardURI()));
    }
    if (TeamProjectProgress::isHistoryVisible()) {
      $tabs->addAction(id(new PHUIButtonView())
        ->setTag('a')->setIcon('fa-history')->setText(pht('Development History'))
        ->setColor($this->section === 'history' ? PHUIButtonView::GREEN : null)
        ->setHref($history_uri));
    }
    $actions = new TeamProjectProgressActionsView();
    if ($has_tasks && $viewer->isLoggedIn() &&
        $viewer->getIsApproved() && !$viewer->getIsDisabled()) {
      // The native create form enforces its own application capability and ACL.
      $create_uri = id(new PhutilURI('/maniphest/task/edit/'))
        ->replaceQueryParam('projects', $project->getPHID());
      $actions->addAction(id(new PHUIButtonView())
        ->setTag('a')->setIcon('fa-plus')->setText(pht('Create Task'))
        ->setHref($create_uri));
    }
    if (TeamProjectProgress::canPublish($viewer, $project)) {
      $actions->addAction(id(new PHUIButtonView())
        ->setTag('a')->setIcon('fa-pencil')->setText(pht('Publish Project Record'))
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
    ), TeamProjectProgress::isHistoryVisible()
      ? pht('Tasks own their status, responsibility, and discussion. Project history preserves results and discussion. A project tag does not share a private task; task comments retain task policies. Time and author refer to the actual task creation time and creator.')
      : pht('Tasks own their status, responsibility, and discussion. A project tag does not share a private task.'));
    $current = null;
    if ($this->section === 'board' && TeamProjectProgress::isHistoryVisible()) {
      $summary = TeamProjectProgress::getSummary($project, $viewer);
      $current = id(new PHUIPropertyListView())->setViewer($viewer)
        ->addProperty(pht('Reported Completion'), $summary['percent'] === null
          ? pht('Not Reported') : $summary['percent'].'%');
      $latest = $summary['latestNode'];
      if ($latest) {
        $current->addProperty(pht('Latest Project Record'), array(
          phutil_tag('a', array('href' => $history_uri.'?entry='.
            $latest->getID().'#entry-'.$latest->getID()),
            idx($latest->getNewValue(), 'title')),
          ' · ', $viewer->renderHandle($latest->getAuthorPHID()),
          ' · ', phabricator_datetime($latest->getDateCreated(), $viewer),
        ));
      }
      $comment = $summary['latestComment'];
      if ($comment) {
        $current->addProperty(pht('Latest Work Comment'), phutil_tag('a',
          array('href' => $history_uri.'?entry='.$comment->getID().
            '#entry-'.$comment->getID()), idx($comment->getNewValue(), 'title')));
      }
    }
    return id(new PHUIObjectBoxView())
      ->setHeader(id(new PHUIHeaderView())->setHeader(pht('Project Workspace')))
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
