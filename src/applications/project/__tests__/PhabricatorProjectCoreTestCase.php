<?php

final class PhabricatorProjectCoreTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testViewProject() {
    $user = $this->createUser();
    $user->save();

    $user2 = $this->createUser();
    $user2->save();

    $proj = $this->createProject($user);

    $proj = $this->refreshProject($proj, $user, true);

    $this->joinProject($proj, $user);
    $proj->setViewPolicy(PhabricatorPolicies::POLICY_USER);
    $proj->save();

    $can_view = PhabricatorPolicyCapability::CAN_VIEW;

    // When the view policy is set to "users", any user can see the project.
    $this->assertTrue((bool)$this->refreshProject($proj, $user));
    $this->assertTrue((bool)$this->refreshProject($proj, $user2));


    // When the view policy is set to "no one", members can still see the
    // project.
    $proj->setViewPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->save();

    $this->assertTrue((bool)$this->refreshProject($proj, $user));
    $this->assertFalse((bool)$this->refreshProject($proj, $user2));
  }

  public function testApplicationPolicy() {
    $user = $this->createUser()
      ->save();

    $proj = $this->createProject($user);

    $this->assertTrue(
      PhabricatorPolicyFilter::hasCapability(
        $user,
        $proj,
        PhabricatorPolicyCapability::CAN_VIEW));

    // This object is visible so its handle should load normally.
    $handle = id(new PhabricatorHandleQuery())
      ->setViewer($user)
      ->withPHIDs(array($proj->getPHID()))
      ->executeOne();
    $this->assertEqual($proj->getPHID(), $handle->getPHID());

    // Change the "Can Use Application" policy for Projecs to "No One". This
    // should cause filtering checks to fail even when they are executed
    // directly rather than via a Query.
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig(
      'phabricator.application-settings',
      array(
        'PHID-APPS-PhabricatorProjectApplication' => array(
          'policy' => array(
            'view' => PhabricatorPolicies::POLICY_NOONE,
          ),
        ),
      ));

    // Application visibility is cached because it does not normally change
    // over the course of a single request. Drop the cache so the next filter
    // test uses the new visibility.
    PhabricatorCaches::destroyRequestCache();

    $this->assertFalse(
      PhabricatorPolicyFilter::hasCapability(
        $user,
        $proj,
        PhabricatorPolicyCapability::CAN_VIEW));

    // We should still be able to load a handle for the project, even if we
    // can not see the application.
    $handle = id(new PhabricatorHandleQuery())
      ->setViewer($user)
      ->withPHIDs(array($proj->getPHID()))
      ->executeOne();

    // The handle should load...
    $this->assertEqual($proj->getPHID(), $handle->getPHID());

    // ...but be policy filtered.
    $this->assertTrue($handle->getPolicyFiltered());

    unset($env);
  }

  public function testIsViewerMemberOrWatcher() {
    $user1 = $this->createUser()
      ->save();

    $user2 = $this->createUser()
      ->save();

    $user3 = $this->createUser()
      ->save();

    $proj1 = $this->createProject($user1);
    $proj1 = $this->refreshProject($proj1, $user1);

    $this->joinProject($proj1, $user1);
    $this->joinProject($proj1, $user3);
    $this->watchProject($proj1, $user3);

    $proj1 = $this->refreshProject($proj1, $user1);

    $this->assertTrue($proj1->isUserMember($user1->getPHID()));

    $proj1 = $this->refreshProject($proj1, $user1, false, true);

    $this->assertTrue($proj1->isUserMember($user1->getPHID()));
    $this->assertFalse($proj1->isUserWatcher($user1->getPHID()));

    $proj1 = $this->refreshProject($proj1, $user1, true, false);

    $this->assertTrue($proj1->isUserMember($user1->getPHID()));
    $this->assertFalse($proj1->isUserMember($user2->getPHID()));
    $this->assertTrue($proj1->isUserMember($user3->getPHID()));

    $proj1 = $this->refreshProject($proj1, $user1, true, true);

    $this->assertTrue($proj1->isUserMember($user1->getPHID()));
    $this->assertFalse($proj1->isUserMember($user2->getPHID()));
    $this->assertTrue($proj1->isUserMember($user3->getPHID()));

    $this->assertFalse($proj1->isUserWatcher($user1->getPHID()));
    $this->assertFalse($proj1->isUserWatcher($user2->getPHID()));
    $this->assertTrue($proj1->isUserWatcher($user3->getPHID()));
  }

  public function testEditProject() {
    $user = $this->createUser();
    $user->save();

    $user->setAllowInlineCacheGeneration(true);

    $proj = $this->createProject($user);


    // When edit and view policies are set to "user", anyone can edit.
    $proj->setViewPolicy(PhabricatorPolicies::POLICY_USER);
    $proj->setEditPolicy(PhabricatorPolicies::POLICY_USER);
    $proj->save();

    $this->assertTrue($this->attemptProjectEdit($proj, $user));


    // When edit policy is set to "no one", no one can edit.
    $proj->setEditPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->save();

    $caught = null;
    try {
      $this->attemptProjectEdit($proj, $user);
    } catch (Exception $ex) {
      $caught = $ex;
    }
    $this->assertTrue($caught instanceof Exception);
  }

  public function testAncestorMembers() {
    $user1 = $this->createUser();
    $user1->save();

    $user2 = $this->createUser();
    $user2->save();

    $parent = $this->createProject($user1);
    $child = $this->createProject($user1, $parent);

    $this->joinProject($child, $user1);
    $this->joinProject($child, $user2);

    $project = id(new PhabricatorProjectQuery())
      ->setViewer($user1)
      ->withPHIDs(array($child->getPHID()))
      ->needAncestorMembers(true)
      ->executeOne();

    $members = array_fuse($project->getParentProject()->getMemberPHIDs());
    ksort($members);

    $this->assertEqual(array(), $members);
    $this->assertEqual(2, count($project->getMemberPHIDs()));
  }

  public function testSubprojectEditPolicy() {
    list($parent, $child, $parent_editor, $child_editor, $other) =
      $this->newSubprojectEditFixture();

    $can_edit = PhabricatorPolicyCapability::CAN_EDIT;
    $can_view = PhabricatorPolicyCapability::CAN_VIEW;
    $cases = array(
      array($parent_editor, false),
      array($child_editor, true),
      array($other, false),
    );

    foreach (array(false, true) as $need_members) {
      foreach ($cases as $case) {
        list($viewer, $expect) = $case;
        $project = $this->refreshProject($child, $viewer, $need_members);

        $this->assertTrue(
          PhabricatorPolicyFilter::hasCapability($viewer, $project, $can_view));
        $this->assertEqual(
          $expect,
          PhabricatorPolicyFilter::hasCapability($viewer, $project, $can_edit),
          $viewer->getUsername());
      }
    }

    $parent = $this->refreshProject($parent, $child_editor, true);
    $this->assertFalse($parent->isUserMember($child_editor->getPHID()));
    $this->assertTrue($parent->isUserMember($parent_editor->getPHID()));
    $this->assertTrue(
      PhabricatorPolicyFilter::hasCapability(
        $parent_editor,
        $parent,
        $can_edit));
    $this->assertFalse(
      PhabricatorPolicyFilter::hasCapability(
        $child_editor,
        $parent,
        $can_edit));
  }

  public function testSubprojectEditForm() {
    list($parent, $child, $parent_editor, $child_editor, $other) =
      $this->newSubprojectEditFixture();

    $root = dirname(phutil_get_library_root('phabricator'));
    require_once $root.'/support/startup/PhabricatorStartup.php';

    $old_method = idx($_SERVER, 'REQUEST_METHOD');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    try {
      foreach (array($parent_editor, $child_editor, $other) as $viewer) {
        $request = id(new AphrontRequest(
          'phabricator.example.com',
          '/project/edit/'.$child->getID().'/'))
          ->setApplicationConfiguration(new AphrontApplicationConfiguration())
          ->setURIMap(array('id' => $child->getID()))
          ->setRequestData(array())
          ->setUser($viewer);

        $controller = id(new PhabricatorProjectEditController())
          ->setRequest($request)
          ->setCurrentApplication(new PhabricatorProjectApplication());

        $response = null;
        $denied = false;
        try {
          $response = $controller->handleRequest($request);
          $denied = ($response instanceof Aphront404Response);
        } catch (PhabricatorPolicyException $ex) {
          $denied = true;
        }

        $expect = ($viewer === $child_editor);
        $this->assertEqual(!$expect, $denied, $viewer->getUsername());
        if ($expect) {
          $this->assertTrue($response instanceof PhabricatorStandardPageView);
        }
      }
    } finally {
      if ($old_method === null) {
        unset($_SERVER['REQUEST_METHOD']);
      } else {
        $_SERVER['REQUEST_METHOD'] = $old_method;
      }
    }
  }

  public function testSubprojectEditTransactions() {
    list($parent, $child, $parent_editor, $child_editor, $other) =
      $this->newSubprojectEditFixture();

    foreach (array(false, true) as $via_conduit) {
      foreach (array($parent_editor, $child_editor, $other) as $viewer) {
        $project = $this->refreshProject($child, $viewer, true);
        $old_name = $project->getName();
        $new_name = $old_name.' edited';
        $old_xactions = id(new PhabricatorProjectTransactionQuery())
          ->setViewer($viewer)
          ->withObjectPHIDs(array($project->getPHID()))
          ->execute();

        $denied = false;
        try {
          if ($via_conduit) {
            id(new ConduitCall('project.edit', array(
              'objectIdentifier' => $project->getPHID(),
              'transactions' => array(
                array('type' => 'name', 'value' => $new_name),
              ),
            )))
              ->setUser($viewer)
              ->execute();
          } else {
            $xaction = id(new PhabricatorProjectTransaction())
              ->setTransactionType(
                PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
              ->setNewValue($new_name);
            $this->applyTransactions($project, $viewer, array($xaction));
          }
        } catch (PhabricatorPolicyException $ex) {
          $denied = true;
        }

        $expect = ($viewer === $child_editor);
        $this->assertEqual(!$expect, $denied, $viewer->getUsername());
        $project = $this->refreshProject($child, $viewer, true);
        $this->assertEqual(
          $expect ? $new_name : $old_name,
          $project->getName());

        if (!$expect) {
          $new_xactions = id(new PhabricatorProjectTransactionQuery())
            ->setViewer($viewer)
            ->withObjectPHIDs(array($project->getPHID()))
            ->execute();
          $this->assertEqual(
            array_keys($old_xactions),
            array_keys($new_xactions));
        }
      }
    }
  }

  public function testSubprojectMilestoneEditPolicy() {
    list($parent, $child, $parent_editor, $child_editor, $other) =
      $this->newSubprojectEditFixture();

    $parent_milestone = $this->createProject($parent_editor, $parent, true);
    $child_milestone = $this->createProject($child_editor, $child, true);
    $can_edit = PhabricatorPolicyCapability::CAN_EDIT;

    foreach (array($parent_editor, $child_editor, $other) as $viewer) {
      $milestone = $this->refreshProject($parent_milestone, $viewer, true);
      $this->assertEqual(
        $viewer === $parent_editor,
        PhabricatorPolicyFilter::hasCapability($viewer, $milestone, $can_edit));

      $milestone = $this->refreshProject($child_milestone, $viewer, true);
      $this->assertEqual(
        $viewer === $child_editor,
        PhabricatorPolicyFilter::hasCapability($viewer, $milestone, $can_edit));
    }
  }

  public function testIndependentMembershipGraph() {
    $alice = $this->createUser()->save();
    $bob = $this->createUser()->save();
    $charlie = $this->createUser()->save();
    $users = array($alice, $bob, $charlie);

    $parent = $this->createProject($alice);
    $this->joinProject($parent, $alice);
    $child = $this->createProject($alice, $parent);
    $sibling = $this->createProject($alice, $parent);
    $this->assertProjectMembers($parent, array($alice), $users);
    $this->assertProjectMembers($child, array(), $users);
    $this->assertProjectMembers($sibling, array(), $users);

    $this->joinProject($child, $bob);
    $grandchild = $this->createProject($alice, $child);
    $this->joinProject($grandchild, $charlie);
    $milestone = $this->createProject($alice, $parent, true);
    $child_milestone = $this->createProject($alice, $child, true);

    $this->assertProjectMembers($parent, array($alice), $users);
    $this->assertProjectMembers($child, array($bob), $users);
    $this->assertProjectMembers($sibling, array(), $users);
    $this->assertProjectMembers($grandchild, array($charlie), $users);
    $this->assertProjectMembers($milestone, array($alice), $users);
    $this->assertProjectMembers($child_milestone, array($bob), $users);

    // Repeat the same request, then remove the member. Only the child's
    // own index and its direct milestone index may change.
    foreach (array('members.add', 'members.add', 'members.remove') as $type) {
      $this->editProjectViaConduit($child, $alice, array(
        array('type' => $type, 'value' => array($bob->getPHID())),
      ));
    }
    $this->assertProjectMembers($child, array(), $users);
    $this->assertProjectMembers($child_milestone, array(), $users);
    $this->assertProjectMembers($parent, array($alice), $users);
    $this->assertProjectMembers($milestone, array($alice), $users);
    $this->assertProjectMembers($grandchild, array($charlie), $users);
    $this->assertProjectMembers($sibling, array(), $users);
  }

  public function testParentMembershipManagement() {
    $alice = $this->createUser()->save();
    $bob = $this->createUser()->save();
    $charlie = $this->createUser()->save();
    $users = array($alice, $bob, $charlie);
    $parent = $this->createProject($alice);
    $child = $this->createProject($alice, $parent);
    $parent->setEditPolicy($alice->getPHID())->save();
    $child->setEditPolicy($bob->getPHID())->save();

    foreach (array('GET', 'POST') as $method) {
      $response = $this->requestProjectController(
        new PhabricatorProjectMembersAddController(),
        $parent, $alice, $method,
        array('memberPHIDs' => array($charlie->getPHID())));
      if ($method === 'GET') {
        $this->assertTrue($response instanceof AphrontDialogView);
      } else {
        $this->assertTrue($response instanceof AphrontRedirectResponse);
      }
    }
    $this->assertProjectMembers($parent, array($charlie), $users);
    $this->assertProjectMembers($child, array(), $users);

    // Neither editor can manage the other project's members through POST.
    foreach (array(array($parent, $bob), array($child, $alice)) as $case) {
      list($project, $actor) = $case;
      $denied = false;
      try {
        $response = $this->requestProjectController(
          new PhabricatorProjectMembersAddController(),
          $project, $actor, 'POST',
          array('memberPHIDs' => array($actor->getPHID())));
        $denied = ($response instanceof Aphront404Response);
      } catch (PhabricatorPolicyException $ex) {
        $denied = true;
      }
      $this->assertTrue($denied);
    }

    $this->editProjectViaConduit($parent, $alice, array(
      array('type' => 'members.set', 'value' => array($alice->getPHID())),
    ));
    $this->assertProjectMembers($parent, array($alice), $users);
    $this->editProjectViaConduit($parent, $alice, array(
      array('type' => 'members.remove', 'value' => array($alice->getPHID())),
    ));
    $this->assertProjectMembers($parent, array(), $users);
    $this->assertProjectMembers($child, array(), $users);
  }

  public function testIndependentViewEditJoinPolicies() {
    $alice = $this->createUser()->save();
    $bob = $this->createUser()->save();
    $other = $this->createUser()->save();
    $parent = $this->createProject($alice);
    $child = $this->createProject($alice, $parent);
    $milestone = $this->createProject($alice, $child, true);
    foreach (array(array($parent, $alice), array($child, $bob)) as $case) {
      list($project, $editor) = $case;
      $project
        ->setViewPolicy($editor->getPHID())
        ->setEditPolicy($editor->getPHID())
        ->setJoinPolicy($editor->getPHID())
        ->save();
    }

    foreach (array($alice, $bob, $other) as $viewer) {
      foreach (array($parent, $child, $milestone) as $project) {
        $visible = ($project === $parent) ? ($viewer === $alice) :
          ($viewer === $bob);
        $loaded = $this->refreshProject($project, $viewer, true);
        $this->assertEqual($visible, (bool)$loaded);
        if ($loaded) {
          $capabilities = array(
            PhabricatorPolicyCapability::CAN_VIEW,
            PhabricatorPolicyCapability::CAN_EDIT,
            PhabricatorPolicyCapability::CAN_JOIN,
          );
          foreach ($capabilities as $capability) {
            $this->assertTrue(PhabricatorPolicyFilter::hasCapability(
              $viewer, $loaded, $capability));
          }
        }
      }
    }

    // When both projects are visible, join and edit remain independent.
    $parent->setViewPolicy(PhabricatorPolicies::POLICY_USER)->save();
    $child->setViewPolicy(PhabricatorPolicies::POLICY_USER)->save();
    foreach (array($alice, $bob, $other) as $viewer) {
      foreach (array($parent, $child, $milestone) as $project) {
        $expect = ($project === $parent) ? ($viewer === $alice) :
          ($viewer === $bob);
        $loaded = $this->refreshProject($project, $viewer);
        foreach (array(PhabricatorPolicyCapability::CAN_EDIT,
                       PhabricatorPolicyCapability::CAN_JOIN) as $capability) {
          $this->assertEqual($expect, PhabricatorPolicyFilter::hasCapability(
            $viewer, $loaded, $capability));
        }
      }
    }
  }

  public function testMilestoneMemberPolicyAndRevocation() {
    $alice = $this->createUser()->save();
    $bob = $this->createUser()->save();
    $parent = $this->createProject($alice);
    $child = $this->createProject($alice, $parent);
    $this->joinProject($child, $bob);
    $this->joinProject($parent, $alice);
    $rule = new PhabricatorProjectMembersPolicyRule();
    $child->setEditPolicy($rule->getObjectPolicyFullKey())->save();
    $milestone = $this->createProject($bob, $child, true);

    foreach (array(true, false, true) as $is_member) {
      if (!$is_member) {
        // The last editor must not leave a live project. Retain the query
        // regression against an empty historical fixture independently.
        $rejected = false;
        try {
          $this->leaveProject($child, $bob);
        } catch (PhabricatorApplicationTransactionValidationException $ex) {
          $rejected = true;
        }
        $this->assertTrue($rejected);
        id(new PhabricatorEdgeEditor())
          ->removeEdge($child->getPHID(),
            PhabricatorProjectProjectHasMemberEdgeType::EDGECONST,
            $bob->getPHID())->save();
        id(new PhabricatorProjectsMembershipIndexEngineExtension())
          ->rematerialize($child);
      } else if (!$this->refreshProject($child, $bob)->isUserMember(
        $bob->getPHID())) {
        // An editor is needed to add someone else; restore the fixture edge
        // directly here, then exercise the real index extension.
        id(new PhabricatorEdgeEditor())
          ->addEdge($child->getPHID(),
            PhabricatorProjectProjectHasMemberEdgeType::EDGECONST,
            $bob->getPHID())
          ->save();
        id(new PhabricatorProjectsMembershipIndexEngineExtension())
          ->rematerialize($child);
      }

      foreach (array($child, $milestone) as $project) {
        $loaded = $this->refreshProject($project, $bob, true);
        $this->assertEqual($is_member, PhabricatorPolicyFilter::hasCapability(
          $bob, $loaded, PhabricatorPolicyCapability::CAN_EDIT));
        $rule->willApplyRules($bob, array(null), array($loaded));
        $this->assertEqual($is_member, $rule->applyRule($bob, null, $loaded));
        $this->assertProjectMembers(
          $project, $is_member ? array($bob) : array(), array($alice, $bob));
      }
    }
    $this->assertProjectMembers($parent, array($alice), array($alice, $bob));
    $this->assertCannotJoinProject($milestone, $bob,
      'Milestone members must be changed on its immediate parent.');
  }

  public function testHiddenProjectAncestors() {
    $alice = $this->createUser()->save();
    $bob = $this->createUser()->save();
    $grandparent = $this->createProject($alice);
    $parent = $this->createProject($alice, $grandparent);
    $child = $this->createProject($alice, $parent);
    $milestone = $this->createProject($alice, $parent, true);
    $parent->setViewPolicy($alice->getPHID())->save();

    $this->assertFalse((bool)$this->refreshProject($parent, $bob));
    $this->assertFalse((bool)$this->refreshProject($milestone, $bob));
    $this->assertTrue((bool)$this->refreshProject($child, $bob));

    foreach (array($alice, $bob) as $viewer) {
      $result = id(new ConduitCall('project.search', array(
        'constraints' => array('ids' => array((int)$child->getID())),
        'attachments' => array('ancestors' => true),
      )))
        ->setUser($viewer)
        ->execute();
      $data = head($result['data']);
      $expect_parent = ($viewer === $alice)
        ? $parent->getRefForConduit()
        : null;
      $this->assertEqual($expect_parent, $data['fields']['parent']);
      $ancestors = array($grandparent->getRefForConduit());
      if ($viewer === $alice) {
        $ancestors[] = $parent->getRefForConduit();
      }
      $this->assertEqual($ancestors,
        $data['attachments']['ancestors']['ancestors']);

      // A rendered page locks Celerity metadata. Each simulated HTTP request
      // needs its own resource response, without altering later tests.
      $resource = new ReflectionProperty('CelerityAPI', 'response');
      $resource->setAccessible(true);
      $old_resource = $resource->getValue();
      try {
        $resource->setValue(null, new CelerityStaticResourceResponse());
        $response = $this->requestProjectController(
          new PhabricatorProjectProfileController(),
          $child, $viewer, 'GET');
        $this->assertTrue($response instanceof PhabricatorStandardPageView);
        $html = (string)$response->produceAphrontResponse()
          ->buildResponseString();
      } finally {
        $resource->setValue(null, $old_resource);
      }
      $this->assertEqual($viewer === $alice,
        strpos($html, $parent->getName()) !== false);
      $this->assertTrue(strpos($html, $grandparent->getName()) !== false);
      $this->assertTrue(strpos($html, $child->getName()) !== false);
      if ($viewer === $bob) {
        $this->assertFalse(strpos($html, $parent->getPHID()) !== false);
        $this->assertFalse(strpos($html, $parent->getProfileURI()) !== false);
      }
    }

    // A hierarchy constraint must not reveal the hidden parent's children.
    foreach (array('parents', 'ancestors') as $constraint) {
      $result = id(new ConduitCall('project.search', array(
        'constraints' => array($constraint => array($parent->getPHID())),
      )))
        ->setUser($bob)
        ->execute();
      $this->assertEqual(array(), $result['data']);
    }

    // Reusing a query for another viewer must neither retain hidden results
    // nor lose the originally requested constraints.
    $query = id(new PhabricatorProjectQuery())
      ->withParentProjectPHIDs(array($parent->getPHID()));
    foreach (array($bob, $alice, $bob) as $viewer) {
      $results = $query->setViewer($viewer)->execute();
      $this->assertEqual($viewer === $alice ? 2 : 0, count($results));
    }

    $results = id(new PhabricatorProjectDatasource())
      ->setViewer($bob)
      ->setRawQuery('')
      ->loadResults();
    $result_phids = mpull($results, 'getPHID');
    $this->assertTrue(in_array($child->getPHID(), $result_phids));
    $this->assertFalse(in_array($parent->getPHID(), $result_phids));
    $this->assertFalse(in_array($milestone->getPHID(), $result_phids));

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($bob)
      ->needImages(true)
      ->withPHIDs(array($parent->getPHID(), $child->getPHID()))
      ->execute();
    $list = id(new PhabricatorProjectListView())
      ->setViewer($bob)
      ->setProjects($projects);
    $html = (string)hsprintf('%s', $list);
    $this->assertFalse(strpos($html, $parent->getName()) !== false);
    $this->assertTrue(strpos($html, $child->getName()) !== false);
  }

  public function testHiddenProjectCombinedWebSearch() {
    $this->assertHiddenProjectCombinedSearch(false);
  }

  public function testHiddenProjectCombinedConduitSearch() {
    $this->assertHiddenProjectCombinedSearch(true);
  }

  public function testHiddenProjectSearchSlugs() {
    list($alice, $bob, $grandparent, $parent, $child, $unrelated,
      $task, $private_task) = $this->newHiddenProjectSearchFixture();
    $g = $grandparent->getPHID();
    $c = $child->getPHID();
    foreach (array(false, true) as $conduit) {
      $this->assertTaskSearch($bob, array($child->getPrimarySlug()),
        array($task), $conduit);
      $this->assertTaskSearch($bob,
        array($g, strtoupper($child->getPrimarySlug()), $c),
        array($task), $conduit);
      foreach (array($parent->getPrimarySlug(), $unrelated->getPrimarySlug(),
        'nonexistent-project-search-slug') as $slug) {
        $this->assertTaskSearch($bob, array($g, $slug, $c), array(), $conduit);
        $this->assertTaskSearch($bob, array($slug), array(), $conduit);
      }
    }
  }

  public function testAnonymousPublicChildSearch() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('policy.allow-public', true);
    list($alice, $bob, $grandparent, $parent, $child, $unrelated,
      $task, $private_task) = $this->newHiddenProjectSearchFixture();
    $public = id(new PhabricatorUser())
      ->attachAlternateCSRFString('anonymous-project-search-test');
    $grandparent->setViewPolicy(PhabricatorPolicies::POLICY_PUBLIC)->save();
    $child
      ->setViewPolicy(PhabricatorPolicies::POLICY_PUBLIC)
      ->setEditPolicy($alice->getPHID())
      ->setJoinPolicy($alice->getPHID())
      ->save();
    $task->setViewPolicy(PhabricatorPolicies::POLICY_PUBLIC)->save();
    $child_milestone = $this->createProject($alice, $child, true);
    $parent_milestone = $this->createProject($alice, $parent, true);

    $visible_child = $this->refreshProject($child, $public, true);
    $this->assertTrue((bool)$visible_child);
    $this->assertEqual(array(), $visible_child->getMemberPHIDs());
    $this->assertFalse(PhabricatorPolicyFilter::hasCapability(
      $public, $visible_child, PhabricatorPolicyCapability::CAN_EDIT));
    $this->assertFalse(PhabricatorPolicyFilter::hasCapability(
      $public, $visible_child, PhabricatorPolicyCapability::CAN_JOIN));
    $this->assertFalse((bool)$this->refreshProject($parent, $public));
    $this->assertTrue((bool)$this->refreshProject($child_milestone, $public));
    $this->assertFalse((bool)$this->refreshProject($parent_milestone, $public));

    $g = $grandparent->getPHID();
    $p = $parent->getPHID();
    $c = $child->getPHID();
    $this->assertTaskSearch($public, array($c), array($task), false);
    $this->assertTaskSearch($public, array($g, $c), array($task), false);
    $this->assertTaskSearch($public, array($g, $p, $c), array(), false);
    $html = $this->renderTaskSearch($public, array($c));
    $this->assertTrue(strpos($html, $task->getTitle()) !== false);
    $this->assertFalse(strpos($html, $private_task->getTitle()) !== false);
    $this->assertFalse(strpos($html, $parent->getName()) !== false);
    $this->assertFalse(strpos($html, $parent->getPHID()) !== false);
    $this->assertFalse(strpos($html, $parent->getProfileURI()) !== false);
    $html = $this->renderTaskSearch($public, array($g, $p, $c));
    $this->assertFalse(strpos($html, $task->getTitle()) !== false);
    $this->assertFalse(strpos($html, $parent->getName()) !== false);

    // Public policies must still obey the installation-wide public-access
    // switch. Do not enable anonymous Conduit calls to exercise this case.
    $env->overrideEnvConfig('policy.allow-public', false);
    $this->assertFalse((bool)$this->refreshProject($child, $public));
    $this->assertFalse((bool)id(new ManiphestTaskQuery())
      ->setViewer($public)
      ->withPHIDs(array($task->getPHID()))
      ->execute());
    // With public access disabled, the application itself is unavailable to
    // this viewer; the normal request gate runs before its search datasource.
    $this->assertFalse((bool)id(new PhabricatorApplicationQuery())
      ->setViewer($public)
      ->withClasses(array(PhabricatorManiphestApplication::class))
      ->execute());
  }

  public function testIndependentMembershipAtomicRejection() {
    list($parent, $child, $alice, $bob, $other) =
      $this->newSubprojectEditFixture();
    $member_type = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;
    foreach (array(false, true) as $conduit) {
      foreach (array($alice, $other) as $actor) {
        $project = $this->refreshProject($child, $actor, true);
        $before = $project->getMemberPHIDs();
        $old_name = $project->getName();
        $old_date = $project->getDateModified();
        $old_xactions = id(new PhabricatorProjectTransactionQuery())
          ->setViewer($actor)
          ->withObjectPHIDs(array($project->getPHID()))
          ->execute();
        $denied = false;
        try {
          if ($conduit) {
            $this->editProjectViaConduit($project, $actor, array(
              array('type' => 'name', 'value' => 'Unauthorized change'),
              array('type' => 'members.add',
                'value' => array($other->getPHID())),
              array('type' => 'edit', 'value' => $actor->getPHID()),
            ));
          } else {
            $this->applyTransactions($project, $actor, array(
              id(new PhabricatorProjectTransaction())
                ->setTransactionType(
                  PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
                ->setNewValue('Unauthorized change'),
              id(new PhabricatorProjectTransaction())
                ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
                ->setMetadataValue('edge:type', $member_type)
                ->setNewValue(array(
                  '+' => array($other->getPHID() => $other->getPHID()),
                )),
              id(new PhabricatorProjectTransaction())
                ->setTransactionType(PhabricatorTransactions::TYPE_EDIT_POLICY)
                ->setNewValue($actor->getPHID()),
            ));
          }
        } catch (PhabricatorPolicyException $ex) {
          $denied = true;
        }
        $this->assertTrue($denied);
        $project = $this->refreshProject($child, $actor, true);
        $this->assertEqual($old_name, $project->getName());
        $this->assertEqual($old_date, $project->getDateModified());
        $this->assertEqual($before, $project->getMemberPHIDs());
        $this->assertEqual($bob->getPHID(), $project->getEditPolicy());
        $new_xactions = id(new PhabricatorProjectTransactionQuery())
          ->setViewer($actor)
          ->withObjectPHIDs(array($project->getPHID()))
          ->execute();
        $this->assertEqual(
          array_keys($old_xactions),
          array_keys($new_xactions));
      }
    }
  }

  public function testIndependentConduitCreationAndTaskPolicy() {
    $alice = $this->createUser()->save();
    $alice->setAllowInlineCacheGeneration(true);
    $bob = $this->createUser()->save();
    $parent = $this->createProject($alice);
    $this->joinProject($parent, $alice);
    $result = id(new ConduitCall('project.edit', array(
      'transactions' => array(
        array('type' => 'name', 'value' => 'Independent Conduit Child'),
        array('type' => 'parent', 'value' => $parent->getPHID()),
        array('type' => 'members.add', 'value' => array($bob->getPHID())),
      ),
    )))
      ->setUser($alice)
      ->execute();
    $child = id(new PhabricatorProjectQuery())
      ->setViewer($alice)
      ->withPHIDs(array($result['object']['phid']))
      ->executeOne();
    $this->assertProjectMembers($parent, array($alice), array($alice, $bob));
    $this->assertProjectMembers($child, array($bob), array($alice, $bob));

    // Membership policies on other objects follow the specified project,
    // while project tags remain organizational and do not grant task access.
    $task = $this->newTask($alice, array($child));
    $task->setViewPolicy($parent->getPHID())->save();
    $this->assertFalse((bool)id(new ManiphestTaskQuery())
      ->setViewer($bob)->withPHIDs(array($task->getPHID()))->execute());
    $task->setViewPolicy($child->getPHID())->save();
    $this->assertTrue((bool)id(new ManiphestTaskQuery())
      ->setViewer($bob)->withPHIDs(array($task->getPHID()))->execute());
    $this->leaveProject($child, $bob);
    $this->assertFalse((bool)id(new ManiphestTaskQuery())
      ->setViewer($bob)->withPHIDs(array($task->getPHID()))->execute());
  }

  public function testIndependentMembershipLegacyIndexes() {
    // Model a legacy parent's stale direct membership and aggregated index.
    // Reindexing must never infer or rewrite direct membership from that index.
    $alice = $this->createUser()->save();
    $bob = $this->createUser()->save();
    $parent = $this->createProject($alice);
    $this->joinProject($parent, $alice);
    $child = $this->createProject($alice, $parent);
    $this->joinProject($child, $bob);
    $material_type = PhabricatorProjectMaterializedMemberEdgeType::EDGECONST;
    $member_type = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;
    id(new PhabricatorEdgeEditor())
      ->removeEdge($parent->getPHID(), $material_type, $alice->getPHID())
      ->addEdge($parent->getPHID(), $material_type, $bob->getPHID())
      ->save();
    $this->assertEqual(array($alice->getPHID()),
      PhabricatorEdgeQuery::loadDestinationPHIDs(
        $parent->getPHID(), $member_type));
    $this->assertEqual(array($bob->getPHID()),
      $this->refreshProject($parent, $bob, true)->getMemberPHIDs());

    id(new PhabricatorProjectsMembershipIndexEngineExtension())
      ->rematerialize($parent);
    $this->assertProjectMembers($parent, array($alice), array($alice, $bob));
    $this->assertProjectMembers($child, array($bob), array($alice, $bob));
  }

  public function testAncestryQueries() {
    $user = $this->createUser();
    $user->save();

    $ancestor = $this->createProject($user);
    $parent = $this->createProject($user, $ancestor);
    $child = $this->createProject($user, $parent);

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withAncestorProjectPHIDs(array($ancestor->getPHID()))
      ->execute();
    $this->assertEqual(2, count($projects));

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withParentProjectPHIDs(array($ancestor->getPHID()))
      ->execute();
    $this->assertEqual(1, count($projects));
    $this->assertEqual(
      $parent->getPHID(),
      head($projects)->getPHID());

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withAncestorProjectPHIDs(array($ancestor->getPHID()))
      ->withDepthBetween(2, null)
      ->execute();
    $this->assertEqual(1, count($projects));
    $this->assertEqual(
      $child->getPHID(),
      head($projects)->getPHID());

    $parent2 = $this->createProject($user, $ancestor);
    $child2 = $this->createProject($user, $parent2);
    $grandchild2 = $this->createProject($user, $child2);

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withAncestorProjectPHIDs(array($ancestor->getPHID()))
      ->execute();
    $this->assertEqual(5, count($projects));

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withParentProjectPHIDs(array($ancestor->getPHID()))
      ->execute();
    $this->assertEqual(2, count($projects));

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withAncestorProjectPHIDs(array($ancestor->getPHID()))
      ->withDepthBetween(2, null)
      ->execute();
    $this->assertEqual(3, count($projects));

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withAncestorProjectPHIDs(array($ancestor->getPHID()))
      ->withDepthBetween(3, null)
      ->execute();
    $this->assertEqual(1, count($projects));

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withPHIDs(
        array(
          $child->getPHID(),
          $grandchild2->getPHID(),
        ))
      ->execute();
    $this->assertEqual(2, count($projects));
  }

  public function testMemberMaterialization() {
    $material_type = PhabricatorProjectMaterializedMemberEdgeType::EDGECONST;

    $user = $this->createUser();
    $user->save();

    $parent = $this->createProject($user);
    $child = $this->createProject($user, $parent);

    $this->joinProject($child, $user);

    $parent_material = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $parent->getPHID(),
      $material_type);

    $this->assertEqual(
      array(),
      $parent_material);
    $this->assertEqual(
      array($user->getPHID()),
      PhabricatorEdgeQuery::loadDestinationPHIDs(
        $child->getPHID(),
        $material_type));
  }

  public function testMilestones() {
    $user = $this->createUser();
    $user->save();

    $parent = $this->createProject($user);

    $m1 = $this->createProject($user, $parent, true);
    $m2 = $this->createProject($user, $parent, true);
    $m3 = $this->createProject($user, $parent, true);

    $this->assertEqual(1, $m1->getMilestoneNumber());
    $this->assertEqual(2, $m2->getMilestoneNumber());
    $this->assertEqual(3, $m3->getMilestoneNumber());
  }

  public function testMilestoneMembership() {
    $user = $this->createUser();
    $user->save();

    $parent = $this->createProject($user);
    $milestone = $this->createProject($user, $parent, true);

    $this->joinProject($parent, $user);

    $milestone = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withPHIDs(array($milestone->getPHID()))
      ->executeOne();

    $this->assertTrue($milestone->isUserMember($user->getPHID()));

    $milestone = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withPHIDs(array($milestone->getPHID()))
      ->needMembers(true)
      ->executeOne();

    $this->assertEqual(
      array($user->getPHID()),
      $milestone->getMemberPHIDs());
  }

  public function testSameSlugAsName() {
    // It should be OK to type the primary hashtag into "additional hashtags",
    // even if the primary hashtag doesn't exist yet because you're creating
    // or renaming the project.

    $user = $this->createUser();
    $user->save();

    $project = $this->createProject($user);

    // In this first case, set the name and slugs at the same time.
    $name = 'slugproject';

    $xactions = array();
    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
      ->setNewValue($name);
    $this->applyTransactions($project, $user, $xactions);

    $xactions = array();
    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectSlugsTransaction::TRANSACTIONTYPE)
      ->setNewValue(array($name));
    $this->applyTransactions($project, $user, $xactions);

    $project = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withPHIDs(array($project->getPHID()))
      ->needSlugs(true)
      ->executeOne();

    $slugs = $project->getSlugs();
    $slugs = mpull($slugs, 'getSlug');

    $this->assertTrue(in_array($name, $slugs));

    // In this second case, set the name first and then the slugs separately.
    $name2 = 'slugproject2';
    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
      ->setNewValue($name2);

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectSlugsTransaction::TRANSACTIONTYPE)
      ->setNewValue(array($name2));

    $this->applyTransactions($project, $user, $xactions);

    $project = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withPHIDs(array($project->getPHID()))
      ->needSlugs(true)
      ->executeOne();

    $slugs = $project->getSlugs();
    $slugs = mpull($slugs, 'getSlug');

    $this->assertTrue(in_array($name2, $slugs));
  }

  public function testDuplicateSlugs() {
    // Creating a project with multiple duplicate slugs should succeed.

    $user = $this->createUser();
    $user->save();

    $project = $this->createProject($user);

    $input = 'duplicate';

    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectSlugsTransaction::TRANSACTIONTYPE)
      ->setNewValue(array($input, $input));

    $this->applyTransactions($project, $user, $xactions);

    $project = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withPHIDs(array($project->getPHID()))
      ->needSlugs(true)
      ->executeOne();

    $slugs = $project->getSlugs();
    $slugs = mpull($slugs, 'getSlug');

    $this->assertTrue(in_array($input, $slugs));
  }

  public function testNormalizeSlugs() {
    // When a user creates a project with slug "XxX360n0sc0perXxX", normalize
    // it before writing it.

    $user = $this->createUser();
    $user->save();

    $project = $this->createProject($user);

    $input = 'NoRmAlIzE';
    $expect = 'normalize';

    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectSlugsTransaction::TRANSACTIONTYPE)
      ->setNewValue(array($input));

    $this->applyTransactions($project, $user, $xactions);

    $project = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withPHIDs(array($project->getPHID()))
      ->needSlugs(true)
      ->executeOne();

    $slugs = $project->getSlugs();
    $slugs = mpull($slugs, 'getSlug');

    $this->assertTrue(in_array($expect, $slugs));


    // If another user tries to add the same slug in denormalized form, it
    // should be caught and fail, even though the database version of the slug
    // is normalized.

    $project2 = $this->createProject($user);

    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectSlugsTransaction::TRANSACTIONTYPE)
      ->setNewValue(array($input));

    $caught = null;
    try {
      $this->applyTransactions($project2, $user, $xactions);
    } catch (PhabricatorApplicationTransactionValidationException $ex) {
      $caught = $ex;
    }

    $this->assertTrue((bool)$caught);
  }

  public function testProjectMembersVisibility() {
    // This is primarily testing that you can create a project and set the
    // visibility or edit policy to "Project Members" immediately.

    $user1 = $this->createUser();
    $user1->save();

    $user2 = $this->createUser();
    $user2->save();

    $project = PhabricatorProject::initializeNewProject($user1)
      ->setName(pht('Test Project for %s', __FUNCTION__))
      ->save();

    $name = pht('Test Project %d', mt_rand());

    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
      ->setNewValue($name);

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)
      ->setNewValue(
        id(new PhabricatorProjectMembersPolicyRule())
          ->getObjectPolicyFullKey());

    $edge_type = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type', $edge_type)
      ->setNewValue(
        array(
          '=' => array($user1->getPHID() => $user1->getPHID()),
        ));

    $this->applyTransactions($project, $user1, $xactions);

    $this->assertTrue((bool)$this->refreshProject($project, $user1));
    $this->assertFalse((bool)$this->refreshProject($project, $user2));

    $rejected = false;
    try {
      $this->leaveProject($project, $user1);
    } catch (PhabricatorApplicationTransactionValidationException $ex) {
      $rejected = true;
    }
    $this->assertTrue($rejected);
    // Continue checking query behavior for an empty legacy fixture without
    // claiming that a live last-editor removal is an allowed operation.
    id(new PhabricatorEdgeEditor())
      ->removeEdge($project->getPHID(), $edge_type, $user1->getPHID())->save();
    id(new PhabricatorProjectsMembershipIndexEngineExtension())
      ->rematerialize($project);

    $this->assertFalse((bool)$this->refreshProject($project, $user1));
  }

  public function testParentProject() {
    $user = $this->createUser();
    $user->save();

    $parent = $this->createProject($user);
    $child = $this->createProject($user, $parent);

    $this->assertTrue(true);

    $child = $this->refreshProject($child, $user);

    $this->assertEqual(
      $parent->getPHID(),
      $child->getParentProject()->getPHID());

    $this->assertEqual(1, (int)$child->getProjectDepth());

    $this->assertFalse(
      $child->isUserMember($user->getPHID()));

    $this->assertFalse(
      $child->getParentProject()->isUserMember($user->getPHID()));

    $this->joinProject($child, $user);

    $child = $this->refreshProject($child, $user);

    $this->assertTrue(
      $child->isUserMember($user->getPHID()));

    $this->assertFalse(
      $child->getParentProject()->isUserMember($user->getPHID()));


    // Hiding an ordinary parent does not hide its independently visible child.

    $user2 = $this->createUser();
    $user2->save();

    // Second user can see the project for now.
    $this->assertTrue((bool)$this->refreshProject($child, $user2));

    // Hide the parent.
    $this->setViewPolicy($parent, $user, $user->getPHID());

    // Both users retain the child's own view permission.
    $this->assertTrue((bool)$this->refreshProject($child, $user));

    $this->assertTrue((bool)$this->refreshProject($child, $user2));
    $this->assertFalse((bool)$this->refreshProject($parent, $user2));
  }

  public function testSlugMaps() {
    // When querying by slugs, slugs should be normalized and the mapping
    // should be reported correctly.
    $user = $this->createUser();
    $user->save();

    $name = 'queryslugproject';
    $name2 = 'QUERYslugPROJECT';
    $slug = 'queryslugextra';
    $slug2 = 'QuErYSlUgExTrA';

    $project = PhabricatorProject::initializeNewProject($user);
    $project->setPHID($project->generatePHID());

    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
      ->setNewValue($name);

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectSlugsTransaction::TRANSACTIONTYPE)
      ->setNewValue(array($slug));

    $this->applyTransactions($project, $user, $xactions);

    $project_query = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withSlugs(array($name));
    $project_query->execute();
    $map = $project_query->getSlugMap();

    $this->assertEqual(
      array(
        $name => $project->getPHID(),
      ),
      ipull($map, 'projectPHID'));

    $project_query = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withSlugs(array($slug));
    $project_query->execute();
    $map = $project_query->getSlugMap();

    $this->assertEqual(
      array(
        $slug => $project->getPHID(),
      ),
      ipull($map, 'projectPHID'));

    $project_query = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withSlugs(array($name, $slug, $name2, $slug2));
    $project_query->execute();
    $map = $project_query->getSlugMap();

    $expect = array(
      $name => $project->getPHID(),
      $slug => $project->getPHID(),
      $name2 => $project->getPHID(),
      $slug2 => $project->getPHID(),
    );

    $actual = ipull($map, 'projectPHID');

    ksort($expect);
    ksort($actual);

    $this->assertEqual($expect, $actual);

    $expect = array(
      $name => $name,
      $slug => $slug,
      $name2 => $name,
      $slug2 => $slug,
    );

    $actual = ipull($map, 'slug');

    ksort($expect);
    ksort($actual);

    $this->assertEqual($expect, $actual);
  }

  public function testJoinLeaveProject() {
    $user = $this->createUser();
    $user->save();

    $proj = $this->createProjectWithNewAuthor();

    $proj = $this->refreshProject($proj, $user, true);
    $this->assertTrue(
      (bool)$proj,
      pht(
        'Assumption that projects are default visible '.
        'to any user when created.'));

    $this->assertFalse(
      $proj->isUserMember($user->getPHID()),
      pht('Arbitrary user not member of project.'));

    // Join the project.
    $this->joinProject($proj, $user);

    $proj = $this->refreshProject($proj, $user, true);
    $this->assertTrue((bool)$proj);

    $this->assertTrue(
      $proj->isUserMember($user->getPHID()),
      pht('Join works.'));


    // Join the project again.
    $this->joinProject($proj, $user);

    $proj = $this->refreshProject($proj, $user, true);
    $this->assertTrue((bool)$proj);

    $this->assertTrue(
      $proj->isUserMember($user->getPHID()),
      pht('Joining an already-joined project is a no-op.'));


    // Leave the project.
    $this->leaveProject($proj, $user);

    $proj = $this->refreshProject($proj, $user, true);
    $this->assertTrue((bool)$proj);

    $this->assertFalse(
      $proj->isUserMember($user->getPHID()),
      pht('Leave works.'));


    // Leave the project again.
    $this->leaveProject($proj, $user);

    $proj = $this->refreshProject($proj, $user, true);
    $this->assertTrue((bool)$proj);

    $this->assertFalse(
      $proj->isUserMember($user->getPHID()),
      pht('Leaving an already-left project is a no-op.'));


    // If a user can't edit or join a project, joining fails.
    $proj->setEditPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->setJoinPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->save();

    $proj = $this->refreshProject($proj, $user, true);
    $caught = null;
    try {
      $this->joinProject($proj, $user);
    } catch (Exception $ex) {
      $caught = $ex;
    }
    $this->assertTrue($ex instanceof Exception);


    // If a user can edit a project, they can join.
    $proj->setEditPolicy(PhabricatorPolicies::POLICY_USER);
    $proj->setJoinPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->save();

    $proj = $this->refreshProject($proj, $user, true);
    $this->joinProject($proj, $user);
    $proj = $this->refreshProject($proj, $user, true);
    $this->assertTrue(
      $proj->isUserMember($user->getPHID()),
      pht('Join allowed with edit permission.'));
    $this->leaveProject($proj, $user);


    // If a user can join a project, they can join, even if they can't edit.
    $proj->setEditPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->setJoinPolicy(PhabricatorPolicies::POLICY_USER);
    $proj->save();

    $proj = $this->refreshProject($proj, $user, true);
    $this->joinProject($proj, $user);
    $proj = $this->refreshProject($proj, $user, true);
    $this->assertTrue(
      $proj->isUserMember($user->getPHID()),
      pht('Join allowed with join permission.'));


    // A user can leave a project even if they can't edit it or join.
    $proj->setEditPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->setJoinPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->save();

    $proj = $this->refreshProject($proj, $user, true);
    $this->leaveProject($proj, $user);
    $proj = $this->refreshProject($proj, $user, true);
    $this->assertFalse(
      $proj->isUserMember($user->getPHID()),
      pht('Leave allowed without any permission.'));
  }


  public function testComplexConstraints() {
    $user = $this->createUser();
    $user->save();

    $engineering = $this->createProject($user);
    $engineering_scan = $this->createProject($user, $engineering);
    $engineering_warp = $this->createProject($user, $engineering);

    $exploration = $this->createProject($user);
    $exploration_diplomacy = $this->createProject($user, $exploration);

    $task_engineering = $this->newTask(
      $user,
      array($engineering),
      pht('Engineering Only'));

    $task_exploration = $this->newTask(
      $user,
      array($exploration),
      pht('Exploration Only'));

    $task_warp_explore = $this->newTask(
      $user,
      array($engineering_warp, $exploration),
      pht('Warp to New Planet'));

    $task_diplomacy_scan = $this->newTask(
      $user,
      array($engineering_scan, $exploration_diplomacy),
      pht('Scan Diplomat'));

    $task_diplomacy = $this->newTask(
      $user,
      array($exploration_diplomacy),
      pht('Diplomatic Meeting'));

    $task_warp_scan = $this->newTask(
      $user,
      array($engineering_scan, $engineering_warp),
      pht('Scan Warp Drives'));

    $this->assertQueryByProjects(
      $user,
      array(
        $task_engineering,
        $task_warp_explore,
        $task_diplomacy_scan,
        $task_warp_scan,
      ),
      array($engineering),
      pht('All Engineering'));

    $this->assertQueryByProjects(
      $user,
      array(
        $task_diplomacy_scan,
        $task_warp_scan,
      ),
      array($engineering_scan),
      pht('All Scan'));

    $this->assertQueryByProjects(
      $user,
      array(
        $task_warp_explore,
        $task_diplomacy_scan,
      ),
      array($engineering, $exploration),
      pht('Engineering + Exploration'));

    // This is testing that a query for "Parent" and "Parent > Child" works
    // properly.
    $this->assertQueryByProjects(
      $user,
      array(
        $task_diplomacy_scan,
        $task_warp_scan,
      ),
      array($engineering, $engineering_scan),
      pht('Engineering + Scan'));
  }

  public function testTagAncestryConflicts() {
    $user = $this->createUser();
    $user->save();

    $stonework = $this->createProject($user);
    $stonework_masonry = $this->createProject($user, $stonework);
    $stonework_sculpting = $this->createProject($user, $stonework);

    $task = $this->newTask($user, array());
    $this->assertEqual(array(), $this->getTaskProjects($task));

    $this->addProjectTags($user, $task, array($stonework->getPHID()));
    $this->assertEqual(
      array(
        $stonework->getPHID(),
      ),
      $this->getTaskProjects($task));

    // Adding a descendant should remove the parent.
    $this->addProjectTags($user, $task, array($stonework_masonry->getPHID()));
    $this->assertEqual(
      array(
        $stonework_masonry->getPHID(),
      ),
      $this->getTaskProjects($task));

    // Adding an ancestor should remove the descendant.
    $this->addProjectTags($user, $task, array($stonework->getPHID()));
    $this->assertEqual(
      array(
        $stonework->getPHID(),
      ),
      $this->getTaskProjects($task));

    // Adding two tags in the same hierarchy which are not mutual ancestors
    // should remove the ancestor but otherwise work fine.
    $this->addProjectTags(
      $user,
      $task,
      array(
        $stonework_masonry->getPHID(),
        $stonework_sculpting->getPHID(),
      ));

    $expect = array(
      $stonework_masonry->getPHID(),
      $stonework_sculpting->getPHID(),
    );
    sort($expect);

    $this->assertEqual($expect,  $this->getTaskProjects($task));
  }

  public function testTagMilestoneConflicts() {
    $user = $this->createUser();
    $user->save();

    $stonework = $this->createProject($user);
    $stonework_1 = $this->createProject($user, $stonework, true);
    $stonework_2 = $this->createProject($user, $stonework, true);

    $task = $this->newTask($user, array());
    $this->assertEqual(array(), $this->getTaskProjects($task));

    $this->addProjectTags($user, $task, array($stonework->getPHID()));
    $this->assertEqual(
      array(
        $stonework->getPHID(),
      ),
      $this->getTaskProjects($task));

    // Adding a milesone should remove the parent.
    $this->addProjectTags($user, $task, array($stonework_1->getPHID()));
    $this->assertEqual(
      array(
        $stonework_1->getPHID(),
      ),
      $this->getTaskProjects($task));

    // Adding the parent should remove the milestone.
    $this->addProjectTags($user, $task, array($stonework->getPHID()));
    $this->assertEqual(
      array(
        $stonework->getPHID(),
      ),
      $this->getTaskProjects($task));

    // First, add one milestone.
    $this->addProjectTags($user, $task, array($stonework_1->getPHID()));
    // Now, adding a second milestone should remove the first milestone.
    $this->addProjectTags($user, $task, array($stonework_2->getPHID()));
    $this->assertEqual(
      array(
        $stonework_2->getPHID(),
      ),
      $this->getTaskProjects($task));
  }

  public function testBoardMoves() {
    $user = $this->createUser();
    $user->save();

    $board = $this->createProject($user);

    $backlog = $this->addColumn($user, $board, 0);
    $column = $this->addColumn($user, $board, 1);

    // New tasks should appear in the backlog.
    $task1 = $this->newTask($user, array($board));
    $expect = array(
      $backlog->getPHID(),
    );
    $this->assertColumns($expect, $user, $board, $task1);

    // Moving a task should move it to the destination column.
    $this->moveToColumn($user, $board, $task1, $backlog, $column);
    $expect = array(
      $column->getPHID(),
    );
    $this->assertColumns($expect, $user, $board, $task1);

    // Same thing again, with a new task.
    $task2 = $this->newTask($user, array($board));
    $expect = array(
      $backlog->getPHID(),
    );
    $this->assertColumns($expect, $user, $board, $task2);

    // Move it, too.
    $this->moveToColumn($user, $board, $task2, $backlog, $column);
    $expect = array(
      $column->getPHID(),
    );
    $this->assertColumns($expect, $user, $board, $task2);

    // Now the stuff should be in the column, in order, with the more recently
    // moved task on top.
    $expect = array(
      $task2->getPHID(),
      $task1->getPHID(),
    );
    $label = pht('Simple move');
    $this->assertTasksInColumn($expect, $user, $board, $column, $label);

    // Move the second task after the first task.
    $options = array(
      'afterPHIDs' => array($task1->getPHID()),
    );
    $this->moveToColumn($user, $board, $task2, $column, $column, $options);
    $expect = array(
      $task1->getPHID(),
      $task2->getPHID(),
    );
    $label = pht('With afterPHIDs');
    $this->assertTasksInColumn($expect, $user, $board, $column, $label);

    // Move the second task before the first task.
    $options = array(
      'beforePHIDs' => array($task1->getPHID()),
    );
    $this->moveToColumn($user, $board, $task2, $column, $column, $options);
    $expect = array(
      $task2->getPHID(),
      $task1->getPHID(),
    );
    $label = pht('With beforePHIDs');
    $this->assertTasksInColumn($expect, $user, $board, $column, $label);
  }

  public function testMilestoneMoves() {
    $user = $this->createUser();
    $user->save();

    $board = $this->createProject($user);

    $backlog = $this->addColumn($user, $board, 0);

    // Create a task into the backlog.
    $task = $this->newTask($user, array($board));
    $expect = array(
      $backlog->getPHID(),
    );
    $this->assertColumns($expect, $user, $board, $task);

    $milestone = $this->createProject($user, $board, true);

    $this->addProjectTags($user, $task, array($milestone->getPHID()));

    // We just want the side effect of looking at the board: creation of the
    // milestone column.
    $this->loadColumns($user, $board, $task);

    $column = id(new PhabricatorProjectColumnQuery())
      ->setViewer($user)
      ->withProjectPHIDs(array($board->getPHID()))
      ->withProxyPHIDs(array($milestone->getPHID()))
      ->executeOne();

    $this->assertTrue((bool)$column);

    // Moving the task to the milestone should have moved it to the milestone
    // column.
    $expect = array(
      $column->getPHID(),
    );
    $this->assertColumns($expect, $user, $board, $task);


    // Move the task within the "Milestone" column. This should not affect
    // the projects the task is tagged with. See T10912.
    $task_a = $task;

    $task_b = $this->newTask($user, array($backlog));
    $this->moveToColumn($user, $board, $task_b, $backlog, $column);

    $a_options = array(
      'beforePHID' => $task_b->getPHID(),
    );

    $b_options = array(
      'beforePHID' => $task_a->getPHID(),
    );

    $old_projects = $this->getTaskProjects($task);

    // Move the target task to the top.
    $this->moveToColumn($user, $board, $task_a, $column, $column, $a_options);
    $new_projects = $this->getTaskProjects($task_a);
    $this->assertEqual($old_projects, $new_projects);

    // Move the other task.
    $this->moveToColumn($user, $board, $task_b, $column, $column, $b_options);
    $new_projects = $this->getTaskProjects($task_a);
    $this->assertEqual($old_projects, $new_projects);

    // Move the target task again.
    $this->moveToColumn($user, $board, $task_a, $column, $column, $a_options);
    $new_projects = $this->getTaskProjects($task_a);
    $this->assertEqual($old_projects, $new_projects);


    // Add the parent project to the task. This should move it out of the
    // milestone column and into the parent's backlog.
    $this->addProjectTags($user, $task, array($board->getPHID()));
    $expect_columns = array(
      $backlog->getPHID(),
    );
    $this->assertColumns($expect_columns, $user, $board, $task);

    $new_projects = $this->getTaskProjects($task);
    $expect_projects = array(
      $board->getPHID(),
    );
    $this->assertEqual($expect_projects, $new_projects);
  }

  public function testColumnExtendedPolicies() {
    $user = $this->createUser();
    $user->save();

    $board = $this->createProject($user);
    $column = $this->addColumn($user, $board, 0);

    // At first, the user should be able to view and edit the column.
    $column = $this->refreshColumn($user, $column);
    $this->assertTrue((bool)$column);

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $user,
      $column,
      PhabricatorPolicyCapability::CAN_EDIT);
    $this->assertTrue($can_edit);

    // Now, set the project edit policy to "Members of Project". This should
    // disable editing.
    $members_policy = id(new PhabricatorProjectMembersPolicyRule())
      ->getObjectPolicyFullKey();
    $board->setEditPolicy($members_policy)->save();

    $column = $this->refreshColumn($user, $column);
    $this->assertTrue((bool)$column);

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $user,
      $column,
      PhabricatorPolicyCapability::CAN_EDIT);
    $this->assertFalse($can_edit);

    // Now, join the project. This should make the column editable again.
    $this->joinProject($board, $user);

    $column = $this->refreshColumn($user, $column);
    $this->assertTrue((bool)$column);

    // This test has been failing randomly in a way that doesn't reproduce
    // on any host, so add some extra assertions to try to nail it down.
    $board = $this->refreshProject($board, $user, true);
    $this->assertTrue((bool)$board);
    $this->assertTrue($board->isUserMember($user->getPHID()));

    $can_view = PhabricatorPolicyFilter::hasCapability(
      $user,
      $column,
      PhabricatorPolicyCapability::CAN_VIEW);
    $this->assertTrue($can_view);

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $user,
      $column,
      PhabricatorPolicyCapability::CAN_EDIT);
    $this->assertTrue($can_edit);
  }

  public function testProjectPolicyRules() {
    $author = $this->generateNewTestUser();

    $proj_a = PhabricatorProject::initializeNewProject($author)
      ->setName('Policy A');
    $proj_a->setPHID($proj_a->generatePHID())
      ->save();

    $proj_b = PhabricatorProject::initializeNewProject($author)
      ->setName('Policy B');
    $proj_b->setPHID($proj_b->generatePHID())
      ->save();

    $user_none = $this->generateNewTestUser();
    $user_any = $this->generateNewTestUser();
    $user_all = $this->generateNewTestUser();

    $this->joinProject($proj_a, $user_any);
    $this->joinProject($proj_a, $user_all);
    $this->joinProject($proj_b, $user_all);

    $any_policy = id(new PhabricatorPolicy())
      ->setRules(
        array(
          array(
            'action' => PhabricatorPolicy::ACTION_ALLOW,
            'rule' => 'PhabricatorProjectsPolicyRule',
            'value' => array(
              $proj_a->getPHID(),
              $proj_b->getPHID(),
            ),
          ),
        ))
      ->save();

    $all_policy = id(new PhabricatorPolicy())
      ->setRules(
        array(
          array(
            'action' => PhabricatorPolicy::ACTION_ALLOW,
            'rule' => 'PhabricatorProjectsAllPolicyRule',
            'value' => array(
              $proj_a->getPHID(),
              $proj_b->getPHID(),
            ),
          ),
        ))
      ->save();

    $any_task = ManiphestTask::initializeNewTask($author)
      ->setViewPolicy($any_policy->getPHID())
      ->save();

    $all_task = ManiphestTask::initializeNewTask($author)
      ->setViewPolicy($all_policy->getPHID())
      ->save();

    $map = array(
      array(
        pht('Project policy rule; user in no projects'),
        $user_none,
        false,
        false,
      ),
      array(
        pht('Project policy rule; user in some projects'),
        $user_any,
        true,
        false,
      ),
      array(
        pht('Project policy rule; user in all projects'),
        $user_all,
        true,
        true,
      ),
    );

    foreach ($map as $test_case) {
      list($label, $user, $expect_any, $expect_all) = $test_case;

      $can_any = PhabricatorPolicyFilter::hasCapability(
        $user,
        $any_task,
        PhabricatorPolicyCapability::CAN_VIEW);

      $can_all = PhabricatorPolicyFilter::hasCapability(
        $user,
        $all_task,
        PhabricatorPolicyCapability::CAN_VIEW);

      $this->assertEqual($expect_any, $can_any, pht('%s / Any', $label));
      $this->assertEqual($expect_all, $can_all, pht('%s / All', $label));
    }
  }

  /**
   * Test that you can successfully destroy a complex projects tree,
   * leaf by leaf, and the depths and the join policies remain consistent.
   */
  public function testProjectDestroy() {
    // Create test actors.
    $author = $this->createUser()->save();
    $mario = $this->createUser()->save();
    $luigi = $this->createUser()->save();

    // Create a project "A". It will have children later.
    $a = $this->createProject($author)->save();

    // Add "Mario" in the project "A", successfully.
    $this->joinProject($a, $mario);

    // Under project "A", create "B".
    // Members of "A" stay in "A" and can still be edited.
    $b = $this->createProject($author, $a)->save();

    // Under project "B", create the milestone "M".
    // Note that the members of "B" are also members of "M".
    $m = $this->createProject($author, $b, true)->save();

    // Under project "B", create "C".
    // Members of "B" stay in "B" and can still be edited.
    $c = $this->createProject($author, $b)->save();

    // Refresh the perspective of Mario.
    $this->refreshProjectInPlace($a, $mario);
    $this->refreshProjectInPlace($b, $mario);
    $this->refreshProjectInPlace($c, $mario);
    $this->refreshProjectInPlace($m, $mario);

    // Check the desired project tree:
    //
    //   Project A > Project B > Milestone M
    //   Project A > Project B > Project C
    //    \ Depth: 0  \          \
    //                 \ Depth: 1 \
    //                             \ Depth: 2
    //
    //   - Mario is a direct member of "A" only.
    //   - Users can join "A" and "B" directly.
    //
    $this->assertEqual(0, (int)$a->getProjectDepth());
    $this->assertEqual(1, (int)$b->getProjectDepth());
    $this->assertEqual(2, (int)$m->getProjectDepth());
    $this->assertEqual(2, (int)$c->getProjectDepth());
    $this->assertTrue($a->isUserMember($mario->getPHID()));
    $this->assertFalse($b->isUserMember($mario->getPHID()));
    $this->assertFalse($c->isUserMember($mario->getPHID()));
    $this->assertFalse($m->isUserMember($mario->getPHID()));
    $this->joinProject($a, $luigi);
    $this->leaveProject($a, $luigi);
    $this->joinProject($b, $luigi);
    $this->leaveProject($b, $luigi);

    // Destroy project "B" and refresh its relatives.
    // Note that its milestone "M" is also nuked automatically.
    $engine = new PhabricatorDestructionEngine();
    $engine->destroyObject($b);
    $this->refreshProjectInPlace($a, $mario);
    $this->refreshProjectInPlace($c, $mario);

    // Check the desired project tree:
    //
    //   Project A > Project C
    //    \ Depth: 0  \
    //                 \ Depth: 1
    //
    //   - Mario remains a direct member of "A" only.
    //   - Users can still join "A" directly.
    //
    $this->assertEqual(null, $this->refreshProject($b, $mario));
    $this->assertEqual(null, $this->refreshProject($m, $mario));
    $this->assertEqual(0, (int)$a->getProjectDepth());
    $this->assertEqual(1, (int)$c->getProjectDepth());
    $this->assertTrue($a->isUserMember($mario->getPHID()));
    $this->assertFalse($c->isUserMember($mario->getPHID()));
    $this->joinProject($a, $luigi);
    $this->leaveProject($a, $luigi);

    // Destroy project "C" and refresh relatives.
    $engine->destroyObject($c);
    $this->refreshProjectInPlace($a, $author);

    // Check the desired project tree:
    //
    //   Project A
    //    \- Depth: 0
    //
    //   - Mario is a direct member of "A"
    //   - Users can still join and leave "A" directly.
    //
    $this->assertEqual(null, $this->refreshProject($c, $mario));
    $this->assertEqual(0, (int)$a->getProjectDepth());
    $this->joinProject($a, $luigi); // No crash
    $this->leaveProject($a, $luigi); // No crash

    // Destroy "A". It works and nothing remains.
    $engine->destroyObject($a);
    $this->assertEqual(null, $this->refreshProject($a, $mario));
  }

  private function moveToColumn(
    PhabricatorUser $viewer,
    PhabricatorProject $board,
    ManiphestTask $task,
    PhabricatorProjectColumn $src,
    PhabricatorProjectColumn $dst,
    $options = null) {

    $xactions = array();

    if (!$options) {
      $options = array();
    }

    $value = array(
      'columnPHID' => $dst->getPHID(),
    ) + $options;

    $xactions[] = id(new ManiphestTransaction())
      ->setTransactionType(ManiphestTaskColumnTransaction::TRANSACTIONTYPE)
      ->setNewValue(array($value));

    $editor = id(new ManiphestTransactionEditor())
      ->setActor($viewer)
      ->setContentSource($this->newContentSource())
      ->setContinueOnNoEffect(true)
      ->applyTransactions($task, $xactions);
  }

  private function assertColumns(
    array $expect,
    PhabricatorUser $viewer,
    PhabricatorProject $board,
    ManiphestTask $task) {
    $column_phids = $this->loadColumns($viewer, $board, $task);
    $this->assertEqual($expect, $column_phids);
  }

  private function loadColumns(
    PhabricatorUser $viewer,
    PhabricatorProject $board,
    ManiphestTask $task) {
    $engine = id(new PhabricatorBoardLayoutEngine())
      ->setViewer($viewer)
      ->setBoardPHIDs(array($board->getPHID()))
      ->setObjectPHIDs(
        array(
          $task->getPHID(),
        ))
      ->executeLayout();

    $columns = $engine->getObjectColumns($board->getPHID(), $task->getPHID());
    $column_phids = mpull($columns, 'getPHID');
    $column_phids = array_values($column_phids);

    return $column_phids;
  }

  private function assertTasksInColumn(
    array $expect,
    PhabricatorUser $viewer,
    PhabricatorProject $board,
    PhabricatorProjectColumn $column,
    $label = null) {

    $engine = id(new PhabricatorBoardLayoutEngine())
      ->setViewer($viewer)
      ->setBoardPHIDs(array($board->getPHID()))
      ->setObjectPHIDs($expect)
      ->executeLayout();

    $object_phids = $engine->getColumnObjectPHIDs(
      $board->getPHID(),
      $column->getPHID());
    $object_phids = array_values($object_phids);

    $this->assertEqual($expect, $object_phids, $label);
  }

  private function addColumn(
    PhabricatorUser $viewer,
    PhabricatorProject $project,
    $sequence) {

    $project->setHasWorkboard(1)->save();

    return PhabricatorProjectColumn::initializeNewColumn($viewer)
      ->setSequence(0)
      ->setProperty('isDefault', ($sequence == 0))
      ->setProjectPHID($project->getPHID())
      ->save();
  }

  private function getTaskProjects(ManiphestTask $task) {
    $project_phids = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $task->getPHID(),
      PhabricatorProjectObjectHasProjectEdgeType::EDGECONST);

    sort($project_phids);

    return $project_phids;
  }

  private function attemptProjectEdit(
    PhabricatorProject $proj,
    PhabricatorUser $user,
    $skip_refresh = false) {

    $proj = $this->refreshProject($proj, $user, true);

    $new_name = $proj->getName().' '.mt_rand();

    $params = array(
      'objectIdentifier' => $proj->getID(),
      'transactions' => array(
        array(
          'type' => 'name',
          'value' => $new_name,
        ),
      ),
    );

    id(new ConduitCall('project.edit', $params))
      ->setUser($user)
      ->execute();

    return true;
  }


  private function addProjectTags(
    PhabricatorUser $viewer,
    ManiphestTask $task,
    array $phids) {

    $xactions = array();

    $xactions[] = id(new ManiphestTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue(
        'edge:type',
        PhabricatorProjectObjectHasProjectEdgeType::EDGECONST)
      ->setNewValue(
        array(
          '+' => array_fuse($phids),
        ));

    $editor = id(new ManiphestTransactionEditor())
      ->setActor($viewer)
      ->setContentSource($this->newContentSource())
      ->setContinueOnNoEffect(true)
      ->applyTransactions($task, $xactions);
  }

  private function newTask(
    PhabricatorUser $viewer,
    array $projects,
    $name = null) {

    $task = ManiphestTask::initializeNewTask($viewer);

    if ($name === null || $name === '') {
      $name = pht('Test Task');
    }

    $xactions = array();

    $xactions[] = id(new ManiphestTransaction())
      ->setTransactionType(ManiphestTaskTitleTransaction::TRANSACTIONTYPE)
      ->setNewValue($name);

    if ($projects) {
      $xactions[] = id(new ManiphestTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
        ->setMetadataValue(
          'edge:type',
          PhabricatorProjectObjectHasProjectEdgeType::EDGECONST)
        ->setNewValue(
          array(
            '=' => array_fuse(mpull($projects, 'getPHID')),
          ));
    }

    $editor = id(new ManiphestTransactionEditor())
      ->setActor($viewer)
      ->setContentSource($this->newContentSource())
      ->setContinueOnNoEffect(true)
      ->applyTransactions($task, $xactions);

    return $task;
  }

  private function assertQueryByProjects(
    PhabricatorUser $viewer,
    array $expect,
    array $projects,
    $label = null) {

    $datasource = id(new PhabricatorProjectLogicalDatasource())
      ->setViewer($viewer);

    $project_phids = mpull($projects, 'getPHID');
    $constraints = $datasource->evaluateTokens($project_phids);

    $query = id(new ManiphestTaskQuery())
      ->setViewer($viewer);

    $query->withEdgeLogicConstraints(
      PhabricatorProjectObjectHasProjectEdgeType::EDGECONST,
      $constraints);

    $tasks = $query->execute();

    $expect_phids = mpull($expect, 'getTitle', 'getPHID');
    ksort($expect_phids);

    $actual_phids = mpull($tasks, 'getTitle', 'getPHID');
    ksort($actual_phids);

    $this->assertEqual($expect_phids, $actual_phids, $label);
  }

  /**
   * Refresh a project in place.
   * @param PhabricatorProject $project Project that will be refreshed.
   * @param PhabricatorUser $viewer
   * @return void
   */
  private function refreshProjectInPlace(
    PhabricatorProject &$project,
    PhabricatorUser $viewer): void {
    $project = $this->refreshProject($project, $viewer);
  }

  /**
   * Get a project, refreshed.
   * @param PhabricatorProject $project Project.
   * @param PhabricatorUser $viewer
   * @param mixed $need_members If you also need project members.
   * @param mixed $need_watchers If you also need project watchers.
   * @return PhabricatorProject|null Refreshed project.
   */
  private function refreshProject(
    PhabricatorProject $project,
    PhabricatorUser $viewer,
    $need_members = false,
    $need_watchers = false) {

    $results = id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->needMembers($need_members)
      ->needWatchers($need_watchers)
      ->withIDs(array($project->getID()))
      ->execute();

    if ($results) {
      return head($results);
    } else {
      return null;
    }
  }

  private function refreshColumn(
    PhabricatorUser $viewer,
    PhabricatorProjectColumn $column) {

    $results = id(new PhabricatorProjectColumnQuery())
      ->setViewer($viewer)
      ->withIDs(array($column->getID()))
      ->execute();

    if ($results) {
      return head($results);
    } else {
      return null;
    }
  }

  private function createProject(
    PhabricatorUser $user,
    ?PhabricatorProject $parent = null,
    $is_milestone = false) {

    $project = PhabricatorProject::initializeNewProject($user, $parent);
    $project->setPHID($project->generatePHID());

    $name = pht('Test Project %d', mt_rand());

    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectNameTransaction::TRANSACTIONTYPE)
      ->setNewValue($name);

    if ($parent) {
      if ($is_milestone) {
        $xactions[] = id(new PhabricatorProjectTransaction())
          ->setTransactionType(
              PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE)
          ->setNewValue($parent->getPHID());
      } else {
        $xactions[] = id(new PhabricatorProjectTransaction())
          ->setTransactionType(
              PhabricatorProjectParentTransaction::TRANSACTIONTYPE)
          ->setNewValue($parent->getPHID());
      }
    }

    $this->applyTransactions($project, $user, $xactions);

    // Force these values immediately; they are normally updated by the
    // index engine.
    if ($parent) {
      if ($is_milestone) {
        $parent->setHasMilestones(1)->save();
      } else {
        $parent->setHasSubprojects(1)->save();
      }
    }

    return $project;
  }

  private function setViewPolicy(
    PhabricatorProject $project,
    PhabricatorUser $user,
    $policy) {

    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)
      ->setNewValue($policy);

    $this->applyTransactions($project, $user, $xactions);

    return $project;
  }

  private function createProjectWithNewAuthor() {
    $author = $this->createUser();
    $author->save();

    $project = $this->createProject($author);

    return $project;
  }

  private function createUser() {
    $rand = mt_rand();

    $user = new PhabricatorUser();
    $user->setUsername('unittestuser'.$rand);
    $user->setRealName(pht('Unit Test User %d', $rand));
    $user->setIsApproved(1);

    return $user;
  }

  private function newHiddenProjectSearchFixture() {
    $alice = $this->createUser()->save();
    $bob = $this->createUser()->save();
    $grandparent = $this->createProject($alice);
    $parent = $this->createProject($alice, $grandparent);
    $child = $this->createProject($alice, $parent);
    $unrelated = $this->createProject($alice);
    $parent->setViewPolicy($alice->getPHID())->save();
    $unrelated->setViewPolicy($alice->getPHID())->save();
    $task = $this->newTask($alice, array($child), 'Visible child search task');
    $private_task = $this->newTask(
      $alice, array($child), 'Private child search task');
    $private_task->setViewPolicy($alice->getPHID())->save();
    $this->newTask($alice, array($grandparent), 'Other ancestor search task');

    return array($alice, $bob, $grandparent, $parent, $child, $unrelated,
      $task, $private_task);
  }

  private function assertHiddenProjectCombinedSearch($conduit) {
    list($alice, $bob, $grandparent, $parent, $child, $unrelated,
      $task, $private_task) = $this->newHiddenProjectSearchFixture();
    $g = $grandparent->getPHID();
    $p = $parent->getPHID();
    $c = $child->getPHID();
    $u = $unrelated->getPHID();
    $missing = id(new PhabricatorProject())->generatePHID();

    $this->assertTaskSearch($bob, array($c), array($task), $conduit);
    $this->assertTaskSearch($bob, array($g, $c), array($task), $conduit);
    $this->assertTaskSearch($bob, array($c, $g, $c), array($task), $conduit);
    $this->assertTaskSearch($bob, array('any('.$c.')'),
      array($task), $conduit);
    $this->assertTaskSearch($alice, array($g, $p, $c),
      array($task, $private_task), $conduit);

    // G (visible) -> P (hidden) -> C (visible). A redundant hidden condition
    // must fail closed in every order, exactly like an unrelated hidden one.
    $blocked = array(
      array($g, $p, $c),
      array($g, $c, $p),
      array($p, $g, $c),
      array($p, $c, $g),
      array($c, $g, $p),
      array($c, $p, $g),
      array($g, $p, $c, $p, $c),
      array($p),
      array($g, $p),
      array($g, $u, $c),
      array($g, $missing, $c),
      array($g, $p, $c, 'only()'),
      array($g, $c, 'any('.$p.')', 'any('.$c.')'),
      array($g, $c, 'not('.$p.')'),
      array($g, $p, $c, 'any('.$c.')'),
    );
    foreach ($blocked as $tokens) {
      $this->assertTaskSearch($bob, $tokens, array(), $conduit);
    }

    // Reusing a datasource with another viewer must revalidate the original
    // tokens rather than reuse a previously authorized simplification.
    $source = new PhabricatorProjectLogicalAncestorDatasource();
    foreach (array($bob, $alice, $bob) as $viewer) {
      $constraints = $source->setViewer($viewer)
        ->evaluateTokens(array($g, $p, $c));
      $results = id(new ManiphestTaskQuery())
        ->setViewer($viewer)
        ->withEdgeLogicConstraints(
          PhabricatorProjectObjectHasProjectEdgeType::EDGECONST,
          $constraints)
        ->execute();
      $this->assertEqual($viewer === $alice ? 2 : 0, count($results));
    }

    if (!$conduit) {
      foreach (array(array($c), array($g, $p, $c), array($g, $u, $c))
        as $tokens) {
        $html = $this->renderTaskSearch($bob, $tokens);
        $this->assertEqual($tokens === array($c),
          strpos($html, $task->getTitle()) !== false);
        $this->assertFalse(strpos($html, $private_task->getTitle()) !== false);
        $this->assertFalse(strpos($html, $parent->getName()) !== false);
        $this->assertFalse(strpos($html, $parent->getProfileURI()) !== false);
        $this->assertFalse(strpos($html, $unrelated->getName()) !== false);
      }
    }
  }

  private function newTaskSearchRequest(
    PhabricatorUser $viewer,
    array $tokens) {

    $viewer->setAllowInlineCacheGeneration(true);
    return id(new AphrontRequest('phabricator.example.com', '/maniphest/'))
      ->setApplicationConfiguration(new AphrontApplicationConfiguration())
      ->setURIMap(array('queryKey' => ''))
      ->setRequestData(array('projectPHIDs' => $tokens))
      ->setUser($viewer);
  }

  private function assertTaskSearch(
    PhabricatorUser $viewer,
    array $tokens,
    array $expect,
    $conduit) {

    $viewer->setAllowInlineCacheGeneration(true);
    if ($conduit) {
      $response = id(new ConduitCall('maniphest.search', array(
        'constraints' => array('projects' => $tokens),
      )))
        ->setUser($viewer)
        ->execute();
      $actual = ipull($response['data'], 'phid');
    } else {
      // Exercise the actual Web search field parsing, saved-query conversion,
      // project extension, and policy-aware query execution.
      $request = $this->newTaskSearchRequest($viewer, $tokens);
      $engine = id(new ManiphestTaskSearchEngine())
        ->setViewer($viewer)
        ->setRequest($request);
      $saved = $engine->buildSavedQueryFromRequest($request);
      $query = $engine->buildQueryFromSavedQuery($saved);
      $pager = $engine->newPagerForSavedQuery($saved);
      $results = $engine->executeQuery($query, $pager);
      $actual = mpull($results, 'getPHID');
    }

    $expected = mpull($expect, 'getPHID');
    sort($expected);
    sort($actual);
    $this->assertEqual($expected, $actual,
      ($conduit ? 'Conduit: ' : 'Web: ').implode(', ', $tokens));
  }

  private function renderTaskSearch(PhabricatorUser $viewer, array $tokens) {
    $request = $this->newTaskSearchRequest($viewer, $tokens);
    $resource = new ReflectionProperty('CelerityAPI', 'response');
    $resource->setAccessible(true);
    $old_resource = $resource->getValue();
    $old_method = idx($_SERVER, 'REQUEST_METHOD');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    try {
      $resource->setValue(null, new CelerityStaticResourceResponse());
      $response = id(new ManiphestTaskListController())
        ->setRequest($request)
        ->setCurrentApplication(new PhabricatorManiphestApplication())
        ->handleRequest($request);
      $this->assertTrue($response instanceof PhabricatorStandardPageView);
      return (string)$response->produceAphrontResponse()->buildResponseString();
    } finally {
      $resource->setValue(null, $old_resource);
      if ($old_method === null) {
        unset($_SERVER['REQUEST_METHOD']);
      } else {
        $_SERVER['REQUEST_METHOD'] = $old_method;
      }
    }
  }

  private function editProjectViaConduit(
    PhabricatorProject $project,
    PhabricatorUser $viewer,
    array $transactions) {

    $viewer->setAllowInlineCacheGeneration(true);
    return id(new ConduitCall('project.edit', array(
      'objectIdentifier' => $project->getPHID(),
      'transactions' => $transactions,
    )))
      ->setUser($viewer)
      ->execute();
  }

  private function assertProjectMembers(
    PhabricatorProject $project,
    array $members,
    array $viewers) {

    $expect = mpull($members, 'getPHID');
    sort($expect);
    $member_type = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;
    $material_type = PhabricatorProjectMaterializedMemberEdgeType::EDGECONST;

    $direct = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $project->getPHID(), $member_type);
    sort($direct);
    $this->assertEqual($project->isMilestone() ? array() : $expect, $direct);
    $material = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $project->getPHID(), $material_type);
    sort($material);
    $this->assertEqual($expect, $material);

    foreach ($viewers as $viewer) {
      foreach (array(0, 1, 2) as $mode) {
        $loaded = id(new PhabricatorProjectQuery())
          ->setViewer($viewer)
          ->withPHIDs(array($project->getPHID()))
          ->needMembers($mode === 1)
          ->needAncestorMembers($mode === 2)
          ->executeOne();
        $this->assertEqual(in_array($viewer->getPHID(), $expect),
          $loaded->isUserMember($viewer->getPHID()));
        if ($mode) {
          $actual = $loaded->getMemberPHIDs();
          sort($actual);
          $this->assertEqual($expect, $actual);
        }
      }
      $search = id(new PhabricatorProjectQuery())
        ->setViewer($viewer)
        ->withPHIDs(array($project->getPHID()))
        ->withMemberPHIDs(array($viewer->getPHID()))
        ->execute();
      $this->assertEqual(in_array($viewer->getPHID(), $expect), (bool)$search);
    }

    $result = id(new ConduitCall('project.search', array(
      'constraints' => array('ids' => array((int)$project->getID())),
      'attachments' => array('members' => true),
    )))
      ->setUser(head($viewers))
      ->execute();
    $data = head($result['data']);
    $actual = ipull($data['attachments']['members']['members'], 'phid');
    sort($actual);
    $this->assertEqual($expect, $actual);
  }

  private function requestProjectController(
    PhabricatorProjectController $controller,
    PhabricatorProject $project,
    PhabricatorUser $viewer,
    $method,
    array $data = array()) {

    $viewer->setAllowInlineCacheGeneration(true);
    $old_method = idx($_SERVER, 'REQUEST_METHOD');
    $_SERVER['REQUEST_METHOD'] = $method;
    try {
      if ($method === 'POST') {
        $data[AphrontRequest::TYPE_FORM] = true;
        $data[AphrontRequest::getCSRFTokenName()] = $viewer->getCSRFToken();
      }
      $request = id(new AphrontRequest('phabricator.example.com',
        '/project/profile/'.$project->getID().'/'))
        ->setApplicationConfiguration(new AphrontApplicationConfiguration())
        ->setURIMap(array('id' => $project->getID()))
        ->setRequestData($data)
        ->setUser($viewer);
      $controller
        ->setRequest($request)
        ->setCurrentApplication(new PhabricatorProjectApplication());

      return $controller->handleRequest($request);
    } finally {
      if ($old_method === null) {
        unset($_SERVER['REQUEST_METHOD']);
      } else {
        $_SERVER['REQUEST_METHOD'] = $old_method;
      }
    }
  }

  private function newSubprojectEditFixture() {
    $parent_editor = $this->createUser()->save();
    $child_editor = $this->createUser()->save();
    $other = $this->createUser()->save();

    foreach (array($parent_editor, $child_editor, $other) as $user) {
      $user->setAllowInlineCacheGeneration(true);
    }

    $parent = $this->createProject($parent_editor);
    $this->joinProject($parent, $parent_editor);
    $child = $this->createProject($parent_editor, $parent);
    $this->joinProject($child, $parent_editor);
    $this->joinProject($child, $child_editor);

    // Seed independent policies directly, without requiring the creator to
    // retain edit access to the child. Both editors remain effective members.
    $parent
      ->setViewPolicy(PhabricatorPolicies::POLICY_USER)
      ->setEditPolicy($parent_editor->getPHID())
      ->save();
    $child
      ->setViewPolicy(PhabricatorPolicies::POLICY_USER)
      ->setEditPolicy($child_editor->getPHID())
      ->save();

    return array($parent, $child, $parent_editor, $child_editor, $other);
  }

  /**
   * Assert that the specified project is not joinable by the specified user.
   *
   * @param $project PhabricatorProject Test project
   * @param $user PhabricatorUser Test user
   * @param $assert_message string Test assert message
   */
  private function assertCannotJoinProject(
    PhabricatorProject $project,
    PhabricatorUser $user,
    string $assert_message) {
    $join_crashed = false;
    try {
      $this->joinProject($project, $user);
    } catch (PhabricatorApplicationTransactionValidationException $e) {
      // You cannot join this project.
      $join_crashed = true;
    }
    $this->assertTrue($join_crashed, $assert_message);
  }

  private function joinProject(
    PhabricatorProject $project,
    PhabricatorUser $user) {
    return $this->joinOrLeaveProject($project, $user, '+');
  }

  private function leaveProject(
    PhabricatorProject $project,
    PhabricatorUser $user) {
    return $this->joinOrLeaveProject($project, $user, '-');
  }

  private function watchProject(
    PhabricatorProject $project,
    PhabricatorUser $user) {
    return $this->watchOrUnwatchProject($project, $user, '+');
  }

  private function unwatchProject(
    PhabricatorProject $project,
    PhabricatorUser $user) {
    return $this->watchOrUnwatchProject($project, $user, '-');
  }

  private function joinOrLeaveProject(
    PhabricatorProject $project,
    PhabricatorUser $user,
    $operation) {
    return $this->applyProjectEdgeTransaction(
      $project,
      $user,
      $operation,
      PhabricatorProjectProjectHasMemberEdgeType::EDGECONST);
  }

  private function watchOrUnwatchProject(
    PhabricatorProject $project,
    PhabricatorUser $user,
    $operation) {
    return $this->applyProjectEdgeTransaction(
      $project,
      $user,
      $operation,
      PhabricatorObjectHasWatcherEdgeType::EDGECONST);
  }

  private function applyProjectEdgeTransaction(
    PhabricatorProject $project,
    PhabricatorUser $user,
    $operation,
    $edge_type) {

    $spec = array(
      $operation => array($user->getPHID() => $user->getPHID()),
    );

    $xactions = array();
    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type', $edge_type)
      ->setNewValue($spec);

    $this->applyTransactions($project, $user, $xactions);

    return $project;
  }

  private function applyTransactions(
    PhabricatorProject $project,
    PhabricatorUser $user,
    array $xactions) {

    $editor = id(new PhabricatorProjectTransactionEditor())
      ->setActor($user)
      ->setContentSource($this->newContentSource())
      ->setContinueOnNoEffect(true)
      ->applyTransactions($project, $xactions);
  }


}
