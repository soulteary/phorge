"""Execute actual new ready/release boundaries; actual fixture close uses SQL-handle seams."""
from pathlib import Path
import json,subprocess,unittest,importlib.util
def _fixture_module(name):
 path=Path(__file__).resolve().with_name(name+".py")
 spec=importlib.util.spec_from_file_location("notice_lifecycle_"+name,path)
 module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module
FIXTURE_HANDLES=_fixture_module("test_workbench_http_writer_close").PHP
ACTUAL_NATIVE_BOOT=_fixture_module("test_notice_history_browser").BOOT
ROOT=Path(__file__).resolve().parents[2]
HOLD=ROOT/'support/governance/notification/notice-wire-hold.php'
class NoticeHistoryLifecycle(unittest.TestCase):
 def test_actual_native_Lisk_close_failure_is_already_cleanup_blocked_and_null_branch_untouched(self):
  source=HOLD.read_text();a=source.index('    if($browser!==null){\n      // Only this new native-browser');b=source.index("    $write('ready.json'",a);boundary=source[a:b]
  for nonnull in (True,False):
   code=ACTUAL_NATIVE_BOOT+'''
class ThrowingOwnedConnection {function close(){$GLOBALS['calls']++;throw$GLOBALS['primary'];}}
$primary=new RuntimeException('synthetic-close-primary');$calls=0;$writes=0;
(new ReflectionProperty(LiskDAO::class,'connections'))->setValue(null,['synthetic-owned'=>new ThrowingOwnedConnection()]);
$GLOBALS['governance_workbench_http_cleanup_permitted']=true;
$browser='''+('[]'if nonnull else'null')+''';$same=false;$failed=false;
$write=static function(){$GLOBALS['writes']++;throw new RuntimeException('unexpected-write');};
try{'''+boundary+'''}catch(Throwable$e){$failed=true;$same=$e===$primary;}
echo json_encode(['failed'=>$failed,'same_primary'=>$same,'close_calls'=>$calls,'writes'=>$writes,'cleanup_permitted'=>$GLOBALS['governance_workbench_http_cleanup_permitted'],'remaining_pool'=>count((new ReflectionProperty(LiskDAO::class,'connections'))->getValue()),'physical_SQL_executed'=>false]);'''
   p=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,timeout=5);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');v=json.loads(p.stdout)
   self.assertEqual(v,{'failed':nonnull,'same_primary':nonnull,'close_calls':int(nonnull),'writes':0,'cleanup_permitted':not nonnull,'remaining_pool':0 if nonnull else 1,'physical_SQL_executed':False})
 def run_php(self,body):
  pre='putenv("PHORGE_GOVERNANCE_ISOLATED_TEST=1");class LiskDAO{static function closeAllConnections(){}}require '+json.dumps(str(ROOT/'support/governance/completion/fixture.php'))+';'+FIXTURE_HANDLES
  p=subprocess.run(['php','-r',pre+body],capture_output=True,text=True,timeout=5)
  self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
 def test_actual_ready_boundary_closes_exact_fixture_writer_and_failure_never_writes_manifest(self):
  s=HOLD.read_text();a=s.index('    if($browser!==null){\n      // Only this new native-browser');b=s.index("    $write('ready.json'",a);body=s[a:b]
  for mode in ('normal','close-false','thread-remains','global-off','thread-null'):
   code='[$fixture,$s]=fixture('+json.dumps(mode)+');$browser=[];$owner=str_repeat("a",64);$origin="http://127.0.0.1:1234";$event=["eventID"=>str_repeat("b",64)];$written=[];$write=static function($name,$value)use(&$written){$written[]=$name;};$check=static function($v,$n){if($v!==true)throw new RuntimeException("bad");};try{'+body+'$ready=true;}catch(Throwable$e){$ready=false;}echo json_encode(["ready"=>$ready,"writes"=>$written,"closed"=>$s->closed,"calls"=>$s->closeCalls,"cleanup_permitted"=>$GLOBALS["governance_workbench_http_cleanup_permitted"]]);'
   v=self.run_php(code);self.assertEqual(v['ready'],mode=='normal');self.assertEqual(v['writes'],['history-browser.json']if mode=='normal'else[]);self.assertFalse(v['cleanup_permitted'])
 def test_actual_defaultnull_branch_has_no_close_or_manifest_or_cleanup_override(self):
  s=HOLD.read_text();a=s.index('    if($browser!==null){\n      // Only this new native-browser');b=s.index("    $write('ready.json'",a);body=s[a:b]
  v=self.run_php('[$fixture,$s]=fixture("normal");$browser=null;$GLOBALS["governance_workbench_http_cleanup_permitted"]=true;'+body+'echo json_encode(["calls"=>$s->closeCalls,"cleanup"=>$GLOBALS["governance_workbench_http_cleanup_permitted"]]);')
  self.assertEqual(v,{'calls':0,'cleanup':True})
 def test_actual_new_release_boundary_accepts_only_canonical_independent_SQL_zero(self):
  s=HOLD.read_text();a=s.index('        if($browser!==null){\n          if($history_queue_quiesced');b=s.index('        $released=true;',a);body=s[a:b]
  setup='class S{function bind_param(...$a){}function execute(){}function get_result(){return$this;}function fetch_assoc(){return["n"=>$GLOBALS["n"]];}function close(){}}class C{function prepare($sql){if($sql!=="SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE USER=?")throw new Exception("SQL");return new S();}}$GLOBALS["control"]=new C();'
  for value in (0,'0',1,'1',False,None,'0.0'):
   raw=json.dumps(value);code='[$fixture,$s]=fixture("normal");'+setup+'$GLOBALS["n"]=json_decode('+json.dumps(raw)+');$browser=[];$history_queue_quiesced=true;$GLOBALS["governance_workbench_http_cleanup_permitted"]=false;try{'+body+'$ok=true;}catch(Throwable$e){$ok=false;}echo json_encode(["ok"=>$ok,"cleanup"=>$GLOBALS["governance_workbench_http_cleanup_permitted"]]);'
   expected=type(value)is int and value==0 or type(value)is str and value=='0';self.assertEqual(self.run_php(code),{'ok':expected,'cleanup':expected})
  driver=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text()
  self.assertIn("}elseif(($workbench_http||$notice_history)&&($GLOBALS['governance_workbench_http_cleanup_permitted']??null)!==true){",driver)
