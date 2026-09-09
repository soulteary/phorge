#!/usr/bin/env php
<?php

// Build the atomic, read-only configuration consumed by
// PhabricatorDeploymentConfigSource. This script intentionally does not boot
// Phorge: it is creating one of the inputs to that bootstrap.

if ($argc !== 4) {
  fwrite(
    STDERR,
    "Usage: build_deployment_config.php " .
    "<collaboration|full> <deployment-file> <local-config>\n");
  exit(64);
}

$profile = $argv[1];
$deployment_path = $argv[2];
$local_path = $argv[3];

if ($profile !== 'collaboration' && $profile !== 'full') {
  fwrite(STDERR, "Unknown product profile.\n");
  exit(64);
}

function read_json_object($path, $optional) {
  if (!is_file($path)) {
    if ($optional) {
      return array();
    }
    throw new Exception('Required JSON file does not exist: '.$path);
  }

  $raw = file_get_contents($path);
  if ($raw === false) {
    throw new Exception('Unable to read JSON file: '.$path);
  }
  $json = ltrim($raw);
  if (!strlen($json) || $json[0] !== '{') {
    throw new Exception('JSON file is not an object: '.$path);
  }
  $value = json_decode($raw, true);
  if (!is_array($value)) {
    throw new Exception('JSON file is not an object: '.$path);
  }
  if ($value && array_keys($value) === range(0, count($value) - 1)) {
    throw new Exception('JSON file is not an object: '.$path);
  }
  return $value;
}

function env_value($key, $default = null) {
  $value = getenv($key);
  return ($value === false) ? $default : $value;
}

function env_mode($key, $selector) {
  $mode = env_value($key, 'auto');
  if ($mode === 'auto') {
    return strlen((string)env_value($selector, '')) ? 'enable' : 'preserve';
  }
  if (!in_array($mode, array('enable', 'disable', 'preserve'), true)) {
    throw new Exception('Unknown '.$key.' value: '.$mode);
  }
  return $mode;
}

function env_uint($key, $default, $allow_empty = false) {
  $value = env_value($key, $default);
  if ($allow_empty && $value === '') {
    return null;
  }
  if (!is_string($value) || !preg_match('/\A\d+\z/', $value)) {
    throw new Exception($key.' must be an unsigned integer.');
  }
  return (int)$value;
}

function nullable_env($key) {
  $value = env_value($key, '');
  return strlen($value) ? $value : null;
}

function local_list(array $local, $key) {
  if (array_key_exists($key, $local) && is_array($local[$key])) {
    return $local[$key];
  }
  return array();
}

function configure_scalar_service(
  array &$config,
  $selector,
  $uri_key,
  $token_key) {

  $uri = env_value($selector, '');
  if (!strlen($uri)) {
    return;
  }

  $config[$uri_key] = rtrim($uri, '/');
  $config[$token_key] = nullable_env(
    preg_replace('/_URI\z/', '_TOKEN', $selector));
}

$local = read_json_object($local_path, false);
$config = read_json_object($deployment_path, true);

// Every generation owns the selected profile. Unlike local.json, this file is
// replaced on every deployment change, so profile and topology can not drift.
$config['phorge.product-profile'] = $profile;

configure_scalar_service(
  $config,
  'GORGE_RENDER_URI',
  'gorge.render.uri',
  'gorge.render.token');
configure_scalar_service(
  $config,
  'GORGE_CONDUIT_URI',
  'gorge.conduit.uri',
  'gorge.conduit.token');
configure_scalar_service(
  $config,
  'GORGE_FILE_URI',
  'gorge.file.uri',
  'gorge.file.token');

// Conduit callbacks arrive with the internal upstream Host. Keep the user's
// existing aliases and append the one owned by this deployment.
$upstream = env_value('GORGE_CONDUIT_UPSTREAM_URL', '');
if (strlen($upstream)) {
  $parts = parse_url($upstream);
  if (!is_array($parts) || empty($parts['host'])) {
    throw new Exception('GORGE_CONDUIT_UPSTREAM_URL is not a valid URI.');
  }
  $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
  $port = isset($parts['port']) ? (int)$parts['port'] : null;
  $default_port = ($scheme === 'https') ? 443 : 80;
  $authority = $parts['host'];
  if ($port && $port !== $default_port) {
    $authority .= ':'.$port;
  }
  $allowed = local_list($local, 'phabricator.allowed-uris');
  $uri = $scheme.'://'.$authority.'/';
  if (!in_array($uri, $allowed, true)) {
    $allowed[] = $uri;
  }
  $config['phabricator.allowed-uris'] = array_values($allowed);
}

$notification_mode = env_mode(
  'GORGE_NOTIFICATION_MODE',
  'GORGE_NOTIFICATION_ADMIN_HOST');
