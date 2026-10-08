"""A failed build must emit failure evidence and clean only its isolated project."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import shutil
import unittest

ROOT=Path(__file__).resolve().parents[3]
class EntryFailure(unittest.TestCase):
    def test_build_failure_records_manifest_and_cleans(self):
        self.assert_failure_records_manifest_and_cleans('base-image-build')

    def test_platform_failure_records_manifest_before_build_and_cleans(self):
        self.assert_failure_records_manifest_and_cleans('image-platform-check')

    def assert_failure_records_manifest_and_cleans(self, failure_stage):
        with tempfile.TemporaryDirectory() as d:
            work=Path(d); docker=work/'docker'; trace=work/'trace'
            docker.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$TEST_TRACE"\ncase "$*" in *" build base") exit 9;; esac\nexit 0\n')
            docker.chmod(0o755)
            env=dict(os.environ,PATH=str(work)+os.pathsep+os.environ['PATH'],TEST_TRACE=str(trace),PYTHONDONTWRITEBYTECODE='1')
            # The paired checkout is a sibling locally but .test-gorge in CI.
            # An explicit archive fixture makes the failure gate independent of both.
            gorge=work/'gorge-source';(gorge/'go').mkdir(parents=True)
            (gorge/'go/go.mod').write_text('module acceptance-fixture\n')
            (gorge/'.source-revision').write_text('a'*40)
            platform_check=gorge/'deploy/release/base_images.py'
            platform_check.parent.mkdir(parents=True)
            platform_check.write_text('import os, sys\nfrom pathlib import Path\n'
                                      'with Path(os.environ["TEST_TRACE"]).open("a") as trace:\n'
                                      '    trace.write("image-platform-check\\n")\n'
                                      'sys.exit(int(os.environ.get("TEST_PLATFORM_FAIL", "0")))\n')
            env['TEST_PLATFORM_FAIL']='7' if failure_stage == 'image-platform-check' else '0'
            # Keep the build-failure gate independent of simultaneous source edits.
            phorge=work/'phorge-source'
            for name in ['deploy/acceptance/accept.py', 'deploy/acceptance/build-lock.json',
                         'deploy/acceptance/compose.yml', 'tests/contract/worker/acceptance_manifest.py',
                         'tests/contract/worker/acceptance_helpers.py']:
                target=phorge/name
                target.parent.mkdir(parents=True,exist_ok=True)
                shutil.copy2(ROOT/name,target)
            (phorge/'.source-revision').write_text('b'*40)
            runtime=phorge/'support/runtime'
            runtime.mkdir(parents=True)
            from delivery_manifest_test import RuntimeIdentity
            RuntimeIdentity().make_runtime(runtime)
            # Imports must not dirty an otherwise clean release checkout even
            # when the caller did not configure Python's bytecode environment.
            env.pop('PYTHONDONTWRITEBYTECODE',None)
            subprocess.run(['git','init','-q',str(phorge)],check=True)
            subprocess.run(['git','-C',str(phorge),'add','.'],check=True)
            subprocess.run(['git','-C',str(phorge),'-c','user.name=Fixture',
                            '-c','user.email=fixture@example.test','-c','commit.gpgsign=false',
                            '-c','core.hooksPath=/dev/null','commit','-qm','fixture'],check=True)
            out=work/'results'
            p=subprocess.run([sys.executable,str(phorge/'deploy/acceptance/accept.py'),'--gorge-dir',str(gorge),'--output',str(out)],env=env,capture_output=True,text=True)
            self.assertEqual(p.returncode,1,p.stdout+p.stderr)
            self.assertTrue((out/'delivery-manifest.json').is_file(),p.stdout+p.stderr)
            m=json.loads((out/'delivery-manifest.json').read_text())
            self.assertEqual(m['result'],'failed')
            self.assertEqual(m['failureStage'], failure_stage)
            self.assertEqual(m['failureType'], 'CalledProcessError')
            self.assertIn('sourceSHA256',m['phorge'])
            self.assertIs(m['phorge']['dirty'],False)
            self.assertFalse(list(phorge.rglob('__pycache__')))
            self.assertEqual(m['gorge']['commit'],'a'*40)
            self.assertIsNone(m['gorge']['dirty'])
            self.assertEqual(len(m['gorge']['sourceSHA256']),64)
            self.assertIn('runtime', m)
            self.assertNotIn('arcanist', m)
            self.assertIn('sourceSHA256', m['runtime'])
            self.assertNotIn('commit', m['runtime'])
            calls=trace.read_text()
            self.assertIn('--env-file /dev/null',calls)
            self.assertIn('image-platform-check',calls)
            if failure_stage == 'base-image-build':
                self.assertIn('--profile build build base',calls)
                self.assertLess(calls.index('image-platform-check'), calls.index('--profile build build base'))
            else:
                self.assertNotIn('--profile build build base',calls)
            self.assertIn('down --volumes --remove-orphans',calls)
            self.assertIn('-p '+m['project'],calls)
            self.assertNotIn('docker-compose.local.yml',calls)

if __name__=='__main__':unittest.main()
