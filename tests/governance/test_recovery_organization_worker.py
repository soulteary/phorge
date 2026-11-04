"""Host-only fixed worker decoder/ownership controls; no native SQL proof."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
WORKERS = ROOT / 'support/governance/native-organization/workers.php'


def php(code):
    result = subprocess.run(['php', '-r', 'require $argv[1];' + code, str(WORKERS)],
                            text=True, capture_output=True, check=True)
    if result.stderr:
        raise AssertionError(result.stderr)
    return json.loads(result.stdout)


class OrganizationProducerWorkerTests(unittest.TestCase):
    def test_valid_commit_and_no_other_status_accepted(self):
        result = php('''
        $valid=array('status'=>'committed','native_member_digest'=>str_repeat('a',64),'restoration_authorized'=>false);
        $accepted=governance_native_organization_producer_result($valid)===$valid;
        $refused=0;
        foreach(array('failed','rejected','pending','allowed')as$status){
          $bad=$valid;$bad['status']=$status;
          try{governance_native_organization_producer_result($bad);}
          catch(RuntimeException $e){if($e->getMessage()!=='native_organization_producer_result_refused'){throw $e;}$refused++;}
        }
        echo json_encode(array($accepted,$refused));
        ''')
        self.assertEqual([True, 4], result)

    def test_types_unknown_fields_and_policy_result_cannot_cross_dispatch(self):
        result = php('''
        $valid=array('status'=>'committed','native_member_digest'=>str_repeat('a',64),'restoration_authorized'=>false);
        $bad=array();
        foreach(array(true,1,null,'A'.str_repeat('a',63),str_repeat('a',63))as$d){$v=$valid;$v['native_member_digest']=$d;$bad[]=$v;}
        foreach(array(true,0,'false')as$d){$v=$valid;$v['restoration_authorized']=$d;$bad[]=$v;}
        $bad[]=$valid+array('authority'=>true);
        $bad[]=array('status'=>'committed','native_policy_digest'=>str_repeat('a',64),'restoration_authorized'=>false);
        $refused=0;foreach($bad as$v){try{governance_native_organization_producer_result($v);}
          catch(RuntimeException $e){if($e->getMessage()!=='native_organization_producer_result_refused'){throw $e;}$refused++;}}
        echo json_encode(array($refused,count($bad)));
        ''')
        self.assertEqual([10, 10], result)

    def test_actual_uint64_generation_uses_independent_integer_oracle(self):
        values = ['1', '9', '99', str(2**63 - 1), str(2**64 - 2)]
        values += [str(10**power - 1) for power in range(1, 19)]
        script = ("class Phobject {} require $argv[1];require $argv[2];"
                  "$out=[];foreach(json_decode(stream_get_contents(STDIN),true)as$v){"
                  "$out[]=PhorgeRecoveryOrganizationProducerFence::nextGuardVersion($v);}"
                  "echo json_encode($out);")
        recovery = ROOT / 'src/infrastructure/governance/recovery'
        args = ['php', '-r', script, str(recovery / 'PhorgeRecoveryStopRequest.php'),
                str(recovery / 'PhorgeRecoveryOrganizationProducerFence.php')]
        result = subprocess.run(args, input=json.dumps(values), text=True,
                                capture_output=True, check=True)
        self.assertEqual('', result.stderr)
        self.assertEqual([str(int(value) + 1) for value in values], json.loads(result.stdout))
        bad_script = script.replace(
            '$out[]=PhorgeRecoveryOrganizationProducerFence::nextGuardVersion($v);',
            "try{PhorgeRecoveryOrganizationProducerFence::nextGuardVersion($v);$out[]=false;}"
            "catch(RuntimeException|InvalidArgumentException $e){$out[]=true;}")
        args[2] = bad_script
        bad = [str(2**64 - 1), str(2**64), '0', '01', True, None, [], 1.5]
        result = subprocess.run(args, input=json.dumps(bad), text=True,
                                capture_output=True, check=True)
        self.assertEqual('', result.stderr)
        self.assertEqual([True] * len(bad), json.loads(result.stdout))

    def test_owned_real_directory_nonce_and_action_are_required(self):
        result = php('''
        $path=sys_get_temp_dir().'/org-worker-host-'.bin2hex(random_bytes(12));mkdir($path,0700);
        $nonce=bin2hex(random_bytes(32));file_put_contents($path.'/owner',$nonce);chmod($path.'/owner',0600);
        $stat=lstat($path);$h=array('index'=>0,'action'=>'recovery.org.member.insert','cleaned'=>false,
          'private'=>$path,'dev'=>$stat['dev'],'ino'=>$stat['ino'],'nonce'=>$nonce);
        try{
          $GLOBALS['governance_native_workers'][0]=$h;
          $accepted=governance_native_organization_producer_handle($h)===$h;$refused=0;
          foreach(array('nonce','ino','action','cleaned')as$key){
            $v=$h;if($key==='nonce'){$v[$key]=str_repeat('0',64);}elseif($key==='ino'){$v[$key]++;}
            elseif($key==='action'){$v[$key]='recovery.policy.insert';}else{$v[$key]=true;}
            if($key==='action'||$key==='cleaned'){$GLOBALS['governance_native_workers'][0]=$v;}else{$GLOBALS['governance_native_workers'][0]=$h;}
            try{governance_native_organization_producer_handle($v);}
            catch(RuntimeException $e){if($e->getMessage()!=='native_organization_producer_handle_refused'){throw $e;}$refused++;}
          }
          echo json_encode(array($accepted,$refused));
        }finally{unlink($path.'/owner');rmdir($path);}
        ''')
        self.assertEqual([True, 4], result)


if __name__ == '__main__':
    unittest.main()
