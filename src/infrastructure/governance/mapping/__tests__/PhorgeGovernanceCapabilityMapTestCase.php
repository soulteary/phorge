<?php

final class PhorgeGovernanceCapabilityMapTestCase extends PhutilTestCase {

  private function descriptor($class, $type, array $metadata = array()) {
    return array(
      'transaction_class' => $class,
      'transaction_type' => $type,
      'metadata' => $metadata,
    );
  }

  public function testDefinitionsUseRealNativeClassesAndConstants() {
    $ids = array();
    foreach (PhorgeGovernanceCapabilityMap::getDefinitions() as $row) {
      $this->assertFalse(isset($ids[$row['mapping_id']]));
      $ids[$row['mapping_id']] = true;
      $object = new ReflectionClass($row['object_class']);
      $envelope = new ReflectionClass($row['transaction_class']);
      $handler = new ReflectionClass($row['handler_class']);
      $this->assertFalse($object->isAbstract());
      $this->assertTrue($envelope->isSubclassOf(
        PhabricatorApplicationTransaction::class));
      $this->assertTrue($handler->getFileName() !== false);
      $this->assertEqual(constant($row['transaction_constant']),
        $row['transaction_type']);
      if ($handler->isSubclassOf(PhabricatorModularTransactionType::class)) {
        $instance = $handler->newInstance();
        $this->assertEqual($instance->getTransactionTypeConstant(),
          $row['transaction_type']);
      }
      $this->assertEqual('partial', $row['coverage']);
      $this->assertTrue($row['activation_blocked']);
      $this->assertFalse($row['risk_complete']);
      $this->assertFalse($row['can_execute']);
      $this->assertEqual(PhabricatorPolicyCapability::CAN_VIEW,
        $row['native_requirements']['view']);
      $this->assertEqual(PhabricatorPolicyCapability::CAN_EDIT,
        $row['native_requirements']['native_edit_path']);
    }
    $this->assertTrue(count($ids) > 20);
  }

  public function testBuiltinProjectDescriptionUsesActualCustomFieldKey() {
    $template = new PhabricatorProjectDescriptionField();
    $fields = $template->createFields(null);
    $this->assertEqual(1, count($fields));
    $this->assertEqual('std:project:internal:description',
      $fields[0]->getFieldKey());
    $row = PhorgeGovernanceCapabilityMap::classify(
      PhabricatorProject::class, PhabricatorProjectTransaction::class,
      PhabricatorTransactions::TYPE_CUSTOMFIELD,
      array('customfield:key' => $fields[0]->getFieldKey()));
    $this->assertEqual('project.description', $row['operation']);
    $this->assertEqual('content-candidate', $row['classification']);
    $this->assertFalse($row['can_execute']);
  }

  public function testModularContentMapsByObjectAndEnvelopeNotBareType() {
    $task = PhorgeGovernanceCapabilityMap::classify(
      ManiphestTask::class, ManiphestTransaction::class,
      ManiphestTaskTitleTransaction::TRANSACTIONTYPE);
    $wiki = PhorgeGovernanceCapabilityMap::classify(
      PhrictionDocument::class, PhrictionTransaction::class,
      PhrictionDocumentTitleTransaction::TRANSACTIONTYPE);
    $this->assertEqual('task.title', $task['operation']);
    $this->assertEqual('wiki.title', $wiki['operation']);
    $this->assertEqual('low', $task['risk']);
    // Same literal type (title) must not mix two objects or handlers.
    $this->assertEqual($task['transaction_type'], $wiki['transaction_type']);
    foreach (array(
      array(ManiphestTask::class, PhrictionTransaction::class),
      array(PhrictionDocument::class, ManiphestTransaction::class),
      array(PhabricatorProject::class, ManiphestTransaction::class),
      array(ManiphestTask::class, ManiphestTaskTitleTransaction::class),
    ) as $pair) {
      $result = PhorgeGovernanceCapabilityMap::classify(
        $pair[0], $pair[1], ManiphestTaskTitleTransaction::TRANSACTIONTYPE);
      $this->assertEqual('blocked', $result['status']);
      $this->assertFalse($result['can_execute']);
    }
  }

  public function testWikiContentRequiresValueAndExpansionReview() {
    foreach (array(PhrictionDocumentTitleTransaction::TRANSACTIONTYPE,
      PhrictionDocumentContentTransaction::TRANSACTIONTYPE) as $type) {
      $result = PhorgeGovernanceCapabilityMap::classify(
        PhrictionDocument::class, PhrictionTransaction::class, $type);
      $this->assertEqual('content-candidate', $result['classification']);
      $this->assertEqual('high', $result['risk']);
      $this->assertFalse($result['risk_complete']);
      $this->assertFalse(in_array('WIKI.COMMENT.ENGINE',
        $result['source_ids'], true));
      $this->assertFalse(in_array('WIKI.EDIT.CONDUIT',
        $result['source_ids'], true));
      $this->assertTrue(in_array('WIKI.EDIT.CONDUIT.LEGACY',
        $result['source_ids'], true));
    }
  }

