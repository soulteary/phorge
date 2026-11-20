"""Retirement contracts; this does not certify full SQL or browser acceptance."""
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class WikiRetirement(unittest.TestCase):
    def manifest(self):
        return json.loads((ROOT / 'docs/operations/retirement/wiki.json').read_text())

    def test_exact_application_sources_are_absent(self):
        record = self.manifest()
        self.assertEqual(len(record['removed_classes']), 66)
        self.assertEqual(len(record['removed_files']), 68)
        self.assertFalse((ROOT / 'src/applications/phriction').exists())
        for item in record['removed_files']:
            with self.subTest(path=item['path']):
                self.assertRegex(item['sha256'], r'^[0-9a-f]{64}$')
                self.assertFalse((ROOT / item['path']).exists())
        library = (ROOT / 'src/__phutil_library_map__.php').read_text()
        for name in record['removed_classes']:
            self.assertNotIn("'" + name + "' =>", library)

    def test_shared_runtime_has_no_reference_to_removed_class_constants(self):
        names = '|'.join(map(re.escape, self.manifest()['removed_classes']))
        expression = re.compile(r'\b(?:' + names + r')\s*::|\b(?:new|extends|implements)\s+(?:' + names + r')\b')
        for source in (ROOT / 'src').rglob('*.php'):
            with self.subTest(path=str(source.relative_to(ROOT))):
                self.assertIsNone(expression.search(source.read_text()))

    def test_editor_api_and_asset_registration_are_removed(self):
        for source in (
            'src/applications/remarkup/remarkupcontroller/PhabricatorRemarkupControl.php',
            'src/applications/remarkup/conduit/RemarkupProcessConduitAPIMethod.php',
            'src/infrastructure/markup/PhabricatorMarkupEngine.php',
            'resources/celerity/map.php',
        ):
            text = (ROOT / source).read_text()
            self.assertNotIn('Phriction', text)
            self.assertNotIn('phriction-document', text)

    def test_historical_patch_code_does_not_need_removed_application(self):
        patches = list((ROOT / 'resources/sql/autopatches').glob('*.phriction.*.php'))
        self.assertEqual(len(patches), 7)
        for path in patches:
            text = path.read_text()
            self.assertNotRegex(text, r'new Phriction')
            self.assertIn("$this->getConn('phriction')", text)
            self.assertNotIn('DROP TABLE', text)
            self.assertNotIn('DELETE FROM', text)
        self.assertFalse(self.manifest()['deployment_permitted'])

    def test_old_mail_is_denied_with_optional_policy_on_or_off(self):
        php = shutil.which('php')
        self.assertIsNotNone(php, 'PHP is required for the mail boundary fixture')
        result = subprocess.run(
            [php, str(Path(__file__).with_name('wiki_retirement_mail_fixture.php'))],
            capture_output=True, text=True, timeout=15)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), {'passed': 7, 'network_used': False})
