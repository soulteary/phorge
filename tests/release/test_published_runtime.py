import copy
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import Mock

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'deploy/release'))
from check_published_runtime import check
from test_image_metadata import fixture,m,REPO,TAG,SHA


def metadata():
    registry,_,_=fixture()
    return dict(m.inspect(registry,REPO,TAG,SHA),repository=REPO,tag=TAG,sourceRevision=SHA,ref='refs/tags/'+TAG,overwritePermitted=False)


class PublishedRuntimeChecks(unittest.TestCase):
    def test_each_platform_executes_its_own_verified_child_not_shared_index(self):
        value=metadata();runner=Mock();result=check(value,runner)
        self.assertTrue(result['passed']);self.assertEqual(runner.call_count,4)
        for n,arch in enumerate(('amd64','arm64')):
            pull=runner.call_args_list[2*n];run=runner.call_args_list[2*n+1]
            reference='ghcr.io/'+REPO+'@'+value['platformDigests'][arch]
            self.assertEqual(pull.args[0],['docker','pull','--platform','linux/'+arch,reference])
            self.assertIn(reference,run.args[0]);self.assertNotIn('ghcr.io/'+REPO+'@'+value['digest'],run.args[0])
            self.assertEqual(run.args[0][run.args[0].index('--network')+1],'none')
            self.assertTrue(pull.kwargs['check']);self.assertTrue(run.kwargs['check'])

    def test_incomplete_conflicting_or_mutable_metadata_fails_before_docker(self):
        base=metadata();variants=[]
        for key,value in [('platformDigests',{}),('platformDigests',None),('platforms',['amd64']),('exists',False),('overwritePermitted',True),('ref','refs/heads/main'),('sourceRevision','short'),('digest','latest')]:
            variants.append(dict(base,**{key:value}))
        for wrong in ('latest',base['digest'],base['platformDigests']['amd64']):
            value=copy.deepcopy(base);value['platformDigests']['arm64']=wrong;variants.append(value)
        for value in variants:
            runner=Mock()
            with self.assertRaises(ValueError):check(value,runner)
            runner.assert_not_called()

    def test_failed_pull_stops_before_runtime_and_other_architecture(self):
        runner=Mock(side_effect=subprocess.CalledProcessError(1,['docker','pull']))
        with self.assertRaises(subprocess.CalledProcessError):check(metadata(),runner)
        self.assertEqual(runner.call_count,1)

    def test_failed_runtime_stops_before_next_architecture(self):
        runner=Mock(side_effect=[None,subprocess.CalledProcessError(1,['docker','run'])])
        with self.assertRaises(subprocess.CalledProcessError):check(metadata(),runner)
        self.assertEqual(runner.call_count,2)
