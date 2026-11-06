"""Safe partial failure metadata and exact retained-runtime contract outcomes."""
import json,subprocess,unittest,re,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
TRANSPORT_CONTRACT='tests/contract/transport/runtime.php'
TRANSPORT_SUCCESS='Transport contracts passed: envelope, mail receipt/uncertainty and search retries.\n'

def validate_runtime_contract_result(relative,result):
 if type(result.returncode)is not int or result.returncode!=0:
  raise ValueError('runtime contract nonzero exit: '+relative)
 if relative!=TRANSPORT_CONTRACT:
  if result.stderr!='':raise ValueError('unexpected runtime contract stderr: '+relative)
  return
 # This existing transport contract intentionally catches retryable database
 # connection failures. Its normal diagnostic stream is not a failure receipt.
 # Require its exact own success marker and only the two observed native retry
 # frames; arbitrary warnings, other PHLOGs and different sources stay refused.
 if result.stdout!=TRANSPORT_SUCCESS:raise ValueError('transport success marker missing or tainted')
 if result.stderr=='':return
 source=ROOT/'src/infrastructure/storage/connection/mysql/AphrontBaseMySQLDatabaseConnection.php'
 source_lines=source.read_text().splitlines()
 callers=[i for i,line in enumerate(source_lines,1)if line.strip()=='phlog($message);']
 if len(callers)!=1:raise ValueError('native retry logging source ambiguous')
 location=str(source)+':'+str(callers[0])
 message=('Retrying database connection to "localhost" after connection failure '
          '(attempt {attempt}; "AphrontConnectionQueryException"; error #2002): '
          'Attempt to connect to root@localhost failed with error #2002: No such file or directory.')
 frames=result.stderr.splitlines(keepends=True)
 if len(frames)!=2:raise ValueError('unexpected native retry diagnostic count')
 for attempt,line in enumerate(frames,1):
  body="PHLOG: '"+message.format(attempt=attempt)+"' at ["+location+"]\n"
  if re.fullmatch(r'\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] '+re.escape(body),line)is None:
   raise ValueError('unexpected native retry diagnostic')

