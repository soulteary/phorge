<?php

/**
 * Unregistered N05 field/transaction classifier, not an authorization adapter.
 *
 * Inputs describe native transaction envelopes. A future trusted adapter must
 * obtain them from real objects and expanded transactions, not HTTP labels.
 * Shape classification never authenticates that adapter or permits execution.
 */
final class PhorgeGovernanceCapabilityMap extends Phobject {

  public static function getKnownOperations($object_class) {
    if (!is_string($object_class)) {
      return array();
    }
    $common = array('policy.replace', 'object.space', 'object.relationship');
    switch ($object_class) {
      case PhabricatorProject::class:
        return array_merge($common, array('project.description', 'project.members'));
      case ManiphestTask::class:
        return array_merge($common, array('task.title', 'task.description'));
      case PhrictionDocument::class:
        return array_merge($common, array('wiki.title', 'wiki.content'));
      default:
        return array();
    }
  }

  public static function getRequiredSourceKinds($operation, $object_class) {
    if (!is_string($operation) || !is_string($object_class) ||
        !in_array($operation, self::getKnownOperations($object_class), true)) {
      throw new InvalidArgumentException('Unsupported operation/object binding.');
    }
    // Named policies and organization sources require proven enumeration (or
    // proven absence) from a future adapter, not a caller's "not referenced".
    $kinds = array(
      'user.account', 'auth.session', 'app.capability', 'space.policy',
      'policy.named', 'org.membership', 'org.appointment',
      'project.member', 'project.parent',
    );
    switch ($object_class) {
      case PhabricatorProject::class:
        $kinds = array_merge($kinds, array('project.policy'));
        break;
      case ManiphestTask::class:
        $kinds = array_merge($kinds, array('task.policy', 'task.owner'));
        break;
      case PhrictionDocument::class:
        $kinds = array_merge($kinds, array('wiki.policy', 'wiki.parent'));
        break;
    }
    sort($kinds, SORT_STRING);
    return $kinds;
  }

  private static function objectDefinitions() {
    return array(
      PhabricatorProject::class => array(
        'family' => 'PROJECT',
        'envelope' => PhabricatorProjectTransaction::class,
        'sources' => array('PROJECT.EDIT.WEB', 'PROJECT.EDIT.ENGINE',
          'PROJECT.EDIT.CONDUIT', 'PROJECT.MEMBER.EDITOR',
          'PROJECT.POLICY.SOURCE'),
        'automatic_boundary' => 'native member-view and ancestor-edit retained',
      ),
      ManiphestTask::class => array(
        'family' => 'TASK',
        'envelope' => ManiphestTransaction::class,
        'sources' => array('TASK.EDIT.WEB', 'TASK.EDIT.ENGINE',
          'TASK.EDIT.EDITOR', 'TASK.EDIT.CONDUIT', 'TASK.EDIT.BULK',
          'TASK.POLICY.SOURCE'),
        'automatic_boundary' => 'native task-owner view/edit retained',
      ),
      PhrictionDocument::class => array(
        'family' => 'WIKI',
        'envelope' => PhrictionTransaction::class,
        'sources' => array('WIKI.EDIT.WEB.LEGACY', 'WIKI.EDIT.EDITOR',
          'WIKI.EDIT.CONDUIT', 'WIKI.EDIT.CONDUIT.LEGACY',
          'WIKI.POLICY.SOURCE'),
        'automatic_boundary' => 'native document and ancestry policy retained',
      ),
    );
  }

  private static function definition(
    $id, $object, $constant, $handler, $field, $operation,
    $classification, $risk, array $metadata = array(), $note = '') {

    $object_map = self::objectDefinitions();
    $native = $object_map[$object];
    return array(
      'mapping_id' => $id,
      'object_class' => $object,
      'transaction_class' => $native['envelope'],
      'transaction_constant' => $constant,
      'transaction_type' => constant($constant),
      'handler_class' => $handler,
      'field' => $field,
      'metadata' => $metadata,
      'operation' => $operation,
      'classification' => $classification,
      'risk' => $risk,
      'status' => 'mapped-contract-only',
      'reason' => $note,
      'source_ids' => array_merge($native['sources'], array(
        'TRANSACTION.MODULAR', 'TRANSACTION.LEGACY')),
      'required_source_kinds' => self::getRequiredSourceKinds($operation, $object),
      'native_requirements' => array(
        'view' => PhabricatorPolicyCapability::CAN_VIEW,
        'native_edit_path' => PhabricatorPolicyCapability::CAN_EDIT,
        'new_content_path' => 'scoped content eligibility, not native edit grant',
        'space' => 'current native Space view check',
        'application' => 'current target application capability',
        'automatic_boundary' => $native['automatic_boundary'],
      ),
      'classification_boundary' =>
        'structural shape only; values, creation and expanded effects unverified',
      'risk_complete' => false,
      'coverage' => 'partial',
      'activation_blocked' => true,
      'can_execute' => false,
    );
  }

