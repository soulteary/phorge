"""Exact main/Calendar union controls. Native PHP selector/cleanup, no SQL."""
import hashlib,json,subprocess,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
MAIN='fc0369af9d84c4a5f23a7080528e70f1ce84c4e7'
WIRE='9710b9edd56b9acf99b7c79b8c7d84669f7f2559'
DRIVER='support/governance/native-organization/test-native-organization-mysql.php'
def git(*args):return subprocess.check_output(['git','-C',str(ROOT),*args])
class CalendarConduitUnion(unittest.TestCase):
 def test_current_main_production_hooks_map_and_workflows_preserved(self):
  self.assertEqual(b'',git('diff',MAIN,'--','src','resources','.github/workflows'))
  for path in ['support/governance/recovery/conduit-producer.php','support/governance/recovery/conduit-focused.php','support/governance/recovery/conduit-cases.php','support/governance/recovery/conduit-diagnostic.php','support/governance/native-organization/native-worker.php','support/governance/native-organization/workers.php']:
   self.assertEqual((ROOT/path).read_bytes(),git('show',MAIN+':'+path))
 def test_signed_Calendar_fixture_files_are_exact_and_not_reimplemented(self):
  paths=git('diff','--name-only','c496e2cf0ae25fb3e5153c6a1070eb53cf56b504',WIRE,'--','support/governance/notification').decode().splitlines()
  self.assertGreater(len(paths),10)
  for path in paths:self.assertEqual((ROOT/path).read_bytes(),git('show',WIRE+':'+path))
 def test_exact_dual_profile_inverse_and_unknown_mutation_refusal(self):
  sys.path.insert(0,str(ROOT/'scripts/governance'))
  import recovery_conduit_source_delta as delta
  import check_private_calendar_reminders as calendar
  raw=(ROOT/DRIVER).read_bytes()
  self.assertEqual(delta.reduce_calendar_wire_union(raw),git('show',MAIN+':'+DRIVER))
  self.assertEqual(delta.reduce_driver(raw),git('show','c496e2cf0ae25fb3e5153c6a1070eb53cf56b504:'+DRIVER))
  self.assertEqual(calendar.reduce_reviewed_calendar_wire_driver(raw),delta.reduce_driver(raw))
  for addition in delta.CALENDAR_WIRE_DRIVER_ADDITIONS:
   changed=raw.replace(addition,b'',1);old=delta.DRIVER_SHA256
   try:
    delta.DRIVER_SHA256=hashlib.sha256(changed).hexdigest()
    with self.assertRaises(ValueError):delta.reduce_driver(changed)
   finally:delta.DRIVER_SHA256=old
 def test_actual_PHP_both_selectors_and_independent_unknown_cleanup_guards(self):
  program=r'''$s=file_get_contents($argv[1]);$a=strpos($s,'$arguments=array_slice');$b=strpos($s,'$fixture = null;',$a);$init=substr($s,$a,$b-$a);$a=strpos($s,'$borrowed_unknown=$phase===');$b=strpos($s,'  if(!$borrowed_unknown){$_COOKIE',$a);$unknown=substr($s,$a,$b-$a);$a=strrpos($s,'  if($borrowed_unknown){');$b=strpos($s,'  if(!$borrowed_unknown){if ($control !== null)',$a);$guard=substr($s,$a,$b-$a);function governance_recovery_conduit_cleanup_ready(){return($GLOBALS['governance_recovery_conduit_cleanup_proved']??null)===true;}class UnionFixture{function cleanup(){$GLOBALS['drops']++;return[];}}$rows=[];foreach(['--calendar-reminder-wire-hold','--conduit-producer-owner']as$selector){$argv=['owned',$selector];eval($init);$fixture=new UnionFixture();$cleanup_failures=[];$checks=[];$drops=0;eval($unknown);eval($guard);$rows[]=['phase'=>$phase,'calendar'=>$calendar_wire,'calendar_cleanup'=>$GLOBALS['governance_workbench_http_cleanup_permitted'],'conduit_cleanup'=>$GLOBALS['governance_recovery_conduit_cleanup_proved'],'drops'=>$drops,'failures'=>count($cleanup_failures)];}echo json_encode($rows);'''
  r=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen','-r',program,str(ROOT/DRIVER)],capture_output=True,text=True,timeout=5)
  self.assertEqual(r.returncode,0,r.stderr);self.assertEqual(r.stderr,'');rows=json.loads(r.stdout)
  self.assertEqual(rows,[{'phase':'calendar-reminder-wire-hold','calendar':True,'calendar_cleanup':False,'conduit_cleanup':True,'drops':0,'failures':1},{'phase':'conduit-producer-owner','calendar':False,'calendar_cleanup':True,'conduit_cleanup':False,'drops':0,'failures':1}])
