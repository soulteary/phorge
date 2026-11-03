<?php

/** Pure bounded storage-value decoder. It never authenticates an actor. */
final class PhorgeRecoveryNativeAccountFacts extends Phobject {
  public static function decode(array $row, $generation, $legacy) {
    $keys = array_keys($row); sort($keys, SORT_STRING);
    if ($keys !== array('accountSecret','conduitCertificate','isAdmin','isApproved',
        'isDisabled','isMailingList','isSystemAgent','phid') ||
        !is_string($row['phid']) ||
        !preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D', $row['phid'])) {
      throw new RuntimeException('recovery_account_shape_unknown');
    }
    $flags = array();
    foreach (array('isAdmin','isApproved','isDisabled','isSystemAgent','isMailingList') as $key) {
      if (!in_array($row[$key], array(0,1,'0','1'), true)) {
        throw new RuntimeException('recovery_account_shape_unknown');
      }
      $flags[$key] = (string)$row[$key] === '1';
    }
    foreach (array('accountSecret','conduitCertificate') as $key) {
      if ($row[$key] !== null && (!is_string($row[$key]) || strlen($row[$key]) > 4096)) {
        throw new RuntimeException('recovery_account_budget_unknown');
      }
    }
    $generation = self::unsigned($generation, true);
    $epoch = $legacy === null ? null : self::unsigned($legacy, false);
    return array('schema_version'=>1, 'user_phid'=>$row['phid'],
      'flags'=>$flags, 'native_transaction_id'=>$generation,
      'legacy_epoch'=>array('state'=>$epoch === null ? 'observed-absence' : 'present', 'value'=>$epoch),
      'security_fields_digest'=>hash('sha256', json_encode(array($row['accountSecret'],
        $row['conduitCertificate']), JSON_THROW_ON_ERROR)),
      'authority'=>false);
  }

  private static function unsigned($value, $zero) {
    if ((!is_string($value) && !is_int($value)) ||
        !preg_match($zero ? '/\A(?:0|[1-9][0-9]*)\z/D' : '/\A[1-9][0-9]*\z/D', (string)$value)) {
      throw new RuntimeException('recovery_generation_unknown');
    }
    $value = (string)$value;
    $maximum = '18446744073709551615';
    if (strlen($value) > 20 || (strlen($value) === 20 && strcmp($value, $maximum) > 0)) {
      throw new RuntimeException('recovery_generation_unknown');
    }
    return $value;
  }
}
