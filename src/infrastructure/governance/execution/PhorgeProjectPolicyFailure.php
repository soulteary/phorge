<?php

/** Public error projection only. Null means the native handler must propagate. */
final class PhorgeProjectPolicyFailure extends Phobject {
  public static function responseFor(Throwable $error) {
    if (get_class($error) !== PhorgeProjectPolicyRefusalException::class) {
      return null;
    }
    // A syntactically invalid identifier does not disclose stored ticket state.
    if ($error->getRefusalReason() === 'ticket-input') {
      return array('status' => 400, 'error' => 'project_approval_invalid_input',
        'message' => 'Enter a valid approval ticket.');
    }
    if ($error->getRefusalReason() === 'request-input') {
      return array('status' => 400, 'error' => 'project_approval_invalid_input',
        'message' => 'The project approval request is invalid.');
    }
    // Do not distinguish missing, unapproved, expired, mismatched or stale
    // opaque tickets before current authorization has been established.
    return array('status' => 403, 'error' => 'project_approval_refused',
      'message' => 'A current approved ticket matching this change is required.');
  }
}
