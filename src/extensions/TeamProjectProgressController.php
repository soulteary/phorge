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
    $kinds = array('node' => '项目记录', 'comment' => '工作评论');
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
      ->setHeader('开发历史')
      ->setViewer($viewer)
      ->setPolicyObject($project);
    $project_actions = id(new TeamProjectProgressActionsView())
      ->addAction(id(new PHUIButtonView())
        ->setTag('a')
        ->setIcon('fa-rss')
        ->setText('项目全部活动')
        ->setHref('/feed/?projectPHIDs='.$project->getPHID()));
    if (PhabricatorApplication::isClassInstalledForViewer(
        PhabricatorManiphestApplication::class, $viewer)) {
      $project_actions->addAction(id(new PHUIButtonView())
          ->setTag('a')
          ->setIcon('fa-tasks')
          ->setText('任务高级查询')
          ->setHref('/maniphest/?projectPHIDs='.$project->getPHID()));
    }
    $project_actions->addAction(id(new PHUIButtonView())
        ->setTag('a')
        ->setIcon('fa-list-alt')
        ->setText('项目变更历史')
        ->setHref('/project/manage/'.$project->getID().'/'));
    $guide = id(new PHUIInfoView())
      ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
      ->appendChild('可执行的工作统一创建为看板卡片，使用卡片状态和负责人推进。'.
        '这里按时间追加项目成果、决策、关键节点说明与工作评论，不再维护另一套执行状态。'.
        '具体任务的进展请在卡片内评论或更新状态；任务内评论按任务自身权限控制。'.
        '项目完成度仍为手工填报的整体评估。任务和项目记录可在“项目全部活动”中一起查看。');
    $summary = id(new TeamProjectProgressSummaryView())
      ->setViewer($viewer)
      ->setProject($project)
      ->setShowActions(false);
    $history_header = id(new PHUIHeaderView())->setHeader('项目记录与评论');
    $history_actions = new TeamProjectProgressActionsView();
    foreach (array('all' => '全部记录', 'node' => '只看项目记录',
        'comment' => '只看工作评论') as $key => $label) {
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
        '当前筛选暂无项目记录。任务评论和状态变化可在“项目全部活动”中查看。'))
      ->appendChild($pager);
    $form = $can_publish
      ? $this->newPublishForm($input, $errors, $uri)
      : id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
        ->appendChild('具备项目编辑权限的用户可以发布项目记录和工作评论。');
    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb('开发历史', $uri);
    $crumbs->setBorder(true);
    return $this->newPage()
      ->setNavigation($this->newNavigation(
        $project, PhabricatorProject::ITEM_WORKBOARD))
      ->setCrumbs($crumbs)
      ->setTitle(array('开发历史', $project->getDisplayName()))
      ->setPageObjectPHIDs(array($project->getPHID()))
      ->appendChild(id(new PHUITwoColumnView())
        ->setHeader($header)
        ->setMainColumn(array(
          id(new TeamProjectWorkspaceView())->setViewer($viewer)
            ->setProject($project)->setSection('history'),
          $project_actions, $guide, $summary, $history, $form)));
  }

  private function newRecordView($record, $project, $uri) {
    $viewer = $this->getViewer();
    $value = $record->getNewValue();
    $kind = TeamProjectProgress::normalizeKind($value['kind']);
    $names = TeamProjectProgress::getKindNames();
    $icons = array('node' => 'fa-flag-checkered', 'comment' => 'fa-comment-o');
    $properties = id(new PHUIPropertyListView())->setViewer($viewer);
    $properties->addProperty('记录', phutil_tag('a',
      array('href' => $uri.'?entry='.$record->getID().
        '#entry-'.$record->getID()), '#'.$record->getID()));
    $properties->addProperty('参与者', $viewer
      ->renderHandleList($value['participants'])->setAsInline(true));
    if ($kind === 'node' && idx($value, 'version') !== 2) {
      $properties->addProperty('历史节点状态（发布时）',
        TeamProjectProgress::getNodeStatusName($record));
    }
    if ($value['percent'] !== null) {
      $properties->addProperty('项目完成度', $value['percent'].'%');
    }
    $body = id(new PHUIRemarkupView($viewer, $value['body']))
      ->setContextObject($project);
    $card_action = null;
    if ($kind === 'node' && TeamProjectProgress::canPublish($viewer, $project) &&
        PhabricatorApplication::isClassInstalledForViewer(
          PhabricatorManiphestApplication::class, $viewer)) {
      $card_action = id(new TeamProjectProgressActionsView())
        ->addAction(id(new PHUIButtonView())->setTag('a')->setIcon('fa-plus')
          ->setText('从此记录新建卡片')
          ->setHref('/maniphest/task/edit/?projectNode='.$record->getID()));
    }
    $record_name = $kind === 'node' && idx($value, 'version') === 2
      ? '项目记录' : $names[$kind];
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
      ->setTitle(array($viewer->renderHandle($record->getAuthorPHID()),
        ' 发布了'.$record_name))
      ->setHideCommentOptions(true)
      ->setIsEditable(false)
      ->setIsRemovable(false)
      ->setCanInteract(false)
      ->addClass('team-project-progress-record')
      ->appendChild(array(
        phutil_tag('h3', array('class' => 'phui-header-header'), $value['title']),
        $properties,
        $body,
        $card_action,
      ));
  }

  private function newPublishForm(array $input, array $errors, $uri) {
    $kind = $input['kind'];
    $header = id(new PHUIHeaderView())->setHeader('发布记录');
    $compose_actions = new TeamProjectProgressActionsView();
    foreach (array('node' => '写项目记录', 'comment' => '写工作评论') as $key => $label) {
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
        ->setName('title')->setLabel('标题')->setValue($input['title'])
        ->setError(true)
        ->setCaption('用一句话概括阶段成果、关键决策或讨论主题。'))
      ->appendControl(id(new AphrontFormTextAreaControl())
        ->setName('body')->setLabel('内容')->setValue($input['body'])
        ->setError(true)
        ->setCaption('记录项目总结、结果、问题和下一步计划；'.
          '可引用任务编号（如 T123）。任务细节请在对应任务内讨论，'.
          '任务链接仍按任务本身的权限查看。'));
    if ($kind === 'node') {
      $form->appendControl(id(new AphrontFormTextControl())
        ->setName('percent')->setLabel('项目完成度 (%)')->setValue($input['percent'])
        ->setCaption('可选，手工填报项目整体完成度（0–100）；留空保留当前值。'.
          '此数值不会根据任务关闭数量自动计算。'));
    }
    $form->appendControl(id(new AphrontFormTokenizerControl())
      ->setName('participants')->setLabel('参与者')->setValue($input['participants'])
      ->setDatasource(new PhabricatorPeopleDatasource())
      ->setCaption('选择参与本次工作的成员；留空默认为发布者。'));
    $form->appendControl(id(new AphrontFormSubmitControl())
      ->setValue('发布记录'));
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
        '记录发布后保留，补充或更正请追加记录。工作卡片的状态在看板维护；工作评论不改变项目完成度。'),
    ));
  }
}