  public function testPolicyAndSpaceAreSensitiveWithoutCallerRisk() {
    foreach (array(
      PhabricatorProject::class => PhabricatorProjectTransaction::class,
      ManiphestTask::class => ManiphestTransaction::class,
      PhrictionDocument::class => PhrictionTransaction::class,
    ) as $object => $envelope) {
      foreach (array(PhabricatorTransactions::TYPE_VIEW_POLICY,
        PhabricatorTransactions::TYPE_EDIT_POLICY,
        PhabricatorTransactions::TYPE_SPACE) as $type) {
        $result = PhorgeGovernanceCapabilityMap::classify(
          $object, $envelope, $type);
        $this->assertEqual('sensitive', $result['classification']);
        $this->assertEqual('high', $result['risk']);
        $this->assertFalse($result['can_execute']);
        $injected = PhorgeGovernanceCapabilityMap::classify(
          $object, $envelope, $type, array('risk' => 'low'));
        $this->assertEqual('blocked', $injected['status']);
      }
    }
    $join = PhorgeGovernanceCapabilityMap::classify(
      PhabricatorProject::class, PhabricatorProjectTransaction::class,
      PhabricatorTransactions::TYPE_JOIN_POLICY);
    $this->assertEqual('policy.replace', $join['operation']);
    $task_join = PhorgeGovernanceCapabilityMap::classify(
      ManiphestTask::class, ManiphestTransaction::class,
      PhabricatorTransactions::TYPE_JOIN_POLICY);
    $this->assertEqual('blocked', $task_join['status']);
  }

  public function testProjectMemberEdgeIsStrictAndNotAnImplicitJoinGrant() {
    $result = PhorgeGovernanceCapabilityMap::classify(
      PhabricatorProject::class, PhabricatorProjectTransaction::class,
      PhabricatorTransactions::TYPE_EDGE,
      array('edge:type' => PhabricatorProjectProjectHasMemberEdgeType::EDGECONST));
    $this->assertEqual('project.members', $result['operation']);
    $this->assertEqual('high', $result['risk']);
    $edge = new PhabricatorProjectProjectHasMemberEdgeType();
    $this->assertEqual(PhabricatorProjectMemberOfProjectEdgeType::EDGECONST,
      $edge->getInverseEdgeConstant());
    foreach (array(array(),
      array('edge:type' => PhabricatorProjectMemberOfProjectEdgeType::EDGECONST),
      array('edge:type' => (string)PhabricatorProjectProjectHasMemberEdgeType::EDGECONST),
      array('edge:type' => 999999)) as $metadata) {
      $unknown = PhorgeGovernanceCapabilityMap::classify(
        PhabricatorProject::class, PhabricatorProjectTransaction::class,
        PhabricatorTransactions::TYPE_EDGE, $metadata);
      $this->assertEqual('blocked', $unknown['status']);
    }
  }

  public function testHistoricalMemberTypeIsRecognizedButNotWritable() {
    $result = PhorgeGovernanceCapabilityMap::classify(
      PhabricatorProject::class, PhabricatorProjectTransaction::class,
      PhorgeProjectLegacyMembersTransaction::TRANSACTIONTYPE);
    $this->assertEqual('project.members', $result['operation']);
    $this->assertEqual('sensitive', $result['classification']);
    $this->assertEqual('blocked', $result['status']);
    $batch = PhorgeGovernanceCapabilityMap::classifyBatch(
      PhabricatorProject::class, array($this->descriptor(
        PhabricatorProjectTransaction::class,
        PhorgeProjectLegacyMembersTransaction::TRANSACTIONTYPE)));
    $this->assertEqual('blocked', $batch['status']);
  }

  public function testRelationshipEffectsStayConservativelySensitive() {
    foreach (array(
      array(PhabricatorProject::class, PhabricatorProjectTransaction::class,
        PhabricatorProjectParentTransaction::TRANSACTIONTYPE),
      array(PhabricatorProject::class, PhabricatorProjectTransaction::class,
        PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE),
      array(ManiphestTask::class, ManiphestTransaction::class,
        ManiphestTaskOwnerTransaction::TRANSACTIONTYPE),
      array(ManiphestTask::class, ManiphestTransaction::class,
        ManiphestTaskColumnTransaction::TRANSACTIONTYPE),
      array(PhrictionDocument::class, PhrictionTransaction::class,
        PhrictionDocumentMoveToTransaction::TRANSACTIONTYPE),
    ) as $triple) {
      $result = PhorgeGovernanceCapabilityMap::classify(
        $triple[0], $triple[1], $triple[2]);
      $this->assertEqual('object.relationship', $result['operation']);
      $this->assertEqual('high', $result['risk']);
      $this->assertFalse($result['can_execute']);
    }
    $tag = PhorgeGovernanceCapabilityMap::classify(
      ManiphestTask::class, ManiphestTransaction::class,
      PhabricatorTransactions::TYPE_EDGE,
      array('edge:type' => PhabricatorProjectObjectHasProjectEdgeType::EDGECONST));
    $this->assertEqual('object.relationship', $tag['operation']);
  }