  public static function getDefinitions() {
    $rows = array();
    $rows[] = self::definition(
      'MAP.PROJECT.DESCRIPTION', PhabricatorProject::class,
      'PhabricatorTransactions::TYPE_CUSTOMFIELD',
      PhabricatorProjectDescriptionField::class, 'description',
      'project.description', 'content-candidate', 'low',
      array('customfield:key' => 'std:project:internal:description'),
      'legacy core custom-field dispatcher; only this builtin description key');
    $rows[0]['source_ids'][] = 'PROJECT.DESCRIPTION.CUSTOMFIELD';
    foreach (array(
      array('MAP.TASK.TITLE', 'ManiphestTaskTitleTransaction', 'title', 'task.title'),
      array('MAP.TASK.DESCRIPTION', 'ManiphestTaskDescriptionTransaction',
        'description', 'task.description'),
      array('MAP.WIKI.TITLE', 'PhrictionDocumentTitleTransaction',
        'title', 'wiki.title'),
      array('MAP.WIKI.CONTENT', 'PhrictionDocumentContentTransaction',
        'content', 'wiki.content'),
    ) as $item) {
      $wiki = strncmp($item[0], 'MAP.WIKI.', 9) === 0;
      $content = self::definition(
        $item[0], $wiki ? PhrictionDocument::class : ManiphestTask::class,
        $item[1].'::TRANSACTIONTYPE', $item[1], $item[2], $item[3],
        'content-candidate', $wiki ? 'high' : 'low', array(),
        $wiki ? 'title/content can publish or restore; empty content expands '.
          'to deletion; value/creation/expansion adapter must verify' :
          'field classification only; native validation/expansion still required');
      if ($wiki) {
        // The newer comment-oriented engine does not prove body-edit coverage.
        $content['source_ids'] = array(
          'WIKI.EDIT.WEB.LEGACY', 'WIKI.EDIT.EDITOR',
          'WIKI.EDIT.CONDUIT.LEGACY', 'WIKI.CREATE.CONDUIT.LEGACY',
          'WIKI.POLICY.SOURCE', 'TRANSACTION.MODULAR', 'TRANSACTION.LEGACY');
      }
      $content_sources = array(
        'MAP.TASK.TITLE' => 'TASK.TITLE.MODULAR',
        'MAP.TASK.DESCRIPTION' => 'TASK.DESCRIPTION.MODULAR',
        'MAP.WIKI.TITLE' => 'WIKI.TITLE.MODULAR',
        'MAP.WIKI.CONTENT' => 'WIKI.CONTENT.MODULAR',
      );
      $content['source_ids'][] = $content_sources[$item[0]];
      $rows[] = $content;
    }
    foreach (self::objectDefinitions() as $object => $native) {
      $policies = array(
        'VIEW' => 'PhabricatorTransactions::TYPE_VIEW_POLICY',
        'EDIT' => 'PhabricatorTransactions::TYPE_EDIT_POLICY',
      );
      if ($object === PhabricatorProject::class) {
        $policies['JOIN'] = 'PhabricatorTransactions::TYPE_JOIN_POLICY';
      }
      foreach ($policies as $field => $constant) {
        $rows[] = self::definition(
          'MAP.'.$native['family'].'.POLICY.'.$field, $object, $constant,
          PhabricatorApplicationTransactionEditor::class,
          strtolower($field).'Policy', 'policy.replace', 'sensitive', 'high');
      }
      $rows[] = self::definition(
        'MAP.'.$native['family'].'.SPACE', $object,
        'PhabricatorTransactions::TYPE_SPACE',
        PhabricatorApplicationTransactionEditor::class, 'spacePHID',
        'object.space', 'sensitive', 'high');
    }
    $member = self::definition(
      'MAP.PROJECT.MEMBERS', PhabricatorProject::class,
      'PhabricatorTransactions::TYPE_EDGE',
      PhabricatorProjectProjectHasMemberEdgeType::class, 'members',
      'project.members', 'sensitive', 'high',
      array('edge:type' => PhabricatorProjectProjectHasMemberEdgeType::EDGECONST),
      'self join/leave exceptions require actual actor/diff adapter; not inferred');
    $member['source_ids'] = array_merge($member['source_ids'], array(
      'PROJECT.MEMBER.ADD', 'PROJECT.MEMBER.REMOVE', 'PROJECT.MEMBER.INVERSE',
      'PROJECT.MEMBER.EDGE.FORWARD'));
    $rows[] = $member;
    foreach (array(
      array('MAP.PROJECT.PARENT', PhabricatorProject::class,
        'PhabricatorProjectParentTransaction', 'parent'),
      array('MAP.PROJECT.MILESTONE', PhabricatorProject::class,
        'PhabricatorProjectMilestoneTransaction', 'milestone'),
      array('MAP.TASK.OWNER', ManiphestTask::class,
        'ManiphestTaskOwnerTransaction', 'owner'),
      array('MAP.TASK.COLUMN', ManiphestTask::class,
        'ManiphestTaskColumnTransaction', 'column'),
      array('MAP.WIKI.MOVE', PhrictionDocument::class,
        'PhrictionDocumentMoveToTransaction', 'move-to'),
    ) as $item) {
      $rows[] = self::definition(
        $item[0], $item[1], $item[2].'::TRANSACTIONTYPE', $item[2], $item[3],
        'object.relationship', 'sensitive', 'high', array(),
        'relationship/automatic-capability effects require expanded adapter');
    }
    foreach (array(ManiphestTask::class, PhrictionDocument::class) as $object) {
      $native = self::objectDefinitions()[$object];
      $rows[] = self::definition(
        'MAP.'.$native['family'].'.PROJECT.EDGE', $object,
        'PhabricatorTransactions::TYPE_EDGE',
        PhabricatorProjectObjectHasProjectEdgeType::class, 'projects',
        'object.relationship', 'sensitive', 'high',
        array('edge:type' => PhabricatorProjectObjectHasProjectEdgeType::EDGECONST),
        'conservative relationship review, not a claim that tagging changes ACL');
    }
    $legacy = self::definition(
      'MAP.PROJECT.MEMBERS.HISTORICAL', PhabricatorProject::class,
      'PhorgeProjectLegacyMembersTransaction::TRANSACTIONTYPE',
      PhorgeProjectLegacyMembersTransaction::class, 'members',
      'project.members', 'sensitive', 'high');
    $legacy['source_ids'][] = 'PROJECT.MEMBER.LEGACY';
    $legacy['status'] = 'blocked';
    $legacy['reason'] = 'historical-rendering-only; no current-write mapping';
    $rows[] = $legacy;
    return $rows;
  }

