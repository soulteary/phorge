<?php
require_once __DIR__.'/startup.php';
require_once __DIR__.'/../../../scripts/setup/lib/gorge_cutover.php';
$config = array(
  'gorge.service-policy' => 'required',
  'gorge.image.mode' => 'gorge', 'gorge.image.uri' => 'http://image:8190',
  'gorge.image.token' => 'fixture', 'phd.gorge-cleanup' => true,
  'metamta.gorge-delivery-mode' => 'native', 'gorge.mailer.exclusive' => true,
  'gorge.taskqueue.owner' => 'gorge', 'gorge.search.token' => 'fixture',
  'cluster.mailers' => array(array('type' => 'gorge', 'options' => array(
    'uri' => 'http://mailer:8110', 'token' => 'fixture'))),
  'cluster.search' => array(array('type' => 'gorge', 'hosts' => array(array(
    'host' => 'search', 'port' => 8120, 'protocol' => 'http',
    'roles' => array('read' => true, 'write' => true))))),
);
$probes = gorge_cutover_probes($config, 'http://worker:8170', 'fixture',
  'http://maintenance:8200', 'fixture');
$shapes = array_column($probes, 4);
foreach (array('mail-delivery', 'image', 'live-index', 'cleanup-owners') as $shape) {
  startup_assert(in_array($shape, $shapes, true), 'Missing cutover probe.');
}
foreach (array('gorge.service-policy' => 'fallback', 'gorge.image.mode' => 'shadow',
  'metamta.gorge-delivery-mode' => 'legacy', 'phd.gorge-cleanup' => false,
  'gorge.search.token' => '', 'gorge.mailer.exclusive' => false) as $key => $value) {
  $bad = $config; $bad[$key] = $value; $rejected = false;
  try { gorge_cutover_probes($bad, 'http://worker', 't', 'http://cleanup', 't'); }
  catch (Exception $ex) { $rejected = true; }
  startup_assert($rejected, 'Unsafe cutover configuration accepted.');
}
startup_assert(gorge_startup_valid('exists', array('data' => array('exists' => false))),
  'Initial bootstrap should allow an unbuilt index.');
startup_assert(!gorge_startup_valid('live-index', array('data' => array('exists' => false))),
  'Production gate accepted an unbuilt index.');
startup_assert(gorge_startup_valid('mail-delivery', array('data' => array(
  'schemaVersion' => 1, 'recovery' => true))), 'Mail capability rejected.');
startup_assert(!gorge_startup_valid('mail-delivery', array('data' => array(
  'schemaVersion' => 1, 'recovery' => false))), 'Missing recovery accepted.');
$image = array('protocolVersion' => 1, 'recipeRevision' => 'phorge-v1',
  'backendRevision' => 'fixture', 'recipes' => array_fill_keys(array('profile',
    'pinboard', 'thumbgrid', 'preview', 'workcard'), array('width' => 1)),
  'inputFormats' => array('image/jpeg', 'image/png', 'image/gif', 'image/webp'),
  'animationPolicies' => array('legacy-static', 'legacy-preserve'));
startup_assert(gorge_startup_valid('image', array('data' => $image)), 'Image rejected.');
unset($image['recipes']['workcard']);
startup_assert(!gorge_startup_valid('image', array('data' => $image)), 'Recipe missing accepted.');
$states = array_map(function($id) {
  return array('id' => $id, 'owner' => 'gorge', 'policyHash' => 'fixture');
}, array('cache.general.ttl', 'cache.general', 'cache.markup', 'conduit.logs',
  'daemon.processes', 'daemon.lock-log'));
startup_assert(gorge_startup_valid('cleanup-owners', array('data' => $states)),
  'Gorge owners rejected.');
$states[0]['owner'] = 'php';
startup_assert(!gorge_startup_valid('cleanup-owners', array('data' => $states)),
  'PHP ownership accepted.');
$states[0]['owner'] = 'gorge'; array_pop($states);
startup_assert(!gorge_startup_valid('cleanup-owners', array('data' => $states)),
  'Missing collector accepted.');
$directory = sys_get_temp_dir().'/gorge-cutover-'.bin2hex(random_bytes(6));
mkdir($directory, 0700);
try {
  file_put_contents($directory.'/local.json', '{}');
  file_put_contents($directory.'/deployment.json', '{}');
  startup_build($directory, array('GORGE_IMAGE_MODE' => 'gorge'), false);
  startup_build($directory, array('GORGE_MAIL_DELIVERY_MODE' => 'native'), false);
  $env = array('GORGE_MAILER_URI' => 'http://mailer', 'GORGE_MAILER_TOKEN' => 'fixture',
    'GORGE_MAIL_DELIVERY_MODE' => 'native', 'GORGE_TASKQUEUE_URI' => 'http://queue',
    'GORGE_IMAGE_URI' => 'http://image', 'GORGE_IMAGE_TOKEN' => 'fixture',
    'GORGE_IMAGE_MODE' => 'gorge', 'GORGE_CLEANUP_GUARD' => 'true');
  $built = startup_build($directory, $env);
  startup_assert($built['phd.gorge-cleanup'] === true &&
    $built['metamta.gorge-delivery-mode'] === 'native', 'Modes not published.');
  // A later profile writer must preserve native mode when it does not set it.
  unset($env['GORGE_MAIL_DELIVERY_MODE']);
  $built = startup_build($directory, $env);
  startup_assert($built['metamta.gorge-delivery-mode'] === 'native', 'Native mode lost.');
} finally {
  foreach (glob($directory.'/*') as $file) { unlink($file); }
  rmdir($directory);
}
echo "Cutover contracts passed: mode guards, capabilities, live index and six owners.\n";
