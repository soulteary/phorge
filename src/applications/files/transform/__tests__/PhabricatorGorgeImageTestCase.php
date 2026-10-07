<?php

final class PhabricatorGorgeImageTestCase extends PhabricatorTestCase {
  public function testTransientFallbackDoesNotPersistRelation() {
    // This must succeed without database fixtures: persisting the default
    // relationship here would attempt SQL and turn the failure into a cache hit.
    $source = id(new PhabricatorFile())->makeEphemeral()
      ->setPHID('PHID-FILE-transient-source');
    $result = id(new PhabricatorTransientImageTestTransform())
      ->executeTransformExplicit($source);
    $this->assertEqual('PHID-FILE-transient-fallback', $result->getPHID());
  }

  public function testOversizedSourceRejectedBeforeStorageRead() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.image.mode', 'gorge');
    $env->overrideEnvConfig('gorge.image.uri', 'http://image:8190');
    $env->overrideEnvConfig('gorge.image.token', 'test-only');
    $file = id(new PhabricatorFile())->makeEphemeral()
      ->setByteSize(16 * 1024 * 1024 + 1)
      ->setStorageEngine('must-not-be-loaded');
    $caught = false;
    try {
      $recipes = id(new PhabricatorFileThumbnailTransform())->generateTransforms();
      $recipes[0]->applyTransform($file);
    } catch (Exception $ex) {
      $caught = strpos($ex->getMessage(), 'too large to transform') !== false;
    }
    $this->assertTrue($caught);
    $file->setMimeType('image/png');
    $caught = false;
    try {
      $file->updateDimensions(false);
    } catch (Exception $ex) {
      $caught = strpos($ex->getMessage(), '16 MiB') !== false;
    }
    $this->assertTrue($caught);
  }

  public function testResponseContractRejectsWrongGeometryAndRevision() {
    $root = dirname(phutil_get_library_root('phabricator'));
    $bytes = Filesystem::readFile($root.'/resources/builtin/image-100x100.png');
    $headers = array(
      array('Content-Type', 'image/png'),
      array('Content-Length', (string)strlen($bytes)),
      array('ETag', '"'.hash('sha256', $bytes).'"'),
      array('X-Gorge-Recipe-Revision', 'phorge-v1'),
      array('X-Gorge-Backend-Revision', 'test-backend'),
      array('X-Gorge-Image-Width', '100'),
      array('X-Gorge-Image-Height', '100'));
    $this->assertEqual($bytes,
      PhabricatorGorgeImageClient::validateTransformResponse(
        $bytes, $bytes, 'thumbgrid', $headers));
    foreach (array('geometry', 'revision', 'digest') as $failure) {
      $bad = $headers;
      $recipe = 'thumbgrid';
      if ($failure === 'geometry') { $recipe = 'preview'; }
      if ($failure === 'revision') { $bad[3][1] = 'unknown'; }
      if ($failure === 'digest') { $bad[2][1] = '"invalid"'; }
      $caught = false;
      try {
        PhabricatorGorgeImageClient::validateTransformResponse(
          $bytes, $bytes, $recipe, $bad);
      } catch (PhabricatorGorgeImageTransientException $ex) {
        $caught = true;
      }
      $this->assertTrue($caught);
    }
  }

  public function testGorgeRequiresCredentials() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.image.uri', 'http://image:8190');
    $env->overrideEnvConfig('gorge.image.token', null);
    $caught = false;
    try { new PhabricatorGorgeImageClient(); }
    catch (PhabricatorGorgeImageTransientException $ex) { $caught = true; }
    $this->assertTrue($caught);
  }
  public function testRealGorgeMemeAndComposeClients() {
    $url = getenv('GORGE_TEST_IMAGE_URL');
    if (!$url) { $this->assertSkipped('Set GORGE_TEST_IMAGE_URL for real image service.'); }
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.image.uri', $url);
    $env->overrideEnvConfig('gorge.image.token', getenv('GORGE_TEST_IMAGE_TOKEN'));
    $client = new PhabricatorGorgeImageClient();
    $root = dirname(phutil_get_library_root('phabricator'));
    $source = Filesystem::readFile($root.'/resources/builtin/image-100x100.png');
    $caps = $client->getCapabilities();
    $this->assertEqual('meme-v1', $caps['meme']['revision']);
    $this->assertEqual('compose-v1', $caps['compose']['revision']);
    $output = $client->meme($source, '@literal', '%text', false);
    $this->assertEqual(100, getimagesizefromstring($output)[0]);
    $output = $client->compose(array('recipe' => 'avatar',
      'background' => '#ff0000', 'border' => array(0, 0, 0, 0.5),
      'layers' => array(base64_encode($source))));
    $this->assertEqual(400, getimagesizefromstring($output)[0]);
    $this->assertEqual('image/png', getimagesizefromstring($output)['mime']);
  }

}
