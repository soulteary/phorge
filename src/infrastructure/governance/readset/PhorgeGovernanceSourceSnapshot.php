<?php

/** Immutable synthetic source row, not a native authorization proof. */
final class PhorgeGovernanceSourceSnapshot extends Phobject {

  private $data;

  public function __construct(array $data) {
    $keys = array('role', 'kind', 'id', 'store', 'revision', 'eligibility');
    $actual = array_keys($data);
    sort($keys);
    sort($actual);
    if ($keys !== $actual) {
      throw new InvalidArgumentException('Incomplete source snapshot.');
    }
    $copy = array();
    foreach ($keys as $key) {
      $value = $data[$key];
      if (!is_string($value) || $value === '' || strlen($value) > 300 ||
          preg_match('/[\x00-\x1f\x7f]/', $value) ||
          preg_match('//u', $value) !== 1) {
        throw new InvalidArgumentException('Invalid source value.');
      }
      // Assign to a new slot, never a copied reference-bearing array.
      $copy[$key] = $value;
    }
    if (!in_array($copy['eligibility'],
        array('eligible', 'ineligible', 'unknown'), true)) {
      throw new InvalidArgumentException('Invalid source eligibility.');
    }
    $this->data = $copy;
  }

  public function getData() {
    return $this->data;
  }

}
