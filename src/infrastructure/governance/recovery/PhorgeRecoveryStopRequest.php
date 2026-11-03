<?php

/** Validates administrator attestations, never recovery authority. */
final class PhorgeRecoveryStopRequest extends Phobject {
  public static function user($value) {
    if (!is_string($value) || !preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D', $value)) {
      throw new InvalidArgumentException('recovery_stop_subject_invalid');
    }
    return $value;
  }

  public static function transactionID($value) {
    if ((!is_string($value) && !is_int($value)) ||
        !preg_match('/\A[1-9][0-9]*\z/D', (string)$value)) {
      throw new InvalidArgumentException('recovery_stop_transaction_invalid');
    }
    $value = (string)$value;
    $max = '18446744073709551615';
    if (strlen($value) > strlen($max) ||
        (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
      throw new InvalidArgumentException('recovery_stop_transaction_invalid');
    }
    return $value;
  }

  public static function validate($subject, $transaction, $category, $reason, $operation) {
    if (!in_array($category, array('technical-pause', 'offboarding', 'security'), true) ||
        !is_string($reason) || strlen($reason) > 2048 || trim($reason) === '' ||
        !phutil_is_utf8($reason) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason) ||
        !is_string($operation) ||
        !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{15,127}\z/D', $operation)) {
      throw new InvalidArgumentException('recovery_stop_request_invalid');
    }
    return array('schema_version' => 1, 'subject' => self::user($subject),
      'native_disable_transaction' => self::transactionID($transaction),
      'category' => $category, 'reason' => $reason, 'operation_id' => $operation);
  }

  public static function event(array $row, $subject, $transaction) {
    $subject = self::user($subject); $transaction = self::transactionID($transaction);
    $keys = array_keys($row); sort($keys, SORT_STRING);
    if ($keys !== array('authorPHID', 'id', 'newValue', 'objectPHID', 'oldValue', 'transactionType') ||
        self::transactionID($row['id']) !== $transaction || $row['objectPHID'] !== $subject ||
        $row['transactionType'] !== PhabricatorUserDisableTransaction::TRANSACTIONTYPE ||
        !is_string($row['oldValue']) || !is_string($row['newValue'])) {
      throw new RuntimeException('recovery_stop_event_unknown');
    }
    self::user($row['authorPHID']);
    // Native modular transactions serialize booleans as JSON. An enable,
    // no-op, arbitrary truthy value, or undocumented legacy value is unknown.
    if ($row['oldValue'] !== 'false' || $row['newValue'] !== 'true') {
      throw new RuntimeException('recovery_stop_event_unknown');
    }
    return hash('sha256', json_encode(array($transaction, $subject,
      $row['authorPHID'], $row['transactionType'], false, true), JSON_THROW_ON_ERROR));
  }
}
