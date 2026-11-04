"""Actual fixture cleanup body with fake SQL/connection boundaries; no DB proof."""
from pathlib import Path
import json, subprocess, unittest
ROOT=Path(__file__).resolve().parents[2]
SOURCE=ROOT/'support/governance/completion/fixture.php'
SCRIPT=r'''
class PhutilAggregateException extends Exception {public $errors;public function __construct($m,$e){parent::__construct($m);$this->errors=$e;}}
require $argv[1];
class CleanupResult {public function __construct(private $n){}public function fetch_assoc(){return array('n'=>$this->n);}public function free(){}}
class CleanupControl {public $queries=array();public $polls=0;public function __construct(private $fault){}
 public function query($sql){$this->queries[]=$sql;if(str_contains($sql,'PROCESSLIST')){$this->polls++;if($this->fault==='observer'){throw new RuntimeException('synthetic observation fault');}return new CleanupResult($this->polls<2?1:0);}return true;}}
class CleanupConnection {public $called=false;public function __construct(private $fail){}public function close(){$this->called=true;if($this->fail){throw new RuntimeException('synthetic close fault');}}}
class CleanupBase {public $called=false;public function cleanup(){$this->called=true;return array('ownedSchemasRemoved'=>7,'readerRemoved'=>true);}}
$mode=(new mysqli_driver())->report_mode;$control=new CleanupControl($argv[2]);$base=new CleanupBase();
$a=new CleanupConnection($argv[2]==='close');$b=new CleanupConnection(false);
$fixture=(new ReflectionClass(PhorgeGovernanceCompletionFixture::class))->newInstanceWithoutConstructor();
foreach(array('control'=>$control,'base'=>$base,'ownerNonce'=>null,'connections'=>array('setup'=>$a,'auxiliary-setup-0'=>$b),
 'auxiliarySetupThreads'=>array('auxiliary-setup-0'=>42),'createdUsers'=>array('synthetic_one','synthetic_two'),
 'createdDatabases'=>array('synthetic_schema_one','synthetic_schema_two'))as$k=>$v){(new ReflectionProperty(PhorgeGovernanceCompletionFixture::class,$k))->setValue($fixture,$v);}
$result=null;$aggregate=false;try{$result=$fixture->cleanup();}catch(PhutilAggregateException $e){$aggregate=count($e->errors)>0;}
$users=0;$schemas=0;foreach($control->queries as$q){if(str_starts_with($q,'DROP USER')){$users++;}if(str_starts_with($q,'DROP DATABASE')){$schemas++;}}
echo json_encode(array('result'=>$result,'aggregate'=>$aggregate,'base_called'=>$base->called,'connections_called'=>$a->called&&$b->called,
 'users_attempted'=>$users,'schemas_attempted'=>$schemas,'polls'=>$control->polls,'mode_restored'=>(new mysqli_driver())->report_mode===$mode));
'''
def run(mode):
    result=subprocess.run(['php','-r',SCRIPT,str(SOURCE),mode],text=True,capture_output=True,check=True)
    if result.stderr:raise AssertionError(result.stderr)
    return json.loads(result.stdout)
class OwnedSetupCleanupTests(unittest.TestCase):
    def assert_attempted_all(self,result):
        self.assertTrue(result['base_called']);self.assertTrue(result['connections_called'])
        self.assertEqual(2,result['users_attempted']);self.assertEqual(2,result['schemas_attempted'])
        self.assertTrue(result['mode_restored'])
    def test_server_disconnect_delay_is_bounded_polled_to_zero(self):
        result=run('none');self.assert_attempted_all(result);self.assertFalse(result['aggregate'])
        self.assertEqual(2,result['polls']);self.assertEqual({'ownedSchemasRemoved':9,'ownedRuntimeUsersRemoved':2,
          'readerRemoved':True,'ownedAuxiliarySetupConnectionsClosed':1},result['result'])
    def test_observer_fault_does_not_skip_principal_or_schema_cleanup(self):
        result=run('observer');self.assert_attempted_all(result);self.assertTrue(result['aggregate']);self.assertIsNone(result['result'])
    def test_connection_close_fault_does_not_skip_other_owned_cleanup(self):
        result=run('close');self.assert_attempted_all(result);self.assertTrue(result['aggregate']);self.assertIsNone(result['result'])
if __name__=='__main__':unittest.main()
