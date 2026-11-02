<?php

/**
 * Explicitly isolated native observations, never a transaction executor.
 * Local editor methods below are a fixed, audited subset, not the complete
 * applyTransactions pipeline, validation, support expansion, or Herald closure.
 */
final class PhorgeGovernanceNativeObservationVerifier extends Phobject {

  private function __construct() {}

  public static function newIsolated() {
    require_once dirname(__DIR__, 4).'/support/runtime/php-version.php';
    phorge_assert_supported_php_version(PHP_VERSION);
    if (!self::hasIsolatedScope()) {
      throw new LogicException('Explicit isolated native observation required.');
    }
    return new self();
  }

  private static function hasIsolatedScope() {
    if (PHP_SAPI !== 'cli' ||
        getenv('PHORGE_GOVERNANCE_ISOLATED_TEST') !== '1') {
      return false;
    }
    foreach (array('PHABRICATOR_ENV', 'PHORGE_DEPLOYMENT_CONFIG',
      'PHORGE_CONTROL_PLANE') as $selector) {
      $value = getenv($selector);
      if ($value !== false && $value !== '') {
        return false;
      }
    }
    $root = dirname(__DIR__, 4);
    foreach (array('local.json', 'deployment.json', 'ENVIRONMENT') as $config) {
      if (file_exists($root.'/conf/local/'.$config)) {
        return false;
      }
    }
    return true;
  }

  public function observeCapabilities(
    PhabricatorUser $viewer,
    PhabricatorPolicyInterface $object,
    array $capabilities) {

    if (!self::hasIsolatedScope()) {
      return self::blocked('isolated_scope_required');
    }
    if (!in_array(get_class($object), array(
      PhabricatorProject::class, ManiphestTask::class,
      PhrictionDocument::class), true)) {
      return self::blocked('unsupported_native_object');
    }
    $types = array(
      PhabricatorProject::class => 'PROJ',
      ManiphestTask::class => 'TASK',
      PhrictionDocument::class => 'WIKI',
    );
    if ($object->getID() === null || !is_string($object->getPHID()) ||
        !preg_match('/\APHID-'.$types[get_class($object)].
          '-[a-zA-Z0-9]{20}\z/', $object->getPHID())) {
      return self::blocked('native_object_identity_required');
    }
    if ($viewer->isOmnipotent() || !$viewer->isLoggedIn() ||
        !$viewer->getIsApproved() || $viewer->getIsDisabled() ||
        $viewer->getIsSystemAgent() || $viewer->getIsMailingList()) {
      // Automatic object rights do not establish account/admission eligibility.
      return self::blocked('ineligible_viewer');
    }
    if (!$capabilities || !array_is_list($capabilities) ||
        count($capabilities) > 3) {
      return self::blocked('unsupported_capabilities');
    }
    foreach ($capabilities as $capability) {
      if (!is_string($capability) || !in_array($capability, array(
        PhabricatorPolicyCapability::CAN_VIEW,
        PhabricatorPolicyCapability::CAN_EDIT,
        PhabricatorPolicyCapability::CAN_JOIN), true) ||
          !in_array($capability, $object->getCapabilities(), true)) {
        return self::blocked('unsupported_capabilities');
      }
    }
    if (count(array_unique($capabilities)) !== count($capabilities)) {
      return self::blocked('unsupported_capabilities');
    }
    $forced = new ReflectionProperty(
      PhabricatorLiskDAO::class, 'forcedNamespace');
    if ($forced->getValue($object) !== null) {
      return self::blocked('native_context_unverified');
    }
    try {
      $context = PhorgeGovernanceNativeCapabilityContext::newIsolated($object);
    } catch (Throwable $error) {
      return self::blocked('native_context_unverified');
    }
    $observations = array();
    $unknown = false;
    try {
      foreach ($capabilities as $capability) {
        $native = 'unknown';
        $automatic = 'unknown';
        try {
          $native = PhabricatorPolicyFilter::hasCapability(
            $viewer, $object, $capability) ? 'allow' : 'deny';
        } catch (Throwable $ex) {
          $unknown = true;
        }
        try {
          $automatic = $object->hasAutomaticCapability($capability, $viewer);
        } catch (Throwable $ex) {
          $unknown = true;
        }
        $observations[$capability] = array(
          'policy_filter' => $native,
          'automatic_capability' => $automatic,
        );
      }
      $result = self::baseResult();
      $result['status'] = $unknown ? 'blocked' : 'observed-native-capability';
      $result['reason'] = $unknown ? 'unknown_native_context' : null;
      $result['observations'] = $observations;
      $result['coverage'] = 'provided-native-object-only';
      if (!$context->isCurrent()) {
        $result = self::blocked('native_context_changed');
      }
    } finally {
      $clean = $context->close();
    }
    if (!$clean) {
      return self::blocked('native_context_cleanup_failed');
    }
    return $result;
  }

