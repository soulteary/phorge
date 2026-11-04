"""Execute the real action-specific decoder. No workers or SQL are started."""
import json
from pathlib import Path
import subprocess
import unittest
ROOT=Path(__file__).resolve().parents[2]
class WorkerResultTests(unittest.TestCase):
    def accepted(self,value):
        script=r'''require $argv[1];$value=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
try{governance_native_lifecycle_producer_result($value);echo 'true';}catch(RuntimeException $e){echo 'false';}'''
        p=subprocess.run(['php','-r',script,str(ROOT/'support/governance/native-organization/workers.php')],input=json.dumps(value),capture_output=True,text=True,check=True)
        self.assertEqual('',p.stderr)
        return json.loads(p.stdout)
    def test_actual_committed_digest_shape_and_fixed_no_authority_boundary(self):
        value=dict(status='committed',native_approval_digest='a'*64,restoration_authorized=False)
        self.assertTrue(self.accepted(value))
        for bad in (dict(value,status='rejected'),dict(value,status='failed'),dict(value,restoration_authorized=True),
                    dict(value,restoration_authorized=0),dict(value,native_approval_digest='bad'),dict(value,unknown='private')):
            self.assertFalse(self.accepted(bad))
    def test_real_private_owner_handle_rejects_changed_nonce_and_foreign_action(self):
        import os,tempfile
        script=r'''require $argv[1];$path=$argv[2];$x=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
$stat=lstat($path);$nonce=str_repeat('a',64);
$GLOBALS['governance_native_workers']=array(array('action'=>$x['action'],'cleaned'=>false,
'private'=>$path,'dev'=>$stat['dev'],'ino'=>$stat['ino'],'nonce'=>$nonce));
$handle=array('index'=>0,'dev'=>$stat['dev'],'ino'=>$stat['ino'],'nonce'=>$x['nonce']);
try{governance_native_lifecycle_producer_handle($handle);echo 'true';}catch(RuntimeException $e){echo 'false';}'''
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory);os.chmod(path,0o700)
            (path/'owner').write_text('a'*64);os.chmod(path/'owner',0o600)
            def run(action='recovery.org.approval.propose',nonce='a'*64):
                p=subprocess.run(['php','-r',script,str(ROOT/'support/governance/native-organization/workers.php'),str(path)],
                    input=json.dumps(dict(action=action,nonce=nonce)),capture_output=True,text=True,check=True)
                self.assertEqual('',p.stderr);return json.loads(p.stdout)
            self.assertTrue(run())
            self.assertFalse(run(nonce='b'*64))
            self.assertFalse(run(action='recovery.org.member.insert'))
            (path/'owner').write_text('b'*64)
            self.assertFalse(run())
if __name__=='__main__':unittest.main()
