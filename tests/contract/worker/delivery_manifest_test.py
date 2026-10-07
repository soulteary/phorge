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


class IsolatedTopology(unittest.TestCase):
    def test_tmpfs_size_is_an_option_instead_of_an_extra_mount(self):
        from acceptance_helpers import validate_isolated_topology
        validate_isolated_topology({'services': {
            'image': {'volumes': [{'type': 'tmpfs', 'target': '/tmp',
                                  'tmpfs': {'size': 536870912, 'mode': 0o1777}}]}}})
        with self.assertRaisesRegex(ValueError, 'absolute container path'):
            validate_isolated_topology({'services': {
                'image': {'tmpfs': ['/tmp:rw', 'size=512m']}}})

    def test_private_tmpfs_cannot_block_the_unprivileged_image_service(self):
        from acceptance_helpers import validate_isolated_topology
        with self.assertRaisesRegex(ValueError, 'writable by the image service user'):
            validate_isolated_topology({'services': {
                'image': {'volumes': [{'type': 'tmpfs', 'target': '/tmp',
                                      'tmpfs': {'size': 536870912}}]}}})

    def test_isolated_topology_rejects_ports_and_existing_container_names(self):
        from acceptance_helpers import validate_isolated_topology
        for service in [{'ports': ['8080:80']}, {'container_name': 'application'}]:
            with self.assertRaisesRegex(ValueError, 'publish ports or reuse'):
                validate_isolated_topology({'services': {'web': service}})



class RuntimeIdentity(unittest.TestCase):
    def make_runtime(self, root):
        import hashlib
        import json
        (root / 'src').mkdir()
        content = b'<?php echo 1;'
        (root / 'src/library.php').write_bytes(content)
        file_digest = hashlib.sha256(content).hexdigest()
        package_digest = hashlib.sha256(b'src/library.php\0' + file_digest.encode() + b'\n').hexdigest()
        manifest = {'schemaVersion': 1, 'runtimeVersion': 'fixture-1',
                    'upstreamRevision': None, 'upstreamSnapshotSHA256': 'b' * 64,
                    'contentSHA256': package_digest, 'patches': [{'id': 'local-ca-fix'}],
                    'files': [{'path': 'src/library.php', 'sha256': file_digest}]}
        path = root / 'manifest.json'
        path.write_text(json.dumps(manifest))
        return path

    def test_runtime_snapshot_is_not_reported_as_upstream_commit(self):
        from acceptance_manifest import runtime_identity
        with tempfile.TemporaryDirectory() as directory:
            manifest = self.make_runtime(Path(directory))
            identity = runtime_identity(manifest)
            self.assertIsNone(identity['upstreamRevision'])
            self.assertNotIn('commit', identity)
            self.assertEqual(identity['runtimeVersion'], 'fixture-1')
            self.assertEqual(identity['upstreamSnapshotSHA256'], 'b' * 64)
            self.assertEqual(len(identity['sourceSHA256']), 64)
            self.assertEqual(len(identity['manifestSHA256']), 64)
            self.assertEqual(identity['patches'], [{'id': 'local-ca-fix'}])

    def test_runtime_mutation_is_rejected_until_manifest_is_updated(self):
        from acceptance_manifest import runtime_identity
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            manifest = self.make_runtime(root)
            (root / 'src/library.php').write_text('<?php echo 2;')
            with self.assertRaisesRegex(ValueError, 'differs from manifest'):
                runtime_identity(manifest)

    def test_runtime_path_must_stay_inside_package(self):
        import json
        from acceptance_manifest import runtime_identity
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            manifest = self.make_runtime(root)
            value = json.loads(manifest.read_text())
            value['files'][0]['path'] = '../outside.php'
            manifest.write_text(json.dumps(value))
            with self.assertRaisesRegex(ValueError, 'Invalid runtime manifest path'):
                runtime_identity(manifest)

    def test_runtime_digest_and_duplicate_records_are_checked(self):
        import json
        from acceptance_manifest import runtime_identity
        with tempfile.TemporaryDirectory() as directory:
            manifest = self.make_runtime(Path(directory))
            value = json.loads(manifest.read_text())
            value['contentSHA256'] = 'a' * 64
            manifest.write_text(json.dumps(value))
            with self.assertRaisesRegex(ValueError, 'content digest'):
                runtime_identity(manifest)
            value['files'].append(value['files'][0])
            manifest.write_text(json.dumps(value))
            with self.assertRaisesRegex(ValueError, 'Invalid runtime manifest path'):
                runtime_identity(manifest)

    def test_runtime_unlisted_file_is_rejected(self):
        from acceptance_manifest import runtime_identity
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            manifest = self.make_runtime(root)
            (root / 'src/unreviewed.php').write_text('<?php echo 3;')
            with self.assertRaisesRegex(ValueError, 'not in manifest'):
                runtime_identity(manifest)

    def test_runtime_rejects_managed_and_generated_symlinks(self):
        import json
        from acceptance_manifest import runtime_identity
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            manifest = self.make_runtime(root)
            managed = root / 'src/library.php'
            duplicate = root / 'duplicate.php'
            managed.rename(duplicate)
            managed.symlink_to(duplicate)
            with self.assertRaisesRegex(ValueError, 'Invalid runtime manifest path'):
                runtime_identity(manifest)
            managed.unlink()
            duplicate.rename(managed)
            value = json.loads(manifest.read_text())
            value['generatedPaths'] = ['generated/**']
            manifest.write_text(json.dumps(value))
            (root / 'generated').symlink_to(root / 'src', target_is_directory=True)
            with self.assertRaisesRegex(ValueError, 'may not be symlinks'):
                runtime_identity(manifest)

    def test_explicit_generated_paths_do_not_hide_other_source(self):
        import json
        from acceptance_manifest import runtime_identity
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            manifest = self.make_runtime(root)
            value = json.loads(manifest.read_text())
            value['generatedPaths'] = ['src/.phutil_module_cache']
            manifest.write_text(json.dumps(value))
            (root / 'src/.phutil_module_cache').write_text('generated')
            runtime_identity(manifest)
            (root / 'src/new.php').write_text('<?php echo 4;')
            with self.assertRaisesRegex(ValueError, 'not in manifest'):
                runtime_identity(manifest)



