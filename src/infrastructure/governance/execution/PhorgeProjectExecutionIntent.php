<?php

/** Fixed typed operation parameters. This value never supplies authority. */
final class PhorgeProjectExecutionIntent extends Phobject {
  private $operation;
  private $members = array();
  private $policy;

  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('project_execution_intent_serialization_refused'); }
  public function __unserialize(array $value) {
    throw new RuntimeException('project_execution_intent_serialization_refused');
  }

  public static function newPolicy($policy) {
    $intent = new self();
    $intent->operation = 'project.edit-policy';
    $intent->policy = PhorgeProjectExecutionCatalog::policy($policy);
    return $intent;
  }

  public static function newMembers($operation, array $members) {
    if (!in_array($operation, array('project.members.add', 'project.members.remove'), true) ||
        !array_is_list($members) || !$members || count($members) > 16) {
      throw new InvalidArgumentException('project_execution_member_intent_refused');
    }
    $copy = array();
    foreach ($members as $member) {
      if (!is_string($member) || !preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/', $member) ||
          isset($copy[$member])) {
        throw new InvalidArgumentException('project_execution_member_identity_refused');
      }
      $copy[$member] = $member;
    }
    ksort($copy, SORT_STRING);
    $intent = new self();
    $intent->operation = $operation;
    $intent->members = array_values($copy);
    return $intent;
  }

  public static function newFromDescriptor(array $descriptor) {
    $keys = array_keys($descriptor);
    sort($keys, SORT_STRING);
    if ($keys !== array('members', 'operation', 'version') ||
        $descriptor['version'] !== 2 || !is_array($descriptor['members'])) {
      throw new InvalidArgumentException('project_execution_intent_version_refused');
    }
    return self::newMembers($descriptor['operation'], $descriptor['members']);
  }

  public static function newFromBinding(array $binding) {
    if (($binding['operation'] ?? null) === 'project.edit-policy') {
      // Existing policy tickets keep their exact interpretation. Unknown/new
      // descriptors cannot be silently treated as the old ticket format.
      if (array_key_exists('intent', $binding)) {
        throw new InvalidArgumentException('project_execution_intent_version_refused');
      }
      return self::newPolicy($binding['newPolicy'] ?? null);
    }
    if (!is_array($binding['intent'] ?? null)) {
      throw new InvalidArgumentException('project_execution_intent_version_refused');
    }
    $intent = self::newFromDescriptor($binding['intent']);
    if ($binding['operation'] !== $intent->getOperation() ||
        ($binding['newPolicy'] ?? null) !== null) {
      throw new InvalidArgumentException('project_execution_member_intent_refused');
    }
    return $intent;
  }

  public static function newFromNativeMembership(PhabricatorProjectTransaction $transaction) {
    if (get_class($transaction) !== PhabricatorProjectTransaction::class ||
        $transaction->getTransactionType() !== PhabricatorTransactions::TYPE_EDGE ||
        $transaction->getMetadata() !== array('edge:type' => PhabricatorProjectProjectHasMemberEdgeType::EDGECONST) ||
        $transaction->getAuthorPHID() !== null || $transaction->getComment() !== null) {
      throw new InvalidArgumentException('project_execution_member_envelope_refused');
    }
    $value = $transaction->getNewValue();
    if (!is_array($value) || count($value) !== 1) {
      throw new InvalidArgumentException('project_execution_member_envelope_refused');
    }
    $key = array_keys($value)[0];
    if (!in_array($key, array('+', '-'), true) || !is_array($value[$key]) ||
        !$value[$key] || count($value[$key]) > 16) {
      throw new InvalidArgumentException('project_execution_member_envelope_refused');
    }
    foreach ($value[$key] as $phid => $member) {
      if ($phid !== $member) {
        throw new InvalidArgumentException('project_execution_member_envelope_refused');
      }
    }
    return self::newMembers($key === '+' ? 'project.members.add' : 'project.members.remove',
      array_values($value[$key]));
  }

  public function isMembership() { return $this->operation !== 'project.edit-policy'; }
  public function isAddition() { return $this->operation === 'project.members.add'; }
  public function getOperation() { return $this->operation; }
  public function getMembers() { return $this->members; }
  public function getPolicy() { return $this->policy; }
  public function getDescriptor() {
    if (!$this->isMembership()) {
      return array('version' => 1, 'operation' => $this->operation, 'policy' => $this->policy);
    }
    return array('version' => 2, 'operation' => $this->operation, 'members' => $this->members);
  }
}
