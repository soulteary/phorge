"""Actual PHP grant decoder host controls, not live-grant or SQL proof."""
from pathlib import Path
import json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
CODE=r'''require $argv[1];require $argv[2];require $argv[3];require $argv[4];
$out=[];foreach(json_decode(stream_get_contents(STDIN),true)as$case){
 try{$value=PhorgeRecoveryMetadataGrantDescription::describe($case[0],$case[1],$case[2]);$out[]=array('accepted'=>true,'value'=>$value);}
 catch(RuntimeException$error){if($error->getMessage()!=='recovery_metadata_grant_profile_unknown'){throw$error;}$out[]=array('accepted'=>false);}}
echo json_encode($out);'''
def decode(cases):
 result=subprocess.run(['php','-r',CODE,str(ROOT/'support/runtime/src/object/Phobject.php'),
  str(ROOT/'support/runtime/src/utils/utils.php'),str(ROOT/'support/runtime/src/utils/utf8.php'),
  str(ROOT/'src/infrastructure/governance/recovery/PhorgeRecoveryMetadataGrantDescription.php')],
  input=json.dumps(cases),text=True,capture_output=True,check=True)
 if result.stderr:raise AssertionError(result.stderr)
 return json.loads(result.stdout)
class NativeGrantDescriptionHostTests(unittest.TestCase):
 def test_static_and_dynamic_native_delimiters_never_become_authority(self):
  cases=[]
  for separator in (',',', ',',   '):
   cases.append([['GRANT '+separator.join(('SELECT','TRIGGER','PROCESS'))+' ON *.* TO `synthetic`@`fixture`',
                  'GRANT '+separator.join(('BACKUP_ADMIN','CONNECTION_ADMIN'))+' ON *.* TO `synthetic`@`fixture`'],'NONE',None])
  for row in decode(cases):
   self.assertTrue(row['accepted']);self.assertTrue(row['value']['required_global_privileges_claimed'])
   for flag in ('actual_live_privileges_proved','metadata_absence_proved','restoration_authorized'):self.assertFalse(row['value'][flag])
 def test_empty_duplicate_control_and_roles_still_refuse(self):
  bad=['SELECT,,PROCESS','SELECT,SELECT','SELECT, SELECT','SELECT,','SELECT, ',
       'SELECT ,PROCESS','SELECT,\tPROCESS','SELECT,process']
  cases=[[[f'GRANT {value} ON *.* TO `synthetic`@`fixture`'],'NONE',None]for value in bad]
  cases += [[['GRANT `role`@`fixture` TO `synthetic`@`fixture`'],'NONE',None],
            [['REVOKE SELECT ON `hidden`.* FROM `synthetic`@`fixture`'],'NONE',None],
            [['GRANT ALL PRIVILEGES ON *.* TO `synthetic`@`fixture`'],'`role`@`fixture`',None],
            [['GRANT ALL PRIVILEGES ON *.* TO `synthetic`@`fixture`'],'NONE','proxy']]
  self.assertEqual([{'accepted':False}]*len(cases),decode(cases))
if __name__=='__main__':unittest.main()
