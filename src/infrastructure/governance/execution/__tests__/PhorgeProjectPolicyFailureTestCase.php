<?php

final class PhorgeProjectPolicyFailureTestCase extends PhutilTestCase {
  private function caught($work) {
    try { $work(); } catch (Throwable $error) { return $error; }
    throw new Exception('Expected failure was not raised.');
  }

  private function row() {
    $data = array('targetPHID' => 'PHID-PROJ-'.str_repeat('p',20),
      'operationID' => str_repeat('a',32), 'operation' => 'project.edit-policy',
      'newPolicy' => PhabricatorPolicies::POLICY_USER,
      'issuedAt' => 10, 'expiresAt' => 20,
      'executor' => array('userPHID' => 'PHID-USER-'.str_repeat('u',20)),
      'sources' => array());
    return array('approvalID' => str_repeat('b',64),
      'targetPHID' => $data['targetPHID'], 'operationID' => $data['operationID'],
      'bindingJSON' => PhorgeProjectExecutionValue::canonical($data),
      'bindingDigest' => PhorgeProjectExecutionValue::digest($data),
      'state' => 'submitted', 'version' => '1');
  }

  public function testOnlyTypedRefusalsProduceSanitizedPublicErrors() {
    $generic = null;
    foreach (array('ticket-unavailable','ticket-expired','ticket-not-ready',
      'execution-binding','ticket-binding','sources-stale','independent-approver') as $reason) {
      $error = PhorgeProjectPolicyRefusalException::newForReason($reason);
      $response = PhorgeProjectPolicyFailure::responseFor($error);
      $this->assertEqual(403, $response['status']);
      if ($generic === null) { $generic = $response; }
      $this->assertEqual($generic, $response);
      $this->assertEqual(array('status','error','message'), array_keys($response));
    }
    $this->assertEqual(null, PhorgeProjectPolicyFailure::responseFor(
      new RuntimeException('project_execution_ticket_refused')));
  }

  public function testNativeMFAAndCSRFFlowAndBackendFailuresRemainUnmapped() {
    $errors = array(new PhabricatorAuthHighSecurityRequiredException(),
      new AphrontMalformedRequestException('Invalid Request (CSRF)', 'Synthetic CSRF failure', true),
      new AphrontSchemaQueryException("#1146: Table 'synthetic.audit' doesn't exist"),
      new JsonException('Synthetic stored binding failure'),
      new RuntimeException('project_execution_scope_cleanup_failed'),
      new TypeError('Synthetic programming failure'), new Error('Synthetic programming failure'));
    foreach ($errors as $error) {
      $this->assertEqual(null, PhorgeProjectPolicyFailure::responseFor($error));
    }
    $this->assertEqual(400, $errors[1]->getHTTPResponseCode());
    $this->assertEqual(0, $errors[2]->getCode());
  }

  public function testMissingTicketIsDistinctFromMalformedStoredTicket() {
    $row = $this->row(); $id = $row['approvalID'];
    $decoded = PhorgeProjectPolicyTicketState::decode($id, $row);
    $this->assertEqual($row, $decoded[0]);
    $missing = $this->caught(function() use ($id) {
      PhorgeProjectPolicyTicketState::decode($id, null);
    });
    $this->assertEqual(PhorgeProjectPolicyRefusalException::class, get_class($missing));
    $this->assertEqual(403, PhorgeProjectPolicyFailure::responseFor($missing)['status']);
    foreach (array(array('state' => 'unknown'), array('bindingJSON' => '{'),
      array('bindingDigest' => str_repeat('0',64)), array('bindingJSON' => null),
      array('version' => '18446744073709551616'), array('targetPHID' => null)) as $changes) {
      $bad = $changes + $row;
      $error = $this->caught(function() use ($id, $bad) {
        PhorgeProjectPolicyTicketState::decode($id, $bad);
      });
      $this->assertEqual(null, PhorgeProjectPolicyFailure::responseFor($error));
      if (isset($changes['bindingJSON']) && $changes['bindingJSON'] === '{') {
        $this->assertEqual(JsonException::class, get_class($error));
      }
    }
  }

  public function testActualExpiryIsDistinctFromCorruptTimeAndClockRollback() {
    $data = array('issuedAt' => 10, 'expiresAt' => 20);
    $this->assertEqual(19, PhorgeProjectPolicyTicketState::validAt($data, '12', 19));
    $expired = $this->caught(function() use ($data) {
      PhorgeProjectPolicyTicketState::validAt($data, '12', 20);
    });
    $this->assertEqual(PhorgeProjectPolicyRefusalException::class, get_class($expired));
    foreach (array(array(array('issuedAt' => '10', 'expiresAt' => 20), null, 19),
      array(array('issuedAt' => 20, 'expiresAt' => 10), null, 19),
      array($data, '012', 19), array($data, 20, 20), array($data, 12, 9)) as $args) {
      $error = $this->caught(function() use ($args) {
        PhorgeProjectPolicyTicketState::validAt($args[0], $args[1], $args[2]);
      });
      $this->assertEqual(null, PhorgeProjectPolicyFailure::responseFor($error));
    }
  }

  public function testAlreadyApprovedStateIsDistinctFromLockedCASFailure() {
    PhorgeProjectPolicyTicketState::requireSubmitted('submitted');
    PhorgeProjectPolicyTicketState::requireUpdated(1);
    $business = $this->caught(function() { PhorgeProjectPolicyTicketState::requireSubmitted('approved'); });
    $backend = $this->caught(function() { PhorgeProjectPolicyTicketState::requireUpdated(0); });
    $this->assertEqual($business->getMessage(), $backend->getMessage());
    $this->assertEqual(403, PhorgeProjectPolicyFailure::responseFor($business)['status']);
    $this->assertEqual(null, PhorgeProjectPolicyFailure::responseFor($backend));
  }

  public function testIdentifierSyntaxIsAnExplicitInputBoundary() {
    $this->assertEqual(400, PhorgeProjectPolicyFailure::responseFor(
      PhorgeProjectPolicyRefusalException::newForReason('request-input'))['status']);
    $this->assertEqual(str_repeat('a',64), PhorgeProjectPolicyTicketState::identifier(str_repeat('a',64)));
    foreach (array(null, '', str_repeat('A',64), str_repeat('a',65), array()) as $id) {
      $error = $this->caught(function() use ($id) { PhorgeProjectPolicyTicketState::identifier($id); });
      $this->assertEqual(PhorgeProjectPolicyRefusalException::class, get_class($error));
      $this->assertEqual(400, PhorgeProjectPolicyFailure::responseFor($error)['status']);
    }
    $unknown = $this->caught(function() { PhorgeProjectPolicyRefusalException::newForReason('unrecognized'); });
    $this->assertEqual(InvalidArgumentException::class, get_class($unknown));
    $this->assertEqual(null, PhorgeProjectPolicyFailure::responseFor($unknown));
  }
}
