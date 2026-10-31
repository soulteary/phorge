<?php

final class PhabricatorProjectHierarchyStorageTestCase
  extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testRootPaginationIncludesArchivedRootsWithoutChildren() {
    $actor = $this->generateNewTestUser();
    $roots = array();
    $all = array();
    foreach (range(1, 5) as $number) {
      $root = $this->newProject($actor, null, 'Root '.$number);
      if ($number === 2) {
        $root->setStatus(PhabricatorProjectStatus::STATUS_ARCHIVED)->save();
      }
      $roots[] = $root;
      $all[] = $root;
    }
    foreach (range(1, 4) as $number) {
      $all[] = $this->newProject($actor, $roots[0], 'Child '.$number);
    }
    $engine = id(new PhabricatorProjectSearchEngine())->setViewer($actor);
    $saved = $engine->buildSavedQueryFromBuiltin('hierarchy');
    $found = array();
    $cursor = null;
    do {
      $pager = id(new AphrontCursorPagerView())->setPageSize(2);
      if ($cursor !== null) {
        $pager->setAfterID($cursor);
      }
      $query = $engine->buildQueryFromSavedQuery($saved)
        ->setViewer($actor)->withPHIDs(mpull($all, 'getPHID'));
      $page = $query->executeWithCursorPager($pager);
      $this->assertTrue(count($page) <= 2);
      $found = array_merge($found, array_values(mpull($page, 'getPHID')));
      $cursor = $pager->getNextPageID();
    } while ($cursor);
    $this->assertEqual(mpull($roots, 'getPHID'), $found);
    $this->assertEqual(count($found), count(array_unique($found)));
  }

  public function testDefaultModeDoesNotChangeNativeAncestorPermissions() {
    $actor = $this->generateNewTestUser();
    $viewer = $this->generateNewTestUser();
    $root = $this->newProject($actor);
    $child = $this->newProject($actor, $root);
    $root->setViewPolicy($actor->getPHID())->save();
    $env = PhabricatorEnv::beginScopedEnv();
    foreach (array('flat', 'hierarchy', 'flat') as $mode) {
      $env->overrideEnvConfig('projects.default-navigation', $mode);
      $visible = id(new PhabricatorProjectQuery())->setViewer($viewer)
        ->withPHIDs(array($child->getPHID()))->execute();
      $this->assertEqual(array(), $visible);
      $engine = id(new PhabricatorProjectSearchEngine())->setViewer($actor);
      $all = $engine->buildSavedQueryFromBuiltin('all');
      $results = $engine->buildQueryFromSavedQuery($all)->setViewer($actor)
        ->withPHIDs(array($root->getPHID(), $child->getPHID()))->execute();
      $this->assertEqual(2, count($results));
    }
    unset($env);
  }

  public function testAnonymousHierarchyUsesNativePublicVisibilitySetting() {
    $actor = $this->generateNewTestUser();
    $root = $this->newProject($actor);
    $child = $this->newProject($actor, $root);
    $root->setViewPolicy(PhabricatorPolicies::POLICY_PUBLIC)->save();
    $child->setViewPolicy(PhabricatorPolicies::POLICY_PUBLIC)->save();
    $viewer = new PhabricatorUser();
    $env = PhabricatorEnv::beginScopedEnv();
    foreach (array(false, true, false) as $allow_public) {
      $env->overrideEnvConfig('policy.allow-public', $allow_public);
      // Public-visibility configuration changes between simulated requests.
      PhabricatorCaches::destroyRequestCache();
      $results = id(new PhabricatorProjectQuery())->setViewer($viewer)
        ->withParentProjectPHIDs(array($root->getPHID()))->execute();
      $this->assertEqual($allow_public ? 1 : 0, count($results));
    }
    unset($env);
    PhabricatorCaches::destroyRequestCache();
  }

  public function testHiddenHierarchyConstraintsAndQueryReuseStayPrivate() {
    $actor = $this->generateNewTestUser();
    $viewer = $this->generateNewTestUser();
    $root = $this->newProject($actor);
    $child = $this->newProject($actor, $root);
    $root->setViewPolicy($actor->getPHID())->save();
    foreach (array('parents', 'ancestors') as $constraint) {
      $result = id(new ConduitCall('project.search', array(
        'constraints' => array($constraint => array($root->getPHID())),
        'attachments' => array('ancestors' => true),
      )))->setUser($viewer)->execute();
      $this->assertEqual(array(), $result['data']);
    }
    $query = id(new PhabricatorProjectQuery())
      ->withParentProjectPHIDs(array($root->getPHID()));
    foreach (array($viewer, $actor, $viewer) as $user) {
      $results = $query->setViewer($user)->execute();
      $this->assertEqual($user === $actor ? 1 : 0, count($results));
    }
  }

  public function testOriginalAncestorTokensAreCheckedBeforeSimplification() {
    $actor = $this->generateNewTestUser();
    $viewer = $this->generateNewTestUser();
    $grand = $this->newProject($actor);
    $parent = $this->newProject($actor, $grand);
    $child = $this->newProject($actor, $parent);
    $parent->setViewPolicy($actor->getPHID())->save();
    $source = id(new PhabricatorProjectLogicalAncestorDatasource())
      ->setViewer($viewer);
    $method = new ReflectionMethod($source, 'didEvaluateTokens');
    $result = $method->invoke($source, array(
      $grand->getPHID(), $parent->getPHID(), $child->getPHID(),
    ));
    $this->assertEqual(1, count($result));
    $this->assertEqual(
      PhabricatorQueryConstraint::OPERATOR_EMPTY, head($result)->getOperator());
  }

  public function testAttachedAncestorNamesAreFilteredInListsAndConduit() {
    $actor = $this->generateNewTestUser();
    $viewer = $this->generateNewTestUser();
    $grand = $this->newProject($actor);
    $parent = $this->newProject($actor, $grand);
    $child = $this->newProject($actor, $parent);
    $parent->setViewPolicy($actor->getPHID())->save();
    // Ancestors are attached by privileged queries for policy evaluation.
    // Presentation must not assume that their presence authorizes disclosure.
    $raw = id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($child->getPHID()))->needImages(true)
      ->needMembers(true)->needAncestorMembers(true)->executeOne();
    $list = id(new PhabricatorProjectListView())->setViewer($viewer)
      ->setProjects(array($raw))->setShowHierarchyPath(true)
      ->setBrowseHierarchy(true);
    $html = (string)hsprintf('%s', $list);
    $this->assertFalse(strpos($html, $parent->getName()) !== false);
    $this->assertFalse(strpos($html, $parent->getPHID()) !== false);
    $this->assertTrue(strpos($html, 'href="/project/view/'.$child->getID().'/"') !== false);
    $this->assertTrue(strpos($html, 'href="/project/subprojects/'.$child->getID().'/"') !== false);
    $attachment = id(new PhabricatorProjectsAncestorsSearchEngineAttachment())
      ->setViewer($viewer)->getAttachmentForObject($raw, array(), array());
    $this->assertEqual(array($grand->getRefForConduit()),
      $attachment['ancestors']);
    $engine = id(new PhabricatorProjectSearchEngine())->setViewer($viewer);
    $method = new ReflectionMethod($engine, 'getObjectWireFieldsForConduit');
    $fields = $method->invoke($engine, $raw, array(), array());
    $this->assertTrue(array_key_exists('parent', $fields));
    $this->assertEqual(null, $fields['parent']);
  }

  public function testParentNavigationUsesDirectParentOrRootAndCanBeDisabled() {
    $actor = $this->generateNewTestUser();
    $viewer = $this->generateNewTestUser();
    $grand = $this->newProject($actor);
    $parent = $this->newProject($actor, $grand);
    $child = $this->newProject($actor, $parent);
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('projects.default-navigation', 'hierarchy');
    $raw = $this->reloadForNavigation($child);
    $crumbs = $this->newCrumbs(new PhabricatorProjectProfileController(),
      $raw, $viewer);
    $this->assertEqual('/project/subprojects/'.$parent->getID().'/',
      head($crumbs->getActions())->getHref());
    $parent->setViewPolicy($actor->getPHID())->save();
    $raw = $this->reloadForNavigation($child);
    $crumbs = $this->newCrumbs(new PhabricatorProjectProfileController(),
      $raw, $viewer);
    $this->assertEqual('/project/query/hierarchy/',
      head($crumbs->getActions())->getHref());
    $html = (string)hsprintf('%s', $crumbs);
    $this->assertFalse(strpos($html, $parent->getName()) !== false);
    $this->assertFalse(strpos($html, $parent->getPHID()) !== false);
    $env->overrideEnvConfig('projects.default-navigation', 'flat');
    $crumbs = $this->newCrumbs(new PhabricatorProjectProfileController(),
      $raw, $viewer);
    $this->assertEqual(array(), $crumbs->getActions());
    unset($env);
  }

  public function testCreationContextReturnsToHeldParentWithoutSkippingLevel() {
    $actor = $this->generateNewTestUser();
    $grand = $this->newProject($actor);
    $parent = $this->newProject($actor, $grand);
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('projects.default-navigation', 'hierarchy');
    $raw = $this->reloadForNavigation($parent);
    foreach (array('setParentProject', 'setMilestoneProject') as $setter) {
      $engine = new PhabricatorProjectEditEngine();
      $engine->$setter($raw);
      $controller = id(new PhabricatorProjectEditController())->setEngine($engine);
      $crumbs = $this->newCrumbs($controller, $raw, $actor);
      $this->assertEqual('/project/subprojects/'.$parent->getID().'/',
        head($crumbs->getActions())->getHref());
    }
    unset($env);
  }

  public function testCommentContextUsesViewBeforeNativeMethodValidation() {
    $actor = $this->generateNewTestUser();
    $viewer = $this->generateNewTestUser();
    $project = $this->newProject($actor);
    $project->setEditPolicy($actor->getPHID())->save();
    $request = id(new AphrontRequest('phabricator.example.com',
      '/project/comment/'.$project->getID().'/'))
      ->setUser($viewer)->setRequestData(array())->setURIMap(array(
        'id' => $project->getID(), 'editAction' => 'comment',
      ));
    $old_method = idx($_SERVER, 'REQUEST_METHOD');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    try {
      $controller = id(new PhabricatorProjectEditController())
        ->setRequest($request)
        ->setCurrentApplication(new PhabricatorProjectApplication());
      $response = $controller->handleRequest($request);
      // The native comment action requires POST. Reaching its method gate
      // proves that navigation context did not add a project-edit requirement.
      $this->assertTrue($response instanceof Aphront400Response);
      $method = new ReflectionMethod(PhabricatorProjectController::class,
        'getProject');
      $loaded = $method->invoke($controller);
      $this->assertEqual($project->getPHID(), $loaded->getPHID());
      $this->assertFalse(PhabricatorPolicyFilter::hasCapability(
        $viewer, $loaded, PhabricatorPolicyCapability::CAN_EDIT));
    } finally {
      if ($old_method === null) {
        unset($_SERVER['REQUEST_METHOD']);
      } else {
        $_SERVER['REQUEST_METHOD'] = $old_method;
      }
    }
  }

  public function testExistingEditBreadcrumbContainsCurrentProjectOnlyOnce() {
    $actor = $this->generateNewTestUser();
    $root = $this->newProject($actor);
    $project = $this->newProject($actor, $root);
    $request = id(new AphrontRequest('phabricator.example.com',
      '/project/edit/'.$project->getID().'/'))
      ->setUser($actor)->setRequestData(array())
      ->setURIMap(array('id' => $project->getID()));
    $old_method = idx($_SERVER, 'REQUEST_METHOD');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    try {
      $response = id(new PhabricatorProjectEditController())
        ->setRequest($request)
        ->setCurrentApplication(new PhabricatorProjectApplication())
        ->handleRequest($request);
      $this->assertTrue($response instanceof PhabricatorStandardPageView);
      $html = (string)hsprintf('%s', $response->getCrumbs());
      $this->assertEqual(1, substr_count($html, $project->getName()));
      $this->assertTrue(strpos($html, $root->getName()) !== false);
    } finally {
      if ($old_method === null) {
        unset($_SERVER['REQUEST_METHOD']);
      } else {
        $_SERVER['REQUEST_METHOD'] = $old_method;
      }
    }
  }

  private function reloadForNavigation(PhabricatorProject $project) {
    return id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($project->getPHID()))->needMembers(true)->executeOne();
  }

  private function newCrumbs($controller, $project, $viewer) {
    $request = id(new AphrontRequest('phabricator.example.com', '/project/'))
      ->setUser($viewer);
    $controller->setRequest($request)
      ->setCurrentApplication(new PhabricatorProjectApplication());
    $method = new ReflectionMethod(PhabricatorProjectController::class,
      'setProject');
    $method->invoke($controller, $project);
    $method = new ReflectionMethod(PhabricatorProjectController::class,
      'newApplicationCrumbs');
    return $method->invoke($controller, 'profile');
  }

  private function newProject(
    PhabricatorUser $actor,
    ?PhabricatorProject $parent = null,
    $label = 'Navigation Project') {

    $project = PhabricatorProject::initializeNewProject($actor, $parent);
    $transactions = array(id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
      ->setNewValue($label.' '.$this->getNextObjectSeed()));
    if ($parent) {
      $transactions[] = id(new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorProjectParentTransaction::TRANSACTIONTYPE)
        ->setNewValue($parent->getPHID());
    }
    id(new PhabricatorProjectTransactionEditor())->setActor($actor)
      ->setContentSource($this->newContentSource())
      ->setContinueOnMissingFields(true)->setContinueOnNoEffect(true)
      ->applyTransactions($project, $transactions);
    $project->setViewPolicy(PhabricatorPolicies::POLICY_USER)
      ->setEditPolicy($actor->getPHID())->save();
    if ($parent) {
      $parent->setHasSubprojects(1)->save();
    }
    return $project;
  }

}
