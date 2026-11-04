"""Host execution of the real pure metadata decoder; no native runtime/SQL proof."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]

class ParticipantMetadataTests(unittest.TestCase):
    def invoke(self, row, required=True):
        script = r'''require $argv[1].'/support/runtime/src/object/Phobject.php';
require $argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryStopRequest.php';
require $argv[1].'/src/applications/people/governance/approval/PhorgeGovernanceNativeApprovalAdapter.php';
$x=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$data=array('parameters'=>array('subject'=>'PHID-USER-aaaaaaaaaaaaaaaaaaaa'),
'requester'=>array('userPHID'=>'PHID-USER-bbbbbbbbbbbbbbbbbbbb'),
'executor'=>array('userPHID'=>'PHID-USER-cccccccccccccccccccc'));
try { PhorgeGovernanceNativeApprovalAdapter::assertParticipantMetadata($x['row'],$data,$x['required']);
echo '{"accepted":true}'; } catch (RuntimeException|InvalidArgumentException $e) { echo '{"accepted":false}'; }'''
        result = subprocess.run(['php', '-r', script, str(ROOT)], input=json.dumps(dict(row=row, required=required)),
                                capture_output=True, text=True, check=True)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)['accepted']

    def good(self):
        return dict(sourceParticipantVersion=1, sourceSubjectPHID='PHID-USER-aaaaaaaaaaaaaaaaaaaa',
                    sourceRequesterPHID='PHID-USER-bbbbbbbbbbbbbbbbbbbb', sourceExecutorPHID='PHID-USER-cccccccccccccccccccc')

    def test_indexed_roles_must_match_actual_binding(self):
        self.assertTrue(self.invoke(self.good()))
        for key in ('sourceSubjectPHID', 'sourceRequesterPHID', 'sourceExecutorPHID'):
            self.assertFalse(self.invoke(dict(self.good(), **{key: 'PHID-USER-dddddddddddddddddddd'})))
        for version in (True, None, 2, '01'):
            self.assertFalse(self.invoke(dict(self.good(), sourceParticipantVersion=version)))

    def test_legacy_is_unavailable_only_in_required_profile_and_cannot_fake_indexing(self):
        row = dict.fromkeys(self.good())
        row['sourceParticipantVersion'] = 0
        self.assertTrue(self.invoke(row, False))
        self.assertFalse(self.invoke(row, True))
        self.assertFalse(self.invoke(dict(row, sourceSubjectPHID=self.good()['sourceSubjectPHID']), False))
        self.assertFalse(self.invoke(self.good(), 1))

if __name__ == '__main__':
    unittest.main()
