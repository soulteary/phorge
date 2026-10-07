<?php

final class PhorgeI18nTestCase extends PhabricatorTestCase {

  public function testi18nValidation() {
    // A cold extraction parses every active library, rather than one unit.
    set_time_limit(120);
    $validator = new PhorgeInternationalizationValidator();
    $errors = $validator->validateLibraries(
      $validator->loadExtractions(true, true));
    $this->assertEqual(array(), $errors, pht('i18n validation errors found!'));
  }

  public function testHistoricalTranslations() {
    $validator = new PhorgeInternationalizationValidator();
    $translations = array(
      'en_US' => array('Retired feature %s' => 'Historical translation %s'),
    );
    $original = $translations;
    $this->assertEqual(
      array(),
      $validator->validateTranslations(array(), $translations, array('en_US')));
    $this->assertEqual($original, $translations);

    $errors = $validator->validateTranslations(
      array(),
      $translations,
      array('en_US'),
      true);
    $this->assertEqual(1, count($errors));
    $this->assertTrue(strpos($errors[0], 'Retired feature %s') !== false);

    // A retained translation is checked as soon as its source returns.
    $translations['en_US']['Retired feature %s'] = '%s %s';
    $errors = $validator->validateTranslations(
      array('Retired feature %s' => array('types' => array(null))),
      $translations,
      array('en_US'));
    $this->assertEqual(1, count($errors));
  }

  public function testActiveTranslationFormats() {
    $validator = new PhorgeInternationalizationValidator();
    $cases = array(
      array('Current %s', array(null), '%s %s'),
      array('Current %s', array(null), '%Q'),
      array('Current %s', array(null), array('%s one', '%s many')),
      array('Current %s', array(null), array(array('%s'))),
      array('Current %s', array('phutilnumber'), '%d'),
      // The source message is validated too, even without a translation.
      array('Current %s %s', array(null), null),
      array('Current %s item(s)', array('number'), null),
    );
    foreach ($cases as $case) {
      list($source, $types, $translation) = $case;
      $translations = $translation === null
        ? array()
        : array('en_US' => array($source => $translation));
      $errors = $validator->validateTranslations(
        array($source => array('types' => $types)),
        $translations,
        array('en_US'));
      $this->assertTrue((bool)$errors, 'Invalid current translation: '.$source);
    }

    $this->assertEqual(
      array(),
      $validator->validateTranslations(
        array('Current %s %s' => array('types' => array(null, null))),
        array('en_US' => array('Current %s %s' => '%2$s %% %1$s')),
        array('en_US')));
    $this->assertEqual(
      array(),
      $validator->validateTranslations(
        array('Current %s item(s)' => array('types' => array('phutilnumber'))),
        array('en_US' => array(
          'Current %s item(s)' => array('Current %s item', 'Current %s items'),
        )),
        array('en_US')));

    $errors = $validator->validateTranslations(
      array(),
      array('unknown_TEST' => array('Historical string' => 'Old translation')),
      array('en_US'));
    $this->assertEqual(1, count($errors));
    $this->assertTrue(strpos($errors[0], 'unknown_TEST') !== false);
  }

  public function testExtractionRemovesDeletedSources() {
    $directory = Filesystem::createTemporaryDirectory();
    $root = $directory.'/fixture/src/';
    Filesystem::createDirectory($root.'messages', 0755, true);
    Filesystem::writeFile($root.'__phutil_library_init__.php', '<?php');
    Filesystem::writeFile(
      $root.'messages/current.php',
      '<?php pht("Current extraction %s", "value");');
    Filesystem::writeFile(
      $root.'messages/retired.php',
      '<?php pht("Retired extraction %s", count(array()));');
    $script = $directory.'/fixture/scripts/tasks/command.php';
    Filesystem::createDirectory(dirname($script), 0755, true);
    Filesystem::writeFile($script, '<?php pht("Script extraction");');

    $extractor = new PhabricatorInternationalizationManagementExtractWorkflow();
    $cache = $extractor->getCachePath($root, 'i18n_strings.json');
    try {
      $strings = $extractor->extractLibraryStrings($root);
      $this->assertEqual(
        array(
          'Current extraction %s',
          'Retired extraction %s',
          'Script extraction',
        ),
        array_keys($strings));
      $this->assertEqual(
        '../scripts/tasks/command.php',
        $strings['Script extraction']['uses'][0]['file']);
      $this->assertFalse(Filesystem::pathExists($cache));
      Filesystem::remove($script);
      $this->extractFixture($directory);
      $strings = phutil_json_decode(Filesystem::readFile($cache));
      $this->assertEqual(
        array('Current extraction %s', 'Retired extraction %s'),
        array_keys($strings));
      $this->assertEqual(array(null), $strings['Current extraction %s']['types']);
      $this->assertEqual(
        array('number'),
        $strings['Retired extraction %s']['types']);

      // No remaining source changes: deletion alone must refresh the cache.
      Filesystem::remove($root.'messages/retired.php');
      $this->extractFixture($directory);
      $strings = phutil_json_decode(Filesystem::readFile($cache));
      $this->assertEqual(array('Current extraction %s'), array_keys($strings));

      Filesystem::remove($root.'messages/current.php');
      $this->extractFixture($directory);
      $this->assertEqual(
        array(),
        phutil_json_decode(Filesystem::readFile($cache)));

      $runtime_root = phutil_get_library_root('arcanist');
      $runtime_cache = $extractor->getCachePath(
        $runtime_root,
        'i18n_strings.json');
      // Check the destination before it exists: this test can run before the
      // full-library extraction in the randomized unit-test order.
      $cache_tree = new FileList(array(
        phutil_get_library_root('phorge').'/.cache/',
      ));
      $runtime_tree = new FileList(array($runtime_root));
      $this->assertTrue($cache_tree->contains($runtime_cache));
      $this->assertFalse($runtime_tree->contains($runtime_cache));
    } finally {
      Filesystem::remove($directory);
      Filesystem::remove(dirname($cache));
    }
  }

  private function extractFixture($directory) {
    $extractor = new PhabricatorInternationalizationManagementExtractWorkflow();
    $args = new PhutilArgumentParser(array('i18n', $directory));
    $args->parseFull($extractor->getArguments());
    $extractor->setArgv($args);
    ob_start();
    try {
      $this->assertEqual(0, $extractor->execute($args));
    } finally {
      ob_end_clean();
    }
  }

}
