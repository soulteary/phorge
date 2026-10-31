<?php

/**
 * Keep the optional gateway identity and Phorge identity on the same request.
 * Executes before the selected application controller.
 */
final class TeamEntrySessionGuard extends PhabricatorAuthSessionEngineExtension {
  const EXTENSIONKEY = 'team-entry-session';

  public function getExtensionName() {
    return pht('Personal entry and application session consistency');
  }

  public function willServeRequestForUser(PhabricatorUser $user) {
    if (PHP_SAPI === 'cli') {
      return;
    }
    $file_policy = PhabricatorEnv::getEnvConfig('integrations.entry-session.enforce-file-policy');
    if (!PhabricatorEnv::getEnvConfig('integrations.entry-session.enabled')) {
      if ($file_policy) {
        $this->enforceFilePolicy($user, PhabricatorStartup::getRequestPath());
      }
      return;
    }
    $settings = PhorgeOptionalIntegrations::getEntryConfiguration();
    if (!$user->isLoggedIn()) {
      // Anonymous registration/login has not selected a Phorge identity yet.
      // File bearer URLs still require policy checks for this anonymous viewer.
      if ($file_policy) {
        $this->enforceFilePolicy($user, PhabricatorStartup::getRequestPath());
      }
      return;
    }

    $web_session = null;
    if ($user->hasSession() &&
        $user->getSession()->getType() === PhabricatorAuthSession::TYPE_WEB) {
      $web_session = $user->getSession();
    }

    if (!$web_session && $this->isAuthenticatedMachineRequest()) {
      // This hook is reached for a logged-in API user only AFTER Conduit has
      // validated its user API credential. Require the gateway credential too.
      return;
    }

    $principal = AphrontRequest::getHTTPHeader($settings['principal-header']);
    $entry_cookie = idx($_COOKIE, $settings['cookie']);
    if (!in_array(idx($_SERVER, 'REMOTE_ADDR'), $settings['trusted-proxies'], true) ||
        !is_string($principal) || $principal === '' ||
        !is_string($entry_cookie) || $entry_cookie === '' ||
        $entry_cookie === 'deleted' || $user->getIsDisabled()) {
      $this->reject($user, $web_session, 'entry_identity_required');
    }

    $configs = id(new PhabricatorAuthProviderConfigQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIsEnabled(true)->execute();
    $config = null;
    foreach ($configs as $candidate) {
      if ($candidate->getShouldAllowLogin() &&
          $candidate->getProvider()->getProviderKey() === $settings['provider']) {
        $config = $candidate;
        break;
      }
    }
    if (!$config) {
      $this->reject($user, $web_session, 'entry_provider_unavailable');
    }

    // Use the real Phorge provider identity mapping, never a mutable email,
    // name, Warden role, or application username.
    $accounts = id(new PhabricatorExternalAccountQuery())
      ->setViewer($user)
      ->requireCapabilities(array(PhabricatorPolicyCapability::CAN_EDIT))
      ->withProviderConfigPHIDs(array($config->getPHID()))
      ->withUserPHIDs(array($user->getPHID()))
      ->withRawAccountIdentifiers(array($principal))
      ->needAccountIdentifiers(true)
      ->execute();
    $matched = false;
    foreach ($accounts as $account) {
      foreach ($account->getAccountIdentifiers() as $identifier) {
        if (hash_equals($principal, $identifier->getIdentifierRaw())) {
          $matched = true;
        }
      }
    }
    if (!$matched) {
      $this->reject($user, $web_session, 'entry_user_changed');
    }

    if (!$web_session) {
      // A public API credential must represent the same user as ForwardAuth.
      return;
    }

    $key = $web_session->getSessionKey();
    $principal_hash = PhabricatorHash::digestWithNamedKey(
      $principal, 'team.entry.principal');
    $entry_hash = PhabricatorHash::digestWithNamedKey(
      $entry_cookie, 'team.entry.session');
    $dao = new TeamEntrySessionBinding();
    $conn = $dao->establishConnection('w');
    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();

    // A unique session key makes the first binding atomic. Existing bindings
    // are NEVER replaced by a later entry session, even for the same person.
    queryfx($conn,
      'INSERT INTO %T (sessionKey, userPHID, principalHash, entryHash, sessionExpires) '.
      'VALUES (%s, %s, %s, %s, %d) ON DUPLICATE KEY UPDATE sessionKey = sessionKey',
      $dao->getTableName(), $key, $user->getPHID(), $principal_hash,
      $entry_hash, $web_session->getSessionExpires());
    $row = queryfx_one($conn, 'SELECT * FROM %T WHERE sessionKey = %s',
      $dao->getTableName(), $key);
    unset($unguarded);

    if (!$row || $row['userPHID'] !== $user->getPHID() ||
        !hash_equals($row['principalHash'], $principal_hash) ||
        !hash_equals($row['entryHash'], $entry_hash)) {
      $this->reject($user, $web_session, 'entry_session_changed');
    }

    // Keep the binding alive exactly as long as the underlying Phorge session.
    if ((int)$row['sessionExpires'] !== (int)$web_session->getSessionExpires()) {
      $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
      queryfx($conn, 'UPDATE %T SET sessionExpires = %d WHERE sessionKey = %s',
        $dao->getTableName(), $web_session->getSessionExpires(), $key);
      unset($unguarded);
    }

    if ($file_policy) {
      $this->enforceFilePolicy($user, PhabricatorStartup::getRequestPath());
    }
  }

  /**
   * Native file URLs are bearer credentials. This optional restriction also
   * requires the current viewer's native file/object policy on every request.
   *
   * @phutil-external-symbol class PhabricatorStartup
   */
  public function enforceFilePolicy(PhabricatorUser $user, $path) {
    $phid = self::getProtectedFilePHID($path);
    if ($phid === null) {
      return;
    }

    // An incomplete authentication session cannot access confidential bytes.
    $viewer = $user;
    if (!$user->getIsApproved() ||
        ($user->hasSession() && $user->getSession()->getIsPartial())) {
      $viewer = new PhabricatorUser();
    }

    if ($this->canViewFile($viewer, $phid, array())) {
      return;
    }

    throw new TeamEntryFilePolicyException();
  }

  private function canViewFile(PhabricatorUser $viewer, $phid, array $seen) {
    if (isset($seen[$phid]) || count($seen) >= 16) {
      return false;
    }
    $seen[$phid] = true;
    // Builtin resources ship with Phorge and are required by the login page.
    // With public policies disabled, their "most open" native policy is users.
    // Restrict this fallback in SQL to genuine builtins; never grant a general
    // profile-image, secret-key, CDN, service-header, or administrator bypass.
    $builtin = id(new PhabricatorFileQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($phid))
      ->withIsBuiltin(true)
      ->withIsDeleted(false)
      ->executeOne();
    if ($builtin) {
      return true;
    }

    // Native FileQuery deliberately makes profile images public and skips
    // their transform ancestry. Even such a derivative must not reveal bytes
    // from a confidential source under this restriction. Check every ancestry
    // layer explicitly, with a cycle/depth bound, before its own native policy.
    $origins = id(new PhabricatorTransformedFile())->loadAllWhere(
      'transformedPHID = %s', $phid);
    $source_visible = !$origins;
    foreach ($origins as $origin) {
      $source_phid = $origin->getOriginalPHID();
      if ($source_phid === PhabricatorPHIDConstants::PHID_VOID) {
        continue;
      }
      if ($this->canViewFile($viewer, $source_phid, $seen)) {
        $source_visible = true;
      }
    }
    if (!$source_visible) {
      return false;
    }

    $files = id(new PhabricatorFileQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($phid))
      ->withIsDeleted(false)
      ->setRaisePolicyExceptions(false)
      ->execute();
    return (bool)$files;
  }

  public static function getProtectedFilePHID($path) {
    if (strncmp($path, '/file/', 6)) {
      return null;
    }
    // Use the very same decoded path and resource routing map as Phorge.
    // This covers data, download and xform routes, including @instance forms,
    // without trusting REQUEST_URI, caller query parameters or our own regex.
    $application = new PhabricatorFilesApplication();
    $route = id(new AphrontRoutingMap())
      ->setSite(new PhabricatorPlatformSite())
      ->setApplication($application)
      ->setRoutes($application->getResourceRoutes())
      ->routePath($path);
    if (!$route) {
      return null;
    }
    return idx($route->getURIData(), 'phid');
  }

  private function isAuthenticatedMachineRequest() {
    $settings = PhorgeOptionalIntegrations::getEntryConfiguration();
    if (in_array(idx($_SERVER, 'REMOTE_ADDR'), $settings['trusted-proxies'], true) ||
        idx($_SERVER, 'REQUEST_METHOD') !== 'POST') {
      return false;
    }
    $path = parse_url((string)idx($_SERVER, 'REQUEST_URI', ''), PHP_URL_PATH);
    if (!is_string($path) || !preg_match('@^/api/[a-z][a-z0-9.]*/*$@D', $path)) {
      return false;
    }
    $expected = PhabricatorGorgeServiceRegistry::getService('conduit')
      ->getConfiguredToken();
    $provided = AphrontRequest::getHTTPHeader('X-Service-Token');
    return is_string($expected) && $expected !== '' &&
      is_string($provided) && hash_equals($expected, $provided);
  }

  private function reject(PhabricatorUser $user, $session, $reason) {
    if ($session) {
      $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
      id(new PhabricatorAuthSessionEngine())->logoutSession($user, $session);
      unset($unguarded);
    }
    throw new TeamEntrySessionMismatchException($reason);
  }

  public function didLogout(PhabricatorUser $user, array $sessions) {
    if (!PhabricatorEnv::getEnvConfig('integrations.entry-session.enabled')) {
      return;
    }
    $dao = new TeamEntrySessionBinding();
    $conn = $dao->establishConnection('w');
    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
    foreach ($sessions as $session) {
      queryfx($conn, 'DELETE FROM %T WHERE sessionKey = %s',
        $dao->getTableName(), $session->getSessionKey());
    }
    unset($unguarded);
  }
}

/** Declare the extra user-schema table to Phorge's schema diagnostics. */
final class TeamEntrySessionBinding extends PhabricatorUserDAO {
  protected $sessionKey;
  protected $userPHID;
  protected $principalHash;
  protected $entryHash;
  protected $sessionExpires;