class ExecutionSnapshot(unittest.TestCase):
    def make_source_and_image(self, work):
        import json
        import shutil
        source = work / 'source'
        runtime = source / 'support/runtime'
        runtime.mkdir(parents=True)
        manifest = RuntimeIdentity().make_runtime(runtime)
        value = json.loads(manifest.read_text())
        value['generatedPaths'] = ['src/parser/xhpast/bin/xhpast', 'support/php-parser/lib/**']
        manifest.write_text(json.dumps(value))
        (source / '.source-revision').write_text('a' * 40)
        image = work / 'image'
        shutil.copytree(source, image)
        binary = image / 'support/runtime/src/parser/xhpast/bin/xhpast'
        binary.parent.mkdir(parents=True)
        binary.write_bytes(b'compiled-in-image')
        parser = image / 'support/runtime/support/php-parser/lib'
        parser.mkdir(parents=True)
        (parser / 'autoload.php').write_text('<?php // prepared parser')
        return source, image

    def test_snapshot_uses_prepared_image_artifacts_without_changing_source(self):
        from acceptance_helpers import prepare_phorge_snapshot
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory)
            source, image = self.make_source_and_image(work)
            target = work / 'snapshot'
            identity = prepare_phorge_snapshot(source, image, target)
            self.assertEqual(identity['commit'], 'a' * 40)
            self.assertIsNone(identity['dirty'])
            self.assertEqual((target / 'support/runtime/src/parser/xhpast/bin/xhpast').read_bytes(),
                             b'compiled-in-image')
            self.assertTrue((target / 'support/runtime/support/php-parser/lib/autoload.php').is_file())
            self.assertFalse((source / 'support/runtime/src/parser/xhpast/bin/xhpast').exists())

    def test_snapshot_rejects_an_image_from_a_different_runtime(self):
        import json
        from acceptance_helpers import prepare_phorge_snapshot
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory)
            source, image = self.make_source_and_image(work)
            manifest = image / 'support/runtime/manifest.json'
            value = json.loads(manifest.read_text())
            value['runtimeVersion'] = 'different-image'
            manifest.write_text(json.dumps(value))
            with self.assertRaisesRegex(ValueError, 'does not match'):
                prepare_phorge_snapshot(source, image, work / 'snapshot')

    def test_snapshot_excludes_deployment_configuration_and_hooks(self):
        from acceptance_helpers import prepare_phorge_snapshot
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory)
            source, image = self.make_source_and_image(work)
            traps = ['conf/local/ENVIRONMENT', 'conf/local/deployment.json',
                     'conf/custom/production.conf.php', 'conf/keys/device.key',
                     'support/preamble.php', 'src/extensions/production.php']
            for name in traps:
                path = source / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('production-config-trap')
            default = source / 'conf/default.conf.php'
            default.write_text('<?php // ordinary project defaults')
            target = work / 'snapshot'
            prepare_phorge_snapshot(source, image, target)
            for name in traps:
                self.assertTrue((source / name).is_file())
                self.assertFalse((target / name).exists(), name)
            self.assertTrue((target / 'conf/default.conf.php').is_file())
            self.assertTrue((target / 'conf/local').is_dir())

if __name__ == '__main__':
    unittest.main()