  public function observeProjectLocalExpansion(
    PhabricatorProject $project,
    array $transactions) {

    return $this->observeLocal(
      $project, $transactions, PhabricatorProject::class,
      PhabricatorProjectTransaction::class,
      PhabricatorProjectTransactionEditor::class, 'expandTransactions', null);
  }

  public function observeTaskLocalExpansion(
    ManiphestTask $task,
    PhabricatorUser $viewer,
    array $transactions) {

    if ($viewer->isOmnipotent() || !$viewer->isLoggedIn() ||
        !$viewer->getIsApproved() || $viewer->getIsDisabled() ||
        $viewer->getIsSystemAgent() || $viewer->getIsMailingList()) {
      return self::blocked('ineligible_viewer');
    }
    return $this->observeLocal(
      $task, $transactions, ManiphestTask::class,
      ManiphestTransaction::class, ManiphestTransactionEditor::class,
      'expandTransaction', $viewer);
  }

  public function observeWikiLocalExpansion(
    PhrictionDocument $document,
    array $transactions) {

    return $this->observeLocal(
      $document, $transactions, PhrictionDocument::class,
      PhrictionTransaction::class, PhrictionTransactionEditor::class,
      'expandTransaction', null);
  }

  private function observeLocal(
    PhabricatorLiskDAO $object,
    array $transactions,
    $expected_object,
    $expected_transaction,
    $editor_class,
    $method,
    $viewer) {

    if (!self::hasIsolatedScope()) {
      return self::blocked('isolated_scope_required');
    }
    if (get_class($object) !== $expected_object ||
        $object->getID() === null || !$transactions ||
        !array_is_list($transactions) || count($transactions) > 20) {
      return self::blocked('unsupported_existing_object_or_batch');
    }
    $copies = array();
    $budget = array('nodes' => 0, 'bytes' => 0);
    try {
      foreach ($transactions as $transaction) {
        if (!is_object($transaction) ||
            get_class($transaction) !== $expected_transaction ||
            $transaction->getID() !== null) {
          return self::blocked('unsupported_transaction_envelope');
        }
        $type = $transaction->getTransactionType();
        $metadata = self::copyValue($transaction->getMetadata(), 0, $budget);
        $mapped = PhorgeGovernanceCapabilityMap::classify(
          $expected_object, $expected_transaction, $type, $metadata);
        if ($mapped['status'] !== 'mapped-contract-only' ||
            !self::supportsLocalType($expected_object, $type)) {
          return self::blocked('unsupported_local_closure');
        }
        $bound = $transaction->getObjectPHID();
        if ($bound !== null && $bound !== $object->getPHID()) {
          return self::blocked('transaction_target_mismatch');
        }
        $copy = clone $transaction;
        $value = $transaction->getNewValue();
        if ($type === PhrictionDocumentMoveToTransaction::TRANSACTIONTYPE &&
            $expected_object === PhrictionDocument::class) {
          if (!is_object($value) ||
              get_class($value) !== PhrictionDocument::class ||
              $value->getID() === null ||
              !self::isPolicyValue($value->getViewPolicy()) ||
              !self::isPolicyValue($value->getEditPolicy())) {
            return self::blocked('unsupported_move_destination');
          }
          $value = clone $value;
        } else {
          $value = self::copyValue($value, 0, $budget);
          if (!self::supportsLocalValue($expected_object, $type, $value)) {
            return self::blocked('unsupported_local_value');
          }
        }
        $copy->setMetadata($metadata);
        $copy->setNewValue($value);
        $copies[] = $copy;
      }
      $editor = new $editor_class();
      if ($viewer !== null) {
        $editor->setActor($viewer);
      }
      // Only existing-object phases are supported. Set the same in-memory
      // context that applyTransactions would set, without invoking its pipeline.
      $phase = new ReflectionProperty(
        PhabricatorApplicationTransactionEditor::class, 'isNewObject');
      $phase->setValue($editor, false);
      $native_method = new ReflectionMethod($editor_class, $method);
      $observed = array();
      $detached_object = clone $object;
      if ($method === 'expandTransactions') {
        $observed = $native_method->invoke($editor, $detached_object, $copies);
      } else {
        foreach ($copies as $copy) {
          $observed = array_merge($observed,
            $native_method->invoke($editor, $detached_object, $copy));
        }
      }
      $summaries = array();
      foreach ($observed as $expanded) {
        if (get_class($expanded) !== $expected_transaction) {
          return self::blocked('unexpected_expansion_envelope');
        }
        $value = $expanded->getNewValue();
        if (is_object($value) &&
            get_class($value) === PhrictionDocument::class) {
          $value = array(
            'object_class' => PhrictionDocument::class,
            'view_policy' => $value->getViewPolicy(),
            'edit_policy' => $value->getEditPolicy(),
          );
        }
        $value = self::copyValue($value, 0, $budget);
        $summaries[] = array(
          'transaction_type' => $expanded->getTransactionType(),
          'value_digest' => hash('sha256', json_encode($value)),
          'content_delete' =>
            $expanded->getMetadataValue('contentDelete') === true,
          'ignore_on_no_effect' => $expanded->getIgnoreOnNoEffect() === true,
        );
      }
      $result = self::baseResult();
      $result['status'] = 'observed-native-local-expansion';
      $result['method'] = $editor_class.'::'.$method;
      $result['transactions'] = $summaries;
      return $result;
    } catch (Throwable $ex) {
      return self::blocked('unknown_native_expansion_context');
    }
  }