  public function getTableName() {
    return 'user_entrysessionbinding';
  }

  protected function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_COLUMN_SCHEMA => array(
        'sessionKey' => 'bytes64',
        'userPHID' => 'phid',
        'principalHash' => 'bytes64',
        'entryHash' => 'bytes64',
        'sessionExpires' => 'epoch',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'key_session' => array('columns' => array('sessionKey'), 'unique' => true),
        'key_expires' => array('columns' => array('sessionExpires')),
      ),
    ) + parent::getConfiguration();
  }
}

final class TeamEntrySessionMismatchException extends Exception {}
final class TeamEntryFilePolicyException extends Exception {}

final class TeamEntrySessionMismatchHandler extends AphrontRequestExceptionHandler {
  public function getRequestExceptionHandlerDescription() {
    return pht('Reject a stale or conflicting personal entry/application session.');
  }

  public function getRequestExceptionHandlerPriority() {
    return 1000;
  }

  public function canHandleRequestThrowable(AphrontRequest $request, $throwable) {
    return $throwable instanceof TeamEntrySessionMismatchException ||
      $throwable instanceof TeamEntryFilePolicyException;
  }

  public function handleRequestThrowable(AphrontRequest $request, $throwable) {
    if ($throwable instanceof TeamEntryFilePolicyException) {
      // Do not render file details, its name, a download dialog or a redirect.
      return id(new AphrontPlainTextResponse())
        ->setHTTPResponseCode(404)
        ->addHeader('Cache-Control', 'private, no-store, max-age=0')
        ->setContent("Not Found\n");
    }
    if ($request->canSetCookies()) {
      $request->clearCookie(PhabricatorCookies::COOKIE_SESSION);
    }

    // Entry authentication rotates its session cookie independently of the
    // application cookie. The guard has already revoked the old application
    // session: reauthenticate a document navigation through the normal native
    // provider instead of asking the person to click a second login button.
    // Never replay a submitted operation, return authenticated bytes from a
    // resource request, or replace the existing entry/session binding.
    if ($this->canReauthenticateNavigation($request, $throwable)) {
      $next_uri = (string)$request->getRequestURI();
      if (!PhabricatorEnv::isValidLocalURIForLink($next_uri) ||
          preg_match('@^/auth(?:/|$)@', $request->getPath())) {
        $next_uri = '/';
      }
      PhabricatorCookies::setNextURICookie($request, $next_uri, true);
      return id(new AphrontRedirectResponse())
        ->setHTTPResponseCode(303)
        ->addHeader('Cache-Control', 'private, no-store, max-age=0')
        ->setURI(PhorgeOptionalIntegrations::getEntryConfiguration()['provider-login-uri']);
    }

    $content = hsprintf(
      '<!doctype html><html lang="zh-CN"><meta charset="utf-8">'.
      '<title>请重新登录</title><h1>入口身份或登录会话已改变</h1>'.
      '<p>原 Phorge 会话已失效，本次操作未执行。请重新登录后再操作。</p>'.
      '<p><a href="%s">以当前入口身份重新登录 Phorge</a></p>'.
      '<p>错误代码：ENTRY_SESSION_MISMATCH</p></html>',
      PhorgeOptionalIntegrations::getEntryConfiguration()['provider-login-uri']);
    return id(new AphrontWebpageResponse())
      ->setHTTPResponseCode(409)
      ->addHeader('Cache-Control', 'no-store')
      ->setContent($content);
  }

