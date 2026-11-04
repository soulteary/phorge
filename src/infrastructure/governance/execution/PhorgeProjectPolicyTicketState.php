<?php

/** Fixed storage validation and refusal boundaries; no actor or permit source. */
final class PhorgeProjectPolicyTicketState extends Phobject {
  public static function identifier($id) {
    // Only this caller-input boundary translates the pure identifier failure.
    try {
      return PhorgeProjectExecutionValue::identifier($id);
    } catch (RuntimeException $error) {
      if (get_class($error) !== RuntimeException::class ||
          $error->getMessage() !== 'project_execution_identifier_refused') {
        throw $error;
      }
      throw PhorgeProjectPolicyRefusalException::newForReason('ticket-input');
    }
  }

  public static function decode($id, $row) {
    self::identifier($id);
    if ($row === null) {
      throw PhorgeProjectPolicyRefusalException::newForReason('ticket-unavailable');
    }
    if (!is_array($row) || ($row['approvalID'] ?? null) !== $id ||
        !is_string($row['bindingJSON'] ?? null) || strlen($row['bindingJSON']) > 65536 ||
        !is_string($row['bindingDigest'] ?? null) ||
        !preg_match('/\A[a-f0-9]{64}\z/D', $row['bindingDigest']) ||
        !is_string($row['targetPHID'] ?? null) ||
        !preg_match('/\APHID-PROJ-[a-zA-Z0-9]{20}\z/D', $row['targetPHID']) ||
        !is_string($row['operationID'] ?? null) ||
        !preg_match('/\A[a-f0-9]{32}\z/D', $row['operationID']) ||
        !in_array($row['state'] ?? null, array('submitted','approved','consumed'), true) ||
        (!is_int($row['version'] ?? null) && !is_string($row['version'] ?? null)) ||
        !preg_match('/\A[1-9][0-9]{0,19}\z/D', (string)$row['version']) ||
        (strlen((string)$row['version']) === 20 &&
          strcmp((string)$row['version'], '18446744073709551615') > 0)) {
      throw new RuntimeException('project_execution_ticket_storage_invalid');
    }
    // Malformed stored JSON remains a JsonException, never a client refusal.
    $data = json_decode($row['bindingJSON'], true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data) ||
        !hash_equals($row['bindingDigest'], PhorgeProjectExecutionValue::digest($data)) ||
        ($row['targetPHID'] ?? null) !== ($data['targetPHID'] ?? null) ||
        ($row['operationID'] ?? null) !== ($data['operationID'] ?? null)) {
      throw new RuntimeException('project_execution_ticket_storage_invalid');
    }
    PhorgeProjectExecutionIntent::newFromBinding($data);
    return array($row, $data);
  }

  public static function validAt(array $data, $approved, $now) {
    if (!is_int($now) || $now < 0 || !is_int($data['issuedAt'] ?? null) ||
        !is_int($data['expiresAt'] ?? null) || $data['issuedAt'] < 0 ||
        $data['expiresAt'] <= $data['issuedAt']) {
      throw new RuntimeException('project_execution_ticket_time_invalid');
    }
    if ($approved !== null) {
      if ((!is_int($approved) && !is_string($approved)) ||
          !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string)$approved) ||
          (float)$approved > 4294967295 || (int)$approved < $data['issuedAt'] ||
          (int)$approved >= $data['expiresAt']) {
        throw new RuntimeException('project_execution_ticket_time_invalid');
      }
    }
    if ($now < $data['issuedAt'] || ($approved !== null && $now < (int)$approved)) {
      throw new RuntimeException('project_execution_clock_invalid');
    }
    if ($now >= $data['expiresAt']) {
      throw PhorgeProjectPolicyRefusalException::newForReason('ticket-expired');
    }
    return $now;
  }

  public static function requireSubmitted($state) {
    if (!in_array($state, array('submitted','approved','consumed'), true)) {
      throw new RuntimeException('project_execution_ticket_storage_invalid');
    }
    if ($state !== 'submitted') {
      throw PhorgeProjectPolicyRefusalException::newForReason('ticket-not-ready');
    }
  }

  public static function requireUpdated($affected) {
    if ($affected !== 1) {
      // The row was already locked. A failed CAS is an invariant/backend
      // failure, unlike an expected already-approved business state.
      throw new RuntimeException('project_execution_transition_refused');
    }
  }
}
