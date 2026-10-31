<?php

final class PhorgeOptionalIntegrationsConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() { return pht('Optional Entry Integrations'); }
  public function getDescription() { return pht('Explicit gateway identity and account-maintenance integration.'); }
  public function getGroup() { return 'core'; }

  public function getOptions() {
    $spec = array(
      'integrations.entry-session.enabled' => array('bool', false,
        'Verify the trusted gateway identity and bind each native web session to its entry cookie.'),
      'integrations.entry-session.enforce-file-policy' => array('bool', false,
        'Require the current native viewer policy for file bearer URLs, independently of entry-session verification.'),
      'integrations.entry-session.trusted-proxies' => array('list<string>', array(),
        'Exact TCP peer IP addresses allowed to provide entry identity; never trust arbitrary forwarded client headers.'),
      'integrations.entry-session.cookie' => array('string', 'entry_session',
        'Name of the gateway host-only session cookie; only its keyed hash is stored.'),
      'integrations.entry-session.principal-header' => array('string', 'X-Auth-User',
        'Header carrying the immutable external account identifier from a trusted proxy.'),
      'integrations.entry-session.provider' => array('string', 'traefik:traefik',
        'Native enabled provider key whose external identifier must match the gateway principal.'),
      'integrations.entry-session.provider-login-uri' => array('string', '',
        'Empty derives the native /auth/login/<provider>/ recovery path; an override must match it.'),
      'integrations.entry-session.entry-login-uri' => array('string', '/auth/',
        'Local gateway login path used after a password reset; configure it for the selected gateway.'),
      'integrations.maintenance.enabled' => array('bool', false,
        'Enable the optional administrative RPC UI only after entry-session verification and the external service are configured.'),
      'integrations.maintenance.endpoint' => array('string', '',
        'HTTPS URL ending in /rpc for the separately deployed account-maintenance service.'),
      'integrations.maintenance.key-file' => array('string', '',
        'Absolute protected server-side file containing exactly 32 HMAC key bytes; never returned to browsers.'),
      'integrations.maintenance.allow-http' => array('bool', false,
        'Explicitly allow HTTP only on a trusted private maintenance network; HTTPS remains the default requirement.'),
    );
    $options = array();
    foreach ($spec as $key => $row) {
      $options[] = $this->newOption($key, $row[0], $row[1])
        ->setLocked(true)->setDescription(pht($row[2]));
    }
    return $options;
  }
}
