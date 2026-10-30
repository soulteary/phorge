<?php

final class PhabricatorProjectHandoffTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true);
  }

  protected function willRunOneTest($test) {
    parent::willRunOneTest($test);
    // Cross-schema locks must see committed fixtures on other connections.
    // Keep the disposable database fixture, but do not wrap each connection
    // in a separate uncommitted transaction for this integration suite.
    self::assertExecutingUnitTests();
    if (strpos(PhabricatorLiskDAO::getStorageNamespace(),
        'phabricator_unittest_') !== 0) {
      throw new Exception('Handoff tests require disposable databases.');
    }
    LiskDAO::endIsolateAllLiskEffectsToTransactions();
  }

  protected function didRunOneTest($test) {
    LiskDAO::beginIsolateAllLiskEffectsToTransactions();
    parent::didRunOneTest($test);
  }

  public function testCreatePrivateChildAndGrandchildForRecipient() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $parent = $this->newProject($alice);
    $middle = $this->newProject($alice, $parent);
    foreach (array($parent, $middle) as $target) {
      $result = $this->api($alice, null, array(
        array('type' => 'name', 'value' => $this->newName()),
        array('type' => 'parent', 'value' => $target->getPHID()),
        array('type' => 'members.set', 'value' => array($bob->getPHID())),
        array('type' => 'view', 'value' => 'obj.project.members'),
        array('type' => 'edit', 'value' => 'obj.project.members'),
        array('type' => 'join', 'value' => 'obj.project.members'),
      ));
      $id = $result['object']['id'];
      $this->assertFalse((bool)$this->load($id, $alice));
      $child = $this->load($id, $bob);
      $this->assertTrue($this->canEdit($child, $bob));
      $this->assertEqual(array($bob->getPHID()), $child->getMemberPHIDs());
      $this->assertEqual(array($alice->getPHID()),
        $this->load($target->getID(), $alice)->getMemberPHIDs());
    }
  }

  public function testExistingProjectTransfersThroughNativeCustomPolicy() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $project = $this->newProject($alice);
    $policy = $this->newPolicy($bob);
    $this->api($alice, $project->getID(), array(
      array('type' => 'edit', 'value' => $policy->getPHID()),
    ));
    $project = $this->load($project->getID(), $alice);
    $this->assertFalse($this->canEdit($project, $alice));
    $this->assertTrue($this->canEdit($project, $bob));
    $name = $this->newName();
    $this->api($bob, $project->getID(), array(
      array('type' => 'name', 'value' => $name),
    ));
    $this->assertEqual($name, $this->load($project->getID(), $bob)->getName());
    $this->expectRejected(function() use ($alice, $project) {
      $this->api($alice, $project->getID(), array(
        array('type' => 'name', 'value' => $this->newName()),
        array('type' => 'edit', 'value' => $alice->getPHID()),
      ));
    });
    $this->assertEqual($name, $this->load($project->getID(), $bob)->getName());
  }

  public function testMissingDisabledAndUnapprovedRecipientsAreRejected() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $project = $this->newProject($alice);
    foreach (array('missing', 'disabled', 'unapproved', 'system') as $mode) {
      $bob->setIsDisabled((int)($mode === 'disabled'))
        ->setIsApproved($mode === 'unapproved' ? 0 : 1)
        ->setIsSystemAgent((int)($mode === 'system'))->save();
      $policy = $mode === 'missing'
        ? PhabricatorPolicies::POLICY_NOONE : $bob->getPHID();
      $this->expectRejected(function() use ($alice, $project, $policy) {
        $this->api($alice, $project->getID(), array(
          array('type' => 'name', 'value' => $this->newName()),
          array('type' => 'edit', 'value' => $policy),
        ));
      });
      $current = $this->load($project->getID(), $alice);
      $this->assertEqual($project->getName(), $current->getName());
      $this->assertEqual($alice->getPHID(), $current->getEditPolicy());
    }
  }

  public function testRecipientMustActuallyHaveViewAccess() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $project = $this->newProject($alice);
    $this->expectRejected(function() use ($alice, $bob, $project) {
      $this->api($alice, $project->getID(), array(
        array('type' => 'view', 'value' => $alice->getPHID()),
        array('type' => 'edit', 'value' => $bob->getPHID()),
      ));
    });
    // Membership provides native automatic view access, even though the
    // explicit view policy still selects Alice.
    $this->api($alice, $project->getID(), array(
      array('type' => 'view', 'value' => $alice->getPHID()),
      array('type' => 'edit', 'value' => $bob->getPHID()),
      array('type' => 'members.set', 'value' => array($bob->getPHID())),
    ));
    $this->assertTrue($this->canEdit($this->load($project->getID(), $bob), $bob));
  }

  public function testCustomDenyTakesPrecedence() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $project = $this->newProject($alice);
    $policy = $this->newPolicy($bob, true);
    $this->expectRejected(function() use ($alice, $project, $policy) {
      $this->api($alice, $project->getID(), array(
        array('type' => 'edit', 'value' => $policy->getPHID()),
      ));
    });
  }

  public function testLastEditingMemberCanNotLeave() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $project = $this->newProject($alice);
    $this->api($alice, $project->getID(), array(
      array('type' => 'edit', 'value' => 'obj.project.members'),
    ));
    $this->expectRejected(function() use ($alice, $project) {
      $this->api($alice, $project->getID(), array(
        array('type' => 'members.remove', 'value' => array($alice->getPHID())),
      ));
    });
    $this->api($alice, $project->getID(), array(
      array('type' => 'members.set', 'value' => array($bob->getPHID())),
    ));
    $this->assertFalse($this->canEdit($this->load($project->getID(), $alice), $alice));
    $this->assertTrue($this->canEdit($this->load($project->getID(), $bob), $bob));
  }

  public function testConfirmationIsRequiredBoundAndRevalidated() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $project = $this->newProject($alice);
    $data = array('editEngine' => 'true', 'name' => $project->getName(),
      'icon' => $project->getIcon(), 'color' => $project->getColor(),
      'policy.view' => 'users', 'policy.edit' => $bob->getPHID(),
      'policy.join' => 'users');
    $first = $this->web($project->getID(), $alice, $data);
    $this->assertTrue($first instanceof AphrontDialogResponse, get_class($first));
    $html = $first->getDialog()->render();
    $fields = $this->confirmationFields((string)$html);
    $this->assertEqual($alice->getPHID(),
      $this->load($project->getID(), $alice)->getEditPolicy());
    // Cancel is a GET: it must not apply the pending transactions.
    $this->web($project->getID(), $alice, array(), 'GET');
    $this->assertEqual($alice->getPHID(),
      $this->load($project->getID(), $alice)->getEditPolicy());

    $forged = $data + array(PhabricatorProjectHandoff::TOKEN => 'forged',
      PhabricatorProjectHandoff::EXPIRES => time() + 800);
    $this->assertTrue($this->web($project->getID(), $alice, $forged)
      instanceof AphrontDialogResponse);
    $changed = $data;
    $changed['name'] = $this->newName();
    $this->assertTrue($this->web($project->getID(), $alice, $changed + $fields)
      instanceof AphrontDialogResponse);
    $this->assertEqual($project->getName(),
      $this->load($project->getID(), $alice)->getName());

    $result = $this->web($project->getID(), $alice, $data + $fields);
    $this->assertTrue($result instanceof AphrontRedirectResponse);
    $this->assertFalse($this->canEdit($this->load($project->getID(), $alice), $alice));
    $this->assertTrue($this->canEdit($this->load($project->getID(), $bob), $bob));
    $denied = false;
    try {
      $response = $this->web($project->getID(), $alice, $data + $fields);
      $denied = $response instanceof Aphront404Response;
    } catch (PhabricatorPolicyException $ex) {
      $denied = true;
    }
    $this->assertTrue($denied);
  }

  public function testConcurrentRevocationRejectsStaleEditor() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $project = $this->newProject($alice);
    $stale = $this->load($project->getID(), $alice);
    $this->api($alice, $project->getID(), array(
      array('type' => 'edit', 'value' => $bob->getPHID()),
    ));
    $this->expectRejected(function() use ($alice, $stale) {
      $this->apply($stale, $alice, array($this->xaction(
        PhabricatorProjectNameTransaction::TRANSACTIONTYPE, $this->newName())));
    });
    $this->assertEqual($project->getName(),
      $this->load($project->getID(), $bob)->getName());
  }

  public function testWarningURLRedirectAndMilestoneInheritance() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $parent = $this->newProject($alice);
    $request = $this->request($parent->getID(), $alice, array(), 'GET');
    $response = id(new PhabricatorProjectSubprojectWarningController())
      ->setRequest($request)
      ->setCurrentApplication(new PhabricatorProjectApplication())
      ->handleRequest($request);
    $this->assertTrue($response instanceof AphrontRedirectResponse);
    $this->assertEqual(
      'http://phabricator.example.com/project/edit/?parent='.$parent->getID(),
      $response->getURI());
    $result = $this->api($alice, null, array(
      array('type' => 'name', 'value' => $this->newName()),
      array('type' => 'milestone', 'value' => $parent->getPHID()),
    ));
    $milestone = $this->load($result['object']['id'], $alice);
    $this->assertTrue($milestone->isMilestone());
    $this->assertEqual($parent->getEditPolicy(), $milestone->getPolicy('edit'));
    $this->assertFalse($this->canEdit($milestone, $bob));
  }

  public function testSpaceAccessIsRequiredForRecipient() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $project = $this->newProject($alice);
    $space = PhabricatorSpacesNamespace::initializeNewNamespace($alice)
      ->setNamespaceName($this->newName())->setViewPolicy($alice->getPHID())
      ->setEditPolicy($alice->getPHID())->save();
    try {
      $project->setSpacePHID($space->getPHID())->save();
      PhabricatorCaches::destroyRequestCache();
      $this->expectRejected(function() use ($alice, $bob, $project) {
        $this->api($alice, $project->getID(), array(
          array('type' => 'edit', 'value' => $bob->getPHID()),
        ));
      });
      $this->assertEqual($alice->getPHID(),
        $this->load($project->getID(), $alice)->getEditPolicy());
      $space->setViewPolicy(PhabricatorPolicies::POLICY_USER)->save();
      PhabricatorCaches::destroyRequestCache();
      $this->api($alice, $project->getID(), array(
        array('type' => 'edit', 'value' => $bob->getPHID()),
      ));
      $this->assertTrue($this->canEdit($this->load($project->getID(), $bob), $bob));
    } finally {
      $space->delete();
      PhabricatorCaches::destroyRequestCache();
    }
  }

  public function testWebCreationConfirmationAndNoCreatorPrivilege() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $parent = $this->newProject($alice);
    $name = $this->newName();
    $data = array('editEngine' => 'true', 'name' => $name,
      'parent' => (string)$parent->getID(), 'members' => array($bob->getPHID()),
      'icon' => $parent->getIcon(), 'color' => $parent->getColor(),
      'policy.view' => 'obj.project.members',
      'policy.edit' => 'obj.project.members',
      'policy.join' => 'obj.project.members');
    $response = $this->web(null, $alice, $data);
    $this->assertTrue($response instanceof AphrontDialogResponse, get_class($response));
    $fields = $this->confirmationFields((string)$response->getDialog()->render());
    $this->assertFalse((bool)id(new PhabricatorProject())->loadOneWhere('name = %s', $name));
    $this->web(null, $alice, $data + $fields);
    $created = id(new PhabricatorProject())->loadOneWhere('name = %s', $name);
    $this->assertTrue((bool)$created);
    $this->assertFalse((bool)$this->load($created->getID(), $alice));
    $this->assertTrue($this->canEdit($this->load($created->getID(), $bob), $bob));
    $this->assertEqual(array($alice->getPHID()),
      $this->load($parent->getID(), $alice)->getMemberPHIDs());
  }

  public function testRecipientRevokedWhileConfirmationIsOpen() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $project = $this->newProject($alice);
    $data = array('editEngine' => 'true', 'name' => $project->getName(),
      'icon' => $project->getIcon(), 'color' => $project->getColor(),
      'policy.view' => 'users', 'policy.edit' => $bob->getPHID(),
      'policy.join' => 'users');
    $response = $this->web($project->getID(), $alice, $data);
    $this->assertTrue($response instanceof AphrontDialogResponse);
    $fields = $this->confirmationFields((string)$response->getDialog()->render());
    $bob->setIsDisabled(1)->save();
    $response = $this->web($project->getID(), $alice, $data + $fields);
    $this->assertTrue($response instanceof PhabricatorStandardPageView);
    $this->assertEqual($alice->getPHID(),
      $this->load($project->getID(), $alice)->getEditPolicy());
  }

  public function testConcurrentRecipientDisableIsObserved() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $project = $this->newProject($alice);
    $marker = new TempFile();
    Filesystem::remove((string)$marker);
    $root = dirname(phutil_get_library_root('phabricator'));
    $program = <<<'PHP'
