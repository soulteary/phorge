<?php

/** A project-local, append-only history of nodes and discussion. */
final class TeamProjectProgressController extends PhabricatorProjectController {

  public function shouldAllowPublic() {
    return true;
  }

  public function handleRequest(AphrontRequest $request) {
    $response = $this->loadProject();
    if ($response) {
      return $response;
    }
    $viewer = $request->getUser();
    $project = $this->getProject();
    $uri = '/project/progress/'.$project->getID().'/';
    $can_publish = TeamProjectProgress::canPublish($viewer, $project);
    $kinds = array('node' => pht('Project Record'), 'comment' => pht('Work Comment'));
    $compose = TeamProjectProgress::normalizeKind(
      $request->getStr('compose', 'node'));
    if (!isset($kinds[$compose])) {
      return new Aphront404Response();
    }
    $input = array(
      'kind' => $compose,
      'title' => '',
      'body' => '',
      'status' => '',
      'percent' => '',
      'participants' => $viewer->getPHID()
        ? array($viewer->getPHID()) : array(),
    );
    $errors = array();
    if ($request->isHTTPPost()) {
      $request->validateCSRF();
      if (!$can_publish) {
        return new Aphront403Response();
      }
      $input = array(
        'kind' => TeamProjectProgress::normalizeKind($request->getStr('kind')),
        'title' => $request->getStr('title'),
        'body' => $request->getStr('body'),
        'status' => $request->getStr('status'),
        'percent' => $request->getStr('percent'),
        'participants' => $request->getArr('participants'),
      );
      if (!isset($kinds[$input['kind']])) {
        return new Aphront400Response();
      }
      try {
        $record = TeamProjectProgress::publish(
          $viewer, $project, $input,
          PhabricatorContentSource::newFromRequest($request));
        return id(new AphrontRedirectResponse())->setURI(
          $uri.'?entry='.$record->getID().'#entry-'.$record->getID());
      } catch (PhabricatorApplicationTransactionValidationException $ex) {
        $errors = $ex->getErrorMessages();
      }
    }

    $kind = TeamProjectProgress::normalizeKind($request->getStr('kind', 'all'));
    if ($request->isHTTPPost()) {
      $kind = 'all';
    }
    if ($kind !== 'all' && !isset($kinds[$kind])) {
      return new Aphront404Response();
    }
    $pager = id(new AphrontCursorPagerView())
      ->readFromRequest($request)
      ->setPageSize(50);
    $entry = $request->getStr('entry', '');
    $query = TeamProjectProgress::newQuery($viewer, $project);
    if (strlen($entry)) {
      if (!preg_match('/\A[1-9][0-9]*\z/', $entry)) {
        return new Aphront404Response();
      }
      $record = $query->withIDs(array($entry))->executeOne();
      if (!$record) {
        return new Aphront404Response();
      }
      $records = array($record);
      $pager = null;
    } else {
      $query = TeamProjectProgress::newQuery(
        $viewer, $project, $kind === 'all' ? null : $kind);
      $records = $query->executeWithCursorPager($pager);
    }

    $header = id(new PHUIHeaderView())
      ->setHeader(pht('Development History'))
      ->setViewer($viewer)
      ->setPolicyObject($project);
    $summary = id(new TeamProjectProgressSummaryView())
      ->setViewer($viewer)
      ->setProject($project)
      ->setShowActions(false);
    $history_header = id(new PHUIHeaderView())->setHeader(pht('Project Records and Comments'));
    $history_actions = new TeamProjectProgressActionsView();
    foreach (array('all' => pht('All Records'), 'node' => pht('Project Records Only'),
        'comment' => pht('Work Comments Only')) as $key => $label) {
      $history_actions->addAction(id(new PHUIButtonView())
        ->setTag('a')
        ->setText($label)
        ->setColor(!$entry && $key === $kind ? PHUIButtonView::GREEN : null)
        ->setHref($uri.($key === 'all' ? '' : '?kind='.$key)));
    }
    $timeline = id(new PHUITimelineView())
      ->setViewer($viewer)
      ->setShouldTerminate(true);
    foreach ($records as $record) {
      $timeline->addEvent($this->newRecordView($record, $project, $uri));
    }
    $history = id(new PHUIObjectBoxView())
      ->setHeader($history_header)
      ->addClass('team-project-progress-history')
      ->appendChild($history_actions)
      ->appendChild($records ? $timeline : phutil_tag('p',
        array('class' => 'phui-box-padding'),
        pht('No project records match this filter. Task comments and status changes remain in the native activity feed.')))
      ->appendChild($pager);
    $form = $can_publish
      ? $this->newPublishForm($input, $errors, $uri)
      : id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
        ->appendChild(pht('Current project editors can publish records when project history is active.'));
    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(pht('Development History'), $uri);
    $crumbs->setBorder(true);
    return $this->newPage()
      ->setNavigation($this->newNavigation(
        $project, PhabricatorProject::ITEM_WORKBOARD))
      ->setCrumbs($crumbs)
      ->setTitle(array(pht('Development History'), $project->getDisplayName()))
      ->setPageObjectPHIDs(array($project->getPHID()))
      ->appendChild(id(new PHUITwoColumnView())
        ->setHeader($header)
        ->setMainColumn(array(
          $summary, $history, $form)));
  }

