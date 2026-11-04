"""Host-only actual PHP diagnostic projection; no VM or native execution."""
import json,subprocess,unittest
from pathlib import Path
class NoticeRouterDiagnostic(unittest.TestCase):
 def test_actual_exception_projection_excludes_message_and_arguments(self):
  helper=Path(__file__).resolve().parents[2]/'support/governance/notification/notice-wire-diagnostic.php'
  code='require $argv[1];$e=new RuntimeException("PRIVATE_TOKEN_SQL_ADDRESS");$v=governance_notice_wire_diagnostic("dispatch",$e);error_log(json_encode($v));echo json_encode($v);'
  result=subprocess.run(['php','-r',code,str(helper)],capture_output=True,check=True)
  value=json.loads(result.stdout);self.assertEqual(value,json.loads(result.stderr))
  self.assertEqual({'diagnostic','phase','error_class','source_file','source_line'},set(value))
  self.assertEqual('dispatch',value['phase']);self.assertEqual('RuntimeException',value['error_class'])
  self.assertGreater(value['source_line'],0);self.assertNotIn(b'PRIVATE_TOKEN_SQL_ADDRESS',result.stdout+result.stderr)
 def test_unknown_phase_is_rejected_and_public_response_remains_generic(self):
  root=Path(__file__).resolve().parents[2];helper=root/'support/governance/notification/notice-wire-diagnostic.php'
  code='require $argv[1];try{governance_notice_wire_diagnostic("PRIVATE_PHASE",new Exception("PRIVATE"));exit(1);}catch(InvalidArgumentException $e){echo "refused";}'
  result=subprocess.run(['php','-r',code,str(helper)],capture_output=True,check=True);self.assertEqual(b'refused',result.stdout)
  source=(root/'support/governance/notification/notice-wire-router.php').read_text()
  self.assertIn('echo \'{"error":"private_mail_fixture_unavailable"}\'',source)
  self.assertNotIn('getMessage()',source);self.assertNotIn('getTrace()',source)
if __name__=='__main__':unittest.main()