require $argv[1].'/scripts/__init_script__.php';
if (strpos($argv[2], 'phabricator_unittest_') !== 0) { exit(7); }
PhabricatorLiskDAO::pushStorageNamespace($argv[2]);
$u = new PhabricatorUser(); $c = $u->establishConnection('w');
$u->openTransaction();
queryfx($c, 'UPDATE %R SET isDisabled = 1 WHERE phid = %s', $u, $argv[3]);
file_put_contents($argv[4], 'locked');
usleep(300000);
$u->saveTransaction();
PHP;
    $future = new ExecFuture('%s -r %s %s %s %s %s', PHP_BINARY, $program,
      $root, PhabricatorLiskDAO::getStorageNamespace(), $bob->getPHID(),
      (string)$marker);
    $future->start();
    $ready = false;
    for ($ii = 0; $ii < 200; $ii++) {
      if (Filesystem::pathExists((string)$marker)) { $ready = true; break; }
      if ($future->isReady()) { break; }
      usleep(10000);
    }
    $this->assertTrue($ready, 'Concurrent writer acquired recipient row lock.');
    try {
      $this->expectRejected(function() use ($alice, $bob, $project) {
        $this->api($alice, $project->getID(), array(
          array('type' => 'edit', 'value' => $bob->getPHID()),
        ));
      });
    } finally {
      $future->resolvex();
    }
    $this->assertEqual($alice->getPHID(),
      $this->load($project->getID(), $alice)->getEditPolicy());
  }

  public function testCreateAndParentPermissionsRemainRequired() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $parent = $this->newProject($bob);
    $transactions = array(
      array('type' => 'name', 'value' => $this->newName()),
      array('type' => 'parent', 'value' => $parent->getPHID()),
      array('type' => 'members.set', 'value' => array($bob->getPHID())),
      array('type' => 'edit', 'value' => 'obj.project.members'),
    );
    $this->expectRejected(function() use ($alice, $transactions) {
      $this->api($alice, null, $transactions);
    });
    $env = PhabricatorEnv::beginScopedEnv();
    try {
      $env->overrideEnvConfig('phabricator.application-settings', array(
        'PHID-APPS-PhabricatorProjectApplication' => array('policy' => array(
          ProjectCreateProjectsCapability::CAPABILITY => PhabricatorPolicies::POLICY_NOONE,
        )),
      ));
      PhabricatorCaches::destroyRequestCache();
      $this->expectRejected(function() use ($bob, $transactions) {
        $this->api($bob, null, $transactions);
      });
      $this->expectRejected(function() use ($bob) {
        $this->newProject($bob);
      });
    } finally {
      unset($env);
      PhabricatorCaches::destroyRequestCache();
    }
  }

  public function testMembershipHandoffUsesOriginalAuthorization() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $project = $this->newProject($alice);
    $this->api($alice, $project->getID(), array(
      array('type' => 'edit', 'value' => 'obj.project.members'),
    ));
    $this->api($alice, $project->getID(), array(
      array('type' => 'members.set', 'value' => array($bob->getPHID())),
    ));
    $result = $this->load($project->getID(), $bob);
    $this->assertTrue($this->canEdit($result, $bob));
    $this->assertFalse($this->canEdit($result, $alice));
    $this->assertEqual(array($bob->getPHID()), $result->getMemberPHIDs());
  }

  public function testRecipientNeedsProjectApplicationAccess() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $project = $this->newProject($alice);
    $env = PhabricatorEnv::beginScopedEnv();
    try {
      $env->overrideEnvConfig('phabricator.application-settings', array(
        'PHID-APPS-PhabricatorProjectApplication' => array('policy' => array(
          PhabricatorPolicyCapability::CAN_VIEW => $alice->getPHID(),
        )),
      ));
      PhabricatorCaches::destroyRequestCache();
      $this->expectRejected(function() use ($alice, $bob, $project) {
        $this->api($alice, $project->getID(), array(
          array('type' => 'edit', 'value' => $bob->getPHID()),
        ));
      });
      $this->assertEqual($alice->getPHID(),
        $this->load($project->getID(), $alice)->getEditPolicy());
    } finally {
      unset($env);
      PhabricatorCaches::destroyRequestCache();
    }
  }

  public function testRevokedSpaceAuthorizationIsRechecked() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $project = $this->newProject($alice);
    $space = PhabricatorSpacesNamespace::initializeNewNamespace($alice)
      ->setNamespaceName($this->newName())
      ->setViewPolicy(PhabricatorPolicies::POLICY_USER)
      ->setEditPolicy($alice->getPHID())->save();
    $project->setSpacePHID($space->getPHID())->save();
    PhabricatorCaches::destroyRequestCache();
    // Cache the pre-change Space as a real request may do before applying
    // project transactions. Only Bob will have Space access after the race.
    $this->assertTrue((bool)$this->load($project->getID(), $alice));
    $marker = new TempFile();
    Filesystem::remove((string)$marker);
    $root = dirname(phutil_get_library_root('phabricator'));
    $program = <<<'PHP'
