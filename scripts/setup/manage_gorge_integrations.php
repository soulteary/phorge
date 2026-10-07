#!/usr/bin/env php
<?php

require_once dirname(__FILE__).'/../../scripts/__init_script__.php';

$args = new PhutilArgumentParser($argv);
$args->parseStandardArguments();
$args->parse(array(
  array('name' => 'action', 'param' => 'action', 'default' => 'health',
    'help' => pht('check, health, usage, capacity, capabilities, effect, inbound, or resolve.')),
  array('name' => 'id', 'param' => 'identity', 'help' => pht('Identity to inspect.')),
  array('name' => 'resolution-file', 'param' => 'path',
    'help' => pht('JSON evidence and verified result. Never resends a message.')),
));
$client = PhabricatorGorgeIntegrationClient::newConfiguredClient();
$action = $args->getArg('action');
if ($action === 'check') {
  $result = $client->checkConfiguration();
} else if ($action === 'resolve') {
  $path = $args->getArg('resolution-file');
  if (!$path) { throw new Exception(pht('Specify --resolution-file.')); }
  $raw = Filesystem::readFile($path);
  if (strlen($raw) > 1024 * 1024 + 4096) {
    throw new Exception(pht('Resolution file is too large.'));
  }
  $result = $client->resolveIntegration(phutil_json_decode($raw));
} else {
  $id = $args->getArg('id');
  if (in_array($action, array('effect', 'inbound'), true) && !$id) {
    throw new Exception(pht('Specify --id.'));
  }
  $result = $client->inspectIntegration($action, $id);
}
echo phutil_json_encode($result)."\n";