if ($notification_mode === 'enable') {
  $admin_host = env_value('GORGE_NOTIFICATION_ADMIN_HOST', '');
  $client_host = env_value('GORGE_NOTIFICATION_CLIENT_HOST', '');
  if (!strlen($admin_host) || !strlen($client_host)) {
    throw new Exception(
      'Both Gorge notification hosts are required in enable mode.');
  }
  $client = array(
    'type' => 'client',
    'host' => $client_host,
    'port' => env_uint('GORGE_NOTIFICATION_CLIENT_PORT', '22280'),
    'protocol' => env_value('GORGE_NOTIFICATION_CLIENT_PROTOCOL', 'http'),
  );
  $client_path = env_value('GORGE_NOTIFICATION_CLIENT_PATH', '');
  if (strlen($client_path)) {
    $client['path'] = $client_path;
  }
  $config['notification.servers'] = array(
    array(
      'type' => 'admin',
      'host' => $admin_host,
      'port' => env_uint('GORGE_NOTIFICATION_ADMIN_PORT', '22281'),
      'protocol' => 'http',
    ),
    $client,
  );
} else if ($notification_mode === 'disable') {
  unset($config['notification.servers']);
}

$mailer_mode = env_mode('GORGE_MAILER_MODE', 'GORGE_MAILER_URI');
if ($mailer_mode !== 'preserve') {
  $key = env_value('GORGE_MAILER_KEY', 'gorge-mailer');
  $mailers = array();
  foreach (local_list($local, 'cluster.mailers') as $mailer) {
    if (is_array($mailer) && isset($mailer['key']) && $mailer['key'] === $key) {
      continue;
    }
    $mailers[] = $mailer;
  }
  if ($mailer_mode === 'enable') {
    $uri = env_value('GORGE_MAILER_URI', '');
    if (!strlen($uri)) {
      throw new Exception('GORGE_MAILER_URI is required in enable mode.');
    }
    $options = array(
      'uri' => rtrim($uri, '/'),
      'timeout' => env_uint('GORGE_MAILER_TIMEOUT', '30'),
      'supports-message-id' => in_array(
        env_value('GORGE_MAILER_SUPPORTS_MESSAGE_ID', '0'),
        array('1', 'true'),
        true),
    );
    $token = nullable_env('GORGE_MAILER_TOKEN');
    if ($token !== null) {
      $options['token'] = $token;
    }
    $mailer = array(
      'key' => $key,
      'type' => 'gorge',
      'inbound' => false,
      'media' => array('email'),
      'options' => $options,
    );
    $priority = env_uint('GORGE_MAILER_PRIORITY', '', true);
    if ($priority !== null) {
      if ($priority < 1) {
        throw new Exception('GORGE_MAILER_PRIORITY must be positive.');
      }
      $mailer['priority'] = $priority;
    }
    $mailers[] = $mailer;
  }
  $config['cluster.mailers'] = array_values($mailers);
}

$search_mode = env_mode('GORGE_SEARCH_MODE', 'GORGE_SEARCH_HOST');
if ($search_mode !== 'preserve') {
  $search = array();
  foreach (local_list($local, 'cluster.search') as $engine) {
    if (is_array($engine) && isset($engine['type']) &&
        $engine['type'] === 'gorge') {
      continue;
    }
    $search[] = $engine;
  }
  if ($search_mode === 'enable') {
    $host = env_value('GORGE_SEARCH_HOST', '');
    if (!strlen($host)) {
      throw new Exception('GORGE_SEARCH_HOST is required in enable mode.');
    }
    if (env_value('GORGE_SEARCH_KEEP_MYSQL', '0') !== '1') {
      $search = array_values(
        array_filter(
          $search,
          function($engine) {
            return !(is_array($engine) && isset($engine['type']) &&
              $engine['type'] === 'mysql');
          }));
    }
    array_unshift(
      $search,
      array(
        'type' => 'gorge',
        'hosts' => array(
          array(
            'host' => $host,
            'port' => env_uint('GORGE_SEARCH_PORT', '8120'),
            'protocol' => env_value('GORGE_SEARCH_PROTOCOL', 'http'),
            'roles' => array('read' => true, 'write' => true),
          ),
        ),
      ));
    $config['gorge.search.token'] = nullable_env('GORGE_SEARCH_TOKEN');
  } else {
    unset($config['gorge.search.token']);
    if (!$search) {
      $search[] = array(
        'type' => 'mysql',
        'roles' => array('read' => true, 'write' => true),
      );
    }
  }
  $config['cluster.search'] = array_values($search);
}