require $argv[1].'/scripts/__init_script__.php';
if (strpos($argv[2], 'phabricator_unittest_') !== 0) { exit(7); }
PhabricatorLiskDAO::pushStorageNamespace($argv[2]);
$s = new PhabricatorSpacesNamespace(); $c = $s->establishConnection('w');
$s->openTransaction();
queryfx($c, 'UPDATE %R SET viewPolicy = %s WHERE phid = %s', $s, $argv[4], $argv[3]);
file_put_contents($argv[5], 'locked');
usleep(300000);
$s->saveTransaction();
PHP;
    $future = new ExecFuture('%s -r %s %s %s %s %s %s', PHP_BINARY, $program,
      $root, PhabricatorLiskDAO::getStorageNamespace(), $space->getPHID(),
      $bob->getPHID(), (string)$marker);
    $future->start();
    try {
      $ready = false;
      for ($ii = 0; $ii < 200; $ii++) {
        if (Filesystem::pathExists((string)$marker)) { $ready = true; break; }
        if ($future->isReady()) { break; }
        usleep(10000);
      }
      $this->assertTrue($ready);
      $this->expectRejected(function() use ($alice, $bob, $project) {
        $this->api($alice, $project->getID(), array(
          array('type' => 'edit', 'value' => $bob->getPHID()),
          array('type' => 'name', 'value' => $this->newName()),
        ));
      });
      $fresh = id(new PhabricatorProject())->load($project->getID());
      $this->assertEqual($project->getName(), $fresh->getName());
      $this->assertEqual($alice->getPHID(), $fresh->getEditPolicy());
    } finally {
      $future->resolvex();
      $space->delete();
      PhabricatorCaches::destroyRequestCache();
    }
  }

  public function testUnsafeAndInvalidPoliciesAreRejectedAtomically() {
    $alice = $this->newUser();
    $bob = $this->newUser();
    $project = $this->newProject($alice);
    $policy = id(new PhabricatorPolicy())->setRules(array(
      array('rule' => 'PhabricatorUsersPolicyRule',
        'action' => 'deny', 'value' => array($alice->getPHID())),
      array('rule' => 'PhabricatorLunarPhasePolicyRule',
        'action' => 'allow', 'value' => array()),
    ))->setDefaultAction(PhabricatorPolicy::ACTION_ALLOW)->save();
    foreach (array($policy->getPHID(), 'obj.maniphest.author',
                   'PHID-PLCY-doesnotexist00000000') as $value) {
      $this->expectRejected(function() use ($alice, $project, $value) {
        $this->api($alice, $project->getID(), array(
          array('type' => 'edit', 'value' => $value),
          array('type' => 'name', 'value' => $this->newName()),
        ));
      });
      $this->assertEqual($project->getName(),
        $this->load($project->getID(), $alice)->getName());
    }
  }

  private function newUser() {
    return $this->generateNewTestUser()->setAllowInlineCacheGeneration(true);
  }

  private function newName() {
    return 'Handoff '.Filesystem::readRandomCharacters(16);
  }

  private function newProject($alice, $parent = null) {
    $project = PhabricatorProject::initializeNewProject($alice, $parent);
    $xactions = array(
      $this->xaction(PhabricatorProjectNameTransaction::TRANSACTIONTYPE, $this->newName()),
      $this->xaction(PhabricatorTransactions::TYPE_EDIT_POLICY, $alice->getPHID()),
      $this->xaction(PhabricatorTransactions::TYPE_EDGE,
        array('+' => array($alice->getPHID() => $alice->getPHID())))
        ->setMetadataValue('edge:type', PhabricatorProjectProjectHasMemberEdgeType::EDGECONST),
    );
    if ($parent) {
      $xactions[] = $this->xaction(
        PhabricatorProjectParentTransaction::TRANSACTIONTYPE, $parent->getPHID());
    }
    $this->apply($project, $alice, $xactions);
    return $this->load($project->getID(), $alice);
  }

  private function newPolicy($bob, $deny = false) {
    $rule = array('rule' => 'PhabricatorUsersPolicyRule',
      'value' => array($bob->getPHID()), 'action' => 'allow');
    $rules = array($rule);
    if ($deny) {
      array_unshift($rules, array('action' => 'deny') + $rule);
    }
    return id(new PhabricatorPolicy())->setRules($rules)
      ->setDefaultAction(PhabricatorPolicy::ACTION_DENY)->save();
  }

  private function xaction($type, $value) {
    return id(new PhabricatorProjectTransaction())
      ->setTransactionType($type)->setNewValue($value);
  }

  private function apply($project, $actor, array $xactions) {
    return id(new PhabricatorProjectTransactionEditor())
      ->setActor($actor)->setContentSource($this->newContentSource())
      ->setContinueOnNoEffect(true)->applyTransactions($project, $xactions);
  }

  private function api($actor, $id, array $transactions) {
    return id(new ConduitCall('project.edit', array(
      'objectIdentifier' => $id, 'transactions' => $transactions)))
      ->setUser($actor)->execute();
  }

  private function load($id, $actor) {
    return head(id(new PhabricatorProjectQuery())->setViewer($actor)
      ->withIDs(array($id))->needMembers(true)->execute());
  }

  private function canEdit($project, $actor) {
    return $project && PhabricatorPolicyFilter::hasCapability(
      $actor, $project, PhabricatorPolicyCapability::CAN_EDIT);
  }

  private function expectRejected($call) {
    $caught = false;
    try {
      $call();
    } catch (Exception $ex) {
      $this->assertTrue(
        $ex instanceof PhabricatorPolicyException ||
        $ex instanceof PhabricatorApplicationTransactionValidationException ||
        $ex instanceof ConduitException,
        'Expected a permission/validation rejection, got '.get_class($ex));
      $caught = true;
    }
    $this->assertTrue($caught);
  }

  private function request($id, $actor, array $data, $method) {
    if ($method === 'POST') {
      $data[AphrontRequest::TYPE_FORM] = true;
      $data[AphrontRequest::getCSRFTokenName()] = $actor->getCSRFToken();
    }
    $path = $id ? '/project/edit/'.$id.'/' : '/project/edit/';
    return id(new AphrontRequest('phabricator.example.com', $path))
      ->setApplicationConfiguration(new AphrontApplicationConfiguration())
      ->setURIMap(array('id' => $id))->setRequestData($data)->setUser($actor);
  }

  private function web($id, $actor, array $data, $method = 'POST') {
    $old = idx($_SERVER, 'REQUEST_METHOD');
    $_SERVER['REQUEST_METHOD'] = $method;
    try {
      $request = $this->request($id, $actor, $data, $method);
      return id(new PhabricatorProjectEditController())->setRequest($request)
        ->setCurrentApplication(new PhabricatorProjectApplication())
        ->handleRequest($request);
    } finally {
      if ($old === null) { unset($_SERVER['REQUEST_METHOD']); }
      else { $_SERVER['REQUEST_METHOD'] = $old; }
    }
  }

  private function confirmationFields($html) {
    $fields = array();
    foreach (array(PhabricatorProjectHandoff::TOKEN, PhabricatorProjectHandoff::EXPIRES) as $key) {
      $matches = array();
      preg_match('/name="'.preg_quote($key, '/').'"[^>]*value="([^"]*)"/', $html, $matches);
      $this->assertTrue(isset($matches[1]), $key.' is rendered');
      $fields[$key] = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }
    return $fields;
  }

}