  private function newRecordView($record, $project, $uri) {
    $viewer = $this->getViewer();
    $value = $record->getNewValue();
    $kind = TeamProjectProgress::normalizeKind($value['kind']);
    $names = TeamProjectProgress::getKindNames();
    $icons = array('node' => 'fa-flag-checkered', 'comment' => 'fa-comment-o');
    $properties = id(new PHUIPropertyListView())->setViewer($viewer);
    $properties->addProperty(pht('Record'), phutil_tag('a',
      array('href' => $uri.'?entry='.$record->getID().
        '#entry-'.$record->getID()), '#'.$record->getID()));
    $properties->addProperty(pht('Participants'), $viewer
      ->renderHandleList($value['participants'])->setAsInline(true));
    if ($kind === 'node' && idx($value, 'version') !== 2) {
      $properties->addProperty(pht('Historical Node Status (When Published)'),
        TeamProjectProgress::getNodeStatusName($record));
    }
    if ($value['percent'] !== null) {
      $properties->addProperty(pht('Reported Project Completion'), $value['percent'].'%');
    }
    $body = id(new PHUIRemarkupView($viewer, $value['body']))
      ->setContextObject($project);
    $record_name = $kind === 'node' && idx($value, 'version') === 2
      ? pht('Project Record') : $names[$kind];
    return id(new PHUITimelineEventView())
      ->setViewer($viewer)
      ->setUserHandle($viewer->loadHandles(array($record->getAuthorPHID()))
        [$record->getAuthorPHID()])
      ->setAuthorPHID($record->getAuthorPHID())
      ->setDateCreated($record->getDateCreated())
      ->setContentSource($record->getContentSource())
      ->setAnchor('entry-'.$record->getID())
      ->setIcon($icons[$kind])
      ->setColor($kind === 'node' && $value['status'] === 'done' ? 'green' : 'blue')
      ->setTitle(pht('%s published %s',
        $viewer->renderHandle($record->getAuthorPHID()), $record_name))
      ->setHideCommentOptions(true)
      ->setIsEditable(false)
      ->setIsRemovable(false)
      ->setCanInteract(false)
      ->addClass('team-project-progress-record')
      ->appendChild(array(
        phutil_tag('h3', array('class' => 'phui-header-header'), $value['title']),
        $properties,
        $body,
      ));
  }

  private function newPublishForm(array $input, array $errors, $uri) {
    $kind = $input['kind'];
    $header = id(new PHUIHeaderView())->setHeader(pht('Publish Record'));
    $compose_actions = new TeamProjectProgressActionsView();
    foreach (array('node' => pht('Write Project Record'), 'comment' => pht('Write Work Comment')) as $key => $label) {
      $compose_actions->addAction(id(new PHUIButtonView())
        ->setTag('a')
        ->setText($label)
        ->setColor($key === $kind ? PHUIButtonView::GREEN : null)
        ->setHref($uri.'?compose='.$key.'#publish'));
    }
    $form = id(new AphrontFormView())
      ->setUser($this->getViewer())
      ->setAction($uri.'?compose='.$kind.'#publish')
      ->addHiddenInput('kind', $kind)
      ->appendControl(id(new AphrontFormTextControl())
        ->setName('title')->setLabel(pht('Title'))->setValue($input['title'])
        ->setError(true)
        ->setCaption(pht('Summarize the result, decision, or discussion in one sentence.')))
      ->appendControl(id(new AphrontFormTextAreaControl())
        ->setName('body')->setLabel(pht('Content'))->setValue($input['body'])
        ->setError(true)
        ->setCaption(pht('Record results, decisions, issues, and next steps. You may reference tasks such as T123. Discuss task details on the task; task links remain subject to their own policies.')));
    if ($kind === 'node') {
      $form->appendControl(id(new AphrontFormTextControl())
        ->setName('percent')->setLabel(pht('Reported Project Completion (%)'))->setValue($input['percent'])
        ->setCaption(pht('Optional manual project assessment (0–100). Leave blank to preserve the current value. This value is not calculated from closed tasks.')));
    }
    $form->appendControl(id(new AphrontFormTokenizerControl())
      ->setName('participants')->setLabel(pht('Participants'))->setValue($input['participants'])
      ->setDatasource(new PhabricatorPeopleDatasource())
      ->setCaption(pht('Choose the users who participated in this work. Leave blank to use the publisher.')));
    $form->appendControl(id(new AphrontFormSubmitControl())
      ->setValue(pht('Publish Record')));
    $box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->addClass('team-project-progress-publish')
      ->appendChild($compose_actions)
      ->appendChild($form);
    if ($errors) {
      $box->setFormErrors($errors);
    }
    return phutil_tag('div', array('id' => 'publish'), array(
      $box,
      phutil_tag('p', array('class' => 'phui-box-padding'),
        pht('Published records are preserved. Append another record to add or correct information. Native tasks retain their own state; work comments do not change reported completion.')),
    ));
  }
}
