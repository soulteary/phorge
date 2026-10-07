#!/usr/bin/env python3
"""Exercise the real PHP catalog bridge with isolated translation fixtures."""

import json
import pathlib
import shutil
import subprocess
import tempfile
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
HELPER = ROOT / 'scripts/i18n/zh_catalog.php'


class ChineseCatalogValidationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.php = shutil.which('php')
        if cls.php is None:
            raise unittest.SkipTest('PHP is not installed')
        result = cls.validate_fixture(
            "'Fixture' => '测试'", {'Fixture': {'types': []}})
        if result.returncode and 'unbuilt or out of date' in result.stderr:
            raise unittest.SkipTest('The bundled PHP parser is not built')
        if result.returncode:
            raise AssertionError('PHP validation bridge failed: ' + result.stderr)
        json.loads(result.stdout)

    @classmethod
    def validate_fixture(cls, declaration, sources):
        with tempfile.TemporaryDirectory(prefix='phorge-zh-catalog-') as directory:
            dictionary = pathlib.Path(directory) / 'translation.php'
            dictionary.write_text(
                '<?php\nfinal class TranslationFixture {\n'
                '  protected function getTranslations() {\n'
                '    return array(' + declaration + ');\n'
                '  }\n}\n', encoding='utf-8')
            return subprocess.run(
                [cls.php, str(HELPER), 'validate', str(dictionary)],
                input=json.dumps(sources), text=True, capture_output=True,
                check=False)

    def assert_validation(self, declaration, source, types, expected_error):
        result = self.validate_fixture(
            declaration, {source: {'types': types}})
        # PHP warnings must become validation errors, never contaminate JSON.
        self.assertEqual('', result.stderr)
        payload = json.loads(result.stdout)
        self.assertEqual({'errors'}, set(payload))
        if expected_error is None:
            self.assertEqual(0, result.returncode)
            self.assertEqual([], payload['errors'])
        else:
            self.assertEqual(1, result.returncode)
            self.assertTrue(payload['errors'])
            self.assertTrue(any(expected_error in error for error in payload['errors']))

    def test_phutil_number_rejects_integer_placeholder(self):
        self.assert_validation(
            "'Current %s' => '%d'", 'Current %s', ['phutilnumber'],
            'uses %d to represent a PhutilNumber')

    def test_plural_array_preserves_phutil_number(self):
        self.assert_validation(
            "'Current %s item(s)' => array('%s 项', '%s 项')",
            'Current %s item(s)', ['phutilnumber'], None)

    def test_invalid_placeholder_is_rejected(self):
        self.assert_validation(
            "'Current %s' => '%Q'", 'Current %s', [None],
            'failed to interpolate properly')

    def test_plural_array_requires_number_or_person(self):
        self.assert_validation(
            "'Current %s' => array('%s 一项', '%s 多项')",
            'Current %s', [None], 'not a number or person')


if __name__ == '__main__':
    unittest.main()
