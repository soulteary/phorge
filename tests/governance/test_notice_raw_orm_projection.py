"""Safe failure projection only, never an authorization or ABA runtime test."""
import json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
class NoticeRawORMProjectionTests(unittest.TestCase):
    def test_actual_projection_keeps_truthful_revival_witness_but_rejects_private_fields(self):
        source=ROOT/'support/governance/notification/notice-raw-orm-aba-cases.php'
        code=r"""require $argv[1];$v=['notifications_enabled'=>true,'recovery_enabled'=>false,'before_allowed'=>true,
          'after_allowed'=>true,'disabled_commit_observed'=>true,'enabled_commit_observed'=>true,'independent_primary_commit_observer_proved'=>true,
          'user_transaction_source_changed'=>false,'legacy_epoch_source_changed'=>false,
          'binding_account_hash_unchanged'=>true,'actual_native_DAO_saves'=>2,'intermediate_policy_calls'=>0];
        $out=governance_notice_raw_orm_projection($v);$refused=[];
        foreach([['actorPHID'=>'SYNTHETIC_PRIVATE_SENTINEL'],['after_allowed'=>1],['actual_native_DAO_saves'=>'2'],['intermediate_policy_calls'=>1]]as$d){
          try{governance_notice_raw_orm_projection(array_replace($v,$d));$refused[]=false;}
          catch(RuntimeException$e){$refused[]=true;}}
        echo json_encode(['facts'=>$out,'rejected'=>$refused]);"""
        result=subprocess.run(['php','-r',code,str(source)],capture_output=True,text=True)
        self.assertEqual(0,result.returncode,result.stderr);self.assertEqual('',result.stderr)
        self.assertNotIn('SYNTHETIC_PRIVATE_SENTINEL',result.stdout)
        value=json.loads(result.stdout);self.assertIs(value['facts']['after_allowed'],True)
        self.assertEqual([True]*4,value['rejected'])
if __name__=='__main__':unittest.main()
