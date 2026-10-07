<?php

// Read-only inventory. Never print credentials, mail contents, phone numbers or OAuth tokens.
require_once dirname(__FILE__).'/../../scripts/__init_script__.php';
$config = PhabricatorGorgeIntegrationClient::config();
$report = array('enabled' => array(
  'inbound' => idx($config, 'inbound', array()),
  'smsAdapterKeys' => array_keys(idx($config, 'sms', array())),
  'connectorKeys' => array_keys(idx($config, 'connectors', array())),
  'fact' => idx($config, 'fact', false) === true,
));
$report['configuredMailers'] = array();
foreach (PhabricatorEnv::getEnvConfig('cluster.mailers') as $mailer) {
  $report['configuredMailers'][] = array('key' => idx($mailer, 'key'),
    'type' => idx($mailer, 'type'), 'inbound' => idx($mailer, 'inbound', true));
}
foreach (array('received' => new PhabricatorMetaMTAReceivedMail(),
  'outbound' => new PhabricatorMetaMTAMail()) as $name => $table) {
  $report[$name] = queryfx_all($table->establishConnection('r'),
    'SELECT status,COUNT(*) AS records FROM %T WHERE dateCreated>=%d GROUP BY status',
    $table->getTableName(), time() - 30 * 86400);
}
$table = new DoorkeeperExternalObject();
$report['externalObjects'] = queryfx_all($table->establishConnection('r'),
  'SELECT applicationType,applicationDomain,COUNT(*) AS records FROM %T '.
  'GROUP BY applicationType,applicationDomain', $table->getTableName());
$table = new PhabricatorFactCursor();
$report['factCursors'] = queryfx_all($table->establishConnection('r'),
  'SELECT name,position FROM %T', $table->getTableName());
$report['limits'] = 'Last 30 days mail status counts are not provider call counts; '.
  'external object counts do not measure connector traffic. Native /usage counts new effect/inbox records.';
echo phutil_json_encode($report)."\n";
