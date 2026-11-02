<?php

/**
 * Immutable fixture binding and complete supplied manifest. Native dependency
 * enumeration is not implemented: coverage remains partial in every instance.
 */
final class PhorgeGovernanceReadSetSnapshot extends Phobject {

  private $binding;
  private $sources;
  private $digest;

  public function __construct(array $binding, array $sources) {
    $keys = array(
      'operation_id', 'actor', 'executor', 'approver', 'target', 'operation',
      'object_class', 'parameters_digest', 'actor_session',
      'executor_session', 'approver_session',
    );
    $actual = array_keys($binding);
    sort($actual);
    sort($keys);
    if ($actual !== $keys || count($sources) > 256) {
      throw new InvalidArgumentException('Invalid read-set binding.');
    }
    $copy = array();
    foreach ($keys as $key) {
      $value = $binding[$key];
      if (!is_string($value) || $value === '' || strlen($value) > 300 ||
          preg_match('/[\x00-\x1f\x7f]/', $value) ||
          preg_match('//u', $value) !== 1) {
        throw new InvalidArgumentException('Invalid binding value.');
      }
      $copy[$key] = $value;
    }
    if (!preg_match('/\A[a-f0-9]{64}\z/', $copy['parameters_digest'])) {
      throw new InvalidArgumentException('Expected a parameter digest.');
    }
    $detached = array();
    foreach ($sources as $source) {
      if (!($source instanceof PhorgeGovernanceSourceSnapshot)) {
        throw new InvalidArgumentException('Expected typed source rows.');
      }
      $detached[] = $source;
    }
    usort($detached, function($a, $b) {
      return strcmp(json_encode($a->getData()), json_encode($b->getData()));
    });
    $this->binding = $copy;
    $this->sources = $detached;
    $rows = array();
    foreach ($detached as $source) {
      $rows[] = $source->getData();
    }
    $this->digest = hash('sha256', json_encode(array($copy, $rows)));
  }

  public function getBinding() {
    return $this->binding;
  }

  public function getSources() {
    return $this->sources;
  }

  public function getDigest() {
    return $this->digest;
  }

  public function getCoverage() {
    return 'partial-fixture-only';
  }

}
