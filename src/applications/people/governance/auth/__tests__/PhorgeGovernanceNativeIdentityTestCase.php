<?php

final class PhorgeGovernanceNativeIdentityTestCase extends PhutilTestCase {
  private function rejects($callable) {
    $rejected = false;
    try { $callable(); } catch (Throwable $error) { $rejected = true; }
    $this->assertTrue($rejected);
  }

  public function testActorRequiresActualWebCredentialNotViewerAlone() {
    $old = $_COOKIE;
    try {
      $_COOKIE = array();
      $request = new AphrontRequest('example.test', '/');
      $request->setUser(new PhabricatorUser());
      $this->rejects(function() use ($request) { return PhorgeGovernanceNativeActorContext::newFromWebRequest($request); });
      $viewer = id(new PhabricatorUser())->setPHID('PHID-USER-aaaaaaaaaaaaaaaaaaaa');
      $request->setUser($viewer);
      $_COOKIE[PhabricatorCookies::COOKIE_SESSION] = 'arbitrary-caller-token';
      $this->rejects(function() use ($request) { return PhorgeGovernanceNativeActorContext::newFromWebRequest($request); });
    } finally { $_COOKIE = $old; }
  }

  public function testNativeIdentityTypesCannotBeCallerConstructedOrSerialized() {
    foreach (array(PhorgeGovernanceNativeActorContext::class,
      PhorgeGovernanceNativeIdentitySource::class,
      PhorgeGovernanceNativeIdentitySnapshot::class) as $class) {
      $reflection = new ReflectionClass($class);
      $this->assertTrue($reflection->isFinal());
      $this->assertTrue($reflection->getConstructor()->isPrivate());
      $this->assertTrue($reflection->getMethod('__clone')->isPrivate());
      $object = $reflection->newInstanceWithoutConstructor();
      $this->rejects(function() use ($object) { return serialize($object); });
      $this->rejects(function() use ($object) { return $object->__unserialize(array('authorized' => true)); });
    }
  }
  public function testConduitViewerAndParametersDoNotSupplyCredentialProof() {
    $viewer = id(new PhabricatorUser())->setPHID('PHID-USER-aaaaaaaaaaaaaaaaaaaa');
    $request = new ConduitAPIRequest(array('authorized'=>true, 'actor'=>$viewer->getPHID()), true);
    $request->setUser($viewer);
    $this->rejects(function() use ($request) { return $request->getGovernanceActorContext(); });
    $this->assertEqual(array('authorized'=>true, 'actor'=>$viewer->getPHID()), $request->getAllParameters());
    foreach (array('api-short', 'clr-'.str_repeat('a',28), '', str_repeat('a',32)) as $token) {
      $this->rejects(function() use ($viewer, $token) { return PhorgeGovernanceNativeActorContext::newFromAuthenticatedAPIToken(
        $viewer, new PhutilOpaqueEnvelope($token)); });
    }
    $context = PhorgeGovernanceNativeActorContext::newFromAuthenticatedAPIToken(
      $viewer, new PhutilOpaqueEnvelope('api-'.str_repeat('a',28)));
    $request->attachGovernanceActorContext($context);
    $this->assertEqual('api-token', $request->getGovernanceActorContext()->getCredentialType());
    $this->assertEqual(array('authorized'=>true, 'actor'=>$viewer->getPHID()), $request->getAllParameters());
    // This is only a credential-bearing context. Current DB source verification
    // is still required; a well-shaped invented token is not an execution grant.
    $other = new ConduitAPIRequest(array(), true);
    $other->setUser(id(new PhabricatorUser())->setPHID('PHID-USER-bbbbbbbbbbbbbbbbbbbb'));
    $this->rejects(function() use ($other, $context) { return $other->attachGovernanceActorContext($context); });
  }

}
