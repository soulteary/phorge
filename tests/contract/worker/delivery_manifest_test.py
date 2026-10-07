"""Build archive identity must not depend on stripped Git metadata."""
import tempfile
from pathlib import Path
import unittest
from acceptance_manifest import source_identity

class ArchiveIdentity(unittest.TestCase):
    def test_archive_mutation_changes_identity(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            (root/'.source-revision').write_text('a'*40)
            (root/'code.php').write_text('<?php echo 1;')
            first=source_identity(root)
            (root/'code.php').write_text('<?php echo 2;')
            second=source_identity(root)
            self.assertEqual(first['commit'],second['commit'])
            self.assertNotEqual(first['sourceSHA256'],second['sourceSHA256'])
            self.assertIsNone(first['dirty'])
    def test_invalid_archive_revision_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);(root/'.source-revision').write_text('main')
            with self.assertRaises(ValueError): source_identity(root)

if __name__=='__main__': unittest.main()