  public static function classify(
    $object_class, $transaction_class, $transaction_type,
    array $metadata = array()) {

    $objects = self::objectDefinitions();
    if (!is_string($object_class) || !isset($objects[$object_class]) ||
        !is_string($transaction_class) ||
        $transaction_class !== $objects[$object_class]['envelope'] ||
        !is_string($transaction_type)) {
      return self::blocked('unsupported_object_or_envelope');
    }
    foreach (self::getDefinitions() as $row) {
      if ($row['object_class'] === $object_class &&
          $row['transaction_class'] === $transaction_class &&
          $row['transaction_type'] === $transaction_type &&
          $row['metadata'] === $metadata) {
        return $row;
      }
    }
    return self::blocked('unknown_or_ambiguous_transaction');
  }

  public static function classifyBatch($object_class, array $transactions) {
    if (!$transactions || !array_is_list($transactions) ||
        count($transactions) > 100) {
      return self::blocked('invalid_bounded_batch');
    }
    $items = array();
    $risk = 'low';
    $sensitive = false;
    foreach ($transactions as $index => $transaction) {
      if (!is_array($transaction)) {
        return self::blocked('invalid_transaction_descriptor');
      }
      $keys = array_keys($transaction);
      sort($keys, SORT_STRING);
      if ($keys !== array('metadata', 'transaction_class', 'transaction_type') ||
          !is_array($transaction['metadata'])) {
        return self::blocked('invalid_transaction_descriptor');
      }
      $item = self::classify($object_class,
        $transaction['transaction_class'], $transaction['transaction_type'],
        $transaction['metadata']);
      if ($item['status'] !== 'mapped-contract-only') {
        $blocked = self::blocked('unsupported_or_ambiguous_batch_item');
        $blocked['blocked_index'] = $index;
        return $blocked;
      }
      $items[] = $item;
      if ($item['risk'] === 'high') {
        $risk = 'high';
      }
      $sensitive = $sensitive || $item['classification'] === 'sensitive';
    }
    return array(
      'status' => $risk === 'high' ? 'review-required-contract-only' :
        'content-candidate-contract-only',
      'classification' => $sensitive ? 'contains-sensitive' : 'content-candidate',
      'risk' => $risk,
      'risk_complete' => false,
      'items' => $items,
      'coverage' => 'partial',
      'activation_blocked' => true,
      'can_execute' => false,
    );
  }

  private static function blocked($reason) {
    return array(
      'status' => 'blocked',
      'reason' => $reason,
      'classification' => 'unknown',
      'risk' => 'unknown',
      'risk_complete' => false,
      'coverage' => 'partial',
      'activation_blocked' => true,
      'can_execute' => false,
    );
  }

}
