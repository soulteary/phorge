<?php

/**
 * Authentication provider that trusts identity from Traefik Forward Auth.
 *
 * When the request has passed through Traefik Auth, headers such as
 * X-Auth-User, X-Auth-Email, X-Auth-Name are set. This provider reads them
 * and logs in or registers the user accordingly. Allow Login, Allow
 * Registration, and Allow Linking are configured like other providers.
 */
final class PhabricatorTraefikAuthProvider extends PhabricatorAuthProvider {

  private $adapter;

  public function getProviderName() {
    return pht('Traefik Auth');
  }

  public function getDescriptionForCreate() {
    return pht(
      'Log in or register using identity headers set by Traefik Forward Auth.');
  }

  public function getConfigurationHelp() {
    return pht(
      'This provider trusts the HTTP headers set by Traefik after Forward Auth. '.
      'Requests must reach this install through Traefik so that headers such as '.
      'X-Auth-User, X-Auth-Email, and X-Auth-Name are set. If Phorge is also '.
      'reachable directly, restrict access or use a trusted proxy so that '.
      'header-based authentication is not spoofed.');
  }

  public function getAdapter() {
    if (!$this->adapter) {
      $this->adapter = new PhutilTraefikAuthAdapter();
    }
    return $this->adapter;
  }

  public function getLoginOrder() {
    return '200-'.$this->getProviderName();
  }

  public function isSessionIdentityCompatible(
    PhabricatorUser $user,
    $identity) {

    if (!phutil_nonempty_string($identity) || !$user->getPHID()) {
      return false;
    }

    // Only a persisted link under this provider can authorize reuse of a
    // native session. Display names and email addresses are not identities.
    $account = id(new PhabricatorExternalAccountQuery())
      ->setViewer($user)
      ->requireCapabilities(array(PhabricatorPolicyCapability::CAN_EDIT))
      ->withProviderConfigPHIDs(array($this->getProviderConfig()->getPHID()))
      ->withUserPHIDs(array($user->getPHID()))
      ->withRawAccountIdentifiers(array($identity))
      ->executeOne();

    return (bool)$account;
  }

  public static function canReuseSessionForProxyIdentity(
    PhabricatorUser $user,
    PhabricatorController $controller) {

    $identity = AphrontRequest::getHTTPHeader('X-Auth-User');
    if (!phutil_nonempty_string($identity)) {
      return true;
    }

    $has_proxy_provider = false;
    foreach (self::getCurrentEnabledProxyProviders() as $provider) {
      $has_proxy_provider = true;
      if ($provider->isSessionIdentityCompatible($user, $identity)) {
        return true;
      }
      if ($provider->canUseSessionForAccountLink(
          $user, $identity, $controller)) {
        return true;
      }
    }

    return !$has_proxy_provider;
  }

  /**
   * Match an already authenticated media user to the current gateway identity.
   * This trusts the same protected proxy ingress as this authentication provider;
   * a client-supplied header alone is not proof of gateway authentication.
   */
  public static function canUseMediaSessionForProxyIdentity(
    PhabricatorUser $user) {

    $providers = self::getCurrentEnabledProxyProviders();

    // Native password-only installations do not acquire a gateway dependency.
    if (!$providers) {
      return true;
    }

    $identity = AphrontRequest::getHTTPHeader('X-Auth-User');
    if (!phutil_nonempty_string($identity)) {
      return false;
    }

    foreach ($providers as $provider) {
      if ($provider->isSessionIdentityCompatible($user, $identity)) {
        return true;
      }
    }

    // The account-linking exception is deliberately unavailable to media reads.
    return false;
  }

