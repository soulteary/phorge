import hashlib
import json
import os
from pathlib import Path
import tempfile
import unittest
from run_matrix import load_fault_manifest


class FaultManifestTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        source = self.root / 'support/governance/notification/smtp-fault.go'
        source.parent.mkdir(parents=True); source.write_bytes(b'synthetic fixture source')
        self.binary = self.root / 'owned-fault'; self.binary.write_bytes(b'synthetic executable'); self.binary.chmod(0o700)
        self.config = {'source_root': str(self.root), 'source_revision': 'a' * 40}
        self.value = {'path': str(self.binary), 'sha256': hashlib.sha256(self.binary.read_bytes()).hexdigest(),
                      'source_revision': 'a' * 40, 'source_sha256': hashlib.sha256(source.read_bytes()).hexdigest()}
        self.manifest = self.root / 'manifest.json'

    def tearDown(self):
        self.temp.cleanup()

    def save(self):
        self.manifest.write_text(json.dumps(self.value)); self.manifest.chmod(0o600)

    def test_source_revision_or_bytes_cannot_be_relabelled(self):
        self.save(); self.assertEqual(load_fault_manifest(self.manifest, self.config), self.value)
        self.value['source_revision'] = 'b' * 40; self.save()
        with self.assertRaisesRegex(ValueError, 'fault_source_binding_refused'):
            load_fault_manifest(self.manifest, self.config)
        self.value['source_revision'] = 'a' * 40
        (self.root / 'support/governance/notification/smtp-fault.go').write_bytes(b'changed')
        self.save()
        with self.assertRaisesRegex(ValueError, 'fault_source_binding_refused'):
            load_fault_manifest(self.manifest, self.config)

    def test_binary_replacement_and_symlink_are_refused(self):
        self.save(); self.binary.write_bytes(b'replaced')
        with self.assertRaisesRegex(ValueError, 'fault_source_binding_refused'):
            load_fault_manifest(self.manifest, self.config)
        target = self.root / 'target'; self.binary.rename(target); self.binary.symlink_to(target)
        with self.assertRaisesRegex(ValueError, 'fault_binary_scope_refused'):
            load_fault_manifest(self.manifest, self.config)

    def test_unknown_manifest_field_and_public_mode_are_refused(self):
        self.value['allow_mock'] = True; self.save()
        with self.assertRaisesRegex(ValueError, 'fault_manifest_shape_refused'):
            load_fault_manifest(self.manifest, self.config)
        del self.value['allow_mock']; self.save(); self.manifest.chmod(0o644)
        with self.assertRaisesRegex(ValueError, 'private_file_scope_refused'):
            load_fault_manifest(self.manifest, self.config)
