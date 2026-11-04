"""Execute native pure internal counter selection, not database/permission proof."""
import json
from pathlib import Path
import subprocess
import unittest
ROOT=Path(__file__).resolve().parents[2]
class GuardVersionTests(unittest.TestCase):
    def invoke(self,value,enabled,public=False):
        script=r'''require $argv[1].'/support/runtime/src/object/Phobject.php';
require $argv[1].'/src/infrastructure/governance/recovery/PhorgeRecoveryStopRequest.php';
require $argv[1].'/src/applications/people/governance/service/PhorgeOrganizationService.php';
$x=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
try{$v=$x['public']?PhorgeOrganizationService::version($x['value']):PhorgeOrganizationService::guardGeneration($x['value'],$x['enabled']);
echo json_encode(array('accepted'=>true,'value'=>$v,'type'=>gettype($v)),JSON_THROW_ON_ERROR);}
catch(RuntimeException $e){echo json_encode(array('accepted'=>false,'reason'=>$e->getMessage()),JSON_THROW_ON_ERROR);}'''
        p=subprocess.run(['php','-r',script,str(ROOT)],input=json.dumps(dict(value=value,enabled=enabled,public=public)),capture_output=True,text=True,check=True)
        self.assertEqual('',p.stderr);return json.loads(p.stdout)
    def test_recovery_guard_accepts_canonical_uint64_current_generation_only(self):
        for v in ('18446744073709551614','18446744073709551615'):
            self.assertEqual(self.invoke(v,True),dict(accepted=True,value=v,type='string'))
        for v in ('0','01','18446744073709551616',True,None,'1.0'):
            self.assertEqual(self.invoke(v,True),dict(accepted=False,reason='recovery_org_producer_generation_unknown'))
        self.assertEqual(self.invoke('1',1),dict(accepted=False,reason='recovery_org_producer_config_unknown'))
    def test_flag_off_and_public_object_version_keep_the_original_signed_domain(self):
        for enabled,public in ((False,False),(True,True)):
            self.assertEqual(self.invoke('1',enabled,public),dict(accepted=True,value=1,type='integer'))
            self.assertEqual(self.invoke('0',enabled,public),dict(accepted=True,value=0,type='integer'))
            for v in ('18446744073709551614','18446744073709551615','9223372036854775807'):
                self.assertEqual(self.invoke(v,enabled,public),dict(accepted=False,reason='organization_version_refused'))
if __name__=='__main__':unittest.main()
