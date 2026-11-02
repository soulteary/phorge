<?php

/**
 * Immutable binding input, not a durable approval or an authentication token.
 * Source versions must be computed by a future trusted target adapter.
 */
final class PhorgeGovernanceApprovalRequest extends Phobject {

  private $data;
  private $digest;

  public function __construct(array $data) {
    $keys = array(
      'operation_id', 'requester', 'executor', 'target', 'operation',
      'target_version', 'parameters', 'read_set', 'issued_at', 'expires_at',
    );
    $actual = array_keys($data);
    sort($keys);
    sort($actual);
    if ($keys !== $actual) {
      throw new InvalidArgumentException('Incomplete approval binding.');
    }
    foreach (array('operation_id', 'requester', 'executor', 'target',
      'operation', 'target_version') as $key) {
      if (!is_string($data[$key]) || $data[$key] === '') {
        throw new InvalidArgumentException('Invalid approval identifier.');
      }
    }
    if (!is_array($data['parameters']) || !is_array($data['read_set']) ||
        !$data['read_set']) {
      throw new InvalidArgumentException('Missing parameters or read set.');
    }
    foreach ($data['read_set'] as $source => $version) {
      if (!is_string($source) || $source === '' ||
          !is_string($version) || $version === '') {
        throw new InvalidArgumentException('Invalid source version.');
      }
    }
    if (!is_int($data['issued_at']) || !is_int($data['expires_at']) ||
        $data['issued_at'] < 0 ||
        $data['expires_at'] <= $data['issued_at']) {
      throw new InvalidArgumentException('Invalid approval validity.');
    }
    $this->data = self::canonicalize($data);
    $json = json_encode(
      $this->data,
      JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      throw new InvalidArgumentException('Invalid approval encoding.');
    }
    $this->digest = hash('sha256', $json);
  }

  public function getDigest() {
    return $this->digest;
  }

  public function getRequester() {
    return $this->data['requester'];
  }

  public function getExecutor() {
    return $this->data['executor'];
  }

  public function isValidAt($now) {
    return is_int($now) &&
      $now >= $this->data['issued_at'] &&
      $now < $this->data['expires_at'];
  }

  private static function canonicalize($value, $depth = 0) {
    if ($depth > 32) {
      throw new InvalidArgumentException('Approval binding is too deep.');
    }
    if (is_array($value)) {
      $is_list = array_is_list($value);
      if (!$is_list) {
        ksort($value, SORT_STRING);
      }
      foreach ($value as $key => $item) {
        $value[$key] = self::canonicalize($item, $depth + 1);
      }
      return $value;
    }
    if ($value === null || is_bool($value) || is_string($value) ||
        is_int($value) || (is_float($value) && is_finite($value))) {
      return $value;
    }
    throw new InvalidArgumentException('Unsupported approval parameter.');
  }

}