class PartialDiagnostic(unittest.TestCase):
 def php(self,code):
  r=subprocess.run(['php','-r',"require$argv[1].'/support/governance/recovery/partial-failure-diagnostic.php';"+code,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(r.returncode,0,r.stderr);self.assertEqual(r.stderr,'');return json.loads(r.stdout)
 def test_exact_failure_projection_drops_all_raw_payloads(self):
  d=self.php(r'''$failure=['stage'=>'focused-native-recovery-partial-composition','error_type'=>'TypeError','source_file'=>'restore-review-cases.php','source_line'=>42,'sql_errno'=>1213,'message'=>'SYNTHETIC_SECRET SQL password PHID-USER-private','input'=>['password'=>'SYNTHETIC_SECRET'],'SQL'=>'SELECT private FROM secret','raw_output'=>'SYNTHETIC_SECRET'];echo json_encode(governance_recovery_partial_failure_projection('recovery-partial-composition',false,$failure));''')
  self.assertEqual(d,{'schema':'native-partial-failure-diagnostic-v1','passed':False,'stage':'focused-native-recovery-partial-composition','error_type':'TypeError','source_file':'restore-review-cases.php','source_line':42,'sql_errno':1213});self.assertNotIn('SYNTHETIC_SECRET',json.dumps(d));self.assertNotIn('PHID-',json.dumps(d))
 def test_only_exact_partial_failure_emits_diagnostic(self):
  d=self.php(r'''$out=[];foreach([['all',false],['project-source-facts',false],['recovery-partial-composition',true],['recovery-partial-composition',0],['recovery-partial-composition',false]]as$args){$out[]=governance_recovery_partial_failure_projection($args[0],$args[1],null);}echo json_encode($out);''')
  self.assertEqual(d[:4],[None]*4);self.assertEqual(d[4]['stage'],'cleanup-unproved');self.assertFalse(d[4]['passed'])
 def test_unknown_types_paths_lines_and_magic_values_are_not_coerced(self):
  d=self.php(r'''class MagicDiagnostic{function __toString(){throw new Exception('must not execute');}}$out=[];foreach([['stage'=>'SYNTHETIC_SECRET','error_type'=>'SYNTHETIC_SECRET','source_file'=>'/private/password.php','source_line'=>true,'sql_errno'=>'1213'],['stage'=>new MagicDiagnostic(),'error_type'=>new MagicDiagnostic(),'source_file'=>new MagicDiagnostic(),'source_line'=>new MagicDiagnostic(),'sql_errno'=>new MagicDiagnostic()]]as$f){$out[]=governance_recovery_partial_failure_projection('recovery-partial-composition',false,$f);}echo json_encode($out);''')
  for row in d:self.assertEqual(row,{'schema':'native-partial-failure-diagnostic-v1','passed':False,'stage':'unknown-stage','error_type':'other-error-type','source_file':None,'source_line':None})
 def test_driver_stdout_status_and_success_only_artifact_remain(self):
  s=(ROOT/'support/governance/native-organization/test-native-organization-mysql.php').read_text();self.assertIn("if($phase==='recovery-partial-composition'&&$passed===false)",s);self.assertIn('fwrite(STDERR,json_encode($diagnostic,JSON_UNESCAPED_SLASHES)',s);self.assertTrue(s.rstrip().endswith('exit($passed?0:1);'))
  workflow=(ROOT/'.github/workflows/atomic-feature-regressions.yml').read_text();upload=workflow.split('name: governance-recovery-partial-composition-php-',1)[0].rsplit('      - uses: actions/upload-artifact@v4',1)[1];self.assertIn('if: success()',upload);self.assertNotIn('always()',upload)

 def test_actual_runtime_tokenizer_product_cut_and_excluded_type_stays_scrubbed(self):
  import tempfile
  # Run the actual verifier's complete source-reference scan independently of
  # its supported-runtime bootstrap. Host PHP 8.5.8 cannot run full verify.php;
  # CI must execute the complete verifier under its required PHP 8.5.12.
  verifier=(ROOT/'scripts/runtime/verify.php').read_text()
  scan=verifier[verifier.index('$snapshot_symbols_path ='):verifier.index('if ($refresh) {')]
  code=r'''$project=$argv[1];$runtime=$argv[2];function phutil_register_library_map($m){$GLOBALS['actual_map']=$m;}require$runtime.'/src/__phutil_library_map__.php';$map=$GLOBALS['actual_map'];'''+scan+'echo json_encode(["passed"=>true]);'
  def run(root):return subprocess.run(['php','-r',code,str(root),str(ROOT/'support/runtime')],capture_output=True,text=True,timeout=30)
  r=run(ROOT);self.assertEqual(r.returncode,0,r.stderr);self.assertEqual(r.stderr,'')
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp)
   for name in ('src','scripts','tests','support','resources','bin','webroot','.github'):(root/name).mkdir()
   # Generated fixture only. Keep excluded PHP references out of project PHP.
   (root/'support/mutation.php').write_text('<'+'?php $symbol="PhutilProxyException";')
   bad=run(root);self.assertNotEqual(bad.returncode,0);self.assertIn('Project references excluded upstream symbol',bad.stderr)
  d=self.php(r'''echo json_encode(governance_recovery_partial_failure_projection('recovery-partial-composition',false,['error_type'=>'PhutilProxyException']));''')
  self.assertEqual(d['error_type'],'other-error-type');self.assertNotIn('PhutilProxyException',json.dumps(d))
 def test_supported_full_runtime_verifier_and_related_contracts(self):
  import re
  version=subprocess.run(['php','-r','echo PHP_VERSION;'],capture_output=True,text=True,timeout=10)
  self.assertEqual(version.returncode,0,version.stderr)
  parsed=re.match(r'^(\d+)\.(\d+)\.(\d+)',version.stdout)
  self.assertIsNotNone(parsed)
  major,minor,patch=map(int,parsed.groups())
  if (major,minor)!=(8,5) or patch<12:
   self.skipTest('Full runtime verifier/contracts require PHP 8.5.12+; host is '+version.stdout)
  for relative in ('scripts/runtime/verify.php','tests/contract/worker/bundled_runtime.php',
                   'tests/contract/runtime/compatibility.php','tests/contract/runtime/diff.php',
                   'tests/contract/runtime/integrity.php','tests/contract/runtime/maintenance.php',
                   'tests/contract/transport/runtime.php'):
   result=subprocess.run(['php',str(ROOT/relative)],cwd=ROOT,capture_output=True,text=True,timeout=60)
   validate_runtime_contract_result(relative,result)

 def test_transport_wrapper_accepts_only_observed_retry_diagnostics_with_real_success(self):
  source=ROOT/'src/infrastructure/storage/connection/mysql/AphrontBaseMySQLDatabaseConnection.php'
  caller=next(i for i,line in enumerate(source.read_text().splitlines(),1)if line.strip()=='phlog($message);')
  retry=''.join('[2026-11-06 06:22:59] PHLOG: \'Retrying database connection to "localhost" after connection failure (attempt '+str(attempt)+'; "AphrontConnectionQueryException"; error #2002): Attempt to connect to root@localhost failed with error #2002: No such file or directory.\' at ['+str(source)+':'+str(caller)+']\n'for attempt in (1,2))
  def actual(stdout,stderr,code=0):
   program='import sys;sys.stdout.write('+repr(stdout)+');sys.stderr.write('+repr(stderr)+');sys.exit('+str(code)+')'
   return subprocess.run([sys.executable,'-c',program],capture_output=True,text=True,timeout=10)
  validate_runtime_contract_result(TRANSPORT_CONTRACT,actual(TRANSPORT_SUCCESS,retry))
  validate_runtime_contract_result(TRANSPORT_CONTRACT,actual(TRANSPORT_SUCCESS,''))
  for output,error,code in [(TRANSPORT_SUCCESS,retry,7),('',retry,0),(TRANSPORT_SUCCESS+'tainted',retry,0),(TRANSPORT_SUCCESS,retry+'PHP Warning: unexpected\n',0),(TRANSPORT_SUCCESS,retry.replace('attempt 2','attempt 1'),0),(TRANSPORT_SUCCESS,retry.replace('error #2002','error #1045'),0),(TRANSPORT_SUCCESS,retry.replace('localhost','foreign-host'),0),(TRANSPORT_SUCCESS,retry.replace('AphrontBaseMySQLDatabaseConnection.php','Other.php'),0),(TRANSPORT_SUCCESS,retry.splitlines(keepends=True)[0],0)]:
   with self.assertRaises(ValueError):validate_runtime_contract_result(TRANSPORT_CONTRACT,actual(output,error,code))
 def test_other_runtime_contracts_keep_silent_stderr_and_nonzero_exit_refusal(self):
  for output,error,code in [('success','',1),('success','PHP Warning: unexpected',0),('success','[2026-11-06 06:22:59] PHLOG: arbitrary',0)]:
   result=subprocess.CompletedProcess([],code,output,error)
   with self.assertRaises(ValueError):validate_runtime_contract_result('scripts/runtime/verify.php',result)