  private static function supportsLocalType($object_class, $type) {
    switch ($object_class) {
      case PhabricatorProject::class:
        return in_array($type, array(
          PhabricatorTransactions::TYPE_CUSTOMFIELD,
          PhabricatorTransactions::TYPE_VIEW_POLICY,
          PhabricatorTransactions::TYPE_EDIT_POLICY,
          PhabricatorTransactions::TYPE_JOIN_POLICY,
          PhabricatorTransactions::TYPE_EDGE,
          PhabricatorProjectParentTransaction::TRANSACTIONTYPE,
          PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE), true);
      case ManiphestTask::class:
        return in_array($type, array(
          ManiphestTaskTitleTransaction::TRANSACTIONTYPE,
          ManiphestTaskDescriptionTransaction::TRANSACTIONTYPE,
          ManiphestTaskOwnerTransaction::TRANSACTIONTYPE), true);
      case PhrictionDocument::class:
        return in_array($type, array(
          PhrictionDocumentTitleTransaction::TRANSACTIONTYPE,
          PhrictionDocumentContentTransaction::TRANSACTIONTYPE,
          PhrictionDocumentMoveToTransaction::TRANSACTIONTYPE), true);
    }
    return false;
  }

  private static function isPolicyValue($value) {
    return is_string($value) && $value !== '' && strlen($value) <= 64 &&
      preg_match('//u', $value) === 1;
  }

  private static function supportsLocalValue($object_class, $type, $value) {
    if (in_array($type, array(
      PhabricatorTransactions::TYPE_VIEW_POLICY,
      PhabricatorTransactions::TYPE_EDIT_POLICY,
      PhabricatorTransactions::TYPE_JOIN_POLICY), true)) {
      return self::isPolicyValue($value);
    }
    if ($object_class === PhabricatorProject::class) {
      if ($type === PhabricatorTransactions::TYPE_EDGE) {
        return is_array($value);
      }
      if (in_array($type, array(
        PhabricatorProjectParentTransaction::TRANSACTIONTYPE,
        PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE), true)) {
        return $value === null || (is_string($value) &&
          preg_match('/\APHID-PROJ-[a-zA-Z0-9]{20}\z/', $value));
      }
      return is_string($value);
    }
    if ($object_class === ManiphestTask::class &&
        $type === ManiphestTaskOwnerTransaction::TRANSACTIONTYPE) {
      return $value === null || (is_string($value) &&
        preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/', $value));
    }
    // The remaining task/Wiki title and body cases require text. This is
    // deliberately only a minimum shape gate, not native value validation.
    return is_string($value);
  }

  private static function copyValue($value, $depth, array &$budget) {
    $budget['nodes']++;
    if ($depth > 16 || $budget['nodes'] > 5000) {
      throw new InvalidArgumentException('Unbounded observation value.');
    }
    if (is_array($value)) {
      if (count($value) > 100) {
        throw new InvalidArgumentException('Unbounded observation array.');
      }
      $copy = array();
      foreach ($value as $key => $item) {
        if (is_string($key) &&
            (strlen($key) > 200 || preg_match('//u', $key) !== 1)) {
          throw new InvalidArgumentException('Invalid observation key.');
        }
        $budget['bytes'] += is_string($key) ? strlen($key) : 8;
        if ($budget['bytes'] > 1048576) {
          throw new InvalidArgumentException('Observation byte budget exceeded.');
        }
        $copy[$key] = self::copyValue($item, $depth + 1, $budget);
      }
      return $copy;
    }
    $budget['bytes'] += is_string($value) ? strlen($value) : 8;
    if ($budget['bytes'] > 1048576) {
      throw new InvalidArgumentException('Observation byte budget exceeded.');
    }
    if ($value === null || is_bool($value) || is_int($value) ||
        (is_string($value) && strlen($value) <= 1000000 &&
          preg_match('//u', $value) === 1)) {
      return $value;
    }
    throw new InvalidArgumentException('Unsupported native observation value.');
  }

  private static function baseResult() {
    return array(
      'status' => 'blocked',
      'coverage' => 'local-method-only',
      'full_expansion_verified' => false,
      'risk_complete' => false,
      'can_execute' => false,
    );
  }

  private static function blocked($reason) {
    $result = self::baseResult();
    $result['reason'] = $reason;
    return $result;
  }

}
