"""Execute safe failure projection only; no native SQL or fixture acceptance."""
import json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
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
   self.assertEqual(result.returncode,0,relative+': '+result.stderr);self.assertEqual(result.stderr,'',relative)