  private static function getCurrentEnabledProxyProviders() {
    $configs = id(new PhabricatorAuthProviderConfigQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withProviderClasses(array(self::class))
      ->withIsEnabled(true)
      ->execute();

    // This final provider has one adapter key. Preserve the ordinary provider
    // loader's refusal of duplicate keys instead of accepting any mapping.
    if (count($configs) > 1) {
      throw new RuntimeException('proxy_identity_provider_ambiguous');
    }

    return mpull($configs, 'getProvider');
  }

  public function canUseSessionForAccountLink(
    PhabricatorUser $user,
    $identity,
    PhabricatorController $controller) {

    if (!$this->shouldAllowAccountLink() ||
        !phutil_nonempty_string($identity)) {
      return false;
    }

    $request = $controller->getRequest();
    $config = $this->getProviderConfig();
    $link_uri = '/auth/link/'.$config->getID().'/';
    if ($controller instanceof PhabricatorAuthLinkController) {
      $is_link_flow = ($request->getURIData('action') === 'link' &&
        (string)$request->getURIData('id') === (string)$config->getID());
    } else if ($controller instanceof PhabricatorAuthLoginController) {
      $is_link_flow =
        ($request->getURIData('pkey') === $this->getProviderKey());
    } else if ($controller instanceof PhabricatorAuthConfirmLinkController) {
      $is_link_flow = true;
    } else if ($controller instanceof PhabricatorAuthValidateController ||
               $controller instanceof PhabricatorAuthFinishController) {
      $is_link_flow =
        (PhabricatorCookies::getNextURICookie($request) === $link_uri);
    } else {
      $is_link_flow = false;
    }
    if (!$is_link_flow) {
      return false;
    }

    // Never use the linking exception to swap an already-linked identity.
    $existing = id(new PhabricatorExternalAccountQuery())
      ->setViewer($user)
      ->requireCapabilities(array(PhabricatorPolicyCapability::CAN_EDIT))
      ->withProviderConfigPHIDs(array($config->getPHID()))
      ->withUserPHIDs(array($user->getPHID()))
      ->executeOne();
    if ($existing) {
      return false;
    }

    $account = id(new PhabricatorExternalAccountQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->requireCapabilities(array(PhabricatorPolicyCapability::CAN_EDIT))
      ->withProviderConfigPHIDs(array($config->getPHID()))
      ->withRawAccountIdentifiers(array($identity))
      ->executeOne();
    if ($account && $account->getUserPHID()) {
      return false;
    }

    if ($controller instanceof PhabricatorAuthConfirmLinkController) {
      // The ordinary controller still verifies the registration cookie,
      // CSRF, Allow Linking, and the native high-security confirmation.
      return $account &&
        ($request->getURIData('akey') === $account->getAccountSecret());
    }

    return true;
  }

  public function isLoginFormAButton() {
    return true;
  }

  protected function getLoginIcon() {
    return 'Generic';
  }

  public function shouldAllowEditingRegistrationFields() {
    return false;
  }

  public function buildLoginForm(
    PhabricatorAuthStartController $controller) {
    $request = $controller->getRequest();
    return $this->renderLoginForm($request, 'start');
  }

  public function buildInviteForm(
    PhabricatorAuthStartController $controller) {
    $request = $controller->getRequest();
    return $this->renderLoginForm($request, 'invite');
  }

  public function buildLinkForm($controller) {
    $request = $controller->getRequest();
    return $this->renderLoginForm($request, 'link');
  }

  protected function renderLoginForm(AphrontRequest $request, $mode) {
    return $this->renderStandardLoginButton(
      $request,
      $mode,
      array(
        // ForwardAuth may redirect to another origin. A navigation link keeps
        // the existing form-action CSP intact without blocking that redirect.
        'navigation' => true,
        'method' => 'GET',
        'uri' => $this->getLoginURI(),
      ));
  }

  public function processLoginRequest(
    PhabricatorAuthLoginController $controller) {

    $request = $controller->getRequest();
    $account = null;
    $response = null;

    $auth_user = AphrontRequest::getHTTPHeader('X-Auth-User');
    $auth_email = AphrontRequest::getHTTPHeader('X-Auth-Email');
    $auth_name = AphrontRequest::getHTTPHeader('X-Auth-Name');

    if (!phutil_nonempty_string($auth_user)) {
      $response = $controller->buildProviderPageResponse(
        $this,
        id(new PHUIInfoView())
          ->setTitle(pht('Traefik authentication required'))
          ->setErrors(
            array(
              pht(
                'No Traefik Auth identity was found in this request. '.
                'Please access this application through Traefik so that '.
                'authentication headers (e.g. X-Auth-User) are set.'),
            )));
      return array($account, $response);
    }

    $username = $this->deriveUsernameFromEmail($auth_email, $auth_user);

    $adapter = $this->getAdapter();
    $adapter->setAccountID($auth_user);
    $adapter->setAccountEmail($auth_email ?: null);
    $adapter->setAccountRealName($auth_name ?: null);
    $adapter->setAccountName($username);

    $identifiers = $adapter->getAccountIdentifiers();
    $account = $this->newExternalAccountForIdentifiers($identifiers);

    return array($account, $response);
  }

  /**
   * Derive a Phorge username from the email local part (before @).
   * Falls back to X-Auth-User if email is missing or the local part is invalid.
   */
  private function deriveUsernameFromEmail($auth_email, $auth_user) {
    if (!phutil_nonempty_string($auth_email)) {
      return $auth_user;
    }
    $at = strpos($auth_email, '@');
    $local = ($at !== false) ? substr($auth_email, 0, $at) : $auth_email;
    $local = preg_replace('/[^a-zA-Z0-9._-]/', '', $local);
    $local = rtrim($local, '.');
    if (phutil_nonempty_string($local) &&
        PhabricatorUser::validateUsername($local)) {
      return $local;
    }
    return $auth_user;
  }

}