  private function canReauthenticateNavigation(
    AphrontRequest $request,
    TeamEntrySessionMismatchException $throwable) {

    // These two reasons are raised only after the trusted entry identity,
    // cookie, enabled provider and active application user were checked.
    if (!in_array($throwable->getMessage(), array(
      'entry_user_changed', 'entry_session_changed'), true)) {
      return false;
    }
    if (!$request->canSetCookies() || !$request->isHTTPGet() ||
        $request->isAjax() || $request->isWorkflow() ||
        $request->isQuicksand() || $request->isConduit() ||
        $request->getExists(AphrontRequest::TYPE_FORM)) {
      return false;
    }
    $accept = AphrontRequest::getHTTPHeader('Accept');
    if (!is_string($accept) || stripos($accept, 'text/html') === false) {
      return false;
    }
    if (preg_match('@^/(?:api|file|res)(?:/|$)@', $request->getPath())) {
      return false;
    }
    $destination = AphrontRequest::getHTTPHeader('Sec-Fetch-Dest');
    return $destination === null || $destination === 'document';
  }
}

/** Register the table through normal bin/storage upgrade, including opt-out sites. */
final class TeamEntrySessionSQLPatchList extends PhabricatorSQLPatchList {
  public function getNamespace() { return 'optionalentrysession'; }
  public function getPatches() {
    return array('20261031.entry-session.sql' => array(
      'type' => 'sql',
      'name' => dirname(phutil_get_library_root('phorge')).
        '/resources/sql/optional-entry-session/20261031.entry-session.sql',
      'after' => array('phabricator:db.user'),
    ));
  }
}