$webhook_mode = env_mode('GORGE_WEBHOOK_MODE', 'GORGE_WEBHOOK_URI');
if ($webhook_mode === 'enable') {
  $uri = env_value('GORGE_WEBHOOK_URI', '');
  if (!strlen($uri)) {
    throw new Exception('GORGE_WEBHOOK_URI is required in enable mode.');
  }
  $config['gorge.webhook.uri'] = rtrim($uri, '/');
  $config['gorge.webhook.token'] = nullable_env('GORGE_WEBHOOK_TOKEN');
  $config['gorge.webhook.owner'] = 'gorge';
} else if ($webhook_mode === 'disable') {
  $config['gorge.webhook.uri'] = null;
  $config['gorge.webhook.token'] = null;
  $config['gorge.webhook.owner'] = 'phorge';
}

$taskqueue_mode = env_mode('GORGE_TASKQUEUE_MODE', 'GORGE_TASKQUEUE_URI');
if ($taskqueue_mode === 'enable') {
  $uri = env_value('GORGE_TASKQUEUE_URI', '');
  if (!strlen($uri)) {
    throw new Exception('GORGE_TASKQUEUE_URI is required in enable mode.');
  }
  $config['gorge.taskqueue.uri'] = rtrim($uri, '/');
  $config['gorge.taskqueue.token'] = nullable_env('GORGE_TASKQUEUE_TOKEN');
  $config['gorge.taskqueue.owner'] = 'gorge';
  // Explicit Gorge ownership and a native taskmaster pool are mutually
  // exclusive. Write this in the same document as the owner and endpoint so
  // no process can observe a zero- or double-consumer transition.
  $config['phd.taskmasters'] = 0;
} else if ($taskqueue_mode === 'disable') {
  $config['gorge.taskqueue.uri'] = null;
  $config['gorge.taskqueue.token'] = null;
  $config['gorge.taskqueue.owner'] = 'phorge';
  unset($config['phd.taskmasters']);
}

$db_mode = env_mode('GORGE_DB_MODE', 'GORGE_DB_URL');
if ($db_mode === 'enable') {
  $uri = env_value('GORGE_DB_URL', '');
  if (!strlen($uri)) {
    throw new Exception('GORGE_DB_URL is required in enable mode.');
  }
  $config['gorge.db.uri'] = rtrim($uri, '/');
  $config['gorge.db.token'] = nullable_env('GORGE_DB_TOKEN');
} else if ($db_mode === 'disable') {
  $config['gorge.db.uri'] = null;
  $config['gorge.db.token'] = null;
}

if ($profile === 'collaboration') {
  $config['gorge.diff.enabled'] = false;
  if (!empty($config['gorge.render.uri'])) {
    $config['syntax-highlighter.engine'] =
      'PhabricatorGorgeSyntaxHighlighterEngine';
  }
  $gitea_uri = getenv('GITEA_BASE_URI');
  if ($gitea_uri !== false) {
    if (strlen($gitea_uri)) {
      $config['gitea.uri'] = rtrim($gitea_uri, '/').'/';
    } else {
      unset($config['gitea.uri']);
    }
  }
} else {
  unset(
    $config['gorge.diff.enabled'],
    $config['syntax-highlighter.engine'],
    $config['gitea.uri']);
}

$directory = dirname($deployment_path);
if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
  throw new Exception('Unable to create directory: '.$directory);
}
$temporary = $deployment_path.'.tmp.'.getmypid();
$json = json_encode(
  $config,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
  throw new Exception('Unable to encode deployment configuration.');
}
$json .= "\n";
if (file_put_contents($temporary, $json, LOCK_EX) === false) {
  throw new Exception('Unable to write deployment configuration.');
}

// Publish an inode which is already readable by the runtime user. On the
// first run, local.json has the ownership established by the entrypoint; on
// later runs, preserve the ownership of the deployment file being replaced.
$ownership_source = is_file($deployment_path)
  ? $deployment_path
  : $local_path;
$owner = fileowner($ownership_source);
$group = filegroup($ownership_source);
if ($owner === false || $group === false) {
  @unlink($temporary);
  throw new Exception('Unable to read deployment configuration ownership.');
}
if (fileowner($temporary) !== $owner && !chown($temporary, $owner)) {
  @unlink($temporary);
  throw new Exception('Unable to set deployment configuration owner.');
}
if (filegroup($temporary) !== $group && !chgrp($temporary, $group)) {
  @unlink($temporary);
  throw new Exception('Unable to set deployment configuration group.');
}
if (!chmod($temporary, 0640)) {
  @unlink($temporary);
  throw new Exception('Unable to set deployment configuration mode.');
}
if (!rename($temporary, $deployment_path)) {
  @unlink($temporary);
  throw new Exception('Unable to install deployment configuration.');
}

echo 'Wrote deployment configuration to '.$deployment_path.".\n";
