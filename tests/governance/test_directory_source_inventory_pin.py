"""Reviewed test-only manifest refresh; no SQL, service or weakened inventory."""
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
PATH = 'tests/governance/test_calendar_conduit_union.py'
BASE = '61013762969ce603c89b42557e15cda4b3eb22ff'
spec = importlib.util.spec_from_file_location('directory_source_gate', ROOT / 'scripts/governance/check_directory_transports_v1.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class DirectorySourceInventoryPin(unittest.TestCase):
    def test_current_source_and_only_reviewed_manifest_entry_changed(self):
        import sys
        sys.path.insert(0,str(ROOT/'scripts/governance'))
        import project_description_admission_delta as n05_delta
        raw=(ROOT/gate.MANIFEST).read_bytes();actual=json.loads(raw)
        dirty=subprocess.run(['git','-C',str(ROOT),'diff','--quiet','HEAD','--','src','resources']).returncode!=0
        if dirty:
            with self.assertRaises((ValueError,subprocess.CalledProcessError)):gate.source(ROOT)
        else:self.assertEqual(gate.source(ROOT),actual)
        for name,pin in actual['pins'].items():
            source=(ROOT/name).read_bytes();self.assertEqual(pin,{'bytes':len(source),'sha256':hashlib.sha256(source).hexdigest()})
        self.assertEqual(set(actual['pins']),gate.SOURCE_FILES)
        actual=json.loads(n05_delta.reduce_if_selected(gate.MANIFEST,raw))
        fixed=json.loads(subprocess.check_output(['git','-C',str(ROOT),'show','4c2245300dc1d78d042b95190dd537eb6b1f9861:'+gate.MANIFEST]))
        self.assertEqual(actual,fixed)
        import sys
        sys.path.insert(0,str(ROOT/'scripts/governance'))
        import recovery_oauth_code_coordinator_delta as coord
        from n05_r3_historical_test_view import historical_coordinator_source
        original = json.loads(historical_coordinator_source(gate.MANIFEST,(ROOT/gate.MANIFEST).read_bytes()))
        previous = json.loads(subprocess.check_output(['git', '-C', str(ROOT), 'show', BASE + ':' + gate.MANIFEST]))
        self.assertEqual(original['pins'][PATH], {'bytes': 10627, 'sha256': '1de4e9eacef2f6844c7a91972218cc532be71d6368d75c371788b864e591a591'})
        self.assertEqual(previous['pins'][PATH], {'bytes': 9302, 'sha256': '1b7a7eccda85748728b0cef14edf88b823ae965e55ad037b5ebff0a9ef7577fb'})
        previous['pins'][PATH] = original['pins'][PATH]
        self.assertEqual(original, previous)

    def test_calendar_mutation_and_unknown_source_still_refused_before_git(self):
        for mutation in ('calendar-bytes', 'unknown-source'):
            with self.subTest(mutation=mutation), tempfile.TemporaryDirectory() as temp:
                root = Path(temp).resolve()
                for name in gate.SOURCE_FILES | {gate.MANIFEST}:
                    target = root / name
                    target.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copyfile(ROOT / name, target)
                if mutation == 'calendar-bytes':
                    with (root / PATH).open('ab') as output:
                        output.write(b'\n# unreviewed change\n')
                else:
                    manifest = json.loads((root / gate.MANIFEST).read_text())
                    manifest['pins']['tests/governance/unreviewed.py'] = {'bytes': 1, 'sha256': hashlib.sha256(b'x').hexdigest()}
                    (root / gate.MANIFEST).write_text(json.dumps(manifest))
                with patch.object(gate.subprocess, 'check_output', side_effect=AssertionError('Git must not run before pin refusal')):
                    with self.assertRaises(ValueError):
                        gate.source(root)
                self.assertEqual(gate.failed_check(), 'source-inventory')
