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