  public function testUnknownCustomFieldsStatusAndEdgesDoNotBecomeContent() {
    foreach (array(
      $this->descriptor(PhabricatorProjectTransaction::class,
        PhabricatorTransactions::TYPE_CUSTOMFIELD,
        array('customfield:key' => 'custom:unreviewed')),
      $this->descriptor(PhabricatorProjectTransaction::class,
        PhabricatorTransactions::TYPE_EDGE, array('edge:type' => 999999)),
      $this->descriptor(PhabricatorProjectTransaction::class, 'unknown'),
    ) as $descriptor) {
      $result = PhorgeGovernanceCapabilityMap::classifyBatch(
        PhabricatorProject::class, array($descriptor));
      $this->assertEqual('blocked', $result['status']);
    }
    $status = PhorgeGovernanceCapabilityMap::classify(
      ManiphestTask::class, ManiphestTransaction::class,
      ManiphestTaskStatusTransaction::TRANSACTIONTYPE);
    $this->assertEqual('blocked', $status['status']);
  }

  public function testBatchRiskCannotBeDilutedByContentOrOrdering() {
    $title = $this->descriptor(ManiphestTransaction::class,
      ManiphestTaskTitleTransaction::TRANSACTIONTYPE);
    $description = $this->descriptor(ManiphestTransaction::class,
      ManiphestTaskDescriptionTransaction::TRANSACTIONTYPE);
    $policy = $this->descriptor(ManiphestTransaction::class,
      PhabricatorTransactions::TYPE_VIEW_POLICY);
    $content = PhorgeGovernanceCapabilityMap::classifyBatch(
      ManiphestTask::class, array($title, $description));
    $this->assertEqual('low', $content['risk']);
    $this->assertFalse($content['risk_complete']);
    foreach (array(array($title, $policy), array($policy, $title)) as $batch) {
      $result = PhorgeGovernanceCapabilityMap::classifyBatch(
        ManiphestTask::class, $batch);
      $this->assertEqual('high', $result['risk']);
      $this->assertEqual('contains-sensitive', $result['classification']);
      $this->assertFalse($result['can_execute']);
    }
  }

  public function testMalformedMixedObjectOrUnknownBatchIsWhollyBlocked() {
    $title = $this->descriptor(ManiphestTransaction::class,
      ManiphestTaskTitleTransaction::TRANSACTIONTYPE);
    $forged = $title;
    $forged['risk'] = 'low';
    foreach (array(array(), array('named' => $title), array('not-a-descriptor'),
      array($forged), array($title, $this->descriptor(PhrictionTransaction::class,
        PhrictionDocumentTitleTransaction::TRANSACTIONTYPE)),
      array($title, $this->descriptor(ManiphestTransaction::class, 'unknown')),
      array_fill(0, 101, $title)) as $batch) {
      $result = PhorgeGovernanceCapabilityMap::classifyBatch(
        ManiphestTask::class, $batch);
      $this->assertEqual('blocked', $result['status']);
      $this->assertFalse($result['can_execute']);
      $this->assertFalse(isset($result['items']));
    }
  }

  public function testSourceKindsAreBoundToNativeObjectFamily() {
    foreach (array(true, 1, array(), new stdClass()) as $unsupported_class) {
      $this->assertEqual(array(),
        PhorgeGovernanceCapabilityMap::getKnownOperations($unsupported_class));
    }
    foreach (array(
      PhabricatorProject::class => 'project.policy',
      ManiphestTask::class => 'task.owner',
      PhrictionDocument::class => 'wiki.parent',
    ) as $object => $specific_kind) {
      foreach (PhorgeGovernanceCapabilityMap::getKnownOperations($object) as $op) {
        $kinds = PhorgeGovernanceCapabilityMap::getRequiredSourceKinds($op, $object);
        $this->assertTrue(in_array($specific_kind, $kinds, true));
        foreach (array('user.account', 'auth.session', 'app.capability',
          'space.policy', 'policy.named', 'org.membership', 'org.appointment',
          'project.member', 'project.parent')
          as $common) {
          $this->assertTrue(in_array($common, $kinds, true));
        }
      }
    }
    foreach (array(array('task.title', PhrictionDocument::class),
      array('project.members', ManiphestTask::class),
      array('unknown', PhabricatorProject::class)) as $pair) {
      $caught = false;
      try {
        PhorgeGovernanceCapabilityMap::getRequiredSourceKinds($pair[0], $pair[1]);
      } catch (InvalidArgumentException $ex) {
        $caught = true;
      }
      $this->assertTrue($caught);
    }
  }

  public function testReturnedDefinitionsDoNotRetainCallerMutation() {
    $first = PhorgeGovernanceCapabilityMap::getDefinitions();
    $id = $first[0]['mapping_id'];
    $first[0]['risk'] = 'forged';
    $first[0]['metadata']['customfield:key'] = 'forged';
    $second = PhorgeGovernanceCapabilityMap::getDefinitions();
    $this->assertEqual($id, $second[0]['mapping_id']);
    $this->assertEqual('low', $second[0]['risk']);
    $this->assertEqual('std:project:internal:description',
      $second[0]['metadata']['customfield:key']);
  }

}
