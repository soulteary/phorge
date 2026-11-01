import importlib.util
from pathlib import Path
import tempfile
import shutil
import unittest

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('consumer_inventory',ROOT/'scripts/permissions/inventory.py')
inventory=importlib.util.module_from_spec(spec);spec.loader.exec_module(inventory)


class ConsumerInventoryTests(unittest.TestCase):
    def test_all_literal_membership_references_have_reviewed_meaning(self):
        report=inventory.collect();self.assertGreater(report['consumerCount'],40)
        self.assertEqual(report['databaseConnectionsOpened'],0)
        self.assertFalse(report['phpBootstrapped']);self.assertFalse(report['runtimeAuthorizationCertified'])
        self.assertTrue(any(r['kind']=='mail-expansion' for r in report['consumers']))
        self.assertTrue(any(r['kind']=='calendar-policy-consumer' for r in report['consumers']))
        self.assertEqual({Path(r['path']).stem for r in report['consumers'] if r['kind']=='subject-preference-writer'},
                         {'PhabricatorProjectWatchController','PhabricatorProjectSilenceController'})
        self.assertTrue(all(r['candidateGenerationIntroduced'] is False for r in report['consumers']))

    def test_new_unreviewed_reference_fails_inventory(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);(root/'scripts/permissions').mkdir(parents=True);(root/'src').mkdir()
            (root/'scripts/permissions/consumers.json').write_text('{"patterns":"needMembers","entries":[]}')
            (root/'src/new.php').write_text('<?php $query->needMembers(true);')
            with self.assertRaisesRegex(ValueError,'references changed'):inventory.collect(root)
