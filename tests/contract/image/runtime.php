<?php

$arcanist = getenv('GORGE_TEST_ARCANIST_DIR');
require $arcanist.'/src/init/init-library.php';
phutil_load_library($arcanist.'/src');
phutil_load_library(dirname(__FILE__).'/../../../src');
PhabricatorEnv::initializeScriptEnvironment(true, true);
$env = PhabricatorEnv::beginScopedEnv();
$env->overrideEnvConfig('gorge.image.uri', getenv('GORGE_TEST_IMAGE_URL'));
$env->overrideEnvConfig('gorge.image.token', getenv('GORGE_TEST_IMAGE_TOKEN'));
$env->overrideEnvConfig('gorge.image.mode', 'gorge');
function image_check($condition, $message) {
  if (!$condition) { throw new Exception($message); }
}
try {
  $client = new PhabricatorGorgeImageClient();
  $caps = $client->getCapabilities();
  image_check($caps['protocolVersion'] === 1 && $caps['recipeRevision'] === 'phorge-v1',
    'Image capability mismatch.');
  $source = file_get_contents(dirname(__FILE__).'/../../../resources/builtin/image-100x100.png');
  $info = $client->probe($source);
  image_check($info['width'] === 100 && $info['height'] === 100, 'Probe dimensions lost.');
  foreach (array('profile', 'pinboard', 'thumbgrid', 'preview', 'workcard') as $recipe) {
    $result = $client->transform($source, $recipe, false);
    image_check(getimagesizefromstring($result) !== false, 'Invalid binary image.');
  }
  $engine = new PhabricatorTestStorageEngine();
  $handle = $engine->writeFile($source, array());
  $file = id(new PhabricatorFile())->makeEphemeral()->setID(991)
    ->setName('probe-contract.png')->setMimeType('image/png')
    ->setByteSize(strlen($source))->setStorageEngine('unit-test')
    ->setStorageHandle($handle)->setStorageFormat('raw');
  image_check($file->isTransformableImage(), 'Gorge capability still requires GD.');
  $file->updateDimensions(false);
  image_check($file->getImageWidth() === 100 && $file->getImageHeight() === 100,
    'File metadata probe failed.');
  // Probe and transform use the actual service token, not a URL token.
  $env->overrideEnvConfig('gorge.image.token', 'invalid-token');
  $denied = false;
  try { id(new PhabricatorGorgeImageClient())->probe($source); }
  catch (Exception $ex) { $denied = true; }
  image_check($denied, 'Image service accepted an invalid token.');
  $env->overrideEnvConfig('gorge.image.token', getenv('GORGE_TEST_IMAGE_TOKEN'));
  $env->overrideEnvConfig('gorge.image.uri', 'http://127.0.0.1:1');
  $transient = false;
  try { id(new PhabricatorGorgeImageClient())->transform($source, 'preview', false); }
  catch (PhabricatorGorgeImageTransientException $ex) { $transient = true; }
  image_check($transient, 'Transport failure became a cacheable result.');
  echo "Image PHP contracts passed: capabilities, probe, five recipes, auth, transient failure.\n";
} catch (Throwable $ex) {
  fwrite(STDERR, $ex->getMessage()."\n"); exit(1);
}
