<?php

final class PhabricatorGorgeIntegrationClient extends PhabricatorGorgeServiceClient {
  protected static function getServiceName() { return pht('Gorge integrations'); }
  protected function getDefaultTimeout() { return 45; }
  public static function config() {
    $config = PhabricatorEnv::getEnvConfig('gorge.integrations');
    return is_array($config) ? $config : array();
  }
  public static function enabled($domain, $key = null) {
    $value = idx(self::config(), $domain, array());
    if ($domain === 'fact') { return $value === true; }
    if (!is_array($value)) { return false; }
    if ($domain === 'inbound') { return in_array($key, $value, true); }
    return phutil_nonempty_string(idx($value, $key));
  }
  public static function newConfiguredClient() {
    $config = self::config();
    $uri = idx($config, 'uri');
    $token = idx($config, 'token');
    if (!phutil_nonempty_string($uri) || !phutil_nonempty_string($token)) {
      throw new Exception(pht('Enabled integrations require uri and token.'));
    }
    return id(new self())->setURI($uri)->setToken($token);
  }
  private function call($path, array $body) {
    $uri = $this->getURI().'/api/integrations/'.$path;
    return self::parseResponseEnvelope($uri,
      $this->newJSONRequestFuture($uri, $body)->resolve());
  }
  public static function sendSMS($adapter, PhabricatorMailSMSMessage $message) {
    $targets = idx(self::config(), 'sms', array());
    $target = idx($targets, $adapter->getKey());
    $id = $message->getGorgeDeliveryID();
    if (!phutil_nonempty_string($id)) {
      throw new PhabricatorMetaMTAPermanentFailureException(
        pht('Native SMS requires a persistent mail identity.'));
    }
    return self::newConfiguredClient()->effect(array(
      'id' => hash('sha256', 'sms:'.$id.':'.$adapter->getKey()),
      'target' => $target,
      'to' => $message->getToNumber()->toE164(),
      'text' => $message->getTextBody(),
    ));
  }
  public function effect(array $body, $connector = false) {
    try {
      $out = $this->call('effect', $body);
    } catch (PhabricatorMetaMTAPermanentFailureException $ex) {
      throw new PhabricatorMetaMTAPermanentFailureException(
        pht('%s Identity: %s.', $ex->getMessage(), idx($body, 'id')));
    }
    switch (idx($out, 'state')) {
      case 'accepted': return idx($out, 'result', array());
      case 'retry': throw new Exception(pht('Integration provider throttled the request.'));
      case 'rejected':
        if ($connector) {
          throw new HTTPFutureHTTPResponseStatus((int)idx($out, 'status'), '', array());
        }
        throw new PhabricatorMetaMTAPermanentFailureException(
        pht('Integration provider rejected the request (HTTP %s).', idx($out, 'status')));
      default: throw new PhabricatorMetaMTAPermanentFailureException(
        pht('Integration outcome is unknown; inspect identity %s before retrying.', idx($body, 'id')));
    }
  }
  public static function connector($key, $context, $principal, $token,
    $path, $method, array $params, $operation = 'default') {
    if ($token instanceof PhutilOpaqueEnvelope) { $token = $token->openEnvelope(); }
    $targets = idx(self::config(), 'connectors', array());
    return self::newConfiguredClient()->effect(array(
      'id' => hash('sha256', phutil_json_encode(array(
        $key, (string)$context, (string)$principal, $path, $method, $operation))),
      'target' => idx($targets, $key), 'principal' => (string)$principal,
      'secret' => $token, 'path' => $path, 'method' => $method, 'params' => $params ?: new stdClass(),
    ), true);
  }
  public function checkConfiguration() {
    $capabilities = $this->inspectIntegration('capabilities');
    $config = self::config();
    foreach (array('inbound', 'sms', 'connectors') as $domain) {
      if (!is_array(idx($config, $domain, array()))) {
        throw new Exception(pht('Integration domain %s must be an array.', $domain));
      }
    }
    if (isset($config['fact']) && !is_bool($config['fact'])) {
      throw new Exception(pht('Integration fact flag must be boolean.'));
    }
    if ((int)idx($capabilities, 'version') < 2) {
      throw new Exception(pht('Integrations protocol version 2 is required.'));
    }
    foreach (idx($config, 'inbound', array()) as $provider) {
      if (!in_array($provider, idx($capabilities, 'inbound', array()), true)) {
        throw new Exception(pht('Inbound provider %s is not enabled on Gorge.', $provider));
      }
    }
    $types = idx($capabilities, 'targets', array());
    foreach (array('sms', 'connectors') as $domain) {
      foreach (idx($config, $domain, array()) as $key => $target) {
        $type = idx($types, $target);
        if ($domain === 'sms') {
          $valid = in_array($type, array('twilio', 'sns'), true);
          $mailers = PhabricatorEnv::getEnvConfig('cluster.mailers');
          $found = false;
          foreach ($mailers as $mailer) {
            if (idx($mailer, 'key') === $key) {
              $expected = $type;
              $found = idx($mailer, 'type') === $expected;
            }
          }
          $valid = $valid && $found;
        } else {
          $expected = strpos($key, 'jira:') === 0 ? 'jira' : $key;
          $valid = in_array($expected, array('jira', 'asana', 'github'), true) &&
            $type === $expected;
        }
        if (!$valid) { throw new Exception(pht('Invalid %s target mapping for %s.', $domain, $key)); }
      }
    }
    if (idx($config, 'fact') === true && idx($capabilities, 'fact') !== true) {
      throw new Exception(pht('Fact writer is not enabled on Gorge.'));
    }
    return array('valid' => true, 'capabilities' => $capabilities);
  }
  public function inspectIntegration($domain, $id = null) {
    if (!in_array($domain, array('health', 'usage', 'capacity', 'capabilities', 'effect', 'inbound'), true)) {
      throw new Exception(pht('Invalid inspection domain.'));
    }
    $uri = $this->getURI().'/api/integrations/'.$domain;
    if ($id !== null) { $uri .= '?id='.rawurlencode($id); }
    return self::parseResponseEnvelope($uri, $this->newRequestFuture($uri)->resolve());
  }
  public function resolveIntegration(array $resolution) {
    return $this->call('resolve', $resolution);
  }
  public static function authenticate($key, $operation, $secret, array $params) {
    $targets = idx(self::config(), 'connectors', array());
    $out = self::newConfiguredClient()->call('auth', array(
      'target' => idx($targets, $key), 'operation' => $operation,
      'secret' => $secret, 'params' => $params ?: new stdClass()));
    if ((int)idx($out, 'status') < 200 || (int)idx($out, 'status') >= 300) {
      throw new Exception(pht('Integration authentication failed.'));
    }
    return idx($out, 'result', array());
  }
  public static function readGitHub($principal, $token, $path, array $params, $etag = null) {
    if ($token instanceof PhutilOpaqueEnvelope) { $token = $token->openEnvelope(); }
    $targets = idx(self::config(), 'connectors', array());
    $out = self::newConfiguredClient()->call('read', array(
      'target' => idx($targets, 'github'), 'principal' => (string)$principal,
      'secret' => $token, 'path' => ltrim($path, '/'), 'method' => 'GET',
      'params' => $params ?: new stdClass(), 'etag' => $etag ?: ''));
    $headers = array();
    foreach (idx($out, 'headers', array()) as $key => $value) { $headers[] = array($key, $value); }
    $status = new HTTPFutureHTTPResponseStatus((int)idx($out, 'status'), '', $headers);
    $remaining = idx(idx($out, 'headers', array()), 'x-ratelimit-remaining');
    $limited = $status->getStatusCode() === 403 && $remaining !== null && !(int)$remaining;
    if ($status->isError() && $status->getStatusCode() !== 304 && !$limited) {
      throw $status;
    }
    return id(new PhutilGitHubResponse())->setStatus($status)
      ->setHeaders($headers)->setBody(idx($out, 'result', array()));
  }
  public static function readConnector($key, $principal, $token, $path, array $params = array()) {
    if ($token instanceof PhutilOpaqueEnvelope) { $token = $token->openEnvelope(); }
    $targets = idx(self::config(), 'connectors', array());
    $out = self::newConfiguredClient()->call('read', array(
      'target' => idx($targets, $key), 'principal' => $principal,
      'secret' => $token, 'path' => $path, 'method' => 'GET', 'params' => $params ?: new stdClass()));
    $status = (int)idx($out, 'status');
    if ($status === 404) { return null; }
    if ($status < 200 || $status >= 300) { throw new Exception(pht('Connector read failed (HTTP %s).', $status)); }
    return idx($out, 'result');
  }
  public static function queueRawInbound($raw, $duplicates = false) {
    return self::newConfiguredClient()->call('inbound', array(
      'provider' => 'raw', 'ingressID' => bin2hex(random_bytes(16)),
      'raw' => base64_encode($raw), 'processDuplicates' => $duplicates));
  }
  public static function queueInbound(AphrontRequest $request, $provider,
    $postmark = null) {
    $fields = array();
    foreach (array('message-headers', 'recipient', 'from', 'subject',
      'stripped-text', 'stripped-html', 'headers', 'to', 'text', 'html') as $key) {
      $fields[$key] = $request->getStr($key);
    }
    $files = array();
    foreach ($_FILES as $file) {
      if (idx($file, 'error') !== UPLOAD_ERR_OK ||
          !is_uploaded_file(idx($file, 'tmp_name'))) {
        throw new Exception(pht('Inbound attachment upload failed.'));
      }
      if ((int)idx($file, 'size') > 6 * 1024 * 1024) {
        throw new Exception(pht('Inbound attachment is too large.'));
      }
      $bytes = file_get_contents($file['tmp_name']);
      if ($bytes === false) { throw new Exception(pht('Unable to read attachment.')); }
      $files[] = array('name' => $file['name'], 'data' => base64_encode($bytes));
    }
    $body = array('provider' => $provider, 'ingressID' => bin2hex(random_bytes(16)), 'fields' => $fields,
      'attachments' => $files);
    if ($postmark !== null) { $body['postmark'] = phutil_json_decode($postmark); }
    self::newConfiguredClient()->call('inbound', $body);
    // Only acknowledged after Gorge commits the durable inbox record.
    return id(new AphrontWebpageResponse())->setContent(pht('Accepted.'));
  }
  protected static function newServiceErrorException($uri, $code, $message) {
    if ($code === 'ERR_OUTCOME_UNKNOWN' || $code === 'ERR_IDENTITY_CONFLICT') {
      return new PhabricatorMetaMTAPermanentFailureException(
        pht('Integration identity requires inspection: %s.', $code));
    }
    return parent::newServiceErrorException($uri, $code, $message);
  }
}
