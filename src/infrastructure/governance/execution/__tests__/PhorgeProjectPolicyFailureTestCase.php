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
      new TypeError('Synthetic programming failure'), new Error('Synthetic programming failure'),new PhabricatorPolicyException());
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
  public function testCallerJSONAndHTTPResponseHaveNarrowTypedBoundary() {
    $this->assertEqual(array('approval_id' => str_repeat('a',64)),
      PhorgeProjectPolicyFailure::decodeRequest('{"approval_id":"'.str_repeat('a',64).'"}'));
    foreach (array('', '{', 'null', 'false', str_repeat(' ',8193)) as $raw) {
      $error = $this->caught(function() use ($raw) { PhorgeProjectPolicyFailure::decodeRequest($raw); });
      $this->assertEqual(PhorgeProjectPolicyRefusalException::class, get_class($error));
      $response = PhorgeProjectPolicyFailure::newHTTPResponse($error);
      $this->assertEqual(400, $response->getHTTPResponseCode());
      $content = phutil_json_decode($response->setAddJSONShield(false)->buildResponseString());
      $this->assertEqual('project_approval_invalid_input', $content['error']);
    }
    $payload=array('new_policy'=>'users','reason'=>'Synthetic reason','target_phid'=>'PHID-PROJ-'.str_repeat('p',20),'ttl_seconds'=>120);
    $this->assertEqual('project.edit-policy',PhorgeProjectPolicyFailure::requestIntent($payload)->getOperation());
    $payload['new_policy']='unknown';
    $bad_intent=$this->caught(function()use($payload){PhorgeProjectPolicyFailure::requestIntent($payload);});
    $this->assertEqual(400,PhorgeProjectPolicyFailure::responseFor($bad_intent)['status']);
    $row=$this->row();$data=phutil_json_decode($row['bindingJSON']);$data['newPolicy']='unknown';
    $row['bindingJSON']=PhorgeProjectExecutionValue::canonical($data);$row['bindingDigest']=PhorgeProjectExecutionValue::digest($data);
    $stored=$this->caught(function()use($row){PhorgeProjectPolicyTicketState::decode($row['approvalID'],$row);});
    $this->assertEqual(null,PhorgeProjectPolicyFailure::responseFor($stored));
    $refusal = PhorgeProjectPolicyRefusalException::newForReason('ticket-unavailable');
    $this->assertEqual(403, PhorgeProjectPolicyFailure::newHTTPResponse($refusal)->getHTTPResponseCode());
  }

  public function testNativeValidationConversionIsNotMissingFieldOrRawException() {
    $refusal = PhorgeProjectPolicyRefusalException::newForReason('ticket-expired');
    $validation = PhorgeProjectPolicyFailure::newValidationException($refusal);
    $this->assertEqual(PhabricatorApplicationTransactionValidationException::class, get_class($validation));
    $this->assertEqual(1, count($validation->getErrors()));
    $error = head($validation->getErrors());
    $this->assertEqual('governance:approval', $error->getType());
    $this->assertFalse((bool)$error->getIsMissingFieldError());
    $this->assertEqual(null, $error->getTransaction());
    $this->assertEqual(null, PhorgeProjectPolicyFailure::responseFor($validation));
    $translations=(new ReflectionMethod(PhabricatorProjectWorkspaceChineseTranslation::class,'getTranslations'))->invoke(new PhabricatorProjectWorkspaceChineseTranslation());
    foreach(array('Enter a valid approval ticket.','The project approval request is invalid.',
      'A current approved ticket matching this change is required.','Approval Required')as$key){
      $this->assertTrue(isset($translations[$key])&&$translations[$key]!==$key);
    }
    $this->assertFalse(str_contains($validation->getMessage(), 'ticket-expired'));
    $this->assertFalse(str_contains($validation->getMessage(), 'project_execution_ticket_expired'));
  }

  public function testKnownExtraEffectsAreRefusalsOnlyInsideActualFormLease() {
    $originals=new ReflectionMethod(PhorgeProjectExecutionCatalog::class,'formOriginals');
    $classify=new ReflectionMethod(PhorgeProjectExecutionCatalog::class,'knownAdditionalEffects');
    $author='PHID-USER-'.str_repeat('u',20);$type=PhabricatorTransactions::TYPE_VIEW_POLICY;
    foreach(array(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,
      PhabricatorProjectColorTransaction::TRANSACTIONTYPE,PhabricatorProjectIconTransaction::TRANSACTIONTYPE,
      PhabricatorProjectSlugsTransaction::TRANSACTIONTYPE,PhabricatorTransactions::TYPE_CUSTOMFIELD)as$extra_type){
      $policy=(new PhabricatorProjectTransaction())->setTransactionType($type)->setNewValue('users');
      $extra=(new PhabricatorProjectTransaction())->setTransactionType($extra_type)->setNewValue(
        $extra_type===PhabricatorProjectSlugsTransaction::TRANSACTIONTYPE?array('synthetic'):'Synthetic explicit content change');
      if($extra_type===PhabricatorTransactions::TYPE_CUSTOMFIELD){
        $extra->setMetadataValue('customfield:key','std:project:internal:description')->setOldValue(null);
      }
      $batch=array($policy,$extra);$raw=$originals->invoke(null,$batch);
      foreach($batch as$xaction){$xaction->setAuthorPHID($author);}
      // Pure shape evidence is not a genuine native form lease.
      $this->assertTrue($classify->invoke(null,$raw,$batch,$type,'users',$author));
      $this->assertFalse(PhorgeProjectExecutionCatalog::hasKnownAdditionalFormEffects(
        new PhabricatorProjectTransactionEditor(),new PhabricatorProject(),$batch,$type,'users'));
      $this->assertFalse($classify->invoke(null,$raw,array($policy,clone $extra),$type,'users',$author));
      $this->assertFalse($classify->invoke(null,$raw,$batch,$type,'admin',$author));
      $extra->setMetadataValue('unknown.extension',true);
      $this->assertFalse($classify->invoke(null,$raw,$batch,$type,'users',$author));
    }
    $policy=(new PhabricatorProjectTransaction())->setTransactionType($type)->setNewValue('users');
    $clear=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_CUSTOMFIELD)
      ->setMetadataValue('customfield:key','std:project:internal:description')->setOldValue('Synthetic prior description')->setNewValue(null);
    $clear_batch=array($policy,$clear);$clear_raw=$originals->invoke(null,$clear_batch);
    foreach($clear_batch as$xaction){$xaction->setAuthorPHID($author);}
    $this->assertTrue($classify->invoke(null,$clear_raw,$clear_batch,$type,'users',$author));
    $join=(new PhabricatorProjectTransaction())->setTransactionType(PhabricatorTransactions::TYPE_JOIN_POLICY)
      ->setNewValue('admin')->setIsDefaultTransaction(true);
    $batch=array($policy,$join);$raw=$originals->invoke(null,$batch);
    foreach($batch as$xaction){$xaction->setAuthorPHID($author);}
    $this->assertFalse($classify->invoke(null,$raw,$batch,$type,'users',$author));
    $this->assertFalse($classify->invoke(null,$raw,array($policy,$policy),$type,'users',$author));
    $public=PhorgeProjectPolicyFailure::responseFor(PhorgeProjectPolicyRefusalException::newForReason('additional-effects'));
    $this->assertEqual(400,$public['status']);
    $this->assertEqual('project_approval_separate_changes_required',$public['error']);
  }

  public function testRealNativeTextareaRenderingRoundTripsBrowserInitialNewlineRule() {
    require_once dirname(phutil_get_library_root('phabricator')).'/support/governance/native-execution/project-view-edit-route-cases.php';
    $render=new ReflectionMethod(AphrontFormTextAreaControl::class,'renderInput');
    foreach(array('', 'Synthetic nonempty description', "\nleading", "\n\nleading\n", "one\ntwo", '<&>')as$value){
      $control=(new AphrontFormTextAreaControl())->setName('synthetic.description')->setValue($value);
      $html=(string)$render->invoke($control);$matches=array();
      $this->assertEqual(1,preg_match('/<textarea\b[^>]*>(.*?)<\/textarea>/s',$html,$matches));
      $this->assertEqual($value,governance_project_native_textarea_value($matches[1]));
    }
  }

}
