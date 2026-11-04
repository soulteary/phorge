<?php

/** Public error projection only. Null means the native handler must propagate. */
final class PhorgeProjectPolicyFailure extends Phobject {
  public static function responseFor(Throwable $error) {
    if (get_class($error) !== PhorgeProjectPolicyRefusalException::class) {
      return null;
    }
    if ($error->getRefusalReason() === 'additional-effects') {
      return array('status'=>400,'error'=>'project_approval_separate_changes_required',
        'message'=>'Save other project changes first, then create a new policy proposal.');
    }
    // A syntactically invalid identifier does not disclose stored ticket state.
    if ($error->getRefusalReason() === 'ticket-input') {
      return array('status' => 400, 'error' => 'project_approval_invalid_input',
        'message' => 'Enter a valid approval ticket.');
    }
    if (in_array($error->getRefusalReason(), array('request-input','proposal-input','proposal-no-change'), true)) {
      return array('status' => 400, 'error' => 'project_approval_invalid_input',
        'message' => 'The project approval request is invalid.');
    }
    // Do not distinguish missing, unapproved, expired, mismatched or stale
    // opaque tickets before current authorization has been established.
    return array('status' => 403, 'error' => 'project_approval_refused',
      'message' => 'A current approved ticket matching this change is required.');
  }
  /** Only caller-input JSON is decoded here; stored JSON never uses this. */
  public static function decodeRequest($raw) {
    if (!is_string($raw) || strlen($raw) > 8192) {
      throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
    }
    try { $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) {
      throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
    }
    if (!is_array($payload)) {
      throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
    }
    return $payload;
  }

  /** Fixed pure parser for caller intent only; never used on stored bindings. */
  public static function requestIntent(array $payload) {
    $keys=array_keys($payload); sort($keys,SORT_STRING);
    try {
      if ($keys===array('new_policy','reason','target_phid','ttl_seconds')) {
        return PhorgeProjectExecutionIntent::newPolicy($payload['new_policy']);
      }
      if ($keys===array('intent','reason','target_phid','ttl_seconds') && is_array($payload['intent'])) {
        return PhorgeProjectExecutionIntent::newFromDescriptor($payload['intent']);
      }
    } catch (InvalidArgumentException $error) {
      if (get_class($error)!==InvalidArgumentException::class || !in_array($error->getMessage(),
          array('project_execution_intent_version_refused','project_execution_member_intent_refused',
            'project_execution_member_identity_refused'),true)) { throw $error; }
      throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
    } catch (RuntimeException $error) {
      if (get_class($error)!==RuntimeException::class || $error->getMessage()!=='project_execution_policy_unsupported') {
        throw $error;
      }
      throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
    }
    throw PhorgeProjectPolicyRefusalException::newForReason('request-input');
  }

  public static function newHTTPResponse(PhorgeProjectPolicyRefusalException $error) {
    $public = self::responseFor($error);
    return (new AphrontJSONResponse())->setHTTPResponseCode($public['status'])
      ->setContent(array('error' => $public['error'], 'message' => pht($public['message'])));
  }

  public static function newValidationException(PhorgeProjectPolicyRefusalException $error) {
    $public = self::responseFor($error);
    // A generic native form error, never a missing-field warning which a
    // caller's ContinueOnMissingFields option could discard.
    return new PhabricatorApplicationTransactionValidationException(array(
      new PhabricatorApplicationTransactionValidationError('governance:approval',
        pht('Approval Required'), pht($public['message']))));
  }

}
