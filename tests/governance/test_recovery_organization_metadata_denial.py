"""Native permission-exception shape controls; no server permission proof."""
from pathlib import Path
import json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
class MetadataDenialTests(unittest.TestCase):
    def test_native_constructor_loses_errno_and_fixed_denial_is_exact(self):
        script=r'''require $argv[1];require $argv[2];require $argv[3];
        $user='gov_c_writer_0123456789ab';$prefix="#1142: SELECT command denied to user '".$user."'@'fixture' for table 'user'";
        $valid=new AphrontAccessDeniedQueryException($prefix."\nNative help text");
        $out=array($valid->getCode()===0,governance_recovery_org_metadata_limited_denial($valid,$user));
        foreach(array(new RuntimeException($prefix),new AphrontAccessDeniedQueryException(str_replace('#1142','#1143',$prefix)),
          new AphrontAccessDeniedQueryException(str_replace("'user'","'other_table'",$prefix)),
          new AphrontAccessDeniedQueryException($prefix,1142),new AphrontAccessDeniedQueryException('arbitrary backend failure'))as$bad){
          $out[]=governance_recovery_org_metadata_limited_denial($bad,$user);}
        $out[]=governance_recovery_org_metadata_limited_denial($valid,'gov_c_writer_ffffffffffff');echo json_encode($out);'''
        recovery=ROOT/'support/governance/recovery/organization-live-metadata-cases.php'
        exception=ROOT/'src/infrastructure/storage/exception'
        result=subprocess.run(['php','-r',script,str(exception/'AphrontQueryException.php'),
          str(exception/'AphrontAccessDeniedQueryException.php'),str(recovery)],capture_output=True,text=True,check=True)
        self.assertEqual('',result.stderr)
        self.assertEqual([True,True,False,False,False,False,False,False],json.loads(result.stdout))
if __name__=='__main__':unittest.main()
