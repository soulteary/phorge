<?php

/** Credential-bearing context. A viewer object alone is never authentication. */
final class PhorgeGovernanceNativeActorContext extends Phobject {
  private $userPHID;
  private $sessionPHID;
  private $sessionKey;
  private $credentialType = 'web';
  private function __construct() {}
  private function __clone() {}
  public function __serialize() { throw new RuntimeException('governance_actor_serialization_refused'); }
  public function __unserialize(array $data) { throw new RuntimeException('governance_actor_serialization_refused'); }
  public function __debugInfo() { return array('scope' => 'native-web-credential'); }

  public static function newFromWebRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();
    $token = $request->getCookie(PhabricatorCookies::COOKIE_SESSION);
    if (!is_object($viewer) || get_class($viewer) !== PhabricatorUser::class || !$viewer->isLoggedIn() ||
        !$viewer->hasSession() || !is_string($token) || strlen($token) > 256 ||
        PhabricatorAuthSessionEngine::getSessionKindFromToken($token) !==
          PhabricatorAuthSessionEngine::KIND_USER) {
      throw new RuntimeException('governance_web_credential_required');
    }
    $session = $viewer->getSession();
    $digest = PhabricatorAuthSession::newSessionDigest(new PhutilOpaqueEnvelope($token));
    if (get_class($session) !== PhabricatorAuthSession::class ||
        $session->getUserPHID() !== $viewer->getPHID() ||
        $session->getType() !== PhabricatorAuthSession::TYPE_WEB ||
        !in_array($session->getIsPartial(), array(0, '0', false), true) ||
        !hash_equals($session->getSessionKey(), $digest)) {
      throw new RuntimeException('governance_web_credential_required');
    }
    $context = new self();
    $context->userPHID = $viewer->getPHID();
    $context->sessionPHID = $session->getPHID();
    $context->sessionKey = new PhutilOpaqueEnvelope($digest);
    return $context;
  }

  public static function newFromAuthenticatedAPIToken(PhabricatorUser $viewer,
    PhutilOpaqueEnvelope $credential) {
    $token = $credential->openEnvelope();
    if (get_class($viewer) !== PhabricatorUser::class || !$viewer->isLoggedIn() ||
        !is_string($token) || !preg_match('/\A(?:api|cli)-[a-zA-Z0-9]{28}\z/', $token)) {
      throw new RuntimeException('governance_api_credential_required');
    }
    $context = new self();
    $context->userPHID = $viewer->getPHID();
    $context->sessionKey = new PhutilOpaqueEnvelope($token);
    $context->credentialType = 'api-token';
    return $context;
  }
  public function getCredentialType() { return $this->credentialType; }
  // Only the source performs a current database lookup with this envelope.
  public function getCredentialEnvelope() { return new PhutilOpaqueEnvelope($this->sessionKey->openEnvelope()); }

  public function getUserPHID() { return $this->userPHID; }
  public function getSessionPHID() { return $this->sessionPHID; }
  public function getCredentialDigest() { return hash('sha256', $this->sessionKey->openEnvelope()); }
}
