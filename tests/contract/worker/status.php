<?php

// Unavailable inventory must not become a zero-count retirement gate.
require_once dirname(__DIR__).'/bootstrap.php';
require dirname(__FILE__).'/../../../scripts/setup/lib/gorge_status.php';
function statusCheck($ok, $message) { if (!$ok) { throw new Exception($message); } }
$config = array('gorge.file.uri' => 'https://files.example',
  'gorge.file.token' => 'NEVER_PRINT_TOKEN', 'gorge.file.uploads' => true,
  'metamta.gorge-delivery-mode' => 'native',
  'gorge.integrations' => array('fact' => true, 'token' => 'NEVER_PRINT_SECRET'));
$report = gorge_status_build($config, function($uri, $token, $path) {
  return array('protocolVersion' => 1, 'backendRevision' => 'binary-revision',
    'token' => $token, 'payload' => 'NEVER_PRINT_BODY');
}, function($domain) {
  if ($domain === 'files') { return array('legacyChunks' => 3); }
  throw new Exception('NEVER_PRINT_DSN_OR_EXCEPTION');
});
$encoded = json_encode($report);
statusCheck(strpos($encoded, 'NEVER_PRINT') === false, 'Sensitive value leaked.');
statusCheck($report['inventory']['queue']['state'] === 'unavailable', 'Unavailable became empty queue.');
statusCheck($report['inventory']['files']['data']['legacyChunks'] === 3, 'History lost.');
statusCheck($report['services']['file']['observation']['capabilities']['protocolVersion'] === 1, 'Protocol lost.');
statusCheck(count($report['workerBoundary']['native']) === 4, 'Native handlers incomplete.');
statusCheck(strpos($report['workerBoundary']['factDaemon'], 'Stop PHP') !== false, 'Fact takeover gate stale.');
statusCheck($report['services']['search']['liveGenerationCutover'] === 'not_proven', 'Shadow treated as live.');
statusCheck(gorge_status_identity('https://user:secret@example.com/a?token=secret') ===
  gorge_status_identity('https://example.com/a'), 'Identity included credentials.');
$unavailable = gorge_status_observe('https://example.com', null, '/meta',
  function() { throw new Exception('credential'); });
statusCheck($unavailable['state'] === 'unavailable', 'Probe failure was accepted.');
echo "Takeover report redaction and unknown-state checks passed.\n";

foreach (array(array(0,0,array('state'=>'not_verified'),true,false),
  array(0,0,array('state'=>'verified','failures'=>0,'partial'=>0),false,false),
  array(1,0,array('state'=>'verified','failures'=>0,'partial'=>0),true,false),
  array(null,null,array('state'=>'verified','failures'=>0,'partial'=>0),true,false),
  array(0,0,array('state'=>'verified','failures'=>1,'partial'=>0),true,false),
  array(0,0,array('state'=>'verified','failures'=>0,'partial'=>1),true,false),
  array(0,0,array('state'=>'verified','failures'=>0,'partial'=>0),true,true)) as $input) {
  $gate = gorge_retirement_file_gate($input[0],$input[1],$input[2],$input[3]);
  statusCheck($gate['canRemoveLegacyReaders'] === $input[4], 'Unsafe retirement gate.');
}

$old=array('reportVersion'=>1,'generatedEpoch'=>100,'inventory'=>array('persistentCapacity'=>array('data'=>array(
  'fixture'=>array('state'=>'observed','physicalDatabaseIdentity'=>'one','data'=>array('records'=>2,'approximateDataBytes'=>100))))));
$new=$old;$new['generatedEpoch']=200;$new['inventory']['persistentCapacity']['data']['fixture']['data']['records']=5;
$growth=gorge_status_growth($new,$old);
statusCheck($growth['tables']['php/fixture']['metrics']['records']['delta']===3,'Growth count incorrect.');
$new['inventory']['persistentCapacity']['data']['fixture']['physicalDatabaseIdentity']='other';
statusCheck(gorge_status_growth($new,$old)['tables']['php/fixture']['state']==='not_comparable','Different databases compared.');
statusCheck(gorge_status_growth($old,$old)['state']==='unavailable','Invalid growth interval accepted.');

$safe = gorge_status_operations(array('backend'=>'mysql', 'token'=>'NEVER_PRINT',
  'tables'=>array(array('table'=>'fixture', 'state'=>'observed', 'records'=>3,
    'payload'=>'NEVER_PRINT', 'states'=>array('done'=>2, 'secret'=>'NEVER_PRINT')))));
statusCheck(strpos(json_encode($safe), 'NEVER_PRINT') === false, 'Operations body leaked.');
statusCheck($safe['tables'][0]['records'] === 3, 'Operations count lost.');
$safe = gorge_status_capabilities(array('backendIdentities'=>array('local-disk'=>
  array('state'=>'observed', 'volumeIdentity'=>'fixture', 'credential'=>'NEVER_PRINT'))));
statusCheck(strpos(json_encode($safe), 'NEVER_PRINT') === false, 'Backend body leaked.');

$disabled = function($uri, $token, $path) {
  statusCheck($path === '/api/file/uploads/meta', 'Disabled uploads queried capacity.');
  return array('enabled' => false);
};
statusCheck(gorge_status_upload_usage(null, null, $disabled)['state'] === 'not_configured', 'Missing config classification.');
statusCheck(gorge_status_upload_usage('http://files', null, $disabled)['state'] === 'not_enabled', 'Disabled classification.');
$enabled = function($uri, $token, $path) {
  return $path === '/api/file/uploads/meta' ? array('enabled' => true) : array('logicalBytes' => 4);
};
statusCheck(gorge_status_upload_usage('http://files', null, $enabled)['data']['logicalBytes'] === 4, 'Enabled capacity lost.');
$broken = function() { throw new Exception('NEVER_PRINT_SECRET'); };
statusCheck(gorge_status_upload_usage('http://files', null, $broken)['state'] === 'unavailable', 'Failure classified as disabled.');
statusCheck(gorge_status_upload_usage('http://files', null, function() { return array(); })['state'] === 'unavailable', 'Missing capability accepted.');
echo "Upload audit classification contracts passed.\n";
